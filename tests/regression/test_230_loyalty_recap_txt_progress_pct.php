<?php
/**
 * Régression : mails/themes/neria_global/core/loyalty_recap.txt doit
 * afficher {progress_pct}, comme le fait déjà loyalty_recap.html.
 *
 * Bug réel corrigé le 09/08/2026 (round 149) : {progress_pct} (pourcentage
 * de progression du client vers son prochain palier de fidélité, fourni
 * par LoyaltyManager) était bien assigné côté PHP mais absent du template
 * .txt — un client recevant le récap mensuel en texte brut ne voyait
 * jamais son pourcentage de progression, seulement un libellé générique
 * sans le chiffre.
 *
 * Test structurel : vérifie que {progress_pct} apparaît bien dans
 * loyalty_recap.txt, dans la branche "prochain palier" (à côté de
 * {next_tier_name}, comme dans le .html).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $txt = file_get_contents(_PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/loyalty_recap.txt');
    neria_assert($txt !== false, 'Impossible de lire loyalty_recap.txt');

    neria_assert(
        strpos($txt, '{progress_pct}') !== false,
        "{progress_pct} est de nouveau absent de loyalty_recap.txt — régression du bug corrigé le 09/08/2026 (round 149) : le client ne verrait plus son pourcentage de progression en version texte brute"
    );

    $posNextTier = strpos($txt, '{next_tier_name}');
    $posProgress = strpos($txt, '{progress_pct}');
    neria_assert(
        $posNextTier !== false && $posProgress !== false && $posProgress < $posNextTier,
        "{progress_pct} n'est plus positionné avant {next_tier_name} dans loyalty_recap.txt — la structure attendue (comme dans le .html) a changé"
    );

    return [
        'pass'    => true,
        'message' => "loyalty_recap.txt affiche bien {progress_pct}, cohérent avec loyalty_recap.html",
    ];
}
