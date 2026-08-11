<?php
/**
 * Régression : SegmentManager::preflightCheck() doit détecter qu'un
 * segment ENTIÈREMENT désabonné du template ne recevra en réalité AUCUN
 * email, comme sendToSegment() (qui filtre déjà via PreferencesManager).
 *
 * Bug réel corrigé le 09/08/2026 (round 146) : preflightCheck() calculait
 * recipient_count uniquement à partir de getCustomersBySegment(), sans
 * jamais tenir compte des préférences d'abonnement — contrairement à
 * sendToSegment(), qui filtre bien chaque destinataire via
 * PreferencesManager::isAllowed(). Un segment de clients tous désabonnés
 * du template concerné passait le contrôle à blanc sans alerte
 * (ok=true, recipient_count=N, aucune issue), alors que l'envoi réel se
 * solderait par 0 email — découvert seulement après coup dans le rapport
 * d'envoi, contredisant l'objectif documenté du contrôle à blanc (détecter
 * un problème AVANT de lancer la campagne).
 *
 * Test comportemental réel : place un client réel dans un segment, le
 * désabonne du template 'vip' (catégorie 'behav'), vérifie que
 * preflightCheck('ghost', 'vip') est bien bloquant avec le nouveau
 * message dédié — puis réabonne-le et vérifie que le preflight redevient
 * OK.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) \Context::getContext()->shop->id;
    $segment    = 'ghost';
    $template   = 'vip';

    $customerRow = $db->getRow("SELECT email FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
    neria_assert($customerRow !== false, 'Client de test introuvable — jeu de test invalide');
    $email = $customerRow['email'];

    $mgr = new SegmentManager(neria_test_module());

    try {
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");
        $db->execute(
            "INSERT INTO {$prefix}neria_customer_segment
                (id_shop, id_customer, segment, total_sent, total_opens, total_clicks, total_conversions, computed_at)
             VALUES ({$idShop}, {$idCustomer}, '{$segment}', 5, 3, 1, 0, NOW())"
        );

        $before = $mgr->preflightCheck($segment, $template);
        neria_assert(
            $before['recipient_count'] > 0,
            "le client de test n'apparaît pas dans le segment '{$segment}' — jeu de test invalide"
        );
        neria_assert(
            $before['ok'] === true,
            "preflightCheck() bloque déjà avant tout désabonnement — jeu de test invalide"
        );

        // Désabonne ce client du template 'vip' (catégorie 'behav')
        $db->execute(
            "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
             VALUES ({$idShop}, {$idCustomer}, '" . pSQL($email) . "', 'behav', 0, NOW())
             ON DUPLICATE KEY UPDATE subscribed = 0"
        );

        $after = $mgr->preflightCheck($segment, $template);
        neria_assert(
            $after['ok'] === false && $after['blocking'] === true,
            "preflightCheck() ne bloque pas un segment entièrement désabonné du template — régression du bug corrigé le 09/08/2026 (round 146) : le marchand ne serait de nouveau alerté qu'après un envoi réel à 0 destinataire"
        );

        $foundIssue = false;
        foreach ($after['issues'] as $issue) {
            if (stripos($issue, 'désactivé') !== false || stripos($issue, 'communications') !== false) {
                $foundIssue = true;
            }
        }
        neria_assert($foundIssue, "aucune issue mentionnant le désabonnement n'a été trouvée dans preflightCheck() : " . json_encode($after['issues']));

        // Réabonne — le preflight doit redevenir OK
        $db->execute("UPDATE {$prefix}neria_preferences SET subscribed = 1 WHERE id_customer = {$idCustomer} AND category = 'behav' AND id_shop = {$idShop}");
        $restored = $mgr->preflightCheck($segment, $template);
        neria_assert($restored['ok'] === true, "preflightCheck() reste bloquant après réabonnement — non-régression cassée");
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer} AND category = 'behav' AND id_shop = {$idShop}");
    }

    return [
        'pass'    => true,
        'message' => "SegmentManager::preflightCheck() détecte bien un segment entièrement désabonné du template et le signale comme bloquant",
    ];
}
