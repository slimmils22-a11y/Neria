<?php
/**
 * Régression : CssInliner::inline() ne doit plus laisser fuiter le PI XML
 * `<?xml encoding="utf-8" ?>` (astuce injectée pour forcer DOMDocument à
 * respecter l'UTF-8) dans le HTML final.
 *
 * Bug réel corrigé le 08/08/2026 (round 137) : le nettoyage utilisait une
 * regex ancrée '^', mais DOMDocument::saveHTML() insère systématiquement
 * un <!DOCTYPE ...> AVANT le PI dans le résultat — l'ancre ne matchait
 * donc jamais et la chaîne littérale restait visible en tête de TOUS les
 * emails Neria envoyés, sans exception (dégradation de marque pour un
 * module "Luxury").
 *
 * Test comportemental réel : appelle CssInliner::inline() sur un fragment
 * HTML réaliste et vérifie que le PI XML n'apparaît nulle part dans le
 * résultat.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CssInliner.php';

    $html = '<html><head><style>.neria-btn{color:red}</style></head>'
          . '<body><a class="neria-btn" href="#">Cliquez</a></body></html>';

    $result = CssInliner::inline($html);

    neria_assert(
        strpos($result, '<?xml') === false,
        "CssInliner::inline() laisse de nouveau fuiter le PI XML '<?xml encoding=\"utf-8\" ?>' dans le HTML final — régression du bug corrigé le 08/08/2026 (round 137) : cet artefact technique redeviendrait visible en tête de tous les emails envoyés"
    );

    return [
        'pass'    => true,
        'message' => "CssInliner::inline() ne laisse plus fuiter le PI XML d'astuce UTF-8 dans le HTML final envoyé au client mail",
    ];
}
