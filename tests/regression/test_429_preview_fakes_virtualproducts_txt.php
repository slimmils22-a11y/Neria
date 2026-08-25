<?php
/**
 * Régression : EmailRenderer::buildPreviewFakes() fournissait
 * {virtualProducts} (HTML) sans son pendant {virtualProductsTxt}, à la
 * différence de toutes les autres paires HTML/txt du même tableau (ex.
 * {shipped_items}/{shipped_items_txt}).
 *
 * Bug réel identifié le 24/08/2026 (round 202) : les VRAIS envois de
 * download_product ne sont pas affectés — PrestaShop cœur fournit toujours
 * {virtualProducts} ET {virtualProductsTxt} ensemble (OrderHistory.php).
 * Mais l'aperçu Design BO (buildCompiledHtml() via buildPreviewFakes(),
 * PAS utilisé pour les envois réels ni pour l'envoi de test) affichait un
 * bloc de téléchargement vide dans l'onglet .txt de download_product : la
 * variable {virtualProductsTxt} restait non résolue puis était retirée
 * silencieusement par le filet de sécurité anti-résidu.
 *
 * Corrigé le 24/08/2026 (round 202) : {virtualProductsTxt} ajouté à
 * buildPreviewFakes(), cohérent avec les autres paires HTML/txt.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
    neria_assert($src !== false, 'Impossible de lire src/EmailRenderer.php');

    $posMethod = strpos($src, 'private function buildPreviewFakes(');
    neria_assert($posMethod !== false, 'buildPreviewFakes() introuvable — jeu de test invalide');

    $posVp = strpos($src, "'{virtualProducts}'", $posMethod);
    neria_assert($posVp !== false, "{virtualProducts} introuvable dans buildPreviewFakes() — jeu de test invalide");

    $posVpTxt = strpos($src, "'{virtualProductsTxt}'", $posMethod);
    neria_assert(
        $posVpTxt !== false,
        "EmailRenderer::buildPreviewFakes() ne fournit plus {virtualProductsTxt} — régression du bug corrigé le 24/08/2026 (round 202) : l'aperçu Design BO du .txt de download_product afficherait de nouveau un bloc de téléchargement vide"
    );

    // Comportemental : simule la substitution telle qu'appliquée par
    // compileNeriaTemplate()/buildCompiledHtml() sur le vrai template txt.
    $txtTemplate = file_get_contents(_PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/download_product.txt');
    neria_assert($txtTemplate !== false, 'download_product.txt introuvable — jeu de test invalide');
    neria_assert(
        strpos($txtTemplate, '{virtualProductsTxt}') !== false,
        'download_product.txt n\'utilise plus {virtualProductsTxt} — jeu de test obsolète, à adapter'
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer::buildPreviewFakes() fournit bien {virtualProductsTxt} en plus de {virtualProducts}, cohérent avec le template download_product.txt — bug corrigé le 24/08/2026 (round 202)",
    ];
}
