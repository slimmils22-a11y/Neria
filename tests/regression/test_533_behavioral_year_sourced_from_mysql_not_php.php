<?php
/**
 * Régression : `BehavioralCronManager::sendBirthdays()` et
 * `sendRelationshipAnniversaries()` détectent le jour du déclenchement
 * CÔTÉ SQL (`DAY(NOW())`/`MONTH(NOW())` ou `DATE_FORMAT(NOW(),'%m-%d')`),
 * mais calculaient l'année de déduplication (`ref_id`) CÔTÉ PHP
 * (`(int) date('Y')`) — deux horloges indépendantes. Si PHP et la
 * session MySQL ne partagent pas le même fuseau horaire (ex. PHP en
 * Europe/Paris, MySQL en UTC — aucun `date_default_timezone_set()`
 * n'existe dans le module, PHP hérite du fuseau `php.ini`/OS du
 * serveur), un client dont la date pivot (anniversaire, 1re commande)
 * tombe le 31/12 ou le 01/01 pouvait voir le jour détecté par MySQL
 * diverger de l'année enregistrée par PHP autour du nouvel an — cassant
 * la déduplication (email envoyé deux fois, ou jamais, une année sur
 * l'autre selon l'heure exacte du déclenchement).
 *
 * `generateBirthdayVoucher()` avait le même défaut de façon indépendante
 * (sa propre ligne `(int) date('Y')`, non partagée avec l'appelant).
 *
 * Bug identifié le 02/09/2026 (round 281, audit "cohérence des fuseaux
 * horaires dans les fenêtres due").
 *
 * Corrigé le 02/09/2026 (round 281) : les 3 emplacements utilisent
 * désormais `(int) $this->db->getValue('SELECT YEAR(NOW())')` — la même
 * horloge MySQL que le test du jour — au lieu de PHP `date('Y')`.
 * `generateBirthdayVoucher()` reçoit ce même millésime en paramètre
 * depuis `sendBirthdays()` (au lieu de le recalculer indépendamment),
 * avec repli sur `YEAR(NOW())` (pas `date('Y')`) si appelée sans année
 * explicite.
 *
 * Test structurel : reproduire un vrai décalage de fuseau horaire PHP/
 * MySQL nécessiterait de reconfigurer l'un des deux moteurs pour ce seul
 * test (risque d'effet de bord sur les autres tests de la suite qui
 * tournent dans le même process/session) — vérifie la présence du
 * sourcing MySQL et l'absence de `(int) date('Y')` dans les 3
 * emplacements concernés.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    // sendBirthdays()
    $posBday = strpos($src, 'private function sendBirthdays(): void');
    neria_assert($posBday !== false, 'sendBirthdays() introuvable — jeu de test invalide');
    $posBdayYear = strpos($src, '$year   = ', $posBday);
    neria_assert($posBdayYear !== false && $posBdayYear - $posBday < 2500, 'jeu de test invalide : affectation $year introuvable dans sendBirthdays()');
    $bodyBday = substr($src, $posBdayYear, 200);
    neria_assert(
        strpos($bodyBday, "SELECT YEAR(NOW())") !== false,
        "BehavioralCronManager::sendBirthdays() ne source plus l'année via SELECT YEAR(NOW()) — régression du bug corrigé le 02/09/2026 (round 281) : un client né le 31/12 ou le 01/01 pourrait de nouveau recevoir son email d'anniversaire deux fois (ou jamais) selon le décalage de fuseau horaire entre PHP et MySQL"
    );
    neria_assert(
        strpos($bodyBday, "(int) date('Y')") === false,
        "BehavioralCronManager::sendBirthdays() calcule de nouveau l'année via PHP date('Y') — régression du bug corrigé le 02/09/2026 (round 281)"
    );

    // generateBirthdayVoucher()
    neria_assert(
        strpos($src, 'private function generateBirthdayVoucher(int $idCustomer, \ConfigManager $config, int $idShop, ?int $year = null): string') !== false,
        "BehavioralCronManager::generateBirthdayVoucher() n'a plus le paramètre \$year optionnel attendu — régression du bug corrigé le 02/09/2026 (round 281)"
    );
    neria_assert(
        strpos($src, '$year = $year ?? (int) $this->db->getValue(\'SELECT YEAR(NOW())\');') !== false,
        "BehavioralCronManager::generateBirthdayVoucher() ne source plus son repli d'année via SELECT YEAR(NOW()) — régression du bug corrigé le 02/09/2026 (round 281)"
    );

    // sendRelationshipAnniversaries()
    $posRa = strpos($src, 'private function sendRelationshipAnniversaries(');
    neria_assert($posRa !== false, 'sendRelationshipAnniversaries() introuvable — jeu de test invalide');
    $posRaYear = strpos($src, '$currentYear = ', $posRa);
    neria_assert($posRaYear !== false && $posRaYear - $posRa < 3000, 'jeu de test invalide : affectation $currentYear introuvable dans sendRelationshipAnniversaries()');
    $bodyRa = substr($src, $posRaYear, 200);
    neria_assert(
        strpos($bodyRa, 'SELECT YEAR(NOW())') !== false,
        "BehavioralCronManager::sendRelationshipAnniversaries() ne source plus \$currentYear via SELECT YEAR(NOW()) — régression du bug corrigé le 02/09/2026 (round 281)"
    );

    $posSendCall = strpos($src, "'relationship_anniversary',\n                    \$r,\n                    ['{years_label}' => \$yearsLabel],");
    neria_assert($posSendCall !== false, "jeu de test invalide : appel send('relationship_anniversary', ...) introuvable");
    $bodySendCall = substr($src, $posSendCall, 200);
    neria_assert(
        strpos($bodySendCall, '$currentYear') !== false && strpos($bodySendCall, "(int) date('Y')") === false,
        "BehavioralCronManager::sendRelationshipAnniversaries() recalcule de nouveau l'année via PHP date('Y') au moment de l'envoi (au lieu de réutiliser \$currentYear sourcé de MySQL) — régression du bug corrigé le 02/09/2026 (round 281)"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager source désormais l'année de déduplication via MySQL (YEAR(NOW())) plutôt que PHP date('Y') dans sendBirthdays()/generateBirthdayVoucher()/sendRelationshipAnniversaries() — bug corrigé le 02/09/2026 (round 281)",
    ];
}
