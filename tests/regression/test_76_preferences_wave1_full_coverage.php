<?php
/**
 * Régression : les 21 templates de ManualSendManager::WAVE1_TEMPLATES /
 * ABTestManager::getEligibleTemplates() découverts au round 72b (au-delà des
 * 4 déjà corrigés au round 72 — voir test_75) doivent eux aussi avoir une
 * entrée dans PreferencesManager::TEMPLATE_CAT, sinon isAllowed() les traite
 * comme "non classés" et les envoie TOUJOURS, même à un client ayant
 * explicitement désactivé la catégorie correspondante.
 *
 * Bug réel corrigé le 06/08/2026 (round 72b, garde-fou étendu de
 * HealthCheckManager::checkTemplateCategoryMappingComplete() aux deux
 * catalogues dynamiques) : 21 templates du groupe "Artisanat / service",
 * "Logistique / incidents" et "VIP / marketing" de WAVE1_TEMPLATES, plus
 * corporate_order_confirm, n'avaient aucune entrée dans TEMPLATE_CAT malgré
 * leur usage réel (envoi manuel BO, A/B testing).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    // template => catégorie attendue, une par groupe de la nouvelle
    // catégorisation (pas besoin de tester les 21, un échantillon par
    // catégorie suffit à couvrir la régression réelle).
    $samples = [
        'artisan_message'         => 'post',
        'repair_completed'        => 'post',
        'white_glove_apology'     => 'post',
        'unboxing_guide'          => 'post',
        'personal_shopper_intro'  => 'behav',
        'concierge_followup'      => 'behav',
        'gift_guarantee'          => 'behav',
        'corporate_order_confirm' => 'b2b',
    ];

    $cats = array_unique(array_values($samples));
    foreach ($cats as $cat) {
        $db->execute(
            "DELETE FROM {$prefix}neria_preferences
             WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND category = '{$cat}'"
        );
        $db->execute(
            "INSERT INTO {$prefix}neria_preferences (id_customer, id_shop, email, category, subscribed, date_upd)
             VALUES ({$idCustomer}, {$idShop}, '', '{$cat}', 0, NOW())"
        );
    }

    try {
        $mgr = new PreferencesManager(neria_test_module());

        foreach ($samples as $tpl => $expectedCat) {
            neria_assert(
                isset(PreferencesManager::TEMPLATE_CAT[$tpl]),
                "'{$tpl}' est absent de TEMPLATE_CAT — régression du fix round 72b"
            );
            neria_assert(
                PreferencesManager::TEMPLATE_CAT[$tpl] === $expectedCat,
                "'{$tpl}' n'est plus catégorisé '{$expectedCat}' — régression du fix round 72b"
            );

            $allowed = $mgr->isAllowed($idCustomer, $tpl, $idShop);
            neria_assert(
                $allowed === false,
                "isAllowed() autorise encore l'envoi de '{$tpl}' à un client ayant désactivé la catégorie '{$expectedCat}' — régression du fix round 72b : ce template redeviendrait 'non classé' et toujours envoyé"
            );
        }

        // Simulation de régression : un template retiré de TEMPLATE_CAT sans
        // retrait du catalogue WAVE1 doit redevenir "toujours autorisé" —
        // vérifie que le test détecterait bien le bug d'origine s'il
        // réapparaissait (comme test_75, on ne peut pas retirer une const
        // PHP à chaud ; on vérifie donc directement le comportement de
        // isAllowed() sur un template réellement absent de TEMPLATE_CAT).
        neria_assert(
            !isset(PreferencesManager::TEMPLATE_CAT['neria_fallback']),
            "neria_fallback ne devrait pas être dans TEMPLATE_CAT (exclusion volontaire) — si ce test échoue, l'hypothèse de contrôle négatif ci-dessous est invalide"
        );
        $allowedFallback = $mgr->isAllowed($idCustomer, 'neria_fallback', $idShop);
        neria_assert(
            $allowedFallback === true,
            "isAllowed() devrait autoriser un template réellement non classé ('non classé → toujours envoyé') — comportement de base cassé"
        );

        return [
            'pass'    => true,
            'message' => "PreferencesManager respecte l'opt-out pour les 21 templates WAVE1/ABTest découverts au round 72b (échantillon post/behav/b2b vérifié)",
        ];
    } finally {
        foreach ($cats as $cat) {
            $db->execute(
                "DELETE FROM {$prefix}neria_preferences
                 WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND category = '{$cat}'"
            );
        }
    }
}
