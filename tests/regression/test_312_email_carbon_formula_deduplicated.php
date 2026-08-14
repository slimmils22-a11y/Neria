<?php
/**
 * Régression : EmailRenderer.php calculait l'empreinte CO2 (formule +
 * bloc HTML complet) en DEUX endroits distincts et identiques (chemin
 * preview et chemin envoi réel). Une modification future du facteur
 * d'émission (0.02) dans une seule occurrence désynchronisait l'aperçu BO
 * de l'email réellement envoyé, sans qu'aucun test structurel existant ne
 * le détecte.
 *
 * Corrigé le 14/08/2026 (round 168) : factorisé dans une méthode unique
 * privée buildCarbonHtml(string $compiled, string $lang): string, appelée
 * depuis les deux chemins de compilation.
 *
 * Test structurel : vérifie qu'il n'existe plus qu'UNE seule occurrence de
 * la formule de calcul (strlen($compiled) / 1024 * 0.02) dans le fichier,
 * et que les deux points d'injection appellent bien buildCarbonHtml().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
    neria_assert($src !== false, 'Impossible de lire EmailRenderer.php');

    $formulaCount = substr_count($src, '$sizeKb * 0.02');
    neria_assert(
        $formulaCount === 1,
        "La formule de calcul CO2 (\$sizeKb * 0.02) apparaît {$formulaCount} fois au lieu d'1 seule — régression du bug corrigé le 14/08/2026 (round 168) : la duplication empêche toute modification cohérente du facteur d'émission entre l'aperçu BO et l'envoi réel"
    );

    $callCount = substr_count($src, '$this->buildCarbonHtml($compiled, $lang)');
    neria_assert(
        $callCount === 2,
        "buildCarbonHtml() est appelée {$callCount} fois au lieu de 2 (chemin preview + chemin envoi réel) — régression du bug corrigé le 14/08/2026 (round 168)"
    );

    neria_assert(
        strpos($src, 'private function buildCarbonHtml(string $compiled, string $lang): string') !== false,
        "La méthode factorisée buildCarbonHtml() a disparu — régression du bug corrigé le 14/08/2026 (round 168)"
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer.php calcule bien l'empreinte CO2 via une seule méthode factorisée (buildCarbonHtml), appelée par les 2 chemins de compilation — bug corrigé le 14/08/2026 (round 168)",
    ];
}
