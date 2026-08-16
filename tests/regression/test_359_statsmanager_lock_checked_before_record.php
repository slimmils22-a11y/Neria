<?php
/**
 * Régression : StatsManager::recordOpen()/recordClick()/recordConversion()
 * calculaient $gotLock (résultat de GET_LOCK()) mais ne le vérifiaient
 * JAMAIS avant d'exécuter la section protégée (eventExists() + record()) —
 * seul le finally s'en servait pour décider s'il fallait RELEASE_LOCK. Si
 * l'acquisition échouait (timeout de 2s atteint, verrou déjà tenu ailleurs
 * sous forte charge — rafale d'ouvertures/clics sur un même token), le code
 * continuait quand même SANS protection, exactement dans le scénario de
 * contention que ce verrou vise à couvrir : la garde anti-double-crédit de
 * points fidélité devenait un théâtre de sécurité qui ne protégeait plus
 * rien précisément quand c'était nécessaire.
 *
 * Corrigé le 16/08/2026 (round 178) : un échec d'acquisition du verrou
 * interrompt désormais explicitement l'exécution (log + return), plutôt
 * que de continuer sans protection.
 *
 * Test comportemental réel : ouvre une SECONDE connexion MySQL brute (hors
 * PrestaShop, GET_LOCK est par SESSION donc une connexion distincte est
 * nécessaire pour bloquer réellement le verrou), prend le verrou nommé
 * qu'utiliserait recordOpen() pour un token de test, puis appelle
 * recordOpen() — doit renoncer immédiatement (aucune ligne neria_stat
 * insérée) plutôt que de continuer sans protection.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    if (!defined('_DB_SERVER_') || !defined('_DB_USER_') || !defined('_DB_NAME_')) {
        return ['pass' => true, 'message' => 'Constantes de connexion DB non accessibles dans cet environnement — test ignoré (rien à vérifier)'];
    }

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    // Un token de test réel doit exister dans neria_stat (event 'sent')
    // pour que recordOpen() atteigne la section protégée par le verrou.
    $token = bin2hex(random_bytes(16));
    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, tracking_token, event_type, date_add)
         VALUES ({$idShop}, 'regtest359', 'fr', '" . pSQL($token) . "', 'sent', NOW())"
    );

    $secondConn = @mysqli_connect(
        defined('_DB_SERVER_') ? _DB_SERVER_ : 'localhost',
        _DB_USER_,
        defined('_DB_PASSWD_') ? _DB_PASSWD_ : '',
        _DB_NAME_
    );

    if (!$secondConn) {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'");
        return ['pass' => true, 'message' => 'Impossible d\'ouvrir une seconde connexion MySQL brute dans cet environnement — test ignoré (rien à vérifier)'];
    }

    $lockName = 'neria_open_' . md5($token);

    try {
        $held = mysqli_fetch_row(mysqli_query($secondConn, "SELECT GET_LOCK('" . mysqli_real_escape_string($secondConn, $lockName) . "', 2)"));
        neria_assert((int) $held[0] === 1, "jeu de test invalide : la seconde connexion n'a pas pu prendre le verrou de test");

        $mgr = new StatsManager($module);
        $mgr->recordOpen($token);

        $recorded = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "' AND event_type = 'open'"
        );

        neria_assert(
            $recorded === 0,
            "StatsManager::recordOpen() a enregistré une ouverture ({$recorded} ligne(s)) alors que le verrou nommé était déjà tenu par une autre connexion — régression du bug corrigé le 16/08/2026 (round 178) : \$gotLock n'est de nouveau jamais vérifié avant la section critique, la protection anti-double-crédit ne protège plus rien sous contention"
        );

        return [
            'pass'    => true,
            'message' => "StatsManager::recordOpen() renonce bien à enregistrer l'événement quand le verrou nommé ne peut pas être acquis — bug corrigé le 16/08/2026 (round 178)",
        ];
    } finally {
        mysqli_query($secondConn, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($secondConn, $lockName) . "')");
        mysqli_close($secondConn);
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'");
    }
}
