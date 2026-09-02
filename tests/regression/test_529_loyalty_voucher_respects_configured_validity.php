<?php
/**
 * Régression : `LoyaltyManager::generateVoucher()` calculait la date
 * d'expiration du bon de récompense de palier fidélité avec
 * `strtotime('+1 year')` codé en dur, ignorant totalement le réglage
 * marchand `NERIA_VOUCHER_VALIDITY` (BO : "Durée de validité des bons",
 * `configure.tpl` clé `configure.voucher_desc`, lu via
 * `ConfigManager::getVoucherValidity()`, défaut 30 jours, plage 1-365).
 * Les 2 AUTRES générateurs de bons du module (`BehavioralCronManager`
 * ligne 399, bon anniversaire ; `OrderTriggersManager` ligne 240, bon
 * palier de commande) lisaient déjà correctement ce réglage — seul le
 * bon de récompense fidélité (palier de points) l'ignorait.
 *
 * Un marchand réglant la durée de validité à 7 jours (politique de bon
 * flash) voyait "Enregistré" en BO ; les bons anniversaire et palier de
 * commande expiraient bien à J+7, mais le bon de récompense fidélité
 * continuait silencieusement à expirer à +1 an, sans aucune indication
 * en BO que ce type de bon suit une règle différente.
 *
 * Bug identifié le 02/09/2026 (round 277, audit "cohérence limites
 * configurables BO vs bornes codées en dur").
 *
 * Corrigé le 02/09/2026 (round 277) : `LoyaltyManager::generateVoucher()`
 * utilise désormais `(new \ConfigManager($this->module))->getVoucherValidity()`
 * exactement comme les 2 autres générateurs, au lieu de `'+1 year'` fixe.
 *
 * Test structurel : `generateVoucher()` est `private` et déclenchée par
 * une logique de paliers de points nécessitant un état client/commande
 * complexe hors de portée raisonnable d'une reproduction complète en
 * CLI ; vérifie la présence de l'appel à `getVoucherValidity()` dans le
 * bloc de construction du `CartRule` et l'absence de la valeur codée en
 * dur '+1 year'.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LoyaltyManager.php');

    $posFn = strpos($src, 'private function generateVoucher(int $idCustomer, array $tier, int $reservationShopId, int $pointsAtReward): string');
    neria_assert($posFn !== false, 'generateVoucher() introuvable — jeu de test invalide');

    $posDateTo = strpos($src, '$cartRule->date_to', $posFn);
    neria_assert($posDateTo !== false && $posDateTo - $posFn < 4000, "\$cartRule->date_to introuvable dans generateVoucher() — jeu de test invalide");

    $body = substr($src, $posDateTo, 200);

    neria_assert(
        strpos($body, 'getVoucherValidity()') !== false,
        "LoyaltyManager::generateVoucher() n'appelle plus getVoucherValidity() pour la date d'expiration du bon — régression du bug corrigé le 02/09/2026 (round 277) : le bon de récompense fidélité ignorerait de nouveau le réglage marchand NERIA_VOUCHER_VALIDITY, contrairement aux bons anniversaire et palier de commande"
    );
    neria_assert(
        strpos($body, "strtotime('+1 year')") === false,
        "LoyaltyManager::generateVoucher() contient de nouveau la durée codée en dur '+1 year' — régression du bug corrigé le 02/09/2026 (round 277)"
    );

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::generateVoucher() respecte désormais le réglage marchand NERIA_VOUCHER_VALIDITY (getVoucherValidity()) au lieu d'une durée codée en dur de +1 an — bug corrigé le 02/09/2026 (round 277)",
    ];
}
