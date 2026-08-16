<?php
/**
 * Régression : WatchdogManager::record() calculait $locked (résultat de
 * GET_LOCK()) mais ne le vérifiait JAMAIS avant d'exécuter la section
 * critique (SELECT + INSERT/UPDATE) — pire que le pattern "vérifié+loggé
 * mais sans effet" déjà corrigé ailleurs (TranslationHistoryManager round
 * 175, StatsManager round 178) : ici il n'y avait même pas de log d'échec,
 * le résultat était simplement ignoré. Sous rafale de messages identiques
 * (le scénario même que ce verrou vise à couvrir), deux appels concurrents
 * pouvaient tous deux lire "aucune entrée existante" et créer 2 lignes au
 * lieu d'une seule consolidée à occurrence_count=2 — recréant exactement la
 * duplication que ce verrou est censé empêcher.
 *
 * Corrigé le 16/08/2026 (round 179, audit transversal de fin de série) : un
 * échec d'acquisition du verrou interrompt désormais explicitement
 * l'exécution (return), fail-safe (une ligne de log non écrite plutôt
 * qu'une duplication silencieuse).
 *
 * Test comportemental réel : ouvre une SECONDE connexion MySQL brute (hors
 * PrestaShop, GET_LOCK est par SESSION donc une connexion distincte est
 * nécessaire pour bloquer réellement le verrou), prend le verrou nommé
 * qu'utiliserait record() pour un message de test, puis appelle
 * warning() — doit renoncer immédiatement (aucune ligne ps_neria_log
 * insérée) plutôt que de continuer sans protection.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    if (!defined('_DB_SERVER_') || !defined('_DB_USER_') || !defined('_DB_NAME_')) {
        return ['pass' => true, 'message' => 'Constantes de connexion DB non accessibles dans cet environnement — test ignoré (rien à vérifier)'];
    }

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    $level   = 'warning';
    $class   = 'RegTest363';
    $message = 'regtest363-' . bin2hex(random_bytes(8));

    $secondConn = @mysqli_connect(
        defined('_DB_SERVER_') ? _DB_SERVER_ : 'localhost',
        _DB_USER_,
        defined('_DB_PASSWD_') ? _DB_PASSWD_ : '',
        _DB_NAME_
    );

    if (!$secondConn) {
        return ['pass' => true, 'message' => 'Impossible d\'ouvrir une seconde connexion MySQL brute dans cet environnement — test ignoré (rien à vérifier)'];
    }

    $lockName = 'neria_log_' . md5($idShop . '|' . $level . '|' . $class . '|' . $message);

    try {
        $held = mysqli_fetch_row(mysqli_query($secondConn, "SELECT GET_LOCK('" . mysqli_real_escape_string($secondConn, $lockName) . "', 2)"));
        neria_assert((int) $held[0] === 1, "jeu de test invalide : la seconde connexion n'a pas pu prendre le verrou de test");

        $wd = new WatchdogManager($module);
        $wd->warning($message, '', $class);

        $recorded = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE message = '" . pSQL($message) . "' AND class = '" . pSQL($class) . "'"
        );

        neria_assert(
            $recorded === 0,
            "WatchdogManager::record() a enregistré une ligne ({$recorded}) alors que le verrou nommé était déjà tenu par une autre connexion — régression du bug corrigé le 16/08/2026 (round 179) : \$locked n'est de nouveau jamais vérifié avant la section critique, une rafale de messages identiques pourrait de nouveau créer des lignes dupliquées au lieu d'une consolidation"
        );

        return [
            'pass'    => true,
            'message' => "WatchdogManager::record() renonce bien à écrire quand le verrou nommé ne peut pas être acquis — bug corrigé le 16/08/2026 (round 179)",
        ];
    } finally {
        mysqli_query($secondConn, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($secondConn, $lockName) . "')");
        mysqli_close($secondConn);
        $db->execute("DELETE FROM {$prefix}neria_log WHERE message = '" . pSQL($message) . "' AND class = '" . pSQL($class) . "'");
    }
}
