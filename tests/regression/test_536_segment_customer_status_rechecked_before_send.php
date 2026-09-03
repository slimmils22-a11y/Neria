<?php
/**
 * Régression : `SegmentManager::sendToSegment()` ne revérifiait jamais le
 * statut `active`/`deleted` du CLIENT juste avant `Mail::Send()` —
 * `getCustomersBySegment()` filtre `active=1`/`deleted=0` UNIQUEMENT au
 * moment du SELECT initial (jusqu'à 500 destinataires chargés en mémoire
 * PHP en une fois). Un envoi SMTP réel prenant ~150-300ms par
 * destinataire, un lot de 500 peut s'étaler sur 1 à 2 minutes. Un client
 * désactivé en BO ou ayant exercé son droit à l'effacement RGPD PENDANT
 * ce laps de temps (la suppression/effacement natif PrestaShop met bien
 * `deleted=1` sur la ligne `ps_customer`) continuait de recevoir la
 * campagne aux itérations suivantes, avec des données déjà périmées en
 * RAM.
 *
 * Bug identifié le 02/09/2026 (round 286, audit "revalidation client
 * entre sélection cron et envoi").
 *
 * Corrigé le 02/09/2026 (round 286) :
 * `explicitSendBlockReason()` accepte désormais un `$idCustomer`
 * optionnel et relit fraîchement `active`/`deleted` juste avant
 * `Mail::Send()`, retournant `'customer_inactive'` si le client n'est
 * plus éligible.
 *
 * Test comportemental réel : désactive temporairement un vrai client,
 * vérifie que `explicitSendBlockReason()` bloque bien l'envoi, puis
 * restaure son état initial.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';

    $db = neria_test_db();
    $idCustomer = neria_test_any_customer_id();
    neria_assert($idCustomer > 0, 'jeu de test invalide : aucun client actif trouvé');

    $originalActive = (int) $db->getValue("SELECT active FROM " . neria_test_prefix() . "customer WHERE id_customer = {$idCustomer}");

    $module = neria_test_module();
    $mgr = new SegmentManager($module);
    $ref = new ReflectionMethod('SegmentManager', 'explicitSendBlockReason');
    $ref->setAccessible(true);

    try {
        $db->execute("UPDATE " . neria_test_prefix() . "customer SET active = 0 WHERE id_customer = {$idCustomer}");

        $reason = $ref->invoke($mgr, 'loyalty_recap', 'neria-round286-test@example.com', (int) Configuration::get('PS_LANG_DEFAULT'), $idCustomer);

        neria_assert(
            $reason === 'customer_inactive',
            "explicitSendBlockReason() ne détecte pas un client désactivé pendant le batch (obtenu : " . var_export($reason, true) . ") — régression du bug corrigé le 02/09/2026 (round 286) : une campagne segment continuerait d'envoyer à un client désactivé/GDPR-purgé en cours de lot"
        );

        // Un client TOUJOURS actif ne doit PAS être bloqué par ce nouveau contrôle.
        $db->execute("UPDATE " . neria_test_prefix() . "customer SET active = 1 WHERE id_customer = {$idCustomer}");
        $reasonActive = $ref->invoke($mgr, 'loyalty_recap', 'neria-round286-test@example.com', (int) Configuration::get('PS_LANG_DEFAULT'), $idCustomer);
        neria_assert(
            $reasonActive !== 'customer_inactive',
            "explicitSendBlockReason() bloque à tort un client toujours actif — régression du nouveau contrôle round 286"
        );
    } finally {
        $db->execute("UPDATE " . neria_test_prefix() . "customer SET active = {$originalActive} WHERE id_customer = {$idCustomer}");
    }

    return [
        'pass'    => true,
        'message' => "SegmentManager::explicitSendBlockReason() relit désormais l'état active/deleted du client juste avant l'envoi — bug corrigé le 02/09/2026 (round 286)",
    ];
}
