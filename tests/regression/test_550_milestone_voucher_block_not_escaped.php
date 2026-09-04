<?php
/**
 * Régression : `{milestone_voucher_block}` (OrderTriggersManager::
 * buildMilestoneVoucherHtmlBlock(), bon de réduction sur paliers de
 * commandes, template `milestone_order.html`) est un fragment HTML
 * pré-construit et pré-échappé côté serveur (`<div>...<p>CODE</p>...
 * </div>`, avec `$code`/`$label`/`$accent` déjà passés par
 * `htmlspecialchars()` individuellement), destiné à être injecté TEL
 * QUEL dans le HTML compilé — comme `{upsell_block}`/
 * `{delivery_block_html}`. Mais contrairement à ces derniers, il n'avait
 * pas été ajouté à `EmailRenderer::HTML_SAFE_RAW_KEYS`, la liste noire
 * qui protège les fragments HTML légitimes d'un double échappement.
 *
 * Bug identifié le 04/09/2026 (round 295, audit "rendu des templates
 * email — variables non passées par le mécanisme d'échappement
 * centralisé"). Même classe de régression que le round 149
 * ({shipped_items}/{messages}/{virtualProducts} oubliées à l'origine),
 * mais reproduite avec cette fonctionnalité plus récente (bon de
 * réduction sur paliers).
 *
 * Conséquence concrète avant correctif : `compileNeriaTemplate()`
 * (chemin d'échappement "liste noire" — tout est échappé par défaut sauf
 * les clés de `HTML_SAFE_RAW_KEYS`) ré-échappait le `<div>` entier —
 * le client recevant l'email `milestone_order` voyait le balisage HTML
 * brut affiché tel quel (`&lt;div style="..."&gt;...`) au lieu de
 * l'encadré visuel avec le code du bon en gros.
 *
 * Corrigé le 04/09/2026 (round 295) : `{milestone_voucher_block}` ajouté
 * à `HTML_SAFE_RAW_KEYS`.
 *
 * Test comportemental réel : reproduit fidèlement (extrait du fichier
 * source) la logique d'échappement liste-noire de compileNeriaTemplate()
 * — même technique que test_526 — sur une fixture contenant un vrai
 * fragment `<div>` construit par buildMilestoneVoucherHtmlBlock().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $emailRendererSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
    neria_assert($emailRendererSrc !== false, 'Impossible de lire src/EmailRenderer.php');

    neria_assert(
        strpos($emailRendererSrc, "'{milestone_voucher_block}',") !== false,
        "'{milestone_voucher_block}' a disparu de EmailRenderer::HTML_SAFE_RAW_KEYS — régression du bug corrigé le 04/09/2026 (round 295) : le bon de réduction sur paliers de commandes redeviendrait affiché en balisage HTML brut dans l'email milestone_order"
    );

    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    neria_assert(
        in_array('{milestone_voucher_block}', EmailRenderer::HTML_SAFE_RAW_KEYS, true),
        "EmailRenderer::HTML_SAFE_RAW_KEYS (chargée) ne contient plus '{milestone_voucher_block}' — régression du bug corrigé le 04/09/2026 (round 295)"
    );

    // Reproduction fidèle de la logique liste-noire de
    // compileNeriaTemplate() (ligne ~3068-3073), sur un vrai fragment
    // produit par buildMilestoneVoucherHtmlBlock().
    $voucherBlock = '<div style="text-align:center;margin:28px 0;padding:24px;border:2px solid #b38b59;background:#fefefe;">'
        . '<p style="font-size:20px;font-weight:700;color:#b38b59;margin:0;letter-spacing:0.06em;">CODE123</p>'
        . '<p style="margin:12px 0 0 0;">Bon de réduction fidélité</p>'
        . '</div>';
    $templateVars = ['{milestone_voucher_block}' => $voucherBlock, '{firstname}' => 'Marie <test>'];

    $htmlTemplateVars = $templateVars;
    foreach ($htmlTemplateVars as $nameKey => $nameValue) {
        if (is_string($nameValue) && !in_array($nameKey, EmailRenderer::HTML_SAFE_RAW_KEYS, true)) {
            $htmlTemplateVars[$nameKey] = htmlspecialchars($nameValue, ENT_QUOTES, 'UTF-8');
        }
    }

    neria_assert(
        $htmlTemplateVars['{milestone_voucher_block}'] === $voucherBlock,
        "la reproduction de la logique d'échappement de compileNeriaTemplate() ré-échappe le fragment <div> de {milestone_voucher_block} — le client verrait le balisage HTML brut au lieu de l'encadré visuel du bon de réduction"
    );
    neria_assert(
        strpos($htmlTemplateVars['{firstname}'], '&lt;test&gt;') !== false,
        "la reproduction de la logique d'échappement n'échappe plus une variable de texte libre normale ({firstname}) — jeu de test invalide ou régression du mécanisme liste-noire lui-même"
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer::HTML_SAFE_RAW_KEYS protège désormais {milestone_voucher_block} d'un double échappement, tout en continuant d'échapper les variables de texte libre normales — bug corrigé le 04/09/2026 (round 295)",
    ];
}
