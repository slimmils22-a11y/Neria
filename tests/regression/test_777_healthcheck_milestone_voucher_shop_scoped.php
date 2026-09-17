<?php
/**
 * Régression : HealthCheckManager::checkMilestoneVoucherCartRuleValidity()
 * recherchait les bons de palier référençant un CartRule PrestaShop
 * disparu/désactivé SANS filtrer par id_shop, alors que le schéma
 * (install.sql, TABLE 38) documente explicitement que id_shop fait
 * TOUJOURS partie de la clé anti-doublon ici (contrairement aux points
 * de fidélité, configurables en cumul transversal) : "palier 5" en
 * boutique A et "palier 5" en boutique B sont deux jalons distincts —
 * round dédié HealthCheckManager (bloc A, 16/09/2026).
 *
 * Sur une installation multi-boutiques, un bon cassé sur une AUTRE
 * boutique polluait le WARNING affiché pour la boutique consultée, sans
 * que le marchand puisse même le retrouver dans son propre BO.
 *
 * Corrigé : la requête filtre désormais explicitement id_shop =
 * $this->idShop.
 *
 * Test comportemental réel : insère un bon de palier RÉFÉRENÇANT UN
 * CART_RULE INEXISTANT sur une boutique FICTIVE — vérifie que le
 * contrôle de la boutique réelle reste OK. Insère ensuite le même
 * défaut sur la boutique RÉELLE — vérifie qu'il est bien détecté.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $db       = neria_test_db();
    $prefix   = neria_test_prefix();
    $realShop = (int) Context::getContext()->shop->id;
    $fakeShop = 999997777;

    $tableCheck = $db->executeS("SHOW TABLES LIKE '" . pSQL($prefix . 'neria_milestone_voucher') . "'");
    if (!is_array($tableCheck) || empty($tableCheck)) {
        return ['pass' => true, 'message' => 'neria_milestone_voucher absente (fonctionnalité désactivée sur cet environnement) — test non applicable'];
    }

    $deadCartRuleId = 999998001; // n'existe certainement pas dans ps_cart_rule

    $cleanup = function () use ($db, $prefix, $fakeShop, $realShop, $deadCartRuleId) {
        $db->execute("DELETE FROM {$prefix}neria_milestone_voucher WHERE id_cart_rule = {$deadCartRuleId} AND id_shop IN ({$fakeShop}, {$realShop})");
    };
    $cleanup();

    try {
        // Bon cassé sur une boutique FICTIVE uniquement.
        $db->execute(
            "INSERT INTO {$prefix}neria_milestone_voucher
                (id_customer, milestone, id_cart_rule, voucher_code, id_shop, created_at)
             VALUES (1, 5, {$deadCartRuleId}, 'REGTEST777FAKE', {$fakeShop}, NOW())"
        );

        $hc = new HealthCheckManager(neria_test_module());
        $method = new ReflectionMethod(HealthCheckManager::class, 'checkMilestoneVoucherCartRuleValidity');
        $method->setAccessible(true);

        $result = $method->invoke($hc);
        neria_assert(
            $result['status'] === 'ok',
            "checkMilestoneVoucherCartRuleValidity() renvoie '{$result['status']}' alors que le seul bon cassé appartient à une AUTRE boutique (fictive) — régression du scoping id_shop, détail obtenu = " . ($result['detail'] ?? '?')
        );

        // Le même défaut, mais sur la boutique RÉELLE cette fois — doit être détecté.
        $db->execute(
            "INSERT INTO {$prefix}neria_milestone_voucher
                (id_customer, milestone, id_cart_rule, voucher_code, id_shop, created_at)
             VALUES (1, 5, {$deadCartRuleId}, 'REGTEST777REAL', {$realShop}, NOW())"
        );
        $resultAfterReal = $method->invoke($hc);
        neria_assert(
            $resultAfterReal['status'] === 'warning',
            "checkMilestoneVoucherCartRuleValidity() ne détecte pas un bon cassé appartenant réellement à la boutique courante — jeu de test invalide ou régression"
        );

        return [
            'pass'    => true,
            'message' => "HealthCheckManager::checkMilestoneVoucherCartRuleValidity() isole bien ses résultats par boutique, cohérent avec la clé anti-doublon id_shop du schéma — bug corrigé round HealthCheckManager (bloc A, 16/09/2026)",
        ];
    } finally {
        $cleanup();
    }
}
