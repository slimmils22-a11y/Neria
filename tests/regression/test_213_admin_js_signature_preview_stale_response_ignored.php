<?php
/**
 * Régression : neria-admin.js::initSignaturePreview() doit ignorer une
 * réponse fetch() obsolète (arrivée après une requête plus récente), via
 * un jeton de requête.
 *
 * Bug réel corrigé le 09/08/2026 (round 146) : aucun jeton/compteur de
 * requête ne protégeait contre une réponse arrivant dans le désordre. Un
 * marchand cliquant "Aperçu" avec le style A puis, rapidement, avec le
 * style B, pouvait voir l'aperçu affiché écrasé par le rendu du style A si
 * sa réponse (plus lente) arrivait APRÈS celle de B — l'aperçu affiché ne
 * correspondait alors plus à la configuration visible à l'écran
 * (style/couleur), sans qu'aucune erreur ne le signale.
 *
 * Test structurel (pas de moteur JS disponible dans cet environnement de
 * test PHP) : vérifie que le jeton de requête est bien déclaré et
 * consulté à la fois dans le .then() de succès et dans le .catch()
 * d'erreur, avant toute mise à jour du DOM.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/js/neria-admin.js');
    neria_assert($src !== false, 'Impossible de lire views/js/neria-admin.js');

    $posFn = strpos($src, 'function initSignaturePreview()');
    neria_assert($posFn !== false, 'initSignaturePreview() introuvable — jeu de test invalide');

    $posEnd = strpos($src, "\n    }\n", strpos($src, "btn.addEventListener('click'", $posFn));
    $body = substr($src, $posFn, ($posEnd !== false ? $posEnd - $posFn : 2500));

    neria_assert(
        strpos($body, 'var requestToken = 0;') !== false,
        "initSignaturePreview() n'a plus de compteur de jeton de requête — régression du bug corrigé le 09/08/2026 (round 146) : une réponse obsolète pourrait de nouveau écraser un aperçu plus récent"
    );
    neria_assert(
        strpos($body, 'var thisRequest = ++requestToken;') !== false,
        "initSignaturePreview() ne capture plus de jeton par requête — régression du bug corrigé le 09/08/2026 (round 146)"
    );

    $countGuards = substr_count($body, 'if (thisRequest !== requestToken) return;');
    neria_assert(
        $countGuards >= 2,
        "initSignaturePreview() ne vérifie plus le jeton dans le .then() de succès ET le .catch() d'erreur (trouvé {$countGuards}/2) — régression du bug corrigé le 09/08/2026 (round 146)"
    );

    return [
        'pass'    => true,
        'message' => "neria-admin.js::initSignaturePreview() ignore bien les réponses fetch() obsolètes via un jeton de requête",
    ];
}
