<?php
/**
 * Régression : `OrderTriggersManager::handleStatusChange()` n'avait AUCUNE
 * protection anti-doublon indépendante du toggle marchand "Mode Silence"
 * (`NERIA_COOLDOWN_ENABLED`, désactivé par défaut) pour les déclencheurs
 * `order_partial_shipped` et `order_on_hold` — contrairement à
 * `handleRefund()`/`handleReturn()`, qui posent un `GET_LOCK()` par
 * commande/avoir indépendant de ce réglage (round 65).
 * `explicitSendBlockReason()` ne bloque le cooldown que si
 * `NERIA_COOLDOWN_ENABLED=1` ; avec le réglage par défaut (0, jamais
 * activé par la majorité des marchands), un redéclenchement du hook
 * `actionOrderStatusPostUpdate` pour LA MÊME transition (module tiers
 * rappelant `setCurrentState()`, retry BO, requête HTTP rejouée par un
 * load balancer) envoyait deux fois l'email au client.
 *
 * Bug identifié le 02/09/2026 (round 280, audit "idempotence des hooks
 * déclencheurs d'email").
 *
 * Corrigé le 02/09/2026 (round 280) : `GET_LOCK('neria_partial_shipped_'
 * . id_order . '_' . id_status, 0)` / `GET_LOCK('neria_order_on_hold_' .
 * id_order . '_' . id_status, 0)` posés avant tout traitement — même
 * motif que `handleRefund()`/`handleReturn()`, scopés par commande +
 * statut cible pour ne pas bloquer une transition ultérieure légitime
 * (ex. un second "on hold" avec un statut custom différent).
 *
 * Test comportemental réel (même technique que test_68, qui a prouvé
 * l'efficacité de ce motif pour handleReturn()) : simule un "autre
 * processus" tenant déjà le verrou via une SECONDE connexion MySQL brute
 * (Db::getInstance() étant un singleton partagé, un GET_LOCK() posé
 * depuis la même connexion PHP ne se bloquerait jamais lui-même). Utilise
 * une VRAIE commande existante pour que handleStatusChange() aille bien
 * jusqu'à la tentative d'envoi si le verrou ne le bloque pas, et vérifie
 * via neria_log qu'AUCUNE trace n'apparaît pendant que le verrou est
 * tenu ailleurs — pour les deux déclencheurs.
 */
require_once __DIR__ . '/bootstrap.php';

function neria_test_532_check(
    \Db $db,
    string $prefix,
    \OrderTriggersManager $mgr,
    int $idOrder,
    string $template,
    \OrderState $newStatus,
    \OrderState $oldStatus,
    string $lockName
): string {
    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'Impossible d\'ouvrir une seconde connexion MySQL pour simuler un processus concurrent — jeu de test invalide');

    $logCountBefore = (int) $db->getValue(
        "SELECT COUNT(*) FROM {$prefix}neria_log WHERE template = '" . pSQL($template) . "' AND class = 'OrderTriggers'"
    );

    try {
        $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "', 2)");
        $row = mysqli_fetch_row($res);
        neria_assert((int) $row[0] === 1, "La seconde connexion n'a pas réussi à acquérir le verrou {$lockName} — jeu de test invalide");

        $start = microtime(true);
        $mgr->handleStatusChange($newStatus, $oldStatus, $idOrder);
        $elapsed = microtime(true) - $start;
        neria_assert($elapsed < 5.0, "handleStatusChange() ({$template}) a mis {$elapsed}s alors que le verrou est censé être non-bloquant (timeout 0)");

        $logCountAfter = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE template = '" . pSQL($template) . "' AND class = 'OrderTriggers'"
        );
        neria_assert(
            $logCountAfter === $logCountBefore,
            "handleStatusChange() a quand même journalisé une tentative d'envoi ({$template} : {$logCountBefore} -> {$logCountAfter}) alors que le verrou {$lockName} était détenu par un autre processus — régression du bug corrigé le 02/09/2026 (round 280) : un double déclenchement du hook actionOrderStatusPostUpdate pourrait de nouveau envoyer {$template} deux fois pour la même transition"
        );

        return 'ok';
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "')");
        mysqli_close($mysqli);
    }
}

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $order = $db->getRow(
        "SELECT o.id_order FROM {$prefix}orders o
         JOIN {$prefix}customer c ON c.id_customer = o.id_customer
         WHERE c.active = 1 AND c.deleted = 0"
    );
    neria_assert($order !== false, 'Aucune commande réelle disponible pour ce test — jeu de test invalide');
    $idOrder = (int) $order['id_order'];

    $mgr = new OrderTriggersManager(neria_test_module());

    // ── order_partial_shipped ───────────────────────────────────────
    $idFakeStatusPs = 990001 + random_int(1, 9999); // id de statut fictif, non standard (hors STANDARD_STATUS_IDS 1-13)
    $newStatusPs = new OrderState();
    $newStatusPs->id = $idFakeStatusPs;
    $newStatusPs->shipped = true;
    $newStatusPs->delivery = false;
    $newStatusPs->send_email = false;
    $newStatusPs->paid = false;
    $oldStatusPs = new OrderState();
    $oldStatusPs->id = 1;
    $oldStatusPs->shipped = false;
    $oldStatusPs->logable = false;

    $r1 = neria_test_532_check(
        $db, $prefix, $mgr, $idOrder, 'order_partial_shipped', $newStatusPs, $oldStatusPs,
        'neria_partial_shipped_' . $idOrder . '_' . $idFakeStatusPs
    );

    // ── order_on_hold ───────────────────────────────────────────────
    $idFakeStatusOh = 990001 + random_int(10000, 19999);
    $newStatusOh = new OrderState();
    $newStatusOh->id = $idFakeStatusOh;
    $newStatusOh->send_email = true;
    $newStatusOh->paid = false;
    $newStatusOh->shipped = false;
    $newStatusOh->delivery = false;
    $newStatusOh->name = ['1' => 'Test On Hold'];
    $oldStatusOh = new OrderState();
    $oldStatusOh->id = 1;
    $oldStatusOh->logable = false;

    $r2 = neria_test_532_check(
        $db, $prefix, $mgr, $idOrder, 'order_on_hold', $newStatusOh, $oldStatusOh,
        'neria_order_on_hold_' . $idOrder . '_' . $idFakeStatusOh
    );

    neria_assert($r1 === 'ok' && $r2 === 'ok', 'jeu de test invalide : un sous-test n\'a pas complété correctement');

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager::handleStatusChange() respecte bien un verrou GET_LOCK() déjà détenu par un processus concurrent pour order_partial_shipped ET order_on_hold : retourne immédiatement sans tenter d'envoi — bug corrigé le 02/09/2026 (round 280)",
    ];
}
