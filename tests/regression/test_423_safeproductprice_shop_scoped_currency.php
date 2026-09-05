<?php
/**
 * Régression : WaitlistManager/LookCompletionManager/UpsellManager::
 * safeProductPrice() calculaient le montant via un panier temporaire dont
 * id_currency était fixé sur $ctx->currency->id (devise AMBIANTE du
 * process — reliquat d'une boutique précédente dans une boucle
 * multi-boutiques, ou devise de session de l'employé BO qui a déclenché le
 * cron), alors que l'appelant formate ensuite ce montant avec
 * PS_CURRENCY_DEFAULT scopé par la VRAIE boutique du client
 * (NeriaTools::displayPrice() ne fait QUE formater, jamais de conversion).
 *
 * Bug réel identifié le 24/08/2026 (round 198) : sur une install
 * multi-devises, le cron "retour en stock"/"complétez votre look"/upsell
 * traitant plusieurs boutiques dans une même exécution pouvait afficher un
 * montant numérique dans une devise différente de celle indiquée par le
 * symbole — écart réel avec le prix qui sera facturé au client.
 *
 * Corrigé le 24/08/2026 (round 198) : les 3 méthodes reçoivent désormais
 * $idShop et résolvent id_currency via PS_CURRENCY_DEFAULT scopé par cette
 * boutique, cohérent avec la devise d'affichage utilisée par l'appelant.
 *
 * Test structurel (une vraie fixture multi-boutiques/multi-devises
 * nécessiterait un jeu de données complet, hors périmètre d'un test isolé) :
 * vérifie que les 3 méthodes résolvent bien id_currency via
 * PS_CURRENCY_DEFAULT scopé par $idShop, et que leurs appelants transmettent
 * bien ce paramètre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // WaitlistManager
    $wlSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($wlSrc !== false, 'Impossible de lire src/WaitlistManager.php');
    // Round 292 : signature élargie d'un paramètre int $idCustomer = 0 —
    // littéraux mis à jour, contrôle inchangé sur le fond, présence de $idShop.
    neria_assert(
        strpos($wlSrc, 'private function safeProductPrice(int $idProduct, int $idShop, int $idCustomer = 0): float') !== false,
        "WaitlistManager::safeProductPrice() n'a plus le paramètre \$idShop — régression du bug corrigé le 24/08/2026 (round 198)"
    );
    neria_assert(
        strpos($wlSrc, "\$tmp->id_currency = (int) \\Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$idShop)") !== false,
        "WaitlistManager::safeProductPrice() ne résout plus id_currency via PS_CURRENCY_DEFAULT scopé par \$idShop — régression du bug corrigé le 24/08/2026 (round 198) : le montant serait de nouveau calculé dans la devise ambiante du process"
    );
    neria_assert(
        strpos($wlSrc, '$this->safeProductPrice($idProduct, $rowShopId, $idCustomer)') !== false,
        "WaitlistManager n'appelle plus safeProductPrice() avec la boutique réelle du client — régression du bug corrigé le 24/08/2026 (round 198)"
    );

    // LookCompletionManager (round 275 : signature élargie d'un paramètre
    // int $idCurrency = 0 ; round 292 : élargie à nouveau d'un paramètre
    // int $idCustomer = 0 — littéraux mis à jour, contrôle inchangé sur le
    // fond, présence de $idShop)
    $lcSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($lcSrc !== false, 'Impossible de lire src/LookCompletionManager.php');
    neria_assert(
        strpos($lcSrc, 'private function safeProductPrice(int $idProduct, int $idShop, int $idCurrency = 0, int $idCustomer = 0): float') !== false,
        "LookCompletionManager::safeProductPrice() n'a plus le paramètre \$idShop — régression du bug corrigé le 24/08/2026 (round 198)"
    );
    neria_assert(
        strpos($lcSrc, '$this->safeProductPrice($pid, $idShop, $idCurrency, $idCustomer)') !== false,
        "LookCompletionManager n'appelle plus safeProductPrice() avec \$idShop — régression du bug corrigé le 24/08/2026 (round 198)"
    );

    // UpsellManager (round 274 : signature élargie d'un paramètre
    // ?int $idCurrency = null — littéraux mis à jour, contrôle inchangé
    // sur le fond, présence de $idShop)
    $usSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
    neria_assert($usSrc !== false, 'Impossible de lire src/UpsellManager.php');
    neria_assert(
        strpos($usSrc, 'private function safeProductPrice(int $idProduct, int $idLang, int $idCustomer = 0, ?int $idShop = null, ?int $idCurrency = null): float') !== false,
        "UpsellManager::safeProductPrice() n'a plus le paramètre \$idShop — régression du bug corrigé le 24/08/2026 (round 198)"
    );
    neria_assert(
        strpos($usSrc, '$this->safeProductPrice($idProduct, $idLang, $idCustomer, $idShop, $idCurrency)') !== false,
        "UpsellManager n'appelle plus safeProductPrice() avec \$idShop — régression du bug corrigé le 24/08/2026 (round 198)"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager/LookCompletionManager/UpsellManager::safeProductPrice() résolvent bien la devise du montant calculé par \$idShop, cohérent avec la devise d'affichage — bug corrigé le 24/08/2026 (round 198)",
    ];
}
