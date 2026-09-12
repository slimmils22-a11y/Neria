<?php
/**
 * Régression : WaitlistManager doit scoper le Mode Silence (cooldown) par
 * PRODUIT **ET** DÉCLINAISON, pas seulement par produit — sinon la
 * notification d'une déclinaison enregistre l'occurrence de cooldown pour
 * TOUT le produit, bloquant à tort la notification légitime d'une AUTRE
 * déclinaison du même produit traitée juste après, dans la même boucle.
 *
 * Bug identifié le 12/09/2026 (round 346, audit WaitlistManager) : un même
 * client peut avoir 2 inscriptions distinctes pour 2 déclinaisons du même
 * produit (round 167/187) — après l'envoi réussi pour la déclinaison A,
 * CooldownManager enregistrait l'occurrence pour scope='product:X' (sans
 * la déclinaison) ; la ligne de la déclinaison B, traitée juste après dans
 * le même passage de notifyProductLocked(), était alors détectée comme
 * "doublon" par isDuplicate() et son claim libéré SANS envoi — alors qu'il
 * s'agit d'une notification légitime pour une déclinaison distincte.
 *
 * Corrigé le 12/09/2026 (round 346) : scope désormais
 * 'product:'.$idProduct.':'.$idProductAttribute aux 2 emplacements
 * concernés ({cooldown_scope} du Mail::Send(), et l'appel isDuplicate()
 * du pré-contrôle).
 *
 * Test comportemental réel sur le mécanisme sous-jacent (CooldownManager::
 * isDuplicate(), avec un client réel) : une occurrence enregistrée pour la
 * déclinaison A (scope 'product:X:1') n'est PAS détectée comme doublon
 * pour la déclinaison B du même produit (scope 'product:X:2') — confirme
 * que le scope par déclinaison isole bien les deux + vérification
 * structurelle que WaitlistManager utilise bien ce format aux 2 sites.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CooldownManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $email      = (string) $db->getValue("SELECT email FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
    $template   = 'waitlist_available';
    $idProduct  = 888780;
    $scopeA     = 'product:' . $idProduct . ':1';
    $scopeB     = 'product:' . $idProduct . ':2';

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_scope IN ('{$scopeA}', '{$scopeB}')");

    try {
        // Occurrence enregistrée pour la déclinaison A uniquement.
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, ref_scope, tracking_token, event_type, date_add)
             VALUES ({$idShop}, '{$template}', 'fr', {$idCustomer}, '{$scopeA}', SHA2(RAND(), 256), 'sent', NOW())"
        );

        $cooldownMgr = new CooldownManager();
        $dupA = $cooldownMgr->isDuplicate($email, $template, 60, $idShop, 0, $scopeA);
        neria_assert($dupA === true, "jeu de test invalide : isDuplicate() ne détecte pas l'occurrence de la déclinaison A elle-même");

        $dupB = $cooldownMgr->isDuplicate($email, $template, 60, $idShop, 0, $scopeB);
        neria_assert(
            $dupB === false,
            "isDuplicate() détecte à tort la déclinaison B ('{$scopeB}') comme doublon de la déclinaison A ('{$scopeA}') — le scope par déclinaison n'isole plus correctement les deux, régression du bug corrigé le 12/09/2026 (round 346)"
        );

        // Vérification structurelle des 2 emplacements corrigés.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
        neria_assert($src !== false, 'Impossible de lire src/WaitlistManager.php');
        neria_assert(
            strpos($src, "'{cooldown_scope}'     => 'product:' . \$idProduct . ':' . (int) \$row['id_product_attribute'],") !== false,
            "WaitlistManager ne fournit plus '{cooldown_scope}' scopé par déclinaison dans son Mail::Send() — régression du bug corrigé le 12/09/2026 (round 346)"
        );
        neria_assert(
            strpos($src, "'product:' . \$idProduct . ':' . \$idProductAttribute))") !== false,
            "WaitlistManager n'appelle plus isDuplicate() avec un scope scopé par déclinaison — régression du bug corrigé le 12/09/2026 (round 346)"
        );

        return [
            'pass'    => true,
            'message' => "WaitlistManager scope désormais bien le Mode Silence par produit ET déclinaison — deux déclinaisons distinctes du même produit ne se bloquent plus mutuellement, bug corrigé le 12/09/2026 (round 346)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_scope IN ('{$scopeA}', '{$scopeB}')");
    }
}
