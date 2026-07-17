<?php
/** Régression : unsubscribe.php et preferences.php doivent filtrer id_shop lors de la résolution du client par email. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $unsubSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/unsubscribe.php');
    $prefSrc  = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/preferences.php');

    neria_assert(
        (bool) preg_match('/UPDATE.*customer.*id_shop/is', $unsubSrc) || str_contains($unsubSrc, 'id_shop'),
        "unsubscribe.php ne semble plus filtrer id_shop — régression du bug de fuite cross-boutique corrigé le 17/07/2026 (commit cc9474f)"
    );
    neria_assert(
        str_contains($prefSrc, 'id_shop'),
        "preferences.php ne semble plus filtrer id_shop lors de la résolution du client — régression du bug de fuite cross-boutique corrigé le 17/07/2026 (commit cc9474f)"
    );

    return ['pass' => true, 'message' => 'Contrôleurs désabonnement/préférences toujours scopés id_shop'];
}
