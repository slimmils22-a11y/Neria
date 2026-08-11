<?php
/**
 * Régression : les méthodes de transformation de MultiClientPreviewManager
 * doivent survivre à un échec PCRE (preg_replace()/preg_replace_callback()
 * renvoyant null), comme replaceInInlineStyles() le fait déjà via `?? $html`.
 *
 * Bug réel corrigé le 09/08/2026 (round 144) : stripStyleAndLinkTags(),
 * transformOutlook(), stripMediaQueries(), transformSamsungEmail(),
 * transformProtonMail() et transformJpCarrier() réaffectaient le résultat
 * brut de preg_replace()/preg_replace_callback() sans filet — en cas
 * d'erreur PCRE (ex. pcre.backtrack_limit dépassé sur un CSS très dense),
 * $html devenait null : soit l'aperçu s'affichait vide (chaîne vide
 * silencieuse), soit addBanner(null, ...) provoquait un TypeError fatal
 * (paramètre $html non-nullable), plantant la page d'aperçu multi-client.
 *
 * Test comportemental réel : force un échec PCRE réel en abaissant
 * pcre.backtrack_limit à 1 (n'importe quel pattern avec backtracking
 * échoue), appelle transformForClient() pour chaque client affecté, et
 * vérifie qu'aucune exception/erreur fatale n'est levée et qu'une chaîne
 * est bien renvoyée (même si le contenu n'a pas pu être transformé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php';

    $html = '<html><head><style>' . str_repeat('.a{color:red;background-image:url(x);border-radius:4px;} ', 50) . '</style></head>'
        . '<body><div style="background-image:url(y);border-radius:8px;">' . str_repeat('x', 200) . '</div></body></html>';

    $mgr = new MultiClientPreviewManager();
    $clients = ['gmail', 'outlook', 'yahoo', 'samsung_email', 'protonmail', 'jp_carrier'];

    $originalLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        foreach ($clients as $client) {
            try {
                $result = $mgr->transformForClient($html, $client);
            } catch (\Throwable $e) {
                neria_assert(
                    false,
                    "transformForClient(\$html, '{$client}') a levé " . get_class($e) . " : " . $e->getMessage() . " — régression du bug corrigé le 09/08/2026 (round 144) : un échec PCRE (preg_replace* renvoyant null) provoquerait de nouveau un crash au lieu d'un filet ?? \$html"
                );
            }
            neria_assert(
                is_string($result),
                "transformForClient(\$html, '{$client}') n'a pas renvoyé de chaîne sous pcre.backtrack_limit réduit"
            );
        }
    } finally {
        ini_set('pcre.backtrack_limit', $originalLimit !== false ? $originalLimit : '1000000');
    }

    return [
        'pass'    => true,
        'message' => "Les méthodes de transformation de MultiClientPreviewManager survivent bien à un échec PCRE (filet ?? \$html), pour les 6 clients concernés par le correctif",
    ];
}
