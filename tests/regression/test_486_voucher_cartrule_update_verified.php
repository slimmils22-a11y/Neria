<?php
/**
 * Régression round 251 (31/08/2026) : `LoyaltyManager::generateVoucher()`,
 * `OrderTriggersManager::generateMilestoneVoucher()` et
 * `BehavioralCronManager::generateBirthdayVoucher()` suivent tous le même
 * schéma en 2 écritures SUR DES TABLES DIFFÉRENTES, sans transaction SQL
 * les encadrant : `CartRule::add()` (écrit dans ps_cart_rule + lang/group,
 * hors contrôle du module) PUIS un `UPDATE` de la table de suivi Neria
 * (neria_loyalty_rewards / neria_milestone_voucher / neria_birthday_voucher)
 * pour y inscrire le id_cart_rule/voucher_code réel. Le retour de cet
 * UPDATE n'était jamais vérifié.
 *
 * Si l'UPDATE échoue (contrainte, connexion coupée, timeout) alors que
 * add() a réussi, la ligne de suivi reste PERMANENMENT à id_cart_rule=0
 * alors qu'un vrai CartRule actif existe déjà — conséquences réelles selon
 * la classe :
 * - LoyaltyManager : revokeInvalidRewards() (`if ($idCartRule <= 0)
 *   continue;`) ne verrait jamais ce bon comme lié au CartRule réel — non
 *   révocable sur un remboursement ultérieur du palier.
 * - OrderTriggersManager/BehavioralCronManager : si un envoi d'email
 *   échoue ENSUITE, la logique de libération de réservation (WHERE
 *   id_cart_rule = 0) supprime la ligne de suivi redevenue "libre" alors
 *   qu'un vrai CartRule actif existe déjà, non traçable — un futur
 *   déclenchement du même jalon/année régénère un SECOND bon actif (perte
 *   financière directe).
 *
 * Corrigé le 31/08/2026 (round 251) : les 3 méthodes vérifient désormais
 * Affected_Rows() sur l'UPDATE et désactivent le CartRule fraîchement créé
 * en cas d'échec, plutôt que de le laisser actif et non traçable.
 *
 * Test réel (partie A) : reproduit authentiquement la condition de
 * déclenchement — insère une réservation réelle (INSERT IGNORE, même
 * schéma que generateVoucher()), la supprime (simule une réservation
 * perdue entre l'INSERT et l'UPDATE final), puis exécute le MÊME motif
 * d'UPDATE que le code réel et vérifie qu'il affecte bien 0 ligne — la
 * condition exacte que le correctif doit détecter.
 *
 * Test réel (partie B) : crée un vrai CartRule actif via le cœur
 * PrestaShop, exécute exactement la même désactivation que le correctif
 * (`$cartRule->active = false; $cartRule->update();`), puis recharge le
 * CartRule depuis la base et vérifie qu'il est bien inactif — preuve
 * réelle que la mitigation retire effectivement le bon de la circulation.
 *
 * Test structurel (partie C) : vérifie la présence du correctif dans les 3
 * fichiers.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    // ── Partie A : reproduction réelle de la condition Affected_Rows()=0 ──
    $idCustomer = neria_test_any_customer_id();
    $tierKey    = 'regtest486';
    $idShop     = (int) Context::getContext()->shop->id;

    $db->execute("DELETE FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '{$tierKey}'");

    $reserved = $db->execute(
        "INSERT IGNORE INTO {$prefix}neria_loyalty_rewards
            (id_customer, tier_key, tier_name, points_at_reward, id_cart_rule, voucher_code, voucher_amount, is_percent, id_shop, sent_at)
         VALUES ({$idCustomer}, '{$tierKey}', 'Regtest486', 100, 0, '', 10, 0, {$idShop}, NOW())"
    );
    neria_assert($reserved && (int) $db->Affected_Rows() === 1, "jeu de test invalide : la réservation initiale a échoué");

    // Simule une réservation perdue entre l'INSERT et l'UPDATE final (le
    // scénario que ce round corrige : un aléa quelconque a fait disparaître
    // la ligne juste avant que le code n'essaie de la compléter).
    $db->execute("DELETE FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '{$tierKey}' AND id_shop = {$idShop}");

    // Exécute EXACTEMENT le même motif d'UPDATE que generateVoucher() —
    // doit affecter 0 ligne puisque la réservation n'existe plus.
    $db->execute(
        "UPDATE {$prefix}neria_loyalty_rewards
         SET id_cart_rule = 999999, voucher_code = 'REGTEST486'
         WHERE id_customer = {$idCustomer} AND tier_key = '{$tierKey}' AND id_shop = {$idShop}"
    );
    neria_assert(
        (int) $db->Affected_Rows() === 0,
        "jeu de test invalide : l'UPDATE de suivi a affecté une ligne alors que la réservation avait été supprimée — le scénario de reproduction ne fonctionne pas"
    );

    // ── Partie B : preuve réelle que la mitigation désactive le CartRule ──
    $cartRule = new CartRule();
    $cartRule->name                    = [(int) Configuration::get('PS_LANG_DEFAULT') => 'Regtest486'];
    $cartRule->code                    = 'REGTEST486-' . uniqid();
    $cartRule->quantity                = 1;
    $cartRule->quantity_per_user       = 1;
    $cartRule->active                  = 1;
    $cartRule->date_from               = date('Y-m-d H:i:s');
    $cartRule->date_to                 = date('Y-m-d H:i:s', strtotime('+1 year'));
    $cartRule->reduction_percent       = 10;
    $cartRule->minimum_amount          = 0;
    $cartRule->minimum_amount_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $cartRule->highlight               = false;
    $cartRule->free_shipping           = false;

    neria_assert($cartRule->add(), "jeu de test invalide : la création du CartRule de test a échoué");
    $idCartRule = (int) $cartRule->id;

    try {
        // Exactement le même mécanisme de désactivation que le correctif.
        $cartRule->active = false;
        $cartRule->update();

        $reloaded = new CartRule($idCartRule);
        neria_assert(
            Validate::isLoadedObject($reloaded) && (int) $reloaded->active === 0,
            "la désactivation du CartRule (\$cartRule->active = false; \$cartRule->update();) ne se répercute pas réellement en base — la mitigation du round 251 ne retirerait pas le bon de la circulation"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '{$tierKey}'");
        $cartRuleCleanup = new CartRule($idCartRule);
        if (Validate::isLoadedObject($cartRuleCleanup)) {
            $cartRuleCleanup->delete();
        }
    }

    // ── Partie C : vérification structurelle des 3 correctifs ──
    $needle = "if ((int) \$this->db->Affected_Rows() !== 1) {\n            \$cartRule->active = false;";
    foreach ([
        'LoyaltyManager.php'         => 'generateVoucher()',
        'OrderTriggersManager.php'   => 'generateMilestoneVoucher()',
        'BehavioralCronManager.php'  => 'generateBirthdayVoucher()',
    ] as $file => $method) {
        $src = str_replace("\r", '', (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/' . $file));
        neria_assert(
            strpos($src, $needle) !== false,
            "{$file} :: {$method} ne vérifie plus Affected_Rows() sur l'UPDATE de suivi après cartRule->add() — régression du bug corrigé le 31/08/2026 (round 251)"
        );
    }

    return [
        'pass'    => true,
        'message' => "La condition de déclenchement (UPDATE de suivi affectant 0 ligne après cartRule->add() réussi) est bien reproductible en réel, et la désactivation du CartRule qui la mitige se répercute effectivement en base — les 3 correctifs (LoyaltyManager/OrderTriggersManager/BehavioralCronManager) sont bien présents",
    ];
}
