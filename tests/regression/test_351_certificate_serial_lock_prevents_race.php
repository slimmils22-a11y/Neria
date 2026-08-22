<?php
/**
 * Régression : CertificateManager::issue() résolvait le numéro de série
 * (generateSerial() + serialExists() avec retry) sans aucun verrou —
 * fenêtre TOCTOU entre la lecture (serialExists()) et l'écriture (INSERT
 * plus bas) : deux émissions passant serialExists() au même instant pour le
 * même numéro se heurtaient ensuite toutes deux à la contrainte UNIQUE en
 * DB (pas de doublon réel), mais l'une des deux échouait visiblement pour
 * un certificat pourtant légitime, malgré le retry à offset croissant déjà
 * en place.
 *
 * Corrigé le 15/08/2026 (round 177) : un verrou nommé MySQL (GET_LOCK,
 * même pattern que TranslationHistoryManager::record()) sérialise
 * désormais toute la résolution+réservation du numéro de série jusqu'à
 * l'INSERT inclus.
 *
 * Test structurel : vérifie la présence du GET_LOCK/RELEASE_LOCK autour du
 * bloc de résolution+réservation du numéro de série dans issue().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CertificateManager.php');

    $posIssue = strpos($src, 'public function issue(');
    neria_assert($posIssue !== false, "Méthode issue() introuvable — jeu de test invalide");
    $issueBody = substr($src, $posIssue, 9000);

    neria_assert(
        strpos($issueBody, "GET_LOCK('") !== false && strpos($issueBody, "RELEASE_LOCK('") !== false,
        "CertificateManager::issue() n'utilise plus de verrou nommé MySQL (GET_LOCK/RELEASE_LOCK) autour de la résolution du numéro de série — régression du bug corrigé le 15/08/2026 (round 177) : la fenêtre TOCTOU entre serialExists() et l'INSERT redeviendrait exploitable sous forte concurrence, provoquant un échec d'émission visible pour un certificat pourtant légitime"
    );

    $posLock = strpos($issueBody, "GET_LOCK(");
    $posInsert = strpos($issueBody, '$inserted = $this->db->insert(self::TABLE');
    $posRelease = strpos($issueBody, "RELEASE_LOCK(");
    neria_assert(
        $posLock !== false && $posInsert !== false && $posRelease !== false && $posLock < $posInsert && $posInsert < $posRelease,
        "L'ordre GET_LOCK → INSERT → RELEASE_LOCK n'est plus respecté dans issue() — le verrou ne couvrirait plus la fenêtre TOCTOU complète jusqu'à l'écriture en base"
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager::issue() sérialise bien la résolution+réservation du numéro de série via un verrou nommé MySQL jusqu'à l'INSERT — bug corrigé le 15/08/2026 (round 177)",
    ];
}
