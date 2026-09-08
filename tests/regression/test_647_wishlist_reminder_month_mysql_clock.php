<?php
/**
 * Régression : BehavioralCronManager::sendWishlistReminders() sourçait le
 * ref_id de déduplication mensuelle (année*100+mois) via `date('Y')` /
 * `date('n')` (horloge/fuseau PHP), au lieu de MySQL — même piège déjà
 * identifié et corrigé pour sendBirthdays() (round 281),
 * sendRelationshipAnniversaries() et sendWinBacks() (round 305), jamais
 * étendu ici malgré le même schéma de dédup par ref_id calendaire.
 *
 * Scénario concret : autour d'un changement de mois (ou d'année), si le
 * serveur PHP tourne dans un fuseau horaire différent de la session MySQL
 * (fréquent en hébergement mutualisé), date('Y')/date('n') (PHP) peuvent
 * diverger de la date réellement retenue côté MySQL pour NOT EXISTS —
 * un client pouvait recevoir la relance wishlist deux fois le même mois
 * (deux ref_id différents pour le même mois réel) ou jamais un mois donné.
 *
 * Corrigé le 08/09/2026 (round 325) : $refId sourcé via
 * SELECT YEAR(NOW()) AS y, MONTH(NOW()) AS m, même pattern que sendWinBacks().
 *
 * Test structurel : vérifie que sendWishlistReminders() sourçe désormais
 * l'année/le mois via YEAR(NOW())/MONTH(NOW()) SQL, plus date('Y')/date('n')
 * PHP.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    $posFn = strpos($src, 'private function sendWishlistReminders(): void');
    neria_assert($posFn !== false, 'sendWishlistReminders() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1200);

    neria_assert(
        strpos($body, "SELECT YEAR(NOW()) AS y, MONTH(NOW()) AS m") !== false,
        "sendWishlistReminders() ne sourçe plus année/mois via YEAR(NOW())/MONTH(NOW()) SQL — régression du bug corrigé le 08/09/2026 (round 325) : un décalage de fuseau horaire PHP/MySQL autour d'un changement de mois pourrait de nouveau faire diverger la clé de déduplication mensuelle"
    );
    neria_assert(
        strpos($body, "date('Y') * 100 + (int) date('n')") === false,
        "sendWishlistReminders() utilise encore date('Y')/date('n') PHP pour ref_id — régression du bug corrigé le 08/09/2026 (round 325)"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::sendWishlistReminders() sourçe bien année/mois du ref_id de déduplication via YEAR(NOW())/MONTH(NOW()) SQL — bug corrigé le 08/09/2026 (round 325)",
    ];
}
