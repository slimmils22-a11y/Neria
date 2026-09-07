<?php
/**
 * Régression : LoyaltyManager::computeRecapWindowDays() calculait l'écart
 * via `time() - strtotime($lastSentRaw)` (horloge PHP des deux côtés — pas
 * un mélange PHP/MySQL en soi), mais le résultat ($windowDays) est ensuite
 * appliqué comme offset depuis NOW() MySQL dans sendRecapToCustomer()
 * (`date_add >= DATE_SUB(NOW(), INTERVAL $windowDays DAY)`) — un écart
 * entre les deux horloges décalait donc légèrement la frontière réelle de
 * la fenêtre par rapport au délai réel écoulé depuis le dernier envoi.
 *
 * Corrigé le 07/09/2026 (round 314) : l'écart est désormais calculé via
 * TIMESTAMPDIFF(SECOND, ..., NOW()) côté MySQL, insensible au fuseau PHP.
 *
 * Test comportemental réel : appelle computeRecapWindowDays() via
 * réflexion (méthode statique, comme test_125/test_331) sous deux fuseaux
 * PHP radicalement différents (Europe/Paris puis Pacific/Kiritimati,
 * UTC+14) pour un même $lastSentRaw fixe, et vérifie que le résultat est
 * identique — preuve que le calcul ne dépend plus de l'horloge PHP.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $originalTz = date_default_timezone_get();

    try {
        $ref = new ReflectionMethod(LoyaltyManager::class, 'computeRecapWindowDays');
        $ref->setAccessible(true);

        // Date fixe (pas relative à "maintenant" PHP) pour éliminer toute
        // dépendance résiduelle au fuseau lors de la construction même de
        // $lastSentRaw.
        $db = neria_test_db();
        $lastSentRaw = (string) $db->getValue('SELECT DATE_SUB(NOW(), INTERVAL 10 DAY)');

        date_default_timezone_set('Europe/Paris');
        $windowParis = $ref->invoke(null, $lastSentRaw);

        date_default_timezone_set('Pacific/Kiritimati');
        $windowExotic = $ref->invoke(null, $lastSentRaw);

        neria_assert(
            $windowParis === $windowExotic,
            "computeRecapWindowDays() renvoie une fenêtre différente selon le fuseau PHP configuré (Europe/Paris={$windowParis} vs Pacific/Kiritimati={$windowExotic}) pour le même \$lastSentRaw — régression du bug corrigé le 07/09/2026 (round 314) : le calcul dépendrait de nouveau de l'horloge PHP (time()) au lieu de TIMESTAMPDIFF() côté MySQL"
        );
        neria_assert(
            $windowParis >= 9 && $windowParis <= 11,
            "computeRecapWindowDays() renvoie {$windowParis} au lieu d'environ 10 pour un dernier envoi vieux de 10 jours — jeu de test invalide"
        );
    } finally {
        date_default_timezone_set($originalTz);
    }

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::computeRecapWindowDays() calcule désormais son écart via TIMESTAMPDIFF() côté MySQL, insensible au fuseau horaire PHP configuré (vérifié sous Europe/Paris ET Pacific/Kiritimati, résultat identique) — bug corrigé le 07/09/2026 (round 314)",
    ];
}
