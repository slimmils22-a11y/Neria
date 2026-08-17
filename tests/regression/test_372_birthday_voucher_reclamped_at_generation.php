<?php
/**
 * Régression : le plafond de sécurité NERIA_VOUCHER_FIXED_CAP n'était
 * appliqué qu'au moment où le marchand SAISISSAIT le montant d'un bon
 * (anniversaire/palier/fidélité) dans le formulaire BO — jamais relu ni
 * re-appliqué au moment de la génération réelle du bon. Un marchand ayant
 * enregistré un montant élevé puis abaissé le plafond après coup voyait
 * le montant déjà en base continuer d'être utilisé tel quel lors de la
 * génération réelle du CartRule, sans qu'aucune alerte ne le signale.
 *
 * Corrigé le 17/08/2026 (round 181) : BehavioralCronManager::
 * generateBirthdayVoucher() (et OrderTriggersManager::
 * generateMilestoneVoucher(), LoyaltyManager::generateVoucher(), même
 * correctif) re-clampent désormais le montant au plafond courant au
 * moment de la génération.
 *
 * Test réel : configure un montant de bon anniversaire supérieur au
 * plafond de sécurité, appelle generateBirthdayVoucher() (privée, via
 * réflexion) pour un client de test, vérifie que le CartRule réellement
 * créé en base a un reduction_amount plafonné, pas le montant brut.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';

    $module     = neria_test_module();
    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $testCustomerId = 971000 + ($idCustomer % 1000);
    $year = (int) date('Y');

    $amountKey  = ConfigManager::KEY_BIRTHDAY_VOUCHER_AMOUNT;
    $percentKey = ConfigManager::KEY_BIRTHDAY_VOUCHER_PERCENT;
    $capKey     = ConfigManager::KEY_VOUCHER_FIXED_CAP;

    $originalAmount  = Configuration::get($amountKey);
    $originalPercent = Configuration::get($percentKey);
    $originalCap     = Configuration::get($capKey);

    $createdCartRuleId = null;

    try {
        $db->execute("DELETE FROM {$prefix}neria_birthday_voucher WHERE id_customer = {$testCustomerId} AND year = {$year}");

        // Montant très supérieur au plafond, mode fixe (pas pourcentage).
        Configuration::updateValue($amountKey, 500, false, null, $idShop);
        Configuration::updateValue($percentKey, 0, false, null, $idShop);
        Configuration::updateValue($capKey, 50, false, null, $idShop);

        $config = new ConfigManager($module);
        neria_assert(
            (float) $config->getBirthdayVoucherAmount() === 500.0 && (float) $config->getVoucherFixedCap() === 50.0,
            "Configuration de test non prise en compte — jeu de test invalide"
        );

        $cron = new BehavioralCronManager($module);
        $ref  = new ReflectionMethod(BehavioralCronManager::class, 'generateBirthdayVoucher');
        $ref->setAccessible(true);
        $code = $ref->invoke($cron, $testCustomerId, $config, $idShop);

        neria_assert($code !== '', "generateBirthdayVoucher() n'a retourné aucun code — jeu de test invalide");

        $idCartRule = (int) $db->getValue(
            "SELECT id_cart_rule FROM {$prefix}neria_birthday_voucher WHERE id_customer = {$testCustomerId} AND year = {$year}"
        );
        neria_assert($idCartRule > 0, "Aucun id_cart_rule enregistré — jeu de test invalide");
        $createdCartRuleId = $idCartRule;

        $reductionAmount = (float) $db->getValue(
            "SELECT reduction_amount FROM {$prefix}cart_rule WHERE id_cart_rule = {$idCartRule}"
        );

        neria_assert(
            $reductionAmount <= 50.0,
            "Le CartRule généré a un reduction_amount de {$reductionAmount} au lieu d'être plafonné à 50 — régression du bug corrigé le 17/08/2026 (round 181) : le plafond NERIA_VOUCHER_FIXED_CAP n'est plus re-appliqué au moment de la génération réelle du bon"
        );
    } finally {
        if ($createdCartRuleId !== null) {
            $cartRule = new CartRule((int) $createdCartRuleId);
            if (Validate::isLoadedObject($cartRule)) {
                $cartRule->delete();
            }
        }
        $db->execute("DELETE FROM {$prefix}neria_birthday_voucher WHERE id_customer = {$testCustomerId} AND year = {$year}");

        if ($originalAmount !== false) {
            Configuration::updateValue($amountKey, $originalAmount, false, null, $idShop);
        }
        if ($originalPercent !== false) {
            Configuration::updateValue($percentKey, $originalPercent, false, null, $idShop);
        }
        if ($originalCap !== false) {
            Configuration::updateValue($capKey, $originalCap, false, null, $idShop);
        }
    }

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::generateBirthdayVoucher() re-clampe bien le montant au plafond courant à la génération — bug corrigé le 17/08/2026 (round 181)",
    ];
}
