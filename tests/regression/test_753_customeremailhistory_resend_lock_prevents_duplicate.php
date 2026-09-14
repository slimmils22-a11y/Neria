<?php
/**
 * Régression : CustomerEmailHistoryManager::resend() n'avait aucune
 * protection contre un double-clic sur le bouton « Renvoyer » du BO (ou
 * deux onglets ouverts sur la même fiche client) — le contrôle anti-doublon
 * (CooldownManager::isDuplicate()) était un pur check-then-act sans verrou,
 * contrairement à StatsManager::recordOpen()/recordClick() qui protègent la
 * même classe de section critique via GET_LOCK(). Deux requêtes HTTP quasi
 * simultanées lisaient toutes deux « pas de doublon récent » avant qu'aucune
 * des deux n'ait déclenché l'enregistrement de l'évènement 'sent' associé,
 * donc les deux passaient le contrôle et 2 emails identiques partaient
 * malgré le Mode Silence actif.
 *
 * Bug identifié le 14/09/2026 (round 356, audit dédié CustomerEmailHistoryManager).
 *
 * Corrigé le 14/09/2026 : resend() acquiert désormais un GET_LOCK (timeout
 * 2s) scopé par id_stat avant de déléguer à resendLocked() (ancien corps de
 * resend(), inchangé) — même motif que StatsManager.
 *
 * Test comportemental réel (2e connexion mysqli brute, même technique que
 * test_693/260/68) : détient le verrou scopé au même id_stat que le test,
 * vérifie que resend() renvoie bien le blocage anti-doublon (pas d'attente
 * excessive, timeout 2s respecté) au lieu de traiter l'envoi normalement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();

    // Fixture minimale : une ligne neria_stat 'sent' réelle pour que
    // getEmailById() la retrouve et que resend() atteigne bien le verrou
    // avant tout autre garde-fou.
    $token = 'regtest753-' . uniqid();
    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, revenue, date_add)
         VALUES ({$idShop}, 'order_conf', 'fr', {$idCustomer}, 0, '{$token}', 'sent', 0.00, NOW())"
    );
    $idStat = (int) $db->Insert_ID();

    $lockKey = 'neria_resend_' . $idStat;

    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'Impossible d\'ouvrir une seconde connexion MySQL pour simuler un renvoi concurrent — jeu de test invalide');

    try {
        $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockKey) . "', 2)");
        $row = mysqli_fetch_row($res);
        neria_assert((int) $row[0] === 1, 'La seconde connexion MySQL n\'a pas pu obtenir le verrou — jeu de test invalide');

        $mgr = new CustomerEmailHistoryManager(neria_test_module());
        $start = microtime(true);
        $result = $mgr->resend($idStat, $idCustomer);
        $elapsed = microtime(true) - $start;

        neria_assert(
            $elapsed < 4.0,
            "resend() a mis {$elapsed}s alors qu'un timeout de verrou de 2s est attendu — possible régression"
        );
        neria_assert(
            $result['ok'] === false && $result['message_key'] === 'history.resend_blocked',
            "resend() n'a pas renvoyé le blocage anti-doublon attendu (obtenu : ok=" . var_export($result['ok'], true) . ", message_key=" . ($result['message_key'] ?? 'null') . ") alors qu'un verrou pour le même id_stat était détenu par une connexion concurrente — régression du bug corrigé le 14/09/2026 (round 356) : le GET_LOCK ne protège plus le contrôle cooldown/blacklist/préférences"
        );

        return [
            'pass'    => true,
            'message' => "CustomerEmailHistoryManager::resend() acquiert désormais un verrou GET_LOCK (scopé par id_stat) avant le contrôle cooldown/blacklist/préférences — un renvoi concurrent identique est bloqué au lieu de déclencher un second envoi réel — bug corrigé le 14/09/2026 (round 356)",
        ];
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($mysqli, $lockKey) . "')");
        mysqli_close($mysqli);
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$token}'");
    }
}
