<?php
/**
 * Régression (rounds 363/367, correctif central round 368) : plusieurs émetteurs
 * d'e-mails (envoi manuel, cron comportemental, file d'attente, mais aussi
 * campagnes de segment, saisonnières, liste d'attente, collections, looks,
 * fidélité, certificats…) n'envoient ni {id_customer} ni {id_address_delivery}.
 * La salutation horaire retombait alors sur le fuseau du pays par défaut de la
 * boutique, quel que soit le pays réel du destinataire.
 *
 * Corrigé au point central : EmailRenderer::resolveCustomerTimezone() retrouve
 * désormais le client par l'ADRESSE E-MAIL du destinataire quand aucun
 * identifiant n'est fourni (Priorité 2 bis).
 *
 * Test comportemental : (1) la méthode résout Asia/Tokyo à partir du seul e-mail
 * d'un client dont l'adresse est au Japon, et retombe sur le pays de la boutique
 * pour un e-mail inconnu ; (2) bout en bout, un vrai Mail::Send() SANS aucun
 * identifiant client journalise bien Asia/Tokyo.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $p  = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $row = $db->getRow("SELECT email, id_lang FROM {$p}customer WHERE id_customer={$idCustomer}");
    neria_assert(is_array($row) && $row['email'] !== '', 'Aucun client de test');
    $email = (string) $row['email'];
    $japan = (int) $db->getValue("SELECT id_country FROM {$p}country WHERE iso_code='JP'");
    neria_assert($japan > 0 && $japan !== (int) Configuration::get('PS_COUNTRY_DEFAULT'), 'Jeu de test invalide (Japon / pays par défaut)');

    $idAddress = (int) $db->getValue("SELECT id_address FROM {$p}address WHERE id_customer={$idCustomer} AND deleted=0 ORDER BY id_address");
    $created = false; $origCountry = null;
    if ($idAddress > 0) {
        $origCountry = (int) $db->getValue("SELECT id_country FROM {$p}address WHERE id_address={$idAddress}");
        $db->execute("UPDATE {$p}address SET id_country={$japan} WHERE id_address={$idAddress}");
    } else {
        $db->execute("INSERT INTO {$p}address (id_customer,id_country,id_state,alias,firstname,lastname,address1,city,postcode,phone,deleted,date_add,date_upd) VALUES ({$idCustomer},{$japan},0,'regtest842','T','R842','1 rue','Ville','00000','0000000000',0,NOW(),NOW())");
        $idAddress = (int) $db->Insert_ID();
        $created = true;
    }

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
        $renderer = new EmailRenderer(neria_test_module());
        $m = new ReflectionMethod(EmailRenderer::class, 'resolveCustomerTimezone');
        $m->setAccessible(true);

        $tz = $m->invoke($renderer, [], $email);
        neria_assert($tz === 'Asia/Tokyo', "resolveCustomerTimezone([], e-mail d'un client japonais) a renvoyé '{$tz}' au lieu de 'Asia/Tokyo' — fallback central par e-mail absent (round 368)");

        $shopTz = $m->invoke($renderer, [], 'inconnu-842@example.invalid');
        neria_assert($shopTz !== 'Asia/Tokyo', "Un e-mail inconnu ne doit pas résoudre le Japon (obtenu '{$shopTz}')");

        // Bout en bout : Mail::Send() réel, aucun {id_customer} ni {id_address_delivery}
        $db->execute("DELETE FROM {$p}neria_stat WHERE id_customer={$idCustomer} AND template='private_sale' AND event_type='sent' AND date_add > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
        $before = (string) $db->getValue('SELECT NOW()');
        Mail::Send(
            (int) $row['id_lang'] ?: (int) Configuration::get('PS_LANG_DEFAULT'),
            'private_sale',
            'Test 842',
            ['{firstname}' => 'Test', '{lastname}' => 'R842', '{shop_name}' => 'Shop'],
            $email, 'Test', null, null, null, null,
            _PS_MODULE_DIR_ . 'neria/mails/', false, (int) Context::getContext()->shop->id
        );
        $log = $db->getRow("SELECT message FROM {$p}neria_log WHERE date_add >= '" . pSQL($before) . "' AND message LIKE '%time_greeting_injected%' ORDER BY date_add DESC, id_log DESC");
        neria_assert($log !== false, "Aucune salutation horaire journalisée pour le Mail::Send() sans identifiant (jeu de test invalide ou salutation désactivée)");
        $decoded = json_decode(substr((string) $log['message'], strlen('::i18n::')), true);
        $loggedTz = (string) ($decoded['v']['tz'] ?? '');
        neria_assert($loggedTz === 'Asia/Tokyo', "Un envoi SANS {id_customer} a journalisé le fuseau '{$loggedTz}' au lieu de 'Asia/Tokyo' — fallback central par e-mail cassé (round 368)");

        return [
            'pass'    => true,
            'message' => "Fallback central : un e-mail envoyé sans {id_customer} ni {id_address_delivery} calcule bien la salutation dans le fuseau du client retrouvé par son adresse e-mail (Asia/Tokyo) — round 368",
        ];
    } finally {
        if ($created) {
            $db->execute("DELETE FROM {$p}address WHERE id_address={$idAddress}");
        } elseif ($origCountry !== null) {
            $db->execute("UPDATE {$p}address SET id_country={$origCountry} WHERE id_address={$idAddress}");
        }
    }
}
