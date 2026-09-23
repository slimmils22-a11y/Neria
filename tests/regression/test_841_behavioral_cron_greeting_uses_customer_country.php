<?php
/**
 * Régression (constat réel du 23/09/2026, campagne P4 sur ps-test) : ni
 * BehavioralCronManager::send() (envoi direct de ~19 automatisations) ni
 * QueueManager::processSingle() (envoi différé de ces mêmes emails à l'heure
 * préférée du client) ne transmettaient {id_customer} à EmailRenderer. Comme pour
 * l'envoi manuel (test_833, round 363), resolveCustomerTimezone() retombait sur
 * le pays PAR DÉFAUT de la boutique : la salutation horaire ({time_greeting}) de
 * TOUS les emails comportementaux suivait le fuseau de la boutique, pas celui du
 * destinataire (journal ps-test : tz America/New_York pour des clients belges et
 * néerlandais).
 *
 * Corrigé : {id_customer} ajouté au tableau $vars de send() et à $allVars de
 * processSingle().
 *
 * Test comportemental réel : la vraie méthode privée send() (Mail::Send complet,
 * fenêtre d'achat désactivée le temps du test pour un envoi direct) vers un client
 * dont l'adresse est basculée sur le Japon ; le journal Watchdog doit refléter
 * Asia/Tokyo, pas le pays par défaut de la boutique. Vérifie aussi le chemin file.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $p      = neria_test_prefix();
    $module = neria_test_module();

    $qm = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
    neria_assert(
        strpos($qm, "'{id_customer}' => \$idCustomerRow,") !== false,
        "QueueManager::processSingle() ne transmet plus {id_customer} — régression du bug corrigé le 23/09/2026 : les emails comportementaux différés retomberaient sur le fuseau de la boutique"
    );

    $idCustomer = neria_test_any_customer_id();
    $row = $db->getRow("SELECT id_customer, email, firstname, lastname, id_lang, id_shop FROM {$p}customer WHERE id_customer={$idCustomer}");
    neria_assert(is_array($row) && $row['email'] !== '', 'Aucun client de test disponible');
    $japan = (int) $db->getValue("SELECT id_country FROM {$p}country WHERE iso_code='JP'");
    neria_assert($japan > 0 && $japan !== (int) Configuration::get('PS_COUNTRY_DEFAULT'), 'Jeu de test invalide (Japon absent ou pays par défaut)');

    $idAddress = (int) $db->getValue("SELECT id_address FROM {$p}address WHERE id_customer={$idCustomer} AND deleted=0 ORDER BY id_address");
    $created = false; $origCountry = null;
    if ($idAddress > 0) {
        $origCountry = (int) $db->getValue("SELECT id_country FROM {$p}address WHERE id_address={$idAddress}");
        $db->execute("UPDATE {$p}address SET id_country={$japan} WHERE id_address={$idAddress}");
    } else {
        $db->execute("INSERT INTO {$p}address (id_customer,id_country,id_state,alias,firstname,lastname,address1,city,postcode,phone,deleted,date_add,date_upd) VALUES ({$idCustomer},{$japan},0,'regtest841','T','R841','1 rue','Ville','00000','0000000000',0,NOW(),NOW())");
        $idAddress = (int) $db->Insert_ID();
        $created = true;
    }

    $refId = 990841;
    $origWindow = Configuration::getGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED');
    Configuration::updateGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED', 0);
    $ctx = Context::getContext();
    $origShop = $ctx->shop;
    try {
        $ctx->shop = new Shop((int) ($row['id_shop'] ?: 1));
        $db->execute("DELETE FROM {$p}neria_behavioral_sent WHERE id_customer={$idCustomer} AND template='win_back' AND ref_id={$refId}");
        $db->execute("DELETE FROM {$p}neria_stat WHERE id_customer={$idCustomer} AND template='win_back' AND event_type='sent' AND date_add > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
        $before = (string) $db->getValue('SELECT NOW()');

        $m = new ReflectionMethod(BehavioralCronManager::class, 'send');
        $m->setAccessible(true);
        $m->invoke(new BehavioralCronManager($module), 'win_back', $row, [], $refId);

        $log = $db->getRow("SELECT message FROM {$p}neria_log WHERE date_add >= '" . pSQL($before) . "' AND message LIKE '%time_greeting_injected%' ORDER BY date_add DESC, id_log DESC");
        neria_assert($log !== false, "Aucune entrée 'time_greeting_injected' journalisée — l'envoi direct n'a pas eu lieu (jeu de test invalide)");
        $decoded = json_decode(substr((string) $log['message'], strlen('::i18n::')), true);
        $tz = (string) ($decoded['v']['tz'] ?? '');
        neria_assert(
            $tz === 'Asia/Tokyo',
            "L'envoi direct du cron comportemental a journalisé le fuseau '{$tz}' au lieu de 'Asia/Tokyo' — régression du bug corrigé le 23/09/2026 : la salutation horaire suit le pays par défaut de la boutique, pas celui du client"
        );
        return [
            'pass'    => true,
            'message' => "Les emails comportementaux (envoi direct et différé) calculent la salutation horaire dans le fuseau réel du client (Asia/Tokyo), plus dans celui de la boutique — bug corrigé le 23/09/2026",
        ];
    } finally {
        $ctx->shop = $origShop;
        if ($origWindow === false || $origWindow === null) {
            Configuration::deleteByName('NERIA_PURCHASE_WINDOW_ENABLED');
        } else {
            Configuration::updateGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED', $origWindow);
        }
        $db->execute("DELETE FROM {$p}neria_behavioral_sent WHERE id_customer={$idCustomer} AND template='win_back' AND ref_id={$refId}");
        if ($created) {
            $db->execute("DELETE FROM {$p}address WHERE id_address={$idAddress}");
        } elseif ($origCountry !== null) {
            $db->execute("UPDATE {$p}address SET id_country={$origCountry} WHERE id_address={$idAddress}");
        }
    }
}
