<?php
/**
 * Régression : StatsManager::recordConversion() était void — ses 3 sorties
 * anticipées (token inconnu, boutique différente de celle de l'envoi
 * tracké, GET_LOCK() non obtenu sous contention) étaient indiscernables
 * d'un succès pour l'appelant. hookActionOrderStatusPostUpdateImpl()
 * (neria.php) journalisait "conversion enregistrée" et supprimait
 * DÉFINITIVEMENT la ligne neria_attribution même sur ces échecs — perdant
 * le token sans jamais avoir réellement crédité la conversion, notamment
 * quand une commande traverse 2 statuts quasi simultanément (verrou perdu
 * par la 2e tentative).
 *
 * Bug réel identifié le 23/08/2026 (round 191).
 *
 * Corrigé le 23/08/2026 (round 191) : recordConversion() retourne
 * désormais bool — false sur un échec transitoire (token/boutique), true
 * si réellement enregistrée OU déjà enregistrée précédemment (cas
 * légitime de nettoyage). neria.php ne journalise/supprime plus que si
 * true.
 *
 * Test comportemental réel : token totalement inconnu (jamais envoyé) et
 * token avec boutique différente de celle de la commande doivent tous deux
 * retourner false.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    $mgr = new StatsManager($module);

    // Cas 1 : token totalement inconnu.
    $unknownToken = bin2hex(random_bytes(16));
    $result1 = $mgr->recordConversion($unknownToken, 999999, 42.0, $idShop);
    neria_assert(
        $result1 === false,
        "recordConversion() avec un token inconnu retourne " . var_export($result1, true) . " au lieu de false — régression du bug corrigé le 23/08/2026 (round 191) : neria.php journaliserait de nouveau 'conversion enregistrée' et supprimerait à tort une ligne neria_attribution sur un échec"
    );

    // Cas 2 : token connu mais boutique différente de celle de l'envoi tracké.
    $token = bin2hex(random_bytes(16));
    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'");
    $otherShop = $idShop + 1;
    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
         VALUES
            ({$otherShop}, 'order_conf', 'fr', 0, 0, '" . pSQL($token) . "', 'sent', NOW())"
    );

    try {
        $result2 = $mgr->recordConversion($token, 999998, 42.0, $idShop);
        neria_assert(
            $result2 === false,
            "recordConversion() avec un token envoyé depuis une AUTRE boutique retourne " . var_export($result2, true) . " au lieu de false — régression du bug corrigé le 23/08/2026 (round 191)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'");
    }

    return [
        'pass'    => true,
        'message' => "StatsManager::recordConversion() retourne bien false sur un échec transitoire (token inconnu, boutique différente) — bug corrigé le 23/08/2026 (round 191)",
    ];
}
