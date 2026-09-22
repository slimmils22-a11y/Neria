<?php
/**
 * Régression (constat réel du 21/09/2026, campagne de tests fonctionnels) : ManualSendManager::send()/
 * scheduleManual() ne transmettaient jamais {id_customer} à EmailRenderer. Résultat : EmailRenderer::
 * resolveCustomerTimezone() (Priorité 1 : adresse du client via id_customer) ne pouvait jamais s'exécuter
 * pour un envoi manuel — retombait systématiquement sur le pays PAR DÉFAUT de la boutique (Priorité 3),
 * quel que soit le pays réel du destinataire.
 *
 * Constaté sur ps-test (pays par défaut de la boutique : États-Unis) : un client français recevait
 * {time_greeting} calculé sur l'heure de New York au lieu de celle de Paris — "Bonjour" à 22h11 heure de
 * Paris (17h11 à New York, tranche "après-midi") au lieu de la salutation du soir attendue.
 *
 * Corrigé : {id_customer} ajouté aux deux tableaux $vars (send() et scheduleManual()).
 *
 * Test comportemental réel : envoi manuel authentique (ManualSendManager::send(), Mail::Send() complet)
 * vers un client de test dont l'adresse est temporairement basculée sur un pays à fuseau très différent
 * de celui de la boutique — le journal Watchdog doit refléter CE fuseau, pas celui du pays par défaut.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $db          = neria_test_db();
    $prefix      = neria_test_prefix();
    $idCustomer  = neria_test_any_customer_id();
    $email       = (string) $db->getValue("SELECT email FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    neria_assert($email !== '', 'Aucun client de test disponible — jeu de test invalide');

    $shopDefaultCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT');
    // Pays à fuseau très éloigné de celui de la boutique de dev (France) : le Japon, retenu aussi par
    // countryIsoToTimezone() (constaté dans EmailRenderer.php) sur une seule zone Asia/Tokyo sans ambiguïté.
    $japan = (int) $db->getValue("SELECT id_country FROM {$prefix}country WHERE iso_code='JP'");
    neria_assert($japan > 0, "Pays 'JP' introuvable — jeu de test invalide");
    neria_assert($japan !== $shopDefaultCountry, 'Le pays par défaut de la boutique de test est déjà le Japon — jeu de test invalide (aucune différence observable)');

    $idAddress = (int) $db->getValue("SELECT id_address FROM {$prefix}address WHERE id_customer={$idCustomer} AND deleted=0 ORDER BY id_address");
    $createdAddress = false;
    $originalCountry = null;

    if ($idAddress > 0) {
        $originalCountry = (int) $db->getValue("SELECT id_country FROM {$prefix}address WHERE id_address={$idAddress}");
        $db->execute("UPDATE {$prefix}address SET id_country={$japan} WHERE id_address={$idAddress}");
    } else {
        // Aucune adresse : en crée une jetable (nécessaire pour que findCustomer()/resolveCustomerTimezone()
        // aient quelque chose à lire), supprimée au nettoyage.
        $db->execute(
            "INSERT INTO {$prefix}address (id_customer, id_country, id_state, alias, firstname, lastname, address1, city, postcode, phone, deleted, date_add, date_upd)
             VALUES ({$idCustomer}, {$japan}, 0, 'regtest833', 'Test', 'Regtest833', '1 rue de test', 'Testville', '00000', '0000000000', 0, NOW(), NOW())"
        );
        $idAddress = (int) $db->Insert_ID();
        $createdAddress = true;
    }

    try {
        $mgr = new ManualSendManager(neria_test_module());

        // 1. Structurel : les 2 tableaux $vars transmettent bien {id_customer}.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
        neria_assert(
            substr_count($src, "'{id_customer}' => (int) (\$customer['id_customer'] ?? 0),") === 2,
            "send()/scheduleManual() ne transmettent plus tous les deux {id_customer} — régression du correctif du 21/09/2026"
        );

        // 2. Comportemental réel : injectTimeGreeting()/resolveCustomerTimezone() résolvent bien Asia/Tokyo
        // à partir du seul {id_customer}, exactement comme send() le construit désormais.
        require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
        $renderer = new EmailRenderer(neria_test_module());
        $resolve  = new ReflectionMethod(EmailRenderer::class, 'resolveCustomerTimezone');
        $resolve->setAccessible(true);
        $tz = $resolve->invoke($renderer, ['{id_customer}' => $idCustomer]);
        neria_assert(
            $tz === 'Asia/Tokyo',
            "resolveCustomerTimezone() n'a pas retrouvé le fuseau du client via {id_customer} (obtenu : '{$tz}') — régression du correctif du 21/09/2026"
        );

        // 3. Bout en bout : un vrai envoi manuel journalise bien ce fuseau (et non le pays par défaut de
        // la boutique) dans le Watchdog. Purge d'abord un éventuel envoi 'sent' récent du même
        // client+template (Mode Silence de CooldownManager), sans quoi ce test bloquerait dès sa 2e
        // exécution rapprochée — comme un envoi réel légitime le ferait.
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer={$idCustomer} AND template='private_sale' AND event_type='sent' AND date_add > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
        // WatchdogManager::info() déduplique les messages IDENTIQUES sur 1h en incrémentant occurrence_count
        // d'une ligne existante plutôt que d'en insérer une nouvelle (id_log inchangé, date_add rafraîchi à
        // NOW()) — un id_log croissant n'est donc pas fiable pour repérer CET appel ; on compare date_add à
        // l'horodatage MySQL pris juste avant l'envoi.
        $beforeTime = (string) $db->getValue('SELECT NOW()');
        $result = $mgr->send('private_sale', $email, '', '', []);
        neria_assert(($result['ok'] ?? false) === true, "Envoi manuel refusé — jeu de test invalide : " . (string) ($result['message'] ?? ''));

        $logRow = $db->getRow(
            "SELECT message FROM {$prefix}neria_log WHERE date_add >= '" . pSQL($beforeTime) . "' AND message LIKE '%time_greeting_injected%' ORDER BY date_add DESC, id_log DESC"
        );
        neria_assert($logRow !== false, "Aucune entrée 'time_greeting_injected' journalisée pour cet envoi — jeu de test invalide");
        $decoded  = json_decode(substr((string) $logRow['message'], strlen('::i18n::')), true);
        $loggedTz = (string) ($decoded['v']['tz'] ?? '');
        neria_assert(
            $loggedTz === 'Asia/Tokyo',
            "L'envoi manuel réel a journalisé le fuseau '{$loggedTz}' au lieu de 'Asia/Tokyo' — la salutation horaire suit encore le pays par défaut de la boutique, pas celui du client (régression du 21/09/2026)"
        );

        return [
            'pass'    => true,
            'message' => "Envoi manuel : la salutation horaire suit bien le fuseau réel du client (Asia/Tokyo) grâce à {id_customer}, plus le pays par défaut de la boutique",
        ];
    } finally {
        if ($createdAddress) {
            $db->execute("DELETE FROM {$prefix}address WHERE id_address={$idAddress}");
        } elseif ($originalCountry !== null) {
            $db->execute("UPDATE {$prefix}address SET id_country={$originalCountry} WHERE id_address={$idAddress}");
        }
    }
}
