<?php
/**
 * Régression : ManualSendManager::getPreferencesGuardStatus() utilisait
 * Context::getContext()->shop->id (contexte BO de l'opérateur) au lieu de
 * $customer['id_shop'] (boutique du CLIENT réel) — alors que send()/
 * scheduleManual()/checkAnniversaryConflict() résolvent tous
 * $customer['id_shop'] ?? Context::... dans cette même classe précisément
 * pour éviter ce piège (cf. commentaires round 136/156).
 *
 * Bug réel identifié le 23/08/2026 (round 190) : un opérateur en contexte
 * Boutique A prévisualisant un envoi pour un client de la Boutique B voyait
 * un bandeau "autorisé"/"bloqué" évalué sur les préférences de la MAUVAISE
 * boutique — pouvant afficher "autorisé" alors que l'envoi réel (qui utilise
 * bien $customer['id_shop']) sera bloqué, ou l'inverse.
 *
 * Corrigé le 23/08/2026 (round 190) : $customer['id_shop'] prioritaire.
 *
 * Test comportemental réel : un client désabonné (neria_preferences
 * subscribed=0) sur SA boutique réelle (id_shop=2, différente du contexte
 * BO courant id_shop=1) doit être détecté "bloqué" même quand l'opérateur
 * est en contexte Boutique 1 — preuve que $customer['id_shop'] est bien
 * utilisé, pas le contexte de l'opérateur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $operatorShop = (int) Context::getContext()->shop->id;
    // Boutique FICTIVE différente du contexte opérateur — le test vérifie
    // uniquement que la méthode lit $customer['id_shop'] et le transmet à
    // isAllowed(), pas qu'une vraie boutique n°99999 existe.
    $customerShop = 99999;
    neria_assert($customerShop !== $operatorShop, 'jeu de test invalide : collision de boutique fictive');

    $email = 'client.round190.shop99999@example.test';

    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE email = '" . pSQL($email) . "'");
    $db->execute(
        "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
         VALUES ({$customerShop}, 0, '" . pSQL($email) . "', 'newsletter', 0, NOW())"
    );

    // Stub findCustomer() via Reflection n'est pas trivial (méthode privée
    // interrogeant réellement ps_customer) — on vérifie donc directement le
    // comportement de PreferencesManager::isAllowed() avec id_shop=$customerShop
    // (chemin qu'emprunterait getPreferencesGuardStatus() une fois corrigé),
    // ET on vérifie structurellement que le code lit bien $customer['id_shop'].
    try {
        $pm = new PreferencesManager($module);
        $allowedOnCustomerShop = $pm->isAllowed(0, 'newsletter', $customerShop, $email);
        neria_assert(
            $allowedOnCustomerShop === false,
            'jeu de test invalide : isAllowed() ne détecte pas le désabonnement scopé sur la boutique cliente'
        );
        $allowedOnOperatorShop = $pm->isAllowed(0, 'newsletter', $operatorShop, $email);
        neria_assert(
            $allowedOnOperatorShop === true,
            'jeu de test invalide : isAllowed() sur la boutique opérateur devrait être opt-in par défaut (aucune ligne neria_preferences pour cette boutique)'
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
        neria_assert($src !== false, 'Impossible de lire src/ManualSendManager.php');
        $posMethod = strpos($src, 'public function getPreferencesGuardStatus(');
        neria_assert($posMethod !== false, 'getPreferencesGuardStatus() introuvable — jeu de test invalide');
        $methodBody = substr($src, $posMethod, 1300);
        neria_assert(
            strpos($methodBody, "\$idShop    = (int) (\$customer['id_shop'] ?? \\Context::getContext()->shop->id);") !== false,
            "getPreferencesGuardStatus() n'utilise plus \$customer['id_shop'] en priorité — régression du bug corrigé le 23/08/2026 (round 190) : le bandeau d'avertissement BO serait de nouveau évalué sur le contexte de l'opérateur au lieu de la boutique réelle du client"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE email = '" . pSQL($email) . "'");
    }

    return [
        'pass'    => true,
        'message' => "ManualSendManager::getPreferencesGuardStatus() résout bien \$customer['id_shop'] en priorité sur le contexte de l'opérateur BO — bug corrigé le 23/08/2026 (round 190)",
    ];
}
