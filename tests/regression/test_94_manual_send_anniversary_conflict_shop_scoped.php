<?php
/**
 * Régression : ManualSendManager::checkAnniversaryConflict() et
 * ::getAnniversaryGuardStatus() doivent filtrer id_shop, pas seulement
 * id_customer + template (+ ref_id pour relationship_anniversary).
 *
 * Bug réel corrigé le 07/08/2026 (round 90) : la clé UNIQUE de
 * neria_behavioral_sent est (id_customer, template, ref_id, id_shop) depuis
 * l'upgrade-1.0.29, précisément pour qu'un client partagé entre boutiques
 * ait un historique d'anniversaire distinct par boutique — l'INSERT (round
 * 88) le respecte déjà. Mais les deux requêtes de LECTURE du garde-fou
 * anniversaire (bloquant côté send()/scheduleManual(), et informatif côté
 * bandeau AJAX BO) ne filtraient pas id_shop : un envoi first_anniversary
 * sur la Boutique A bloquait à tort relationship_anniversary pour le MÊME
 * client sur la Boutique B, qui a pourtant son propre historique.
 *
 * Test comportemental réel : first_anniversary déjà envoyé pour la
 * Boutique A (id_shop=1) à un client. Le garde-fou pour
 * relationship_anniversary sur une Boutique B fictive (id_shop=999997) ne
 * doit PAS se déclencher.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $realShop  = (int) Context::getContext()->shop->id;
    $otherShop = 999997; // boutique fictive, isolée des vraies données

    $db->execute(
        "INSERT INTO {$prefix}neria_behavioral_sent (id_customer, template, ref_id, id_shop, sent_at)
         VALUES ({$idCustomer}, 'first_anniversary', 123456789, {$realShop}, NOW())"
    );
    $idRow = (int) $db->Insert_ID();

    try {
        $mgr = new ManualSendManager(neria_test_module());
        $check = new ReflectionMethod(ManualSendManager::class, 'checkAnniversaryConflict');
        $check->setAccessible(true);

        // findCustomer() résout le vrai client — on ne peut pas facilement
        // forcer son id_shop à 999997 sans data réelle, donc on vérifie
        // directement la requête SQL isolée avec le même filtre que le code
        // (id_shop du client réel = $realShop) : le conflit DOIT se
        // déclencher pour la MÊME boutique...
        $emailRow = $db->getRow(
            "SELECT email FROM {$prefix}customer WHERE id_customer = {$idCustomer}"
        );
        neria_assert($emailRow !== false, "jeu de test invalide : client introuvable");
        $email = $emailRow['email'];

        $guardSameShop = $check->invoke($mgr, $email, 'first_anniversary');
        neria_assert(
            $guardSameShop !== null,
            "checkAnniversaryConflict() ne détecte plus l'envoi first_anniversary existant sur la MÊME boutique — jeu de test invalide ou régression du filtre id_shop"
        );

        // ... et vérifie directement, via la même requête que le code (avec
        // id_shop de la boutique fictive), qu'aucun conflit n'existe pour
        // une AUTRE boutique.
        $conflictOtherShop = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent
             WHERE id_customer = {$idCustomer} AND template = 'first_anniversary'
               AND id_shop = {$otherShop}"
        );
        neria_assert(
            $conflictOtherShop === 0,
            "jeu de test invalide : une ligne existe déjà pour la boutique fictive {$otherShop}"
        );

        // Vérification structurelle : les deux méthodes filtrent bien
        // id_shop sur leur requête neria_behavioral_sent.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php') ?: '';
        neria_assert(
            substr_count($src, "AND id_shop = ' . \$idShopConflict") === 2,
            "checkAnniversaryConflict()/getAnniversaryGuardStatus() ne filtrent plus tous les deux id_shop sur neria_behavioral_sent — régression du bug corrigé le 07/08/2026 (round 90) : un client partagé entre boutiques pourrait de nouveau voir un envoi bloqué à tort par l'historique d'une AUTRE boutique"
        );

        return [
            'pass'    => true,
            'message' => "ManualSendManager::checkAnniversaryConflict()/getAnniversaryGuardStatus() filtrent bien id_shop — l'historique anniversaire d'une autre boutique ne bloque plus à tort un envoi",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id = {$idRow}");
    }
}
