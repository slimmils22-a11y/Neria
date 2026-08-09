<?php
/**
 * Régression : ConfigManager::toggleBooleanKey() doit vérifier le retour de
 * GET_LOCK() et refuser la bascule si le verrou n'a pas été acquis.
 *
 * Bug réel corrigé le 09/08/2026 (round 141) : GET_LOCK(name, 3) renvoie 0
 * (timeout) ou NULL (erreur) sans jamais être testé — le code continuait à
 * lire-modifier-écrire la config comme si le verrou était acquis. Sous
 * contention réelle (deux clics rapprochés + charge DB dépassant 3s), la
 * protection anti-course documentée par le code n'était pas réellement
 * appliquée.
 *
 * Ce test tient le verrou depuis une SECONDE connexion MySQL brute (même
 * technique que test_68) pour forcer un vrai timeout de GET_LOCK() côté
 * ConfigManager, puis vérifie que toggleBooleanKey() ne bascule PAS l'état
 * en base pendant que le verrou est détenu ailleurs.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $db     = neria_test_db();
    $module = neria_test_module();

    $cfg     = new ConfigManager($module);
    $initial = $cfg->isSignatureEnabled();
    $lockName = 'neria_toggle_' . ConfigManager::KEY_SIGNATURE_ENABLED;

    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'Impossible d\'ouvrir une seconde connexion MySQL pour simuler un processus concurrent — jeu de test invalide');

    try {
        $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "', 2)");
        $row = mysqli_fetch_row($res);
        neria_assert((int) $row[0] === 1, "La seconde connexion n'a pas réussi à acquérir le verrou {$lockName} — jeu de test invalide");

        $result = $cfg->toggleSignatureEnabled();

        $finalDb = (bool) Configuration::get(ConfigManager::KEY_SIGNATURE_ENABLED);
        neria_assert(
            $finalDb === $initial,
            "toggleSignatureEnabled() a modifié l'état en base ({$initial} -> {$finalDb}) alors que le verrou était détenu par un autre processus (GET_LOCK aurait dû timeout et refuser la bascule) — régression du bug corrigé le 09/08/2026 (round 141) : le retour de GET_LOCK() n'est plus vérifié"
        );
        neria_assert(
            $result === $initial,
            "toggleSignatureEnabled() a renvoyé " . var_export($result, true) . " (bascule) au lieu de l'état inchangé " . var_export($initial, true) . " pendant que le verrou est détenu ailleurs"
        );
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "')");
        mysqli_close($mysqli);
        Configuration::updateValue(ConfigManager::KEY_SIGNATURE_ENABLED, (int) $initial);
    }

    return [
        'pass'    => true,
        'message' => "toggleBooleanKey() respecte bien l'échec de GET_LOCK() : aucune bascule n'est appliquée quand le verrou est détenu par un autre processus",
    ];
}
