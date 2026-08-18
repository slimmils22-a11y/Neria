<?php
/**
 * Régression : PageSpeedManager::getTargetUrl() lisait NERIA_PAGESPEED_TARGET_URL
 * en GLOBAL (Configuration::get() sans suffixe id_shop), contrairement à
 * tout le reste de la classe (cache, dernière erreur, dernière tentative)
 * qui passe systématiquement par cacheKey() (suffixe id_shop). Sur une
 * install multi-boutiques, une URL personnalisée configurée par la
 * boutique A s'appliquait à TOUTES les boutiques : la boutique B se
 * voyait analyser et afficher le rapport PageSpeed du site de A sous sa
 * propre clé de cache scopée, sans aucun avertissement.
 *
 * Corrigé le 17/08/2026 (round 182) : lecture scopée via cacheKey(),
 * cohérente avec l'écriture (neria.php, suffixée par $this->context->shop->id).
 *
 * Test comportemental réel : configure une URL personnalisée pour une
 * boutique fictive A, vérifie qu'elle N'apparaît PAS pour le contexte de
 * la boutique courante (repli sur le domaine réel de la boutique
 * courante), puis configure une URL personnalisée pour la boutique
 * courante et vérifie qu'elle est bien celle-là qui est retournée — pas
 * celle de la boutique A.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $otherShop = 999988;
    $key = 'NERIA_PAGESPEED_TARGET_URL';

    $originalCurrent = $db->getValue("SELECT value FROM {$prefix}configuration WHERE name = '{$key}_{$idShop}'");

    try {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name IN ('{$key}_{$idShop}', '{$key}_{$otherShop}')");

        // Boutique A (fictive) configure une URL personnalisée. Passe par
        // Configuration::updateValue() (pas un INSERT SQL brut) pour que le
        // cache statique interne de la classe Configuration reflète bien la
        // valeur dans CE process PHP — identique au vrai point d'écriture
        // (neria.php, action save_seo_config).
        Configuration::updateValue("{$key}_{$otherShop}", 'https://boutique-a-fictive.example.com');

        $mgr = new PageSpeedManager($module);
        $urlWithoutOwnCustom = $mgr->getTargetUrl();

        neria_assert(
            strpos($urlWithoutOwnCustom, 'boutique-a-fictive.example.com') === false,
            "getTargetUrl() de la boutique courante ({$idShop}) renvoie l'URL personnalisée de la boutique fictive {$otherShop} ('{$urlWithoutOwnCustom}') — régression du bug corrigé le 17/08/2026 (round 182) : fuite de configuration inter-boutiques"
        );

        // La boutique courante configure sa PROPRE URL personnalisée.
        Configuration::updateValue("{$key}_{$idShop}", 'https://boutique-courante.example.com');

        $mgr2 = new PageSpeedManager($module);
        $urlWithOwnCustom = $mgr2->getTargetUrl();

        neria_assert(
            strpos($urlWithOwnCustom, 'boutique-courante.example.com') !== false,
            "getTargetUrl() ne retourne pas l'URL personnalisée de la boutique courante ('{$urlWithOwnCustom}') alors qu'elle est bien configurée pour son propre id_shop"
        );
        neria_assert(
            strpos($urlWithOwnCustom, 'boutique-a-fictive.example.com') === false,
            "getTargetUrl() de la boutique courante mélange sa propre URL avec celle de la boutique fictive {$otherShop}"
        );
    } finally {
        Configuration::deleteByName("{$key}_{$idShop}");
        Configuration::deleteByName("{$key}_{$otherShop}");
        if ($originalCurrent !== false && $originalCurrent !== null) {
            Configuration::updateValue("{$key}_{$idShop}", $originalCurrent);
        }
    }

    return [
        'pass'    => true,
        'message' => "PageSpeedManager::getTargetUrl() est bien scopée par boutique, plus de fuite de configuration inter-boutiques — bug corrigé le 17/08/2026 (round 182)",
    ];
}
