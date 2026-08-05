<?php
/** Régression : sendGhostCarts() doit filtrer par id_shop dans le NOT EXISTS sur les commandes, sinon un achat validé sur une AUTRE boutique (compte client mutualisé multi-boutiques) masque silencieusement un panier abandonné réel sur la boutique courante. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');

    neria_assert(
        (bool) preg_match('/private function sendGhostCarts\(\).*?NOT EXISTS\s*\(\s*SELECT 1 FROM `\' \. \$this->prefix \. \'orders` o.*?o\.valid = 1\s*AND o\.id_shop = \' \. \$idShop \. \'/s', $src),
        "sendGhostCarts() ne filtre plus o.id_shop dans le NOT EXISTS sur les commandes — régression du bug corrigé le 05/08/2026 (round 55) : un achat validé sur une autre boutique masquerait un panier abandonné réel sur la boutique courante"
    );

    return ['pass' => true, 'message' => 'sendGhostCarts() reste scopé par id_shop dans son NOT EXISTS sur les commandes'];
}
