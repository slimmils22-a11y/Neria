<?php
/**
 * Régression : ABTestManager::getTestStatus() comptait les tests actifs
 * sans désactiver le cache SQL de PrestaShop (paramètre $use_cache, true
 * par défaut) — même famille de bug que les rounds 210-213 (voir
 * getVariantBValue()/copyVariantBToDefault() dans ce même fichier). Le
 * statut affiché en BO juste après une action create/delete pouvait
 * rester en retard d'un rafraîchissement.
 *
 * Corrigé le 07/09/2026 (round 315) : $use_cache=false.
 *
 * Vérification structurelle ciblée : confirme que le 2e argument `false`
 * est bien présent dans l'appel réel, à l'intérieur du corps de la
 * méthode.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ABTestManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ABTestManager.php');

    $posFn = strpos($src, 'public function getTestStatus(string $template): string');
    neria_assert($posFn !== false, 'getTestStatus() introuvable — jeu de test invalide');

    $body = substr($src, $posFn, 700);

    neria_assert(
        strpos($body, 'SELECT COUNT(*)') !== false && strpos($body, 'template') !== false,
        "ABTestManager::getTestStatus() a changé de forme inattendue — jeu de test invalide"
    );

    // Cible le code réel (juste après pSQL($template)), pas un strpos('false')
    // en texte libre qui matcherait aussi le commentaire explicatif ci-dessus
    // ("$use_cache=false") — même piège d'auto-collision rounds 246/312.
    $posTpl = strpos($body, 'pSQL($template)');
    neria_assert($posTpl !== false, 'jeu de test invalide');
    $tail = substr($body, $posTpl, 40);
    neria_assert(
        strpos($tail, 'false') !== false,
        "ABTestManager::getTestStatus() n'appelle plus getValue() avec \$use_cache=false — régression du bug corrigé le 07/09/2026 (round 315)"
    );

    return [
        'pass'    => true,
        'message' => "ABTestManager::getTestStatus() contourne bien le cache SQL PrestaShop (\$use_cache=false) — bug corrigé le 07/09/2026 (round 315)",
    ];
}
