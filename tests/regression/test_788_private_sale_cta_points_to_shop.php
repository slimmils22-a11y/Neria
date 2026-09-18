<?php
/**
 * Bloc 4 (18/09/2026) : le bouton « Accéder à la vente privée » du template
 * private_sale pointait vers {history_url} (historique de commandes) —
 * repéré en lisant l'email réellement reçu d'une campagne saisonnière sur
 * ps-test. Corrigé : {shop_url}, comme les autres templates de campagne
 * (vip, early_access, exclusive_preview, win_back...).
 *
 * Test : les sources HTML et TXT du template n'utilisent plus {history_url}
 * et le bouton (.neria-btn) pointe vers {shop_url}.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $dir = _PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/';
    foreach (['private_sale.html', 'private_sale.txt'] as $f) {
        $src = (string) file_get_contents($dir . $f);
        neria_assert($src !== '', "{$f} introuvable");
        neria_assert(strpos($src, '{history_url}') === false, "{$f} contient de nouveau {history_url}");
        neria_assert(strpos($src, '{shop_url}') !== false, "{$f} ne contient plus {shop_url}");
    }
    $html = (string) file_get_contents($dir . 'private_sale.html');
    neria_assert(
        preg_match('/<a href="\{shop_url\}" class="neria-btn">/', $html) === 1,
        "le bouton (.neria-btn) de private_sale ne pointe plus vers {shop_url}"
    );

    return ['pass' => true, 'message' => "le bouton de private_sale pointe vers la boutique ({shop_url}), plus vers l'historique de commandes — bloc 4 (18/09/2026)"];
}
