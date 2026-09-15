<?php
/**
 * Régression : `neria-emergency.php` lisait `NERIA_HEALTH_RESULTS`/
 * `NERIA_HEALTH_LAST_RUN`/`NERIA_CONSECUTIVE_FAILURES` via un simple
 * `SELECT ... LIMIT 1` SANS `id_shop` ni tri déterministe — sur une
 * install multiboutique, ces 3 clés sont écrites scopées par boutique
 * (`Configuration::updateValue()` sans `$idShop` explicite retombe sur
 * `Shop::getContextShopID(true)`). Cette page de secours (justement
 * conçue pour fonctionner SANS notion de "boutique en cours") pouvait
 * donc afficher les résultats d'une boutique arbitraire au pire moment
 * — précisément quand on cherche à savoir laquelle est réellement en
 * panne.
 *
 * Bug confirmé le 15/09/2026 (round sécurité dédié, addendum au finding
 * round 331) — traité en bloc (d) de la feuille de route Addons, sur
 * arbitrage explicite de l'utilisateur : agrégation (afficher chaque
 * boutique séparément) plutôt que scoping (qui nécessiterait un sélecteur
 * de boutique sur une page volontairement minimale).
 *
 * Corrigé le 15/09/2026 : une section "Contrôles de santé" PAR boutique
 * active, chacune avec SES PROPRES résultats/dernière exécution/échecs
 * consécutifs — plus une colonne "Boutique" dans le tableau des logs
 * (neria_log a bien sa propre colonne id_shop, schéma confirmé round
 * 359) quand plusieurs boutiques actives sont détectées.
 *
 * Validé en conditions réelles le 15/09/2026 sur l'installation à 2
 * boutiques natives ps-test.neriasoftware.com (voir
 * reference_o2switch_ps_test_environment.md) : chaque section affiche
 * bien le résultat de SA boutique, aucune fuite/masquage constaté.
 *
 * Test comportemental réel (local) : insère une 2e boutique fictive
 * réelle dans `shop` (même technique que test_759 pour `shop_url`) avec
 * des résultats de santé DIFFÉRENTS de la boutique 1, exécute le script
 * réellement (capture de sortie), vérifie que les 2 jeux de résultats
 * apparaissent bien séparément.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $fakeIdShop = 999997701;
    $fakeShopName = 'RegTest770 Boutique B';
    $realIdShop = (int) Context::getContext()->shop->id;

    $cleanup = function () use ($db, $prefix, $fakeIdShop) {
        $db->execute("DELETE FROM {$prefix}shop WHERE id_shop = {$fakeIdShop}");
        $db->execute("DELETE FROM {$prefix}configuration WHERE name IN ('NERIA_HEALTH_RESULTS','NERIA_HEALTH_LAST_RUN','NERIA_CONSECUTIVE_FAILURES') AND id_shop IN ({$fakeIdShop})");
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'NERIA_HEALTH_RESULTS' AND id_shop = " . (int) Context::getContext()->shop->id . " AND value LIKE '%regtest770%'");
    };
    $cleanup();

    try {
        $realShopGroup = (int) $db->getValue("SELECT id_shop_group FROM {$prefix}shop WHERE id_shop = {$realIdShop}");
        $db->execute(
            "INSERT INTO {$prefix}shop (id_shop, id_shop_group, name, active, deleted, color, theme_name, id_category)
             SELECT {$fakeIdShop}, id_shop_group, '" . pSQL($fakeShopName) . "', 1, 0, color, theme_name, id_category
             FROM {$prefix}shop WHERE id_shop = {$realIdShop}"
        );

        $resultsReal = json_encode(['regtest770_check' => ['status' => 'ok', 'detail' => 'REGTEST770_SHOP_REAL_OK']]);
        $resultsFake = json_encode(['regtest770_check' => ['status' => 'error', 'detail' => 'REGTEST770_SHOP_FAKE_DOWN']]);

        $db->execute(
            "INSERT INTO {$prefix}configuration (name, value, id_shop, id_shop_group, date_add, date_upd)
             VALUES ('NERIA_HEALTH_RESULTS', '" . pSQL($resultsReal) . "', {$realIdShop}, {$realShopGroup}, NOW(), NOW())"
        );
        $db->execute(
            "INSERT INTO {$prefix}configuration (name, value, id_shop, id_shop_group, date_add, date_upd)
             VALUES ('NERIA_HEALTH_RESULTS', '" . pSQL($resultsFake) . "', {$fakeIdShop}, {$realShopGroup}, NOW(), NOW())"
        );

        $token = (string) Configuration::get('NERIA_EMERGENCY_TOKEN');
        neria_assert($token !== '', 'NERIA_EMERGENCY_TOKEN vide — jeu de test invalide');

        $_GET['token'] = $token;
        $_GET['lang']  = 'fr';

        ob_start();
        require _PS_MODULE_DIR_ . 'neria/neria-emergency.php';
        $html = ob_get_clean();

        neria_assert(
            strpos($html, 'REGTEST770_SHOP_REAL_OK') !== false,
            "La page d'urgence n'affiche plus les résultats de la boutique réelle (id_shop={$realIdShop}) — jeu de test invalide ou régression"
        );
        neria_assert(
            strpos($html, 'REGTEST770_SHOP_FAKE_DOWN') !== false,
            "La page d'urgence n'affiche plus les résultats de la 2e boutique (id_shop={$fakeIdShop}) — régression du bug corrigé le 15/09/2026 : sur une install multiboutique, seule une boutique arbitraire serait de nouveau affichée"
        );
        neria_assert(
            strpos($html, htmlspecialchars($fakeShopName)) !== false,
            "Le nom de la 2e boutique ({$fakeShopName}) n'apparaît plus dans la page — la section par boutique ne s'affiche plus correctement"
        );

        return [
            'pass'    => true,
            'message' => "neria-emergency.php affiche désormais les résultats de santé de CHAQUE boutique active séparément (agrégation), plus une colonne Boutique dans le journal des logs — bug corrigé le 15/09/2026",
        ];
    } finally {
        unset($_GET['token'], $_GET['lang']);
        $cleanup();
    }
}
