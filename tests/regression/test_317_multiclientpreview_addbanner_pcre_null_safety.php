<?php
/**
 * Régression : MultiClientPreviewManager::addBanner() était la SEULE
 * méthode du fichier à retourner directement le résultat brut de
 * preg_replace() sans filet `?? $html` — contrairement à
 * stripStyleAndLinkTags(), transformOutlook(), stripMediaQueries(),
 * transformSamsungEmail(), transformProtonMail() et transformJpCarrier(),
 * toutes corrigées au round 144 pour ce même piège (voir test_203).
 * addBanner() est le DERNIER appel de chaque transform*() : un échec
 * PCRE (pcre.backtrack_limit dépassé, réaliste sur une balise <body>
 * chargée d'attributs sur un hébergement mutualisé aux limites basses)
 * faisait retourner null par preg_replace(), provoquant une TypeError
 * fatale sur cette méthode déclarée `: string` — plantant tout le rendu
 * multi-client (15 clients) d'un coup, pas seulement celui affecté.
 *
 * Corrigé le 15/08/2026 (round 170) : `?? $html` ajouté, alignant
 * addBanner() sur le reste du fichier.
 *
 * Test comportemental réel : force un échec PCRE réel (pcre.backtrack_limit
 * abaissé à 1) avec une balise <body> volontairement complexe (de quoi
 * faire échouer le moteur PCRE même sur un pattern simple), appelle
 * addBanner() directement via réflexion (méthode privée) et vérifie
 * qu'aucune TypeError n'est levée et qu'une chaîne est bien renvoyée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php';

    // Balise <body> volontairement lourde en attributs répétés, pour
    // maximiser les chances de backtracking sur le pattern [^>]* même
    // sous une limite très basse.
    $body = '<body ' . str_repeat('data-x="' . str_repeat('y', 30) . '" ', 80) . '>';
    $html = '<html><head></head>' . $body . str_repeat('contenu ', 100) . '</body></html>';

    $mgr = new MultiClientPreviewManager();
    $ref = new ReflectionMethod(MultiClientPreviewManager::class, 'addBanner');
    $ref->setAccessible(true);

    $originalLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        try {
            $result = $ref->invoke($mgr, $html, 'gmail');
        } catch (\Throwable $e) {
            neria_assert(
                false,
                "addBanner() a levé " . get_class($e) . " : " . $e->getMessage() . " — régression du bug corrigé le 15/08/2026 (round 170) : preg_replace() renvoyant null sur un échec PCRE provoquerait de nouveau une TypeError fatale, faute de filet ?? \$html"
            );
        }
        neria_assert(
            is_string($result) && $result !== '',
            "addBanner() n'a pas renvoyé de chaîne non-vide sous pcre.backtrack_limit réduit"
        );
    } finally {
        ini_set('pcre.backtrack_limit', $originalLimit !== false ? $originalLimit : '1000000');
    }

    return [
        'pass'    => true,
        'message' => "MultiClientPreviewManager::addBanner() survit bien à un échec PCRE (filet ?? \$html) au lieu de planter tout le rendu multi-client — bug corrigé le 15/08/2026 (round 170)",
    ];
}
