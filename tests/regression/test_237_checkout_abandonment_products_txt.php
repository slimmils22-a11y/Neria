<?php
/**
 * Régression : BehavioralCronManager::sendCheckoutAbandonment() doit
 * injecter {products_txt}, et checkout_abandonment.txt doit l'utiliser —
 * même correctif déjà appliqué à abandoned_cart_1/2/3 (même famille de
 * relance panier) le 2026-07-13, oublié sur ce template.
 *
 * Bug réel corrigé le 09/08/2026 (round 151) : {products_txt} n'était
 * jamais injecté dans sendCheckoutAbandonment(), et le template .txt ne le
 * référençait pas — la version texte brut de la relance panier abandonné
 * 1h n'affichait AUCUN article, contrairement à sa version HTML.
 *
 * Test structurel : vérifie que BehavioralCronManager.php injecte bien
 * {products_txt} via buildCartProductsTxt() dans sendCheckoutAbandonment(),
 * et que checkout_abandonment.txt référence bien {products_txt}.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    $posFn = strpos($src, 'function sendCheckoutAbandonment');
    neria_assert($posFn !== false, 'sendCheckoutAbandonment() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 3200);

    neria_assert(
        strpos($body, "'{products_txt}'") !== false && strpos($body, '$this->buildCartProductsTxt($idCart)') !== false,
        "sendCheckoutAbandonment() n'injecte plus {products_txt} — regression du bug corrige le 09/08/2026 (round 151) : la version texte de la relance panier abandonne 1h n'afficherait de nouveau aucun article"
    );

    $txt = file_get_contents(_PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/checkout_abandonment.txt');
    neria_assert($txt !== false, 'Impossible de lire checkout_abandonment.txt');
    neria_assert(
        strpos($txt, '{products_txt}') !== false,
        "checkout_abandonment.txt ne reference plus {products_txt} — regression du bug corrige le 09/08/2026 (round 151)"
    );

    return [
        'pass'    => true,
        'message' => "checkout_abandonment affiche bien la liste des articles du panier dans sa version texte, comme abandoned_cart_1/2/3",
    ];
}
