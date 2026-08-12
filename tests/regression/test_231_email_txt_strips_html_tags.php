<?php
/**
 * Régression : la version .txt d'un email compilé ne doit jamais contenir
 * de balises HTML brutes issues d'une traduction (ex. {history_info},
 * {guest_tracking_info}, {tracking_info} — clés contenant un lien HTML
 * complet <a href="...">...</a> pensé pour le rendu HTML).
 *
 * Bug réel corrigé le 09/08/2026 (round 150) : le pipeline HTML applique
 * un nettoyage (sanitizeTranslationHtml()) avant d'injecter une traduction
 * résolue via {neria_trad key='...'}, mais le pipeline TXT injectait la
 * traduction BRUTE sans aucun nettoyage. Un client recevant la version
 * texte brute d'un email transactionnel fréquent (bankwire, order_conf,
 * payment, refund, shipped...) voyait littéralement
 * '<a href="...">View your order history</a>' au lieu d'un texte lisible
 * — sur 15 templates au total.
 *
 * Test comportemental réel : invoque EmailRenderer::compileNeriaTemplate()
 * (via Reflection, vrai pipeline d'envoi) pour 'bankwire', lit le fichier
 * .txt réellement écrit sur disque, vérifie qu'il ne contient plus de
 * balise HTML brute et que le texte du lien reste lisible.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module = neria_test_module();
    $renderer = new EmailRenderer($module);

    $method = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
    $method->setAccessible(true);
    $outFile = $method->invoke($renderer, 'bankwire', 'fr', 'fr', [
        '{firstname}'   => 'Test',
        '{order_name}'  => 'NR-TEST999',
        '{total_paid}'  => '100,00 €',
    ], true, true);

    neria_assert($outFile !== null, "compileNeriaTemplate('bankwire') n'a pas produit de fichier — jeu de test invalide");

    $txtFile = preg_replace('/\.html$/', '.txt', $outFile);
    neria_assert(file_exists($txtFile), "le fichier .txt correspondant n'a pas ete genere : {$txtFile}");

    $txt = file_get_contents($txtFile);

    neria_assert(
        strpos($txt, '<a href') === false && strpos($txt, '</a>') === false,
        "la version .txt de bankwire contient encore une balise HTML brute (<a href.../</a>) — regression du bug corrige le 09/08/2026 (round 150) : le pipeline texte n'echapperait/nettoierait de nouveau plus les traductions HTML"
    );
    neria_assert(
        stripos($txt, 'history') !== false || stripos($txt, 'historique') !== false,
        "le texte du lien 'history_info' a disparu du .txt (pas seulement les balises) — le nettoyage semble trop agressif"
    );

    return [
        'pass'    => true,
        'message' => "La version .txt des emails ne contient plus de balises HTML brutes issues des traductions (history_info/guest_tracking_info/tracking_info)",
    ];
}
