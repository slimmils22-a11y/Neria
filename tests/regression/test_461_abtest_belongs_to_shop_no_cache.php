<?php
/**
 * Régression : neria.php::abtestBelongsToShop() lisait via Db::getValue()
 * sans $use_cache=false, alors que cette méthode sert de contrôle
 * D'AUTORISATION (pas un simple anti-doublon) avant 6 actions sensibles :
 * save_variant_b, reset_variant_b, restore_variant_b, export/import CSV
 * de traductions A/B. Même famille de bug systémique que les rounds
 * 210-217 (quote_add, WebhookManager::retryOne()...), mais jamais
 * appliqué à ce garde-fou précis.
 *
 * Bug réel : sous cache SQL périmé, un résultat "true" mis en cache
 * pourrait autoriser ces actions même après qu'un test A/B a été
 * désactivé ou déplacé vers une autre boutique — contournement du
 * contrôle d'accès par cache SQL périmé.
 *
 * Corrigé le 26/08/2026 (round 222) : $use_cache=false explicite.
 *
 * Test structurel (méthode privée du contrôleur admin, invocable
 * seulement dans un contexte AdminController complet) : vérifie la
 * présence du garde-fou dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posMethod = strpos($src, 'private function abtestBelongsToShop(int $idAbtest, string $template = \'\'): bool');
    neria_assert($posMethod !== false, 'abtestBelongsToShop() introuvable — jeu de test invalide');

    $body = substr($src, $posMethod, 1200);

    neria_assert(
        strpos($body, 'return (bool) Db::getInstance()->getValue($sql, false);') !== false,
        "neria.php::abtestBelongsToShop() n'a plus \$use_cache=false — régression du bug corrigé le 26/08/2026 (round 222) : le contrôle d'autorisation avant écriture sur neria_abtest_translation pourrait de nouveau être contourné par un résultat de cache SQL périmé"
    );

    return [
        'pass'    => true,
        'message' => "neria.php::abtestBelongsToShop() lit bien avec \$use_cache=false — bug corrigé le 26/08/2026 (round 222)",
    ];
}
