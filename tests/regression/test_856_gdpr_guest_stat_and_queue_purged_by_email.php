<?php
/**
 * Régression (observation P7 du 24/09/2026) : l'effacement RGPD d'une personne SANS compte client
 * (id_customer = 0 : envoi manuel à une adresse libre, inscription newsletter seule) ne supprimait ni ses
 * lignes de suivi (neria_stat — variables du message chiffrées : prénom, adresse…) ni sa file d'envoi
 * (neria_queue.recipient_email), et l'export du droit d'accès ne les listait pas : ces lignes ne portent
 * aucune colonne e-mail exploitable sans déchiffrer.
 *
 * Corrigé : GdprAuditManager::guestStatRows() retrouve les lignes id_customer = 0 par le contenu déchiffré
 * (valeur exactement égale à l'adresse), utilisée par la purge ET l'export ; la file est purgée par
 * recipient_email.
 *
 * Test comportemental : deux personnes sans compte (lignes de suivi chiffrées + file d'envoi) ; l'export de
 * l'une ne contient que ses lignes ; sa purge supprime ses lignes et laisse intactes celles de l'autre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';
    $module = neria_test_module();
    $db = neria_test_db();
    $p = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $a = 'regtest856-a-' . uniqid() . '@example.com';
    $b = 'regtest856-b-' . uniqid() . '@example.com';
    $tokens = ['t856a' . uniqid(), 't856b' . uniqid()];

    $insertStat = static function (string $email, string $token) use ($db, $p, $idShop): void {
        $vars = json_encode(['firstname' => 'Regtest', 'email' => $email], JSON_UNESCAPED_UNICODE);
        $enc = class_exists('CryptoManager') ? CryptoManager::encrypt($vars) : $vars;
        $db->execute("INSERT INTO {$p}neria_stat (id_shop, template, lang, id_customer, tracking_token, event_type, rendered_vars, date_add)
            VALUES ({$idShop}, 'vip', 'fr', 0, '" . pSQL($token) . "', 'sent', '" . pSQL($enc) . "', NOW())");
    };
    $insertQueue = static function (string $email, string $template) use ($db, $p, $idShop): void {
        $db->execute("INSERT INTO {$p}neria_queue (id_customer, id_shop, id_lang, template, recipient_email, recipient_name, vars_json, ref_id, send_at, status, created_at)
            VALUES (0, {$idShop}, 1, '" . pSQL($template) . "', '" . pSQL($email) . "', '', '{}', 0, DATE_ADD(NOW(), INTERVAL 1 DAY), 'pending', NOW())");
    };
    $count = static function (string $where) use ($db, $p): int {
        return (int) $db->getValue("SELECT COUNT(*) FROM {$p}" . $where, false);
    };

    try {
        $insertStat($a, $tokens[0]);
        $insertStat($b, $tokens[1]);
        $insertQueue($a, 'regtest856_a');
        $insertQueue($b, 'regtest856_b');
        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');

        $export = $mgr->exportCustomerData(0, $a);
        neria_assert(!empty($export['neria_stat']) && count($export['neria_stat']) === 1, "L'export ne contient pas exactement la ligne de suivi de la personne sans compte : " . json_encode(array_keys($export)));
        neria_assert(($export['neria_stat'][0]['rendered_vars']['email'] ?? '') === $a, "L'export ne présente pas les variables déchiffrées de la personne");
        neria_assert(!empty($export['neria_queue']) && count($export['neria_queue']) === 1, "L'export ne contient pas sa ligne de file d'envoi — régression du correctif du 24/09/2026");

        $purged = $mgr->purgeCustomerData(0, $a, $idShop);
        neria_assert($purged >= 2, "purgeCustomerData() n'a supprimé que {$purged} ligne(s) pour la personne sans compte");
        neria_assert($count("neria_stat WHERE tracking_token = '" . pSQL($tokens[0]) . "'") === 0, "La ligne de suivi de la personne effacée subsiste — régression du correctif du 24/09/2026");
        neria_assert($count("neria_queue WHERE recipient_email = '" . pSQL($a) . "'") === 0, "La file d'envoi de la personne effacée subsiste");
        neria_assert($count("neria_stat WHERE tracking_token = '" . pSQL($tokens[1]) . "'") === 1, "La ligne de suivi d'une AUTRE personne a été supprimée");
        neria_assert($count("neria_queue WHERE recipient_email = '" . pSQL($b) . "'") === 1, "La file d'envoi d'une AUTRE personne a été supprimée");
    } finally {
        $db->execute("DELETE FROM {$p}neria_stat WHERE tracking_token IN ('" . pSQL($tokens[0]) . "','" . pSQL($tokens[1]) . "')");
        $db->execute("DELETE FROM {$p}neria_queue WHERE recipient_email IN ('" . pSQL($a) . "','" . pSQL($b) . "')");
    }

    return [
        'pass'    => true,
        'message' => "L'export et l'effacement RGPD retrouvent les lignes de suivi (chiffrées) et la file d'envoi d'une personne sans compte, sans toucher à celles d'une autre — corrigé le 24/09/2026",
    ];
}
