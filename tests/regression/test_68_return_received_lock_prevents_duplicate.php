<?php
/**
 * Régression : OrderTriggersManager::handleReturn() doit poser un verrou
 * GET_LOCK('neria_return_' . id, 0) avant traitement — même correctif que
 * handleRefund() (verrouillé par avoir) — et le libérer en finally.
 *
 * Bug réel corrigé le 06/08/2026 (round 65, piste identifiée le 05/08/2026
 * round 54) : rien n'empêchait un double déclenchement du hook
 * actionObjectOrderReturnAddAfter (rejeu, module tiers, double dispatch
 * PrestaShop) de renvoyer deux fois l'email return_received pour LE MÊME
 * retour. Le scope Mode Silence ({id_order}/{cooldown_scope}, round 63)
 * atténue le risque mais reste un contrôle non-atomique (lecture puis
 * écriture), insuffisant contre deux appels quasi simultanés.
 *
 * Ce test simule un "autre processus" tenant déjà le verrou via une SECONDE
 * connexion MySQL brute (mysqli) — Db::getInstance() étant un singleton
 * partagé, un GET_LOCK() posé depuis la même connexion PHP ne se
 * bloquerait jamais lui-même (ré-acquisition idempotente dans la même
 * session MySQL), ce qui ne prouverait rien. Utilise une VRAIE commande
 * existante (pour que handleReturn() aille bien jusqu'à la tentative
 * d'envoi si le verrou ne le bloque pas) et vérifie via neria_log qu'AUCUNE
 * trace 'return_received'/'OrderTriggers' n'apparaît pendant que le verrou
 * est tenu ailleurs.
 */
require_once __DIR__ . '/bootstrap.php';

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

    $idFakeReturn = 900000 + random_int(1, 99999); // id de retour fictif, isolé des vraies données
    $lockName     = 'neria_return_' . $idFakeReturn;

    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'Impossible d\'ouvrir une seconde connexion MySQL pour simuler un processus concurrent — jeu de test invalide');

    $logCountBefore = (int) $db->getValue(
        "SELECT COUNT(*) FROM {$prefix}neria_log WHERE template = 'return_received' AND class = 'OrderTriggers'"
    );

    try {
        $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "', 2)");
        $row = mysqli_fetch_row($res);
        neria_assert((int) $row[0] === 1, 'La seconde connexion n\'a pas réussi à acquérir le verrou — jeu de test invalide');

        $mgr = new OrderTriggersManager(neria_test_module());
        $fakeReturn = new OrderReturn();
        $fakeReturn->id = $idFakeReturn;
        $fakeReturn->id_order = $idOrder; // vraie commande — pour que handleReturn() aille jusqu'à Mail::Send() si le verrou ne le bloque pas

        $start = microtime(true);
        $mgr->handleReturn($fakeReturn);
        $elapsed = microtime(true) - $start;
        neria_assert($elapsed < 5.0, "handleReturn() a mis {$elapsed}s alors que le verrou est censé être non-bloquant (timeout 0) — possible régression du timeout GET_LOCK");

        $logCountAfter = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE template = 'return_received' AND class = 'OrderTriggers'"
        );
        neria_assert(
            $logCountAfter === $logCountBefore,
            "handleReturn() a quand même journalisé une tentative d'envoi ({$logCountBefore} -> {$logCountAfter}) alors que le verrou était détenu par un autre processus — régression du bug corrigé le 06/08/2026 (round 65) : handleReturn() ne respecte plus (ou ne pose plus) le verrou anti-doublon, un double déclenchement du hook pourrait de nouveau envoyer return_received deux fois pour le même retour"
        );

        $res2 = mysqli_query($mysqli, "SELECT IS_USED_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "')");
        $row2 = mysqli_fetch_row($res2);
        neria_assert(
            $row2[0] !== null,
            "Le verrou neria_return_{$idFakeReturn} n'est plus détenu par la connexion concurrente après l'appel à handleReturn() — handleReturn() aurait libéré un verrou qu'il ne détient pas"
        );

        return [
            'pass'    => true,
            'message' => "handleReturn() respecte bien le verrou GET_LOCK('neria_return_' . id) déjà détenu par un processus concurrent : retourne immédiatement sans tenter d'envoi ni toucher au verrou",
        ];
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "')");
        mysqli_close($mysqli);
    }
}
