<?php
/**
 * Régression : CssInliner::inline() doit respecter !important dans la
 * cascade — une déclaration !important doit gagner face à une déclaration
 * non-important, même moins spécifique ou traitée après.
 *
 * Bug réel corrigé le 08/08/2026 (round 137) : !important n'était ni
 * détecté ni priorisé — traité comme un simple fragment de la valeur. Une
 * règle !important (ex. un thème forçant une couleur pour contourner un
 * style de base) perdait silencieusement face à une règle non-important
 * mais plus spécifique, cassant la cascade CSS standard et créant un rendu
 * divergent entre Apple Mail (garde <style>, respecte !important) et
 * Gmail/Outlook (ne voient que l'inline, ne le respectaient plus).
 *
 * Test comportemental réel : une règle moins spécifique mais !important
 * doit l'emporter sur une règle plus spécifique non-important.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CssInliner.php';

    // a{color} (élément, spécificité 0, mais !important) vs
    // .neria-link{color} (classe, spécificité 1, sans !important) — malgré
    // sa spécificité plus faible, la règle !important doit gagner.
    $html = '<html><head><style>'
          . 'a{color:blue !important}'
          . '.neria-link{color:red}'
          . '</style></head>'
          . '<body><a class="neria-link" href="#">Lien</a></body></html>';

    $result = CssInliner::inline($html);

    neria_assert(
        preg_match('/class="neria-link"[^>]*style="[^"]*color:\s*blue/i', $result) === 1,
        "CssInliner::inline() ne respecte plus !important — régression du bug corrigé le 08/08/2026 (round 137) : la règle !important (color:blue) devrait l'emporter sur la règle non-important plus spécifique (color:red), cascade CSS standard violée. HTML obtenu : {$result}"
    );

    return [
        'pass'    => true,
        'message' => "CssInliner::inline() respecte bien !important dans la cascade — une déclaration !important l'emporte sur une déclaration non-important même moins spécifique",
    ];
}
