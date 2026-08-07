<?php
/**
 * Régression : BehavioralCronManager::generateBirthdayVoucher() doit
 * filtrer id_shop dans l'UPDATE final qui complète la réservation
 * (id_cart_rule + voucher_code), pas seulement id_customer + year — même
 * correctif déjà appliqué à OrderTriggersManager::generateMilestoneVoucher()
 * (round 56 / test_59).
 *
 * Bug réel corrigé le 07/08/2026 (round 89) : la table
 * neria_birthday_voucher a une clé unique (id_customer, year, id_shop)
 * depuis l'upgrade-1.0.29, précisément pour qu'un client partagé entre
 * boutiques ait une réservation distincte par boutique. Mais l'UPDATE qui
 * écrit le vrai id_cart_rule/voucher_code après création du CartRule (et le
 * DELETE de rollback en cas d'échec) ne filtraient QUE sur id_customer et
 * year. Sur un client partagé avec un anniversaire dans deux boutiques, le
 * premier UPDATE écrasait AUSSI la ligne de réservation de l'autre
 * boutique — celle-ci recevait un voucher_code inutilisable (CartRule
 * restreint à shop_restriction=1 sur une autre boutique).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $year       = (int) date('Y');

    // Réservation "en cours" (id_cart_rule=0) déjà posée par la boutique 2
    // pour le même client et la même année — état réaliste si la boutique 2
    // a fait son propre INSERT IGNORE mais n'a pas encore terminé la
    // création de son CartRule/UPDATE final.
    $db->execute(
        "INSERT INTO {$prefix}neria_birthday_voucher
            (id_customer, year, id_cart_rule, voucher_code, id_shop, created_at)
         VALUES ({$idCustomer}, {$year}, 0, '', 2, NOW())"
    );

    try {
        $mgr    = new BehavioralCronManager(neria_test_module());
        $config = new ConfigManager(neria_test_module());
        $ref    = new ReflectionMethod(BehavioralCronManager::class, 'generateBirthdayVoucher');
        $ref->setAccessible(true);

        // Complète la réservation de la boutique 1 uniquement.
        $code = $ref->invoke($mgr, $idCustomer, $config, 1);
        neria_assert($code !== '', "generateBirthdayVoucher() n'a pas produit de code — jeu de test invalide ou CartRule::add() a échoué");

        $rowShop1 = $db->getRow(
            "SELECT id_cart_rule, voucher_code FROM {$prefix}neria_birthday_voucher
             WHERE id_customer = {$idCustomer} AND year = {$year} AND id_shop = 1"
        );
        $rowShop2 = $db->getRow(
            "SELECT id_cart_rule, voucher_code FROM {$prefix}neria_birthday_voucher
             WHERE id_customer = {$idCustomer} AND year = {$year} AND id_shop = 2"
        );

        neria_assert(
            $rowShop1 !== false && (int) $rowShop1['id_cart_rule'] > 0 && $rowShop1['voucher_code'] === $code,
            "la réservation de la boutique 1 n'a pas été complétée par generateBirthdayVoucher()"
        );
        neria_assert(
            $rowShop2 !== false && (int) $rowShop2['id_cart_rule'] === 0 && $rowShop2['voucher_code'] === '',
            "la réservation de la boutique 2 a été altérée par l'UPDATE de la boutique 1 (id_cart_rule=" . ($rowShop2['id_cart_rule'] ?? 'absent') . ", voucher_code=" . ($rowShop2['voucher_code'] ?? 'absent') . ") — régression du bug corrigé le 07/08/2026 (round 89) : l'UPDATE final n'est plus scopé par id_shop"
        );

        if ((int) $rowShop1['id_cart_rule'] > 0) {
            (new CartRule((int) $rowShop1['id_cart_rule']))->delete();
        }

        return [
            'pass'    => true,
            'message' => "generateBirthdayVoucher() ne touche que la réservation de la boutique ciblée ; la réservation d'une autre boutique pour la même année reste intacte",
        ];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_birthday_voucher WHERE id_customer = {$idCustomer} AND year = {$year}"
        );
    }
}
