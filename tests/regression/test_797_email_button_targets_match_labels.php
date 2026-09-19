<?php
/**
 * Bloc 5 (19/09/2026), relecture des emails reçus sur ps-test puis balayage
 * des 117 templates : 8 boutons avaient une cible sans rapport avec leur
 * libellé — « View terms » (extended_warranty) → historique de commandes ;
 * « Contact my advisor » (concierge_followup, personal_shopper_intro) et
 * « Choose my packaging » → accueil ; « Discover my selection »
 * (milestone_order), « Discover my new privileges » (loyalty_tier_upgrade),
 * « Use my reward » (loyalty_reward_expiry), « Discover my attention »
 * (first_anniversary) → historique de commandes (connexion requise).
 *
 * Corrigé : {terms_url} (nouvelle variable, page CMS des conditions, repli
 * accueil), {contact_page_url} (page contact) et {shop_url} (accueil).
 *
 * Test : (1) table cible attendue sur les sources HTML ET TXT des 8
 * templates ; (2) compilation RÉELLE de extended_warranty sans fournir
 * {terms_url} : le lien produit est résolu, n'est ni le placeholder, ni
 * l'historique de commandes, ni '#'.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    $dir = _PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/';

    $map = [
        'extended_warranty'      => 'terms_url',
        'concierge_followup'     => 'contact_page_url',
        'personal_shopper_intro' => 'contact_page_url',
        'packaging_choice'       => 'contact_page_url',
        'milestone_order'        => 'shop_url',
        'loyalty_tier_upgrade'   => 'shop_url',
        'loyalty_reward_expiry'  => 'shop_url',
        'first_anniversary'      => 'shop_url',
    ];
    foreach ($map as $tpl => $var) {
        $html = (string) file_get_contents($dir . $tpl . '.html');
        $txt  = (string) file_get_contents($dir . $tpl . '.txt');
        neria_assert(
            preg_match('/<a href="\{' . $var . '\}"[^>]*class="neria-btn"/', $html) === 1,
            "{$tpl}.html : le bouton ne pointe plus vers {" . $var . "}"
        );
        neria_assert(
            preg_match('/neria_trad key=\'[a-z0-9_]+\'\}\s*:?\s*\{' . $var . '\}/', $txt) === 1,
            "{$tpl}.txt : la ligne du bouton ne pointe plus vers {" . $var . "}"
        );
    }

    $module   = neria_test_module();
    $renderer = new EmailRenderer($module);
    $method   = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
    $method->setAccessible(true);
    $outName = 'regtest797_terms';
    $files   = [_PS_MODULE_DIR_ . 'neria/mails/en/' . $outName . '.html', _PS_MODULE_DIR_ . 'neria/mails/en/' . $outName . '.txt'];
    try {
        $vars = ['{firstname}' => 'Slim', '{time_greeting}' => 'Hello', '{product_name}' => 'Coussin', '{order_name}' => 'ABC123'];
        $res  = $method->invoke($renderer, 'extended_warranty', 'en', 'en', $vars, false, false, $outName);
        neria_assert($res !== null && is_file($files[0]), "compileNeriaTemplate(extended_warranty) a échoué");
        $h = (string) file_get_contents($files[0]);
        neria_assert(preg_match('/<a href="([^"]+)"[^>]*class="neria-btn"/', $h, $m) === 1, "bouton introuvable");
        $href = html_entity_decode($m[1]);
        $ctx  = \Context::getContext();
        $history = $ctx->link->getPageLink('history', true, (int) $ctx->language->id);
        neria_assert(strpos($href, '{terms_url}') === false, "{terms_url} non résolu (lien mort)");
        neria_assert($href !== '#' && $href !== '', "lien du bouton vide ou '#'");
        neria_assert($href !== $history, "le bouton « conditions » pointe de nouveau vers l'historique de commandes");
    } finally {
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    return ['pass' => true, 'message' => "les 8 boutons corrigés pointent vers la page correspondant à leur libellé ({terms_url} résolu à l'envoi) — bloc 5 (19/09/2026)"];
}
