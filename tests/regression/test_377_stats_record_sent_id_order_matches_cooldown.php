<?php
/**
 * Régression : StatsManager::recordSent() lisait $params['idOrder'] —
 * une clé jamais définie nulle part dans le module (aucun appelant ne la
 * fournit). Chaque ligne 'sent' enregistrée dans neria_stat avait donc
 * systématiquement id_order = 0, quel que soit le template. Le hook
 * hookActionEmailSendBeforeImpl() (neria.php) lit pourtant correctement
 * $params['templateVars']['{id_order}'] pour la VÉRIFICATION du cooldown
 * juste avant recordSent() — un décalage de clé entre écriture et
 * lecture qui rendait CooldownManager::isDuplicate() scopé par commande
 * (id_order > 0) totalement inopérant : aucune ligne existante ne
 * pouvait jamais matcher un id_order réel puisque toutes étaient à 0.
 *
 * Corrigé le 18/08/2026 (round 184) : recordSent() lit désormais
 * $params['templateVars']['{id_order}'], la même clé que le hook.
 *
 * Test comportemental réel : appelle recordSent() avec templateVars
 * contenant {id_order}, vérifie que la ligne neria_stat créée porte bien
 * ce id_order (pas 0), puis vérifie que CooldownManager::isDuplicate()
 * détecte bien le doublon pour cette même commande.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CooldownManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $module     = neria_test_module();
    $idShop     = (int) Context::getContext()->shop->id;

    // Client réel actif nécessaire : CooldownManager::isDuplicate() résout
    // idCustomer via l'email et ignore les invités (idCustomer <= 0).
    $idCustomer = neria_test_any_customer_id();
    $customerEmail = (string) $db->getValue(
        "SELECT email FROM {$prefix}customer WHERE id_customer = {$idCustomer}"
    );
    neria_assert($customerEmail !== '', "Impossible de résoudre l'email du client de test — jeu de test invalide");

    $testOrderId = 976500 + ($idCustomer % 400);
    $testToken   = bin2hex(random_bytes(16));

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$testToken}'");

    try {
        $mgr = new StatsManager($module);
        $mgr->recordSent([
            'to'             => $customerEmail,
            'idCustomer'     => $idCustomer,
            'neria_template' => 'order_conf',
            'neria_lang'     => 'fr',
            'neria_token'    => $testToken,
            'templateVars'   => ['{id_order}' => $testOrderId],
        ]);

        $storedIdOrder = $db->getValue(
            "SELECT id_order FROM {$prefix}neria_stat WHERE tracking_token = '{$testToken}'"
        );
        neria_assert(
            $storedIdOrder !== false,
            "Aucune ligne neria_stat créée par recordSent() — jeu de test invalide"
        );
        neria_assert(
            (int) $storedIdOrder === $testOrderId,
            "recordSent() a enregistré id_order={$storedIdOrder} au lieu de {$testOrderId} — régression du bug corrigé le 18/08/2026 (round 184) : la clé \$params['idOrder'] (inexistante) serait de nouveau lue au lieu de \$params['templateVars']['{id_order}']"
        );

        $cdMgr = new CooldownManager();
        $isDup = $cdMgr->isDuplicate($customerEmail, 'order_conf', 60, $idShop, $testOrderId);
        neria_assert(
            $isDup === true,
            "CooldownManager::isDuplicate() ne détecte pas le doublon pour la commande #{$testOrderId} alors qu'un envoi 'sent' vient d'être enregistré pour cette même commande — régression du bug corrigé le 18/08/2026 (round 184) : le Mode Silence resterait inopérant pour tous les templates liés à une commande"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$testToken}'");
    }

    return [
        'pass'    => true,
        'message' => "StatsManager::recordSent() enregistre bien le vrai id_order (via templateVars), rendant CooldownManager::isDuplicate() de nouveau opérant pour les templates liés à une commande — bug corrigé le 18/08/2026 (round 184)",
    ];
}
