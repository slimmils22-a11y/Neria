<?php
/**
 * Régression : `GdprAuditManager::purgeCustomerData()` ne touchait jamais
 * `neria_log`, alors que `message` (via `WatchdogManager::i18nMsg()`) et
 * `context` (le `$context` brut passé à `info()/warning()/error()/
 * critical()`) contiennent très régulièrement l'email en clair du client
 * — pattern utilisé dans une vingtaine de fichiers du module
 * (confirmations/échecs d'envoi, remboursements, etc.). La table était
 * déclarée `customer_col => null, has_pii => false` dans `REGISTRY`, donc
 * hors du périmètre de `getPiiTablesByCustomer()` ET absente de la
 * cartographie PII affichée au marchand.
 *
 * Bug identifié le 01/09/2026 (round 270, audit "angles morts RGPD").
 *
 * Corrigé le 01/09/2026 (round 270) : nouveau bloc dédié dans
 * `purgeCustomerData()` (même schéma que `neria_webhook_queue`, round
 * 144 : décodage JSON et comparaison EXACTE, pas de `LIKE` sous-chaîne),
 * couvrant `message` (préfixe `::i18n::`, clé `v`) ET `context`.
 * `REGISTRY['neria_log'].has_pii` corrigé à `true`.
 *
 * Test comportemental réel : insère 2 lignes `neria_log` pour le client A
 * — l'une avec l'email dans `message` (format `::i18n::`), l'autre avec
 * l'email UNIQUEMENT dans `context` — et 1 ligne pour un client B dont
 * l'email contient celui de A comme sous-chaîne (protection round 144).
 * Purge de A : ses 2 lignes doivent disparaître, celle de B doit survivre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $emailA = 'jean@example.com';
    $emailB = 'bigjean@example.com'; // contient "jean@example.com" comme sous-chaîne

    $db->execute("DELETE FROM {$prefix}neria_log WHERE id_shop = 999999");

    // Ligne A #1 : email dans message (format ::i18n::)
    $msgA1 = "::i18n::" . json_encode(['k' => 'watchdog.queue_sent_to', 'v' => ['template' => 'order_conf', 'email' => $emailA, 'id' => 1]]);
    $db->execute(
        "INSERT INTO {$prefix}neria_log (id_shop, level, template, class, message, context, date_add)
         VALUES (999999, 'info', 'order_conf', 'QueueManager', '" . pSQL($msgA1) . "', NULL, NOW())"
    );
    $idLogA1 = (int) $db->Insert_ID();

    // Ligne A #2 : email UNIQUEMENT dans context
    $db->execute(
        "INSERT INTO {$prefix}neria_log (id_shop, level, template, class, message, context, date_add)
         VALUES (999999, 'warning', '', 'Unsubscribe', 'test message sans email', '" . pSQL(json_encode(['email' => $emailA])) . "', NOW())"
    );
    $idLogA2 = (int) $db->Insert_ID();

    // Ligne B : email de B contient celui de A comme sous-chaîne — ne doit PAS être purgée
    $msgB = "::i18n::" . json_encode(['k' => 'watchdog.queue_sent_to', 'v' => ['template' => 'order_conf', 'email' => $emailB, 'id' => 2]]);
    $db->execute(
        "INSERT INTO {$prefix}neria_log (id_shop, level, template, class, message, context, date_add)
         VALUES (999999, 'info', 'order_conf', 'QueueManager', '" . pSQL($msgB) . "', NULL, NOW())"
    );
    $idLogB = (int) $db->Insert_ID();

    try {
        neria_assert($idLogA1 > 0 && $idLogA2 > 0 && $idLogB > 0, 'jeu de test invalide : INSERT échoué');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->purgeCustomerData(0, $emailA);

        $rowA1Exists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_log WHERE id_log = {$idLogA1}");
        $rowA2Exists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_log WHERE id_log = {$idLogA2}");
        $rowBExists  = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_log WHERE id_log = {$idLogB}");

        neria_assert(
            $rowA1Exists === 0,
            "la ligne neria_log contenant l'email de A dans 'message' n'a pas été purgée — régression du bug corrigé le 01/09/2026 (round 270) : neria_log échapperait de nouveau au droit à l'effacement RGPD"
        );
        neria_assert(
            $rowA2Exists === 0,
            "la ligne neria_log contenant l'email de A UNIQUEMENT dans 'context' n'a pas été purgée — régression du bug corrigé le 01/09/2026 (round 270)"
        );
        neria_assert(
            $rowBExists === 1,
            "la ligne du client B (email={$emailB}) a été purgée par erreur suite à la demande d'effacement de A (email={$emailA}) — le matching doit rester une comparaison EXACTE, pas une sous-chaîne (round 144)"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php');
        $registryPos = strpos($src, "'table'        => 'neria_log',");
        neria_assert($registryPos !== false, "entrée REGISTRY 'neria_log' introuvable");
        $registryBlock = substr($src, $registryPos, 400);
        neria_assert(
            strpos($registryBlock, "'has_pii'      => true,") !== false,
            "REGISTRY['neria_log'].has_pii n'est plus à true — régression du bug corrigé le 01/09/2026 (round 270) : la cartographie PII affichée au marchand redeviendrait trompeuse sur ce point"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_log WHERE id_shop = 999999");
    }

    return [
        'pass'    => true,
        'message' => "GdprAuditManager::purgeCustomerData() purge désormais neria_log.message ET .context (comparaison exacte), et REGISTRY['neria_log'].has_pii reflète bien la réalité — bug corrigé le 01/09/2026 (round 270)",
    ];
}
