<?php
/**
 * Régression : `ManualSendManager::send()` (envoi manuel immédiat déclenché
 * depuis le formulaire BO, `neria.php` `neria_action=send_manual`) n'avait
 * aucune protection contre un double-clic ou une resoumission de formulaire
 * (bouton "précédent" du navigateur, retry réseau) — le formulaire
 * (`send.tpl`) n'a ni token CSRF/idempotency ni bouton désactivé au submit.
 * Chaque requête POST identique était traitée indépendamment par `send()`,
 * passant tous les garde-fous (bounce, blacklist, préférences, contexte
 * commande) puisqu'aucun d'eux n'est un verrou anti-doublon d'ENVOI. Le
 * seul filet existant (`CooldownManager::isDuplicate()`, "Mode Silence")
 * est désactivé par défaut ; `checkDuplicate()` est purement informatif
 * (retourne toujours `blocked=false`, simple bandeau JS avant le clic,
 * jamais rappelé au moment réel de l'envoi). Un double-clic pouvait donc
 * faire recevoir DEUX FOIS le même email au client.
 *
 * Bug identifié le 11/09/2026 (round 337, audit StatsManager/
 * ManualSendManager).
 *
 * Corrigé le 11/09/2026 (round 337) : `send()` acquiert désormais un
 * verrou MySQL nommé (GET_LOCK, timeout=0, best-effort) scopé par
 * email+template+orderRef AVANT de déléguer à `sendInner()` (l'ancien
 * corps de `send()`, renommé et inchangé) — une seconde requête identique
 * quasi simultanée échoue immédiatement (`msg.send_blocked_duplicate`) au
 * lieu de déclencher un second envoi réel.
 *
 * Test comportemental réel (2e connexion mysqli brute, même technique que
 * test_260/68/255) : détient le verrou scopé au même email+template+
 * orderRef que le test, vérifie que `send()` retourne bien le blocage
 * immédiatement (pas de blocage perceptible, verrou non-bloquant) SANS
 * jamais atteindre les garde-fous internes (email invalide passé
 * délibérément — si le verrou ne bloquait pas, l'erreur retournée serait
 * `msg.send_invalid_email`, pas `msg.send_blocked_duplicate`), PUIS
 * vérifie qu'un verrou pour une combinaison DIFFÉRENTE reste totalement
 * libre pendant ce temps (scoping correct).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $template = 'regtest693_template';
    $email    = 'not-a-real-email'; // volontairement invalide : si le verrou anti-doublon
                                     // ne bloquait pas, sendInner() renverrait
                                     // msg.send_invalid_email, pas le blocage attendu.
    $orderRef = 'RT693';
    $lockKey  = 'neria_manualsend_' . md5($email . '|' . $template . '|' . $orderRef);

    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'Impossible d\'ouvrir une seconde connexion MySQL pour simuler un envoi concurrent — jeu de test invalide');

    try {
        $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockKey) . "', 2)");
        $row = mysqli_fetch_row($res);
        neria_assert((int) $row[0] === 1, 'La seconde connexion MySQL n\'a pas pu obtenir le verrou — jeu de test invalide');

        // Un verrou pour une combinaison différente doit rester libre —
        // preuve que le scoping isole bien les envois distincts.
        $otherKey = 'neria_manualsend_' . md5('other@example.test|' . $template . '|' . $orderRef);
        $resOther = mysqli_query($mysqli, "SELECT IS_USED_LOCK('" . mysqli_real_escape_string($mysqli, $otherKey) . "')");
        $rowOther = mysqli_fetch_row($resOther);
        neria_assert($rowOther[0] === null, "le verrou d'une autre combinaison ({$otherKey}) n'est pas libre — jeu de test invalide");

        $mgr = new ManualSendManager(neria_test_module());
        $start = microtime(true);
        $result = $mgr->send($template, $email, $orderRef, 'Sujet de test', []);
        $elapsed = microtime(true) - $start;

        neria_assert(
            $elapsed < 5.0,
            "send() a mis {$elapsed}s alors qu'un verrou non-bloquant (timeout 0) est attendu — possible régression"
        );
        neria_assert(
            $result['ok'] === false,
            "send() a renvoyé ok=true alors qu'un envoi identique était déjà 'en cours' (verrou détenu par la 2e connexion) — régression du bug corrigé le 11/09/2026 (round 337)"
        );
        neria_assert(
            strpos($result['message'], 'déjà en cours de traitement') !== false
                || strpos($result['message'], 'already being processed') !== false,
            "send() n'a pas renvoyé le message de blocage anti-doublon attendu (message obtenu : '{$result['message']}') — régression du bug corrigé le 11/09/2026 (round 337) : le verrou GET_LOCK ne bloque plus l'envoi concurrent, sendInner() aurait été atteint directement (message d'email invalide attendu dans ce cas)"
        );

        // Le verrou de l'autre combinaison doit toujours être libre —
        // preuve que send() n'a jamais tenté de le toucher (scoping correct).
        $resOther2 = mysqli_query($mysqli, "SELECT IS_USED_LOCK('" . mysqli_real_escape_string($mysqli, $otherKey) . "')");
        $rowOther2 = mysqli_fetch_row($resOther2);
        neria_assert($rowOther2[0] === null, "le verrou d'une autre combinaison a été affecté par send() — régression de scoping");

        return [
            'pass'    => true,
            'message' => "ManualSendManager::send() acquiert désormais un verrou GET_LOCK anti-doublon (email+template+orderRef) avant de déléguer à sendInner() — une seconde requête identique quasi simultanée est bloquée au lieu de déclencher un second envoi réel — bug corrigé le 11/09/2026 (round 337)",
        ];
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($mysqli, $lockKey) . "')");
        mysqli_close($mysqli);
    }
}
