<?php
/**
 * Régression (P8b, 26/09/2026) : les phrases d'exemple des aperçus du back-office ({voucher_usage}, {apology_reason},
 * {customs_status}, {message}, {reply}...) étaient écrites en français quelle que soit la langue prévisualisée.
 *
 * Corrigé : data/preview_samples.json (28 clés × 19 langues) appliqué par EmailRenderer::localizePreviewSamples() ;
 * noms propres, adresses et produits d'exemple inchangés. Aucun effet sur les e-mails réels.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $langs = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    $samples = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/preview_samples.json'), true);
    neria_assert(is_array($samples) && count($samples) >= 28, 'preview_samples.json illisible ou incomplet');
    foreach ($samples as $key => $byLang) {
        foreach ($langs as $l) {
            neria_assert(!empty($byLang[$l]), "Exemple d'aperçu manquant : {$key}/{$l}");
        }
    }

    $renderer = new EmailRenderer($module);
    $rm = new ReflectionMethod($renderer, 'buildPreviewFakes');
    $rm->setAccessible(true);
    $fr = $rm->invoke($renderer, 'vip', 'fr');
    $en = $rm->invoke($renderer, 'vip', 'en');
    $ja = $rm->invoke($renderer, 'vip', 'ja');
    neria_assert($fr['{voucher_usage}'] === 'Saisissez ce code dans votre panier lors de votre prochaine commande.', 'fr : phrase d\'exemple modifiée');
    neria_assert($en['{voucher_usage}'] === 'Enter this code in your cart on your next order.', 'en : {voucher_usage} non traduit : ' . $en['{voucher_usage}']);
    neria_assert($ja['{apology_reason}'] !== $fr['{apology_reason}'] && $ja['{customs_status}'] !== $fr['{customs_status}'], 'ja : exemples encore en français');
    neria_assert(strpos($en['{messages}'], 'Customer') !== false && strpos($en['{messages}'], 'Client') === false, 'en : {messages} non traduit : ' . $en['{messages}']);
    neria_assert(strpos($en['{shipped_items}'], 'Parcel 1 / 2') !== false, 'en : {shipped_items} non traduit');

    return ['pass' => true, 'message' => "Les phrases d'exemple des aperçus du back-office suivent la langue prévisualisée (28 clés × 19 langues) — corrigé le 26/09/2026"];
}
