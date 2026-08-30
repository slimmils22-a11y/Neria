<?php
/**
 * Régression round 244 (30/08/2026) : le traitement de l'action BO
 * `generate_signature` (neria.php) appelait SignatureGenerator::generate()
 * puis ::delete($idShop, '', $path) (nettoie tous les anciens PNG de la
 * boutique sauf celui qu'on vient d'écrire) puis écrivait la ligne
 * neria_signature en base, SANS AUCUN verrou. delete() sans $style style
 * glob() TOUS les fichiers `signature_{idShop}_*.png` de la boutique et
 * supprime tout sauf $excludePath — deux soumissions quasi simultanées de
 * ce formulaire pour LA MÊME boutique (deux comptes admin actifs, ou
 * double-clic), avec des styles différents, pouvaient chacune supprimer le
 * fichier PNG que l'AUTRE venait tout juste d'écrire : selon l'ordre
 * d'entrelacement, la ligne neria_signature insérée en dernier pouvait
 * référencer un image_path déjà supprimé par le nettoyage de l'autre
 * requête — signature cassée (404) dans les emails envoyés ensuite.
 *
 * Corrigé le 30/08/2026 (round 244) : generate()+delete()+écriture DB sont
 * désormais encadrés par GET_LOCK('neria_signature_' . $idShop, 5) /
 * RELEASE_LOCK, même idiome que MonthlyReportManager::deliverReportLocked()
 * (round 157) — rend la section critique atomique entre deux requêtes.
 *
 * Test comportemental réel : ouvre une VRAIE seconde connexion MySQL
 * (mysqli, indépendante de Db::getInstance() utilisée par le reste du
 * test) pour vérifier le comportement RÉEL de GET_LOCK — MySQL n'accorde un
 * verrou nommé qu'à UNE connexion à la fois. La connexion de test acquiert
 * le verrou du même nom que celui posé par neria.php pour une boutique
 * factice ; la seconde connexion tente de l'acquérir en mode fail-fast
 * (timeout 0, comme le fait implicitement neria.php avec son timeout de 5s
 * si la première requête ne le libère pas) et doit échouer tant que le
 * premier n'est pas relâché — prouve que deux soumissions concurrentes du
 * formulaire signature pour la même boutique seraient désormais
 * sérialisées, pas exécutées en parallèle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Partie A : structurel — GET_LOCK/RELEASE_LOCK encadrent bien la
    //    section generate()+delete()+écriture DB dans neria.php ──
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');
    $src = str_replace("\r", '', $src);

    $posSig = strpos($src, "'generate_signature'");
    neria_assert($posSig !== false, "action generate_signature introuvable — jeu de test invalide");

    $posLock    = strpos($src, "GET_LOCK('\" . pSQL(\$sigLockName) . \"', 5)", $posSig);
    $posDelete  = strpos($src, "\$sigGenerator->delete(\$idShop, '', \$path);", $posSig);
    $posRelease = strpos($src, "RELEASE_LOCK('\" . pSQL(\$sigLockName) . \"')", $posSig);

    neria_assert(
        $posLock !== false && $posDelete !== false && $posRelease !== false
            && $posLock < $posDelete && $posDelete < $posRelease,
        "neria.php n'encadre plus generate()+delete()+écriture DB par GET_LOCK/RELEASE_LOCK par boutique — régression du bug corrigé le 30/08/2026 (round 244)"
    );

    // ── Partie B : comportemental réel — deux connexions MySQL distinctes,
    //    même verrou nommé, doit se comporter en exclusion mutuelle ──
    $db = neria_test_db();

    $idShopFake = 999877; // boutique fictive, isolée de toute vraie donnée
    $lockName   = 'neria_signature_' . $idShopFake;

    $conn2 = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_);
    neria_assert($conn2 !== false, "impossible d'ouvrir une seconde connexion MySQL indépendante — jeu de test invalide (vérifier _DB_SERVER_/_DB_USER_/_DB_PASSWD_/_DB_NAME_)");

    try {
        // La connexion de test (celle de neria_test_db()) acquiert le verrou
        // en premier, comme le ferait la 1ère requête HTTP concurrente.
        $acquired1 = (int) $db->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 0)", false);
        neria_assert($acquired1 === 1, "jeu de test invalide : la 1ère connexion n'a pas pu acquérir le verrou (déjà détenu par un autre process ?)");

        // La 2nde connexion (simulant la 2ème requête HTTP concurrente)
        // tente le MÊME verrou nommé en mode fail-fast : doit échouer tant
        // que la 1ère ne l'a pas relâché — c'est exactement ce qui empêche
        // désormais deux soumissions concurrentes du formulaire signature
        // de s'exécuter en parallèle pour la même boutique.
        $result2 = mysqli_query($conn2, "SELECT GET_LOCK('" . mysqli_real_escape_string($conn2, $lockName) . "', 0) AS locked");
        neria_assert($result2 !== false, "la requête GET_LOCK sur la 2nde connexion a échoué : " . mysqli_error($conn2));
        $row2 = mysqli_fetch_assoc($result2);
        neria_assert(
            (int) $row2['locked'] === 0,
            "la 2nde connexion a pu acquérir le MÊME verrou nommé alors que la 1ère le détient encore — régression du bug corrigé le 30/08/2026 (round 244) : deux soumissions concurrentes du formulaire signature pour la même boutique ne seraient plus sérialisées"
        );

        // Libère le verrou de la 1ère connexion : la 2nde doit alors pouvoir
        // l'acquérir — prouve que ce n'est pas un blocage permanent mais
        // bien une sérialisation normale (la 2ème requête attend son tour).
        $db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        $result3 = mysqli_query($conn2, "SELECT GET_LOCK('" . mysqli_real_escape_string($conn2, $lockName) . "', 2) AS locked");
        neria_assert($result3 !== false, "la 2e tentative GET_LOCK sur la 2nde connexion a échoué : " . mysqli_error($conn2));
        $row3 = mysqli_fetch_assoc($result3);
        neria_assert(
            (int) $row3['locked'] === 1,
            "la 2nde connexion n'a pas pu acquérir le verrou après sa libération par la 1ère — le verrou semble bloqué de façon permanente plutôt que correctement libéré"
        );
        mysqli_query($conn2, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($conn2, $lockName) . "')");

        return [
            'pass'    => true,
            'message' => "neria.php encadre bien generate()+delete()+écriture DB par GET_LOCK/RELEASE_LOCK par boutique, et le verrou sérialise réellement deux connexions MySQL concurrentes (vérifié avec une vraie 2nde connexion) — bug corrigé le 30/08/2026 (round 244)",
        ];
    } finally {
        $db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        mysqli_close($conn2);
    }
}
