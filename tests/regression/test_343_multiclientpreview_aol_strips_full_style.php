<?php
/**
 * Régression : MultiClientPreviewManager::transformAol() n'appelait que
 * stripMediaQueries() (identique à Yahoo), alors que
 * CLIENTS['aol']['support'] annonce explicitement "media queries et styles
 * supprimés" — les blocs <style> restants et tout le CSS interne étaient
 * conservés intégralement dans l'aperçu.
 *
 * Bug réel corrigé le 15/08/2026 (round 175) : un marchand sélectionnant
 * l'aperçu "AOL Mail" pour vérifier qu'une règle CSS problématique disparaît
 * bien (parce que le libellé le lui promet) voyait un rendu qui la
 * conservait, contrairement à ce qui était annoncé — faux sentiment de
 * sécurité avant envoi réel. transformAol() appelle désormais
 * stripStyleAndLinkTags() (retire les <style> en entier, media queries
 * comprises, et les <link rel="stylesheet">), comme transformNaver().
 *
 * Test comportemental réel : un HTML avec un <style> contenant une règle
 * non-media ET une media query doit ressortir de l'aperçu AOL sans aucune
 * balise <style>.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php';

    $html = '<html><head><style>.neria-btn{color:red;} @media (max-width:600px){.neria-btn{color:blue;}}</style></head>'
          . '<body><a class="neria-btn" href="#">Bouton</a></body></html>';

    $mgr    = new MultiClientPreviewManager();
    $result = $mgr->transformForClient($html, 'aol');

    neria_assert(
        stripos($result, '<style') === false,
        "MultiClientPreviewManager::transformAol() conserve encore un bloc <style> dans l'aperçu — régression du bug corrigé le 15/08/2026 (round 175) : le libellé AOL promet 'media queries et styles supprimés' mais seule stripMediaQueries() serait de nouveau appelée, laissant les styles non-media intacts. HTML obtenu : {$result}"
    );

    neria_assert(
        stripos($result, 'color:red') === false && stripos($result, 'color:blue') === false,
        "Une règle CSS de l'ancien bloc <style> a fuité dans l'aperçu AOL malgré la suppression du bloc — obtenu : {$result}"
    );

    return [
        'pass'    => true,
        'message' => "MultiClientPreviewManager::transformAol() supprime bien l'intégralité des <style> (media queries comprises), cohérent avec son libellé — bug corrigé le 15/08/2026 (round 175)",
    ];
}
