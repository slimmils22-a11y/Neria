<?php
/**
 * Régression : AbTestManager::copyVariantBToDefault() lisait id_abtest
 * via un SELECT sans $use_cache=false — même famille de bug systémique
 * que les rounds 210-212.
 *
 * Bug réel : le texte SQL de ce SELECT (WHERE id_shop/template/variant=
 * 'B'/is_active=1) est identique d'un cycle de test A/B à l'autre sur le
 * même template — l'id_abtest n'apparaît pas dans le WHERE, seulement
 * dans le SELECT. Sous cache SQL BO actif, applyWinner('B') sur un
 * NOUVEAU cycle de test pouvait renvoyer l'id_abtest d'un cycle
 * PRÉCÉDENT (déjà désactivé), promouvant en production les traductions
 * de l'ancien test B au lieu du test réellement gagnant.
 *
 * Corrigé le 26/08/2026 (round 213) : $use_cache=false explicite.
 *
 * Test structurel (reproduire deux cycles de test A/B successifs avec
 * cache Memcache injecté serait redondant avec test_440/441 qui
 * démontrent déjà le mécanisme sous-jacent de bout en bout) : vérifie la
 * présence du garde-fou dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ABTestManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ABTestManager.php');

    $posMethod = strpos($src, 'private function copyVariantBToDefault(string $template): void');
    neria_assert($posMethod !== false, 'copyVariantBToDefault() introuvable — jeu de test invalide');

    $body = substr($src, $posMethod, 1200);

    neria_assert(
        strpos($body, "AND `is_active` = 1\",\n            false\n        );") !== false,
        "AbTestManager::copyVariantBToDefault() n'a plus \$use_cache=false sur sa lecture d'id_abtest — régression du bug corrigé le 26/08/2026 (round 213) : un nouveau cycle de test A/B gagné par B pourrait promouvoir les traductions d'un ANCIEN test B déjà désactivé, via un résultat de cache SQL périmé"
    );

    return [
        'pass'    => true,
        'message' => "AbTestManager::copyVariantBToDefault() résout bien id_abtest avec \$use_cache=false — bug corrigé le 26/08/2026 (round 213)",
    ];
}
