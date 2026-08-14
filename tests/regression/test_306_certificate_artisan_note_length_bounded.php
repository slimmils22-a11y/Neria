<?php
/**
 * Régression : la note libre de l'artisan (saisie employé BO, sans limite
 * de longueur) était imprimée telle quelle dans le PDF via MultiCell(),
 * alors que le pied de page plus bas (SetXY(20, 270)) reste à une
 * position ABSOLUE fixe, indépendante de la hauteur réellement occupée.
 * Une note très longue pouvait pousser le corps du texte/la signature
 * au-delà de Y=270, chevauchant le pied de page.
 *
 * Corrigé le 14/08/2026 (round 167) : la note affichée est bornée à 280
 * caractères (mb_substr, sûr sur les caractères multioctets) avant
 * impression.
 *
 * Test structurel : vérifie la présence de la borne de longueur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire CertificateManager.php');

    $posNote = strpos($src, "// ── Note de l'artisan");
    neria_assert($posNote !== false, "Bloc de la note de l'artisan introuvable — jeu de test invalide");
    $body = substr($src, $posNote, 1700);

    neria_assert(
        strpos($body, 'mb_strlen($artisanNote) > 280') !== false,
        "La borne de longueur de la note de l'artisan a disparu — régression du bug corrigé le 14/08/2026 (round 167) : une note très longue pourrait de nouveau chevaucher le pied de page du certificat PDF"
    );
    neria_assert(
        strpos($body, '$displayNote') !== false && strpos($body, "'\"' . \$displayNote . '\"'") !== false,
        "Le MultiCell() n'affiche plus la note bornée (\$displayNote) — régression du bug corrigé le 14/08/2026 (round 167)"
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager borne bien la longueur de la note de l'artisan avant impression PDF — bug corrigé le 14/08/2026 (round 167)",
    ];
}
