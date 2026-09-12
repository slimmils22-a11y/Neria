<?php
/**
 * Régression : le shutdown handler de NeriaErrorHandler doit basculer sur
 * l'INSERT brut de secours si WatchdogManager::critical() n'a en réalité
 * RIEN écrit (fail-safe silencieux sous contention GET_LOCK, round 179),
 * même s'il n'a levé aucune exception.
 *
 * Bug identifié le 12/09/2026 (round 345, audit ConfigManager/
 * NeriaErrorHandler) : depuis le round 335, le shutdown handler tente
 * WatchdogManager::critical() puis retourne INCONDITIONNELLEMENT dès
 * qu'aucune exception n'est levée — mais WatchdogManager::record()
 * renonce SILENCIEUSEMENT (return, sans exception) si GET_LOCK() échoue
 * sous contention (round 179, fail-safe documenté). "Pas d'exception" ne
 * prouve donc pas "écrit avec succès". Précisément pendant une rafale de
 * fatals PHP identiques — le scénario même que la déduplication round 335
 * visait à couvrir — le fatal pouvait n'être journalisé NULLE PART : ni
 * dans neria_log (record() a renoncé), ni dans le repli brut (jamais
 * atteint, le handler croyait le critical() réussi).
 *
 * Corrigé le 12/09/2026 (round 345) : après critical(), une vérification
 * SELECT confirme qu'une ligne récente (date_add rafraîchi à chaque
 * occurrence consolidée, round 189) existe réellement avant de renoncer
 * au repli — sinon, le handler continue vers l'INSERT brut.
 *
 * Test comportemental réel (2 volets) :
 * 1. Reproduit le fail-safe documenté round 179 : verrouille le NOM exact
 *    que WatchdogManager::record() utiliserait pour CE message, depuis une
 *    connexion mysqli séparée (GET_LOCK est propre à la connexion — round
 *    343), puis appelle critical() et vérifie qu'AUCUNE ligne n'a été
 *    écrite — confirme la prémisse exacte du bug.
 * 2. Vérifie que la requête de détection ajoutée par le correctif (même
 *    littéral SQL) ne trouverait donc rien dans ce scénario précis — le
 *    handler basculerait bien vers le repli (le shutdown handler réel
 *    n'est déclenché qu'à la fin du process PHP — register_shutdown_
 *    function() — impraticable à observer de bout en bout, même limite
 *    déjà acceptée pour ce fichier, cf. test_685/test_479).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $class  = 'NeriaErrorHandler';
    $message = 'Round 345 test fatal message ' . uniqid();

    // Même formule EXACTE que WatchdogManager::record() pour ce message.
    $lockName = 'neria_log_' . md5($idShop . '|critical|' . $class . '|' . $message);

    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'jeu de test invalide : connexion mysqli séparée impossible');
    $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "', 2)");
    $row = $res ? mysqli_fetch_row($res) : null;
    neria_assert($row && (int) $row[0] === 1, "jeu de test invalide : impossible d'acquérir le verrou de test sur la connexion séparée");

    try {
        // Volet 1 : critical() ne doit RIEN écrire sous contention (fail-safe round 179).
        $countBefore = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE id_shop = {$idShop} AND class = '{$class}' AND message = '" . pSQL($message) . "'"
        );
        (new WatchdogManager($module))->critical($message, '', $class);
        $countAfter = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE id_shop = {$idShop} AND class = '{$class}' AND message = '" . pSQL($message) . "'"
        );
        neria_assert(
            $countAfter === $countBefore,
            "jeu de test invalide : WatchdogManager::critical() a écrit une ligne malgré le verrou détenu par la connexion séparée — le fail-safe round 179 lui-même serait cassé (pas seulement la détection round 345)"
        );

        // Volet 2 : la requête de détection du correctif round 345 ne
        // trouve donc bien AUCUNE ligne récente dans ce scénario précis —
        // le shutdown handler réel basculerait vers le repli.
        $written = (bool) $db->getValue(
            "SELECT 1 FROM {$prefix}neria_log
             WHERE id_shop = {$idShop} AND level = 'critical' AND class = '{$class}'
               AND message = '" . pSQL($message) . "'
               AND date_add > DATE_SUB(NOW(), INTERVAL 5 SECOND)",
            false
        );
        neria_assert(
            $written === false,
            "la requête de détection round 345 trouve à tort une ligne alors qu'aucune n'a été écrite — jeu de test invalide"
        );

        // Vérification structurelle que ce mécanisme est bien celui utilisé
        // par le shutdown handler réel (shutdown non observable de bout en
        // bout, cf. test_685/test_479).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/NeriaErrorHandler.php');
        neria_assert($src !== false, 'Impossible de lire src/NeriaErrorHandler.php');
        neria_assert(
            strpos($src, "AND `date_add` > DATE_SUB(NOW(), INTERVAL 5 SECOND)") !== false
                && strpos($src, 'if ($written) {') !== false,
            "NeriaErrorHandler ne vérifie plus qu'une ligne récente existe réellement après critical() — régression du bug corrigé le 12/09/2026 (round 345) : un fatal pourrait de nouveau n'être journalisé nulle part sous contention GET_LOCK"
        );

        return [
            'pass'    => true,
            'message' => "Confirmé : WatchdogManager::critical() renonce bien silencieusement sous contention GET_LOCK (fail-safe round 179) sans écrire ni lever d'exception, et NeriaErrorHandler détecte désormais ce cas pour basculer vers son repli — bug corrigé le 12/09/2026 (round 345)",
        ];
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "')");
        mysqli_close($mysqli);
        $db->execute("DELETE FROM {$prefix}neria_log WHERE id_shop = {$idShop} AND class = '{$class}' AND message = '" . pSQL($message) . "'");
    }
}
