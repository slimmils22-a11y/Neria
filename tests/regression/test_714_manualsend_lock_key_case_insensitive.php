<?php
/**
 * Régression : ManualSendManager::send() doit calculer sa clé de verrou
 * anti-doublon (GET_LOCK, round 337) sur l'email NORMALISÉ en casse, pas
 * sur la saisie brute — le lookup SQL réel (findCustomer()) étant
 * insensible à la casse (collation MySQL par défaut).
 *
 * Bug identifié le 12/09/2026 (round 343, audit ManualSendManager) : la
 * clé de verrou était calculée via md5(trim($email) . ...) sans
 * strtolower(). Deux requêtes POST quasi simultanées avec des casses
 * différentes de la même adresse ("Test@x.com" vs "test@x.com")
 * obtenaient deux clés GET_LOCK distinctes et contournaient totalement le
 * verrou anti-doublon ajouté au round 337.
 *
 * Test comportemental réel : acquiert manuellement le verrou nommé
 * correspondant à un email en MAJUSCULES via la formule désormais utilisée
 * par send() (Tools::strtolower(trim($email))), puis appelle send() avec
 * la MÊME adresse en minuscules — si la clé est bien normalisée, l'appel
 * doit être bloqué comme un doublon (même verrou nommé déjà détenu),
 * prouvant que les deux casses produisent bien la même clé GET_LOCK.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $email    = 'test@x.com';
    $emailUC  = 'TEST@X.COM';
    $template = 'round343_lock_test_template';
    $orderRef = 'ROUND343-LOCKTEST';

    $lockKey = 'neria_manualsend_' . md5(Tools::strtolower(trim($emailUC)) . '|' . $template . '|' . $orderRef);

    // GET_LOCK() est propre à la CONNEXION MySQL — le réacquérir depuis la
    // même connexion (Db::getInstance(), utilisée aussi bien ici que par
    // ManualSendManager::send()) réussirait trivialement sans jamais tester
    // un vrai blocage. Une connexion mysqli séparée simule un processus
    // concurrent réel — même pattern que test_260 (verrou CalendarManager).
    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'jeu de test invalide : connexion mysqli séparée impossible');
    $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockKey) . "', 2)");
    $row = $res ? mysqli_fetch_row($res) : null;
    neria_assert($row && (int) $row[0] === 1, "jeu de test invalide : impossible d'acquérir le verrou de test sur la connexion séparée");

    try {
        $mgr = new ManualSendManager(neria_test_module());
        $result = $mgr->send($template, $email, $orderRef, 'Sujet de test', []);

        neria_assert(
            $result['ok'] === false && ($result['message'] ?? '') === AdminTranslator::t('msg.send_blocked_duplicate'),
            "ManualSendManager::send() n'est plus bloqué par un verrou déjà détenu sous une casse d'email différente (obtenu : " . json_encode($result) . ") — régression du bug corrigé le 12/09/2026 (round 343) : le verrou anti-doublon round 337 pourrait de nouveau être contourné par une simple différence de casse"
        );

        return [
            'pass'    => true,
            'message' => "ManualSendManager::send() calcule bien sa clé de verrou anti-doublon sur l'email normalisé en casse — deux requêtes différant seulement par la casse de l'adresse partagent désormais le même verrou GET_LOCK, bug corrigé le 12/09/2026 (round 343)",
        ];
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($mysqli, $lockKey) . "')");
        mysqli_close($mysqli);
    }
}
