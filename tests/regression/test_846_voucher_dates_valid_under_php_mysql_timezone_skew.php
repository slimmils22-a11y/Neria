<?php
/**
 * Régression (constat réel du 24/09/2026, campagne P5 sur ps-test) : un bon de fidélité fraîchement
 * émis était REFUSÉ à la validation de la commande — PaymentModule::validateOrder() redirigeait le
 * client vers le panier ("Ce bon de réduction n'est pas encore valable") — tant que les fuseaux PHP
 * et MySQL différaient (PHP America/New_York, MySQL Europe/Paris sur ps-test : 6 h d'écart).
 *
 * Cause : le cœur PrestaShop valide un bon avec DEUX horloges — la recherche par code
 * (`NOW() BETWEEN date_from AND date_to`, MySQL) et CartRule::checkValidity()
 * (`strtotime(date_from) > time()`, PHP). Le round 314 n'avait ancré date_from que sur l'horloge MySQL
 * (prémisse « validation purement MySQL » incomplète) : la validation PHP échouait pendant 6 h.
 *
 * Corrigé (round 372) : NeriaTools::voucherWindow() — date_from = le plus tôt des deux horloges,
 * date_to = le plus tard + validité. Les 3 générateurs de bons (fidélité, anniversaire, palier de
 * commandes) l'utilisent.
 *
 * Test comportemental : désynchronise réellement le fuseau PHP (America/New_York) et la session MySQL
 * (+02:00), fabrique un VRAI bon avec chacun des 3 générateurs, puis rejoue les deux comparaisons du
 * cœur (PHP strtotime/time() et SQL NOW() BETWEEN) — les deux doivent accepter le bon. Un témoin
 * négatif prouve que l'ancienne formule (date_from = NOW() MySQL seul) échouait bien dans ce décalage.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $p  = neria_test_prefix();
    $module = neria_test_module();
    $idCustomer = neria_test_any_customer_id();
    $idShop = (int) $db->getValue("SELECT id_shop FROM {$p}customer WHERE id_customer={$idCustomer}") ?: 1;
    $config = new ConfigManager($module);

    $origPhpTz = date_default_timezone_get();
    $origSqlTz = (string) $db->getValue('SELECT @@session.time_zone', false);
    $created = [];
    $ctx = Context::getContext();
    $origShop = $ctx->shop;
    try {
        $ctx->shop = new Shop($idShop);
        date_default_timezone_set('America/New_York');
        $db->execute("SET time_zone = '+02:00'");

        $phpNow   = date('Y-m-d H:i:s');
        $mysqlNow = (string) $db->getValue('SELECT NOW()', false);
        neria_assert(
            strtotime($mysqlNow) - strtotime($phpNow) > 3600 * 3,
            "Le décalage PHP/MySQL n'a pas pu être simulé (PHP {$phpNow}, MySQL {$mysqlNow}) — jeu de test invalide"
        );

        // Témoin négatif : l'ancienne formule (date_from = NOW() MySQL seul) est dans le futur pour PHP.
        neria_assert(
            strtotime($mysqlNow) > time(),
            "Témoin négatif invalide : NOW() MySQL devrait paraître futur à l'horloge PHP dans ce décalage"
        );

        $window = NeriaTools::voucherWindow(30);
        neria_assert(strtotime($window['from']) <= time(), "voucherWindow()['from'] ({$window['from']}) est dans le futur pour PHP");
        neria_assert($window['from'] <= $mysqlNow, "voucherWindow()['from'] ({$window['from']}) est dans le futur pour MySQL ({$mysqlNow})");
        neria_assert(strtotime($window['to']) - time() > 29 * 86400, "voucherWindow()['to'] raccourcit la validité de 30 jours (obtenu {$window['to']})");

        $tier = ['key' => 'regtest846', 'name' => 'Regtest846', 'points' => 1, 'amount' => 5, 'is_percent' => false];
        $codes = [];

        $m = new ReflectionMethod(LoyaltyManager::class, 'generateVoucher');
        $m->setAccessible(true);
        $codes['fidélité'] = (string) $m->invoke(new LoyaltyManager($module), $idCustomer, $tier, $idShop, 1, true, null);
        $created[] = (int) CartRule::getIdByCode($codes['fidélité']);

        $m = new ReflectionMethod(BehavioralCronManager::class, 'generateBirthdayVoucher');
        $m->setAccessible(true);
        $codes['anniversaire'] = (string) $m->invoke(new BehavioralCronManager($module), $idCustomer, $config, $idShop, 1999);
        $created[] = (int) CartRule::getIdByCode($codes['anniversaire']);

        // generateMilestoneVoucher() suppose la réservation posée par claimMilestone() (INSERT IGNORE).
        $db->execute("DELETE FROM {$p}neria_milestone_voucher WHERE id_customer={$idCustomer} AND milestone=9999");
        $db->execute("INSERT INTO {$p}neria_milestone_voucher (id_customer, milestone, id_cart_rule, voucher_code, id_shop, created_at) VALUES ({$idCustomer}, 9999, 0, '', {$idShop}, NOW())");
        $m = new ReflectionMethod(OrderTriggersManager::class, 'generateMilestoneVoucher');
        $m->setAccessible(true);
        $codes['palier de commandes'] = (string) $m->invoke(new OrderTriggersManager($module), $idCustomer, 9999, $config, $idShop);
        $created[] = (int) CartRule::getIdByCode($codes['palier de commandes']);

        foreach ($codes as $label => $code) {
            neria_assert($code !== '', "Le générateur de bon « {$label} » n'a produit aucun code (jeu de test invalide)");
            $idRule = (int) CartRule::getIdByCode($code);
            neria_assert($idRule > 0, "Bon « {$label} » introuvable en base ({$code})");
            $created[] = $idRule;
            $rule = new CartRule($idRule);

            neria_assert(
                strtotime($rule->date_from) <= time(),
                "Bon « {$label} » : date_from ({$rule->date_from}) est dans le FUTUR pour l'horloge PHP — CartRule::checkValidity() le refuserait « pas encore valable » à la validation de la commande — régression du bug corrigé le 24/09/2026 (round 372)"
            );
            neria_assert(
                strtotime($rule->date_to) >= time(),
                "Bon « {$label} » : date_to ({$rule->date_to}) est déjà passée pour l'horloge PHP"
            );
            $sqlOk = (int) $db->getValue("SELECT NOW() BETWEEN date_from AND date_to FROM {$p}cart_rule WHERE id_cart_rule={$idRule}", false);
            neria_assert(
                $sqlOk === 1,
                "Bon « {$label} » : la recherche par code (NOW() MySQL BETWEEN date_from AND date_to) le refuse — régression du bug corrigé le 07/09/2026 (round 314)"
            );
        }

        return [
            'pass'    => true,
            'message' => "Décalage PHP/MySQL simulé (" . $phpNow . " / " . $mysqlNow . ") : les bons de fidélité, d'anniversaire et de palier de commandes sont acceptés par les DEUX validations du cœur (PHP checkValidity et SQL NOW() BETWEEN) — bug corrigé le 24/09/2026 (round 372)",
        ];
    } finally {
        $db->execute("SET time_zone = '" . pSQL($origSqlTz ?: 'SYSTEM') . "'");
        date_default_timezone_set($origPhpTz);
        $ctx->shop = $origShop;
        foreach (array_unique(array_filter($created)) as $idRule) {
            (new CartRule((int) $idRule))->delete();
        }
        $db->execute("DELETE FROM {$p}neria_loyalty_rewards WHERE tier_key='regtest846'");
        $db->execute("DELETE FROM {$p}neria_birthday_voucher WHERE id_customer={$idCustomer} AND year=1999");
        $db->execute("DELETE FROM {$p}neria_milestone_voucher WHERE id_customer={$idCustomer} AND milestone=9999");
    }
}
