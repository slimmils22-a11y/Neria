<?php
/**
 * Régression : PreferencesManager::TEMPLATE_CAT doit couvrir vip,
 * private_invitation, voucher et voucher_new (envoi manuel via
 * ManualSendManager::WAVE1_TEMPLATES, éligibles A/B testing via
 * ABTestManager::getEligibleTemplates()) — sinon isAllowed() les traite
 * comme "non classés" et autorise TOUJOURS leur envoi, même à un client
 * ayant explicitement désactivé la catégorie correspondante.
 *
 * Bug réel corrigé le 06/08/2026 (round 72) : ces 4 templates n'avaient
 * aucune entrée dans TEMPLATE_CAT malgré leur usage réel (envoi manuel,
 * A/B testing) — violation silencieuse de l'opt-out client.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $templates  = ['vip', 'private_invitation', 'voucher', 'voucher_new'];

    // Le client désactive explicitement la catégorie 'behav'.
    $db->execute(
        "DELETE FROM {$prefix}neria_preferences
         WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND category = 'behav'"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_preferences (id_customer, id_shop, email, category, subscribed, date_upd)
         VALUES ({$idCustomer}, {$idShop}, '', 'behav', 0, NOW())"
    );

    try {
        $mgr = new PreferencesManager(neria_test_module());

        foreach ($templates as $tpl) {
            neria_assert(
                isset(PreferencesManager::TEMPLATE_CAT[$tpl]),
                "'{$tpl}' est de nouveau absent de TEMPLATE_CAT — régression du bug corrigé le 06/08/2026 (round 72)"
            );

            $allowed = $mgr->isAllowed($idCustomer, $tpl, $idShop);
            neria_assert(
                $allowed === false,
                "isAllowed() autorise encore l'envoi de '{$tpl}' à un client ayant désactivé la catégorie 'behav' — régression du bug corrigé le 06/08/2026 (round 72) : ce template redeviendrait 'non classé' et toujours envoyé, en violation de l'opt-out client"
            );
        }

        return [
            'pass'    => true,
            'message' => "PreferencesManager respecte bien l'opt-out 'behav' pour vip/private_invitation/voucher/voucher_new",
        ];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_preferences
             WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND category = 'behav'"
        );
    }
}
