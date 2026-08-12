<?php
/**
 * Régression : 2 bugs de MonthlyReportManager::deliverReport() corrigés le
 * 09/08/2026 (round 157) :
 * - Un seul destinataire en échec (parmi plusieurs) faisait échouer
 *   sendReport() dans son ensemble, empêchant markSent() — isDue() restait
 *   vrai pendant toute la fenêtre de rattrapage (1er au 7 du mois),
 *   régénérant et renvoyant le rapport EN ENTIER à TOUS les destinataires
 *   (y compris ceux déjà servis correctement) à chaque chargement de page.
 *   Corrigé : deliverReport() retourne désormais true dès qu'AU MOINS UN
 *   envoi a réussi ($anySent), pas seulement si TOUS ont réussi.
 * - deliverReport() écrit le rendu compilé sur un chemin de fichier fixe
 *   et partagé (mails/{iso}/monthly_report.html), sans protection contre
 *   un envoi automatique (checkAndSend(), verrouillé) et un envoi manuel
 *   BO (send_report_now, PAS verrouillé) concurrents — risque de fuite de
 *   données entre boutiques sur une install multi-boutiques. Corrigé par
 *   un GET_LOCK('neria_monthly_report_deliver', 5) global englobant
 *   deliverReport().
 *
 * Test comportemental réel (verrou) : une seconde connexion MySQL brute
 * (mysqli, même technique que test_68) détient le verrou
 * neria_monthly_report_deliver pendant l'appel à sendReport() — vérifie
 * que l'appel se termine rapidement (pas de blocage indéfini au-delà du
 * timeout de 5s) et retourne false plutôt que d'écraser le fichier partagé
 * en cours d'utilisation par le détenteur du verrou.
 *
 * Test structurel ($anySent) : vérifie la présence du code qui distingue
 * "au moins un envoi réussi" de "tous les envois réussis" pour la décision
 * de markSent().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Partie 1 : structurel — $anySent pilote bien le retour ──────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php');
    neria_assert($src !== false, 'Impossible de lire MonthlyReportManager.php');
    neria_assert(
        strpos($src, '$anySent = true;') !== false && strpos($src, 'return $anySent;') !== false,
        "MonthlyReportManager::deliverReportLocked() ne distingue plus \$anySent de \$ok — régression du bug corrigé le 09/08/2026 (round 157) : un seul destinataire en échec ferait de nouveau échouer markSent() pour tout le mois"
    );

    // ── Partie 2 : comportemental réel — GET_LOCK() respecté ────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';

    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'Impossible d\'ouvrir une seconde connexion MySQL pour simuler un processus concurrent — jeu de test invalide');

    try {
        $lockName = 'neria_monthly_report_deliver';
        $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "', 2)");
        $row = mysqli_fetch_row($res);
        neria_assert((int) $row[0] === 1, 'La seconde connexion MySQL n\'a pas pu obtenir le verrou — jeu de test invalide');

        $rm = new MonthlyReportManager(neria_test_module());
        $prev  = new DateTime('first day of last month');
        $start = microtime(true);
        $ok    = $rm->sendReport((int) $prev->format('Y'), (int) $prev->format('n'));
        $elapsed = microtime(true) - $start;

        neria_assert(
            $elapsed < 8.0,
            "sendReport() a mis {$elapsed}s alors qu'un timeout de 5s est attendu sur GET_LOCK('neria_monthly_report_deliver', 5) — possible régression du timeout"
        );
        neria_assert(
            $ok === false,
            "sendReport() n'a pas retourné false alors que neria_monthly_report_deliver était déjà détenu par un processus concurrent — le verrou ne protège plus le fichier de rendu partagé, risque de fuite de données entre boutiques"
        );
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('neria_monthly_report_deliver')");
        mysqli_close($mysqli);
    }

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::deliverReport() respecte le verrou global (fichier de rendu partagé protégé) et markSent() ne dépend plus d'un succès à 100% — bugs corrigés le 09/08/2026 (round 157)",
    ];
}
