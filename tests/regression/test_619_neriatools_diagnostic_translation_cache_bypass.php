<?php
/**
 * Régression : NeriaTools::getDiagnosticReport() comptait les traductions
 * (neria_translation) sans désactiver le cache SQL de PrestaShop
 * (paramètre $use_cache, true par défaut) — contrairement aux lectures
 * voisines (exists/count par table) du même bloc, déjà corrigées au round
 * 216 pour la même raison. Ce compte alimente translations.ok (seuil
 * 5000), 20 des 100 points du score de santé affiché au marchand/support.
 *
 * Corrigé le 07/09/2026 (round 315) : $use_cache=false.
 *
 * Vérification structurelle ciblée : confirme que le 2e argument `false`
 * est bien présent dans l'appel réel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/NeriaTools.php');
    neria_assert($src !== false, 'Impossible de lire src/NeriaTools.php');

    $posMethod = strpos($src, 'public static function getDiagnosticReport(Neria $module): array');
    neria_assert($posMethod !== false, 'getDiagnosticReport() introuvable — jeu de test invalide');

    // 2e occurrence de "neria_translation" dans la méthode : la 1re est
    // l'entrée du tableau $tables (audit exists/count par table), la 2e
    // est la requête COUNT(*) dédiée qui alimente translations.ok.
    $posFirst = strpos($src, 'neria_translation', $posMethod);
    neria_assert($posFirst !== false, 'Comptage neria_translation introuvable dans getDiagnosticReport() — jeu de test invalide');
    $posFn = strpos($src, 'neria_translation', $posFirst + 1);
    neria_assert($posFn !== false, '2e occurrence de neria_translation introuvable — jeu de test invalide');

    // Fenêtre étroite centrée sur le code réel (pas de risque de matcher un
    // commentaire explicatif, qui se trouve avant $posFn ici).
    $body = substr($src, $posFn, 60);

    neria_assert(
        strpos($body, 'false') !== false,
        "NeriaTools::getDiagnosticReport() n'appelle plus getValue() avec \$use_cache=false pour le comptage neria_translation — régression du bug corrigé le 07/09/2026 (round 315) : translations.ok (20 des 100 points du score de santé) pourrait de nouveau se baser sur un décompte périmé"
    );

    return [
        'pass'    => true,
        'message' => "NeriaTools::getDiagnosticReport() contourne bien le cache SQL PrestaShop (\$use_cache=false) pour le comptage des traductions — bug corrigé le 07/09/2026 (round 315)",
    ];
}
