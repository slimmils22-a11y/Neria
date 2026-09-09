<?php
/**
 * Régression : EmailRenderer::buildCarbonHtml() mesurait la taille du HTML
 * (strlen($compiled)/1024) AVANT l'appel à CssInliner::inline(), exécuté
 * juste après aux deux points d'appel de buildCompiledHtml() (le placeholder
 * carbone doit être injecté avant l'inlining pour des raisons DOM, cf. leurs
 * commentaires). CssInliner convertit les règles <style> en attributs
 * style="" DUPLIQUÉS sur chaque élément concerné — le HTML final réellement
 * envoyé au client est presque toujours PLUS LOURD après inlining qu'avant,
 * sous-estimant systématiquement le CO2 affiché par rapport à l'email
 * réellement livré (contredisant l'intention documentée "Empreinte estimée
 * de CET email").
 *
 * Corrigé le 09/09/2026 (round 328) : la taille est désormais mesurée sur
 * une copie déjà passée par CssInliner::inline() (sans toucher au flux réel
 * de $compiled, qui doit rester non-inliné à ce stade pour la suite).
 *
 * Test comportemental réel : construit un HTML factice avec une règle CSS
 * partagée par 30 <td> (cas représentatif d'un template riche en styles),
 * vérifie que buildCarbonHtml() calcule bien le CO2 sur la taille APRÈS
 * inlining (plus grande), pas AVANT.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CssInliner.php';

    $module = neria_test_module();

    $carbonWasEnabled = Configuration::get('NERIA_CARBON_ENABLED');
    Configuration::updateValue('NERIA_CARBON_ENABLED', 1);

    try {
        // HTML factice : une règle CSS partagée par 30 <td>, cas typique où
        // l'inlining fait grossir sensiblement le document (chaque <td>
        // reçoit sa propre copie de la déclaration).
        $rows = str_repeat('<tr><td class="cell">Regtest659 contenu de cellule</td></tr>', 30);
        $fakeCompiled = '<style>.cell { color: #333333; font-family: Georgia, serif; padding: 12px 18px; border: 1px solid #e8d5b0; background: #f9f6f1; }</style>'
            . '<table>' . $rows . '</table><!-- NERIA_CARBON -->';

        $sizeBeforeInline = strlen($fakeCompiled) / 1024;
        $inlined = CssInliner::inline($fakeCompiled);
        $sizeAfterInline = strlen($inlined) / 1024;

        neria_assert(
            $sizeAfterInline > $sizeBeforeInline * 1.05,
            "jeu de test invalide : CssInliner::inline() ne fait pas grossir sensiblement ce HTML factice (avant=" . round($sizeBeforeInline, 2) . "Ko, après=" . round($sizeAfterInline, 2) . "Ko) — impossible de distinguer les deux calculs"
        );

        $renderer = new EmailRenderer($module);
        $ref = new ReflectionMethod(EmailRenderer::class, 'buildCarbonHtml');
        $ref->setAccessible(true);
        $html = $ref->invoke($renderer, $fakeCompiled, 'en');

        if (trim($html) === '') {
            return ['pass' => true, 'message' => 'Empreinte carbone non activable sur cette install de test (config manquante) — test ignoré (rien à vérifier)'];
        }

        preg_match('/~([\d.]+)g CO₂/u', $html, $m);
        neria_assert(!empty($m[1]), "Impossible d'extraire la valeur CO2 affichée ('" . strip_tags($html) . "')");
        $actualCo2 = (float) $m[1];

        $expectedCo2Buggy   = round($sizeBeforeInline * 0.02, 1);
        $expectedCo2Correct = round($sizeAfterInline * 0.02, 1);

        neria_assert(
            $actualCo2 !== $expectedCo2Buggy || $expectedCo2Buggy === $expectedCo2Correct,
            "buildCarbonHtml() calcule encore le CO2 sur la taille AVANT inlining ({$expectedCo2Buggy}g) — régression du bug corrigé le 09/09/2026 (round 328) : l'empreinte affichée sous-estimerait de nouveau systématiquement le poids réel de l'email livré au client"
        );
        neria_assert(
            abs($actualCo2 - $expectedCo2Correct) < 0.05,
            "buildCarbonHtml() renvoie {$actualCo2}g, attendu ~{$expectedCo2Correct}g (calculé sur la taille APRÈS inlining) — régression du bug corrigé le 09/09/2026 (round 328)"
        );
    } finally {
        Configuration::updateValue('NERIA_CARBON_ENABLED', $carbonWasEnabled);
    }

    return [
        'pass'    => true,
        'message' => "EmailRenderer::buildCarbonHtml() calcule bien le CO2 sur la taille du HTML APRÈS inlining CSS (poids réellement livré au client), pas avant — bug corrigé le 09/09/2026 (round 328)",
    ];
}
