<?php
/**
 * Régression : LoyaltyManager::checkAndReward() formatait le montant
 * communiqué au client dans l'email de récompense avec
 * $this->context->currency (devise de NAVIGATION du visiteur au moment du
 * déclenchement), alors que le CartRule réellement généré juste après
 * (generateVoucher()) utilise PS_CURRENCY_DEFAULT scopé par
 * $reservationShopId — deux sources de devise totalement indépendantes.
 *
 * Bug réel identifié le 23/08/2026 (round 197) : sur une install
 * multi-devises, un client naviguant en USD alors que la boutique par
 * défaut est en EUR recevait un email annonçant "$10" alors que le bon
 * réellement généré accordait 10€ — montant/devise trompeurs, le client
 * ne récupère jamais réellement les "$10" annoncés.
 *
 * Corrigé le 23/08/2026 (round 197) : le montant affiché utilise désormais
 * la MÊME résolution de devise que le CartRule (PS_CURRENCY_DEFAULT scopé
 * par $reservationShopId), pas $this->context->currency.
 *
 * Test structurel (une vraie fixture points/paliers/multi-devises
 * nécessiterait un jeu de données complet, hors périmètre d'un test isolé
 * — voir test_330 pour la même contrainte sur ce fichier) : vérifie que le
 * calcul du montant affiché utilise bien la même résolution PS_CURRENCY_DEFAULT
 * que generateVoucher(), pas $this->context->currency.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LoyaltyManager.php');

    $posDisplay = strpos($src, 'NeriaTools::displayPrice((float) $tier[\'amount\'], $currencyVoucher, $idLangCustomer)');
    neria_assert(
        $posDisplay !== false,
        "LoyaltyManager::checkAndReward() n'utilise plus \$currencyVoucher (résolu comme generateVoucher()) pour formater le montant affiché — régression du bug corrigé le 23/08/2026 (round 197) : le montant communiqué au client redeviendrait formaté dans \$this->context->currency (devise de navigation du visiteur), potentiellement différente de la devise réelle du CartRule généré"
    );

    // Vérifie que la résolution de $currencyVoucher scope bien par
    // $reservationShopId, symétrique à generateVoucher().
    $posResolve = strpos($src, '$idCurrencyVoucher = $reservationShopId > 0');
    neria_assert(
        $posResolve !== false && $posResolve < $posDisplay,
        "LoyaltyManager::checkAndReward() ne résout plus \$idCurrencyVoucher via PS_CURRENCY_DEFAULT scopé par \$reservationShopId — régression du bug corrigé le 23/08/2026 (round 197)"
    );

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::checkAndReward() affiche bien le montant dans la MÊME devise que le CartRule réellement généré — bug corrigé le 23/08/2026 (round 197)",
    ];
}
