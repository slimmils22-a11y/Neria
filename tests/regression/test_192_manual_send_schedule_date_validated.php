<?php
/**
 * Régression : ManualSendManager::scheduleManual() doit valider le format
 * et la cohérence temporelle de $sendAt avant de planifier, comme
 * CalendarManager::setManualOverride() (même lot de fichiers) le fait déjà
 * pour ses propres dates.
 *
 * Bug réel corrigé le 09/08/2026 (round 143) : $sendAt (saisie libre du
 * formulaire BO) était transmis brut jusqu'à QueueManager::enqueueAt(),
 * sans DateTime::createFromFormat() ni comparaison avec "maintenant" — une
 * date passée par erreur de frappe (ex. mauvaise année) déclenchait un
 * envoi IMMÉDIAT au prochain passage du cron au lieu de la date voulue,
 * silencieusement ; une chaîne malformée produisait un comportement
 * indéfini côté MySQL (coercition en 0000-00-00, également <= NOW()).
 *
 * Test comportemental réel : vérifie que scheduleManual() refuse (1) une
 * date malformée, (2) une date valide mais déjà passée, et (3) accepte
 * toujours une date future valide (non-régression) — dans les 3 cas, en
 * confirmant via neria_queue qu'aucune ligne n'a été insérée pour les cas
 * refusés.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $mgr    = new ManualSendManager($module);

    $emailMalformed = 'regtest-round143-malformed-' . uniqid() . '@example.com';
    $emailPast      = 'regtest-round143-past-' . uniqid() . '@example.com';
    $emailFuture    = 'regtest-round143-future-' . uniqid() . '@example.com';

    try {
        // Cas 1 : date malformée
        $resultMalformed = $mgr->scheduleManual('vip', $emailMalformed, '', 'Sujet test', [], 'pas-une-date');
        neria_assert(
            is_array($resultMalformed) && ($resultMalformed['ok'] ?? true) === false,
            "scheduleManual() a accepté une date malformée ('pas-une-date') — régression du bug corrigé le 09/08/2026 (round 143)"
        );
        $countMalformed = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($emailMalformed) . "'");
        neria_assert($countMalformed === 0, "une ligne a quand même été insérée en file pour une date malformée");

        // Cas 2 : date valide mais déjà passée
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));
        $resultPast = $mgr->scheduleManual('vip', $emailPast, '', 'Sujet test', [], $pastDate);
        neria_assert(
            is_array($resultPast) && ($resultPast['ok'] ?? true) === false,
            "scheduleManual() a accepté une date déjà passée ({$pastDate}) — régression du bug corrigé le 09/08/2026 (round 143) : l'envoi partirait immédiatement au lieu d'être refusé"
        );
        $countPast = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($emailPast) . "'");
        neria_assert($countPast === 0, "une ligne a quand même été insérée en file pour une date passée");

        // Cas 3 : non-régression — une date future valide doit toujours fonctionner
        $futureDate = date('Y-m-d H:i:s', strtotime('+3 days'));
        $resultFuture = $mgr->scheduleManual('vip', $emailFuture, '', 'Sujet test', [], $futureDate);
        neria_assert(
            is_array($resultFuture) && ($resultFuture['ok'] ?? false) === true,
            "scheduleManual() rejette à tort une date future valide ({$futureDate}) : " . json_encode($resultFuture)
        );
        $countFuture = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($emailFuture) . "'");
        neria_assert($countFuture === 1, "aucune ligne insérée en file pour une date future valide — non-régression cassée");
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE recipient_email IN ('" . pSQL($emailMalformed) . "', '" . pSQL($emailPast) . "', '" . pSQL($emailFuture) . "')");
    }

    return [
        'pass'    => true,
        'message' => "ManualSendManager::scheduleManual() refuse bien une date malformée ou déjà passée, tout en acceptant une date future valide",
    ];
}
