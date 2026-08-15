<?php
/**
 * Régression : CssInliner::merge() doit respecter la cascade CSS standard
 * "la dernière déclaration gagne" quand une même propriété apparaît deux
 * fois au sein de la même règle (ou du même style inline d'origine) — pas
 * seulement l'ordre entre règles de spécificités différentes (déjà couvert
 * par test_100/test_164).
 *
 * Bug réel corrigé le 15/08/2026 (round 173) : pour une règle contenant un
 * fallback CSS classique comme `.neria-btn { color:#999; color:red; }`,
 * merge() conservait la PREMIÈRE déclaration rencontrée (#999) au lieu de
 * la dernière (red) — car la boucle testait `!array_key_exists($k, $result)`
 * et ignorait toute déclaration ultérieure non-!important pour une clé déjà
 * vue. Apple Mail (garde <style>, respecte l'ordre CSS standard) affichait
 * donc "red" tandis que Gmail/Outlook (ne voient que le style inliné produit
 * par merge()) affichaient "#999" — rendu visuel divergent et silencieux
 * entre les principaux clients email ciblés par le module.
 *
 * Test comportemental réel : une règle avec la même propriété déclarée deux
 * fois (fallback), la seconde valeur doit se retrouver dans le style
 * inliné final.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CssInliner.php';

    $html = '<html><head><style>'
          . '.neria-btn{color:#999999;color:red}'
          . '</style></head>'
          . '<body><a class="neria-btn" href="#">Bouton</a></body></html>';

    $result = CssInliner::inline($html);

    neria_assert(
        preg_match('/class="neria-btn"[^>]*style="[^"]*color:\s*red/i', $result) === 1,
        "CssInliner::inline() ne retient plus la dernière déclaration d'une propriété dupliquée dans une même règle — régression du bug corrigé le 15/08/2026 (round 173) : la cascade CSS standard (la dernière valeur gagne, ici 'red') serait de nouveau violée au profit de la première ('#999999'), rendu divergent entre Apple Mail et Gmail/Outlook. HTML obtenu : {$result}"
    );

    neria_assert(
        preg_match('/class="neria-btn"[^>]*style="[^"]*color:\s*#999999/i', $result) !== 1,
        "CssInliner::inline() a inliné l'ancienne valeur #999999 pour color en plus de (ou au lieu de) red — la déclaration dupliquée n'a pas été correctement résolue par la cascade"
    );

    return [
        'pass'    => true,
        'message' => "CssInliner::merge() applique bien la cascade 'dernière déclaration gagne' pour une propriété dupliquée au sein d'une même règle — bug corrigé le 15/08/2026 (round 173)",
    ];
}
