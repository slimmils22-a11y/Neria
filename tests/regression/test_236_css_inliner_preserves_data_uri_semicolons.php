<?php
/**
 * Régression : CssInliner::merge() ne doit pas corrompre une valeur CSS
 * contenant un ';' littéral (ex. data URI base64, très courant pour les
 * logos/signatures embarqués — cf. SignatureGenerator.php).
 *
 * Bug réel corrigé le 09/08/2026 (round 151) : merge() découpait les
 * déclarations CSS avec un simple explode(';', ...), sans tenir compte des
 * ';' internes à une valeur — ex. `url(data:image/png;base64,...)`.
 * Résultat : la déclaration `background` inlinée était tronquée/invalide,
 * et le fragment restant (sans ':') était silencieusement jeté. L'image
 * disparaissait dans les clients qui ignorent <style> (Gmail, Outlook —
 * la cible même de CssInliner).
 *
 * Test comportemental réel : appelle CssInliner::inline() (seul point
 * d'entrée public) sur un HTML avec un style inline data-URI existant ET
 * une règle <style> qui déclenche merge() sur le même élément, vérifie que
 * la data URI ressort intacte dans le style inliné final.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CssInliner.php';

    $dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    $html = '<html><head><style>.btn { color: #ffffff; }</style></head>'
        . '<body><a class="btn" style="background: url(' . $dataUri . ') no-repeat;">Lien</a></body></html>';

    $result = CssInliner::inline($html);

    neria_assert(
        strpos($result, $dataUri) !== false,
        "la data URI n'apparait plus intacte dans le HTML inline — regression du bug corrige le 09/08/2026 (round 151) : merge() coupe de nouveau au milieu du ';' de la data URI"
    );
    neria_assert(
        strpos($result, 'color: #ffffff') !== false || strpos($result, 'color:#ffffff') !== false,
        "la regle CSS du bloc <style> (color) n'a pas ete fusionnee avec le style inline existant — merge() semble ne plus fonctionner du tout"
    );

    return [
        'pass'    => true,
        'message' => "CssInliner::merge() preserve bien une data URI (';' interne) lors de la fusion avec une regle CSS",
    ];
}
