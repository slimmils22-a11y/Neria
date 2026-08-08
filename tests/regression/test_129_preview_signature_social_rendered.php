<?php
/**
 * Régression : EmailRenderer::buildCompiledHtml() (partagée par
 * renderPreviewHtml() — aperçu Design BO — et renderWithVars() — renvoi
 * depuis l'historique client) doit résoudre la signature manuscrite et les
 * réseaux sociaux avec la config réelle actuelle, et traiter le bloc
 * {if isset($var) && $var}...{/if} comme le fait compileNeriaTemplate()
 * (envoi réel).
 *
 * Bug réel corrigé le 08/08/2026 (round 125) : buildCompiledHtml() n'avait
 * ni les variables de contenu ({$neria_signature_url}/{$neria_social_links}),
 * ni le pass regex isset() présents dans compileNeriaTemplate() — le bloc
 * {if isset($neria_has_signature) && $neria_has_signature}...{/if} tombait
 * systématiquement dans le nettoyage générique et disparaissait, quelle que
 * soit la configuration marchand réelle. L'aperçu Design du BO ne
 * reflétait donc jamais la signature/les réseaux sociaux configurés, et un
 * email renvoyé depuis l'historique client les perdait par rapport à
 * l'email d'origine.
 *
 * Test fonctionnel réel : active une signature + un lien Instagram pour la
 * boutique de test, génère un aperçu (renderPreviewHtml) et vérifie que le
 * HTML produit contient bien l'URL de la signature et le lien Instagram —
 * pas un bloc vide.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $db->execute("DELETE FROM {$prefix}neria_signature WHERE id_shop = {$idShop}");
    $db->execute(
        "INSERT INTO {$prefix}neria_signature
            (id_shop, signer_name, signer_title, image_path, is_active, date_add, date_upd)
         VALUES
            ({$idShop}, 'Regtest Round125', 'Fondatrice', 'img/regtest-signature.png', 1, NOW(), NOW())"
    );

    $prevInstagram = (string) Configuration::get('NERIA_SOCIAL_INSTAGRAM');
    Configuration::updateValue('NERIA_SOCIAL_INSTAGRAM', 'https://instagram.com/regtest_round125');

    try {
        $renderer = new EmailRenderer(neria_test_module());
        $html = $renderer->renderPreviewHtml('order_conf', 'fr');

        neria_assert(
            strpos($html, 'regtest-signature.png') !== false,
            "L'aperçu ne contient pas l'URL de la signature manuscrite configurée — régression du bug corrigé le 08/08/2026 (round 125) : buildCompiledHtml() ne résout plus {\$neria_signature_url} avec la vraie config"
        );
        neria_assert(
            strpos($html, 'instagram.com/regtest_round125') !== false,
            "L'aperçu ne contient pas le lien Instagram configuré — régression du bug corrigé le 08/08/2026 (round 125) : buildCompiledHtml() ne résout plus {\$neria_social_links} avec la vraie config"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_signature WHERE id_shop = {$idShop}");
        Configuration::updateValue('NERIA_SOCIAL_INSTAGRAM', $prevInstagram);
    }

    return [
        'pass'    => true,
        'message' => "EmailRenderer::buildCompiledHtml() résout bien la signature manuscrite et les réseaux sociaux avec la config marchand réelle, dans l'aperçu Design BO",
    ];
}
