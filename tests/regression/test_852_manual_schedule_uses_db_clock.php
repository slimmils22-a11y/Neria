<?php
/**
 * Régression (constat réel du 24/09/2026, P6c sur ps-test) : un envoi manuel planifié « dans 3 heures » partait
 * IMMÉDIATEMENT. ManualSendManager::scheduleManual() stockait l'heure saisie par le marchand (horloge PHP,
 * fuseau de la boutique) telle quelle dans neria_queue.send_at, que QueueManager::processQueue() compare à NOW()
 * de MySQL (`send_at <= NOW()`). Sur ps-test PHP est en US/Eastern et MySQL à Paris : 6 h d'avance — tout envoi
 * planifié à moins de 6 h partait tout de suite, et à plus de 6 h partait 6 h trop tôt. Même famille que le bug
 * des bons de réduction (round 372).
 *
 * Corrigé : l'heure saisie est convertie à l'horloge de la base avant l'insertion (NeriaTools::
 * dbClockOffsetSeconds()/shiftWallClock()), et la liste des envois planifiés du back-office la reconvertit à
 * l'heure de la boutique, celle que le marchand a saisie.
 *
 * Test comportemental : fuseau PHP forcé à un décalage d'au moins 3 h par rapport à MySQL, planification réelle
 * dans 3 h, lecture de la ligne en file (ni due, ni décalée) et de la liste affichée (heure saisie retrouvée).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $phpTzBefore = date_default_timezone_get();
    $mysqlOffset = (int) $db->getValue('SELECT TIME_TO_SEC(TIMEDIFF(NOW(), UTC_TIMESTAMP()))', false);
    $email = 'regtest852-' . uniqid() . '@example.com';
    try {
        // Fuseau PHP choisi pour que l'écart avec MySQL soit d'au moins 3 h dans un sens ou l'autre
        foreach (['Pacific/Auckland', 'America/Los_Angeles', 'Asia/Kolkata', 'UTC'] as $tz) {
            date_default_timezone_set($tz);
            if (abs((int) date('Z') - $mysqlOffset) >= 3 * 3600) {
                break;
            }
        }
        $skew = (int) date('Z') - $mysqlOffset;
        neria_assert(abs($skew) >= 3 * 3600, "Jeu de test invalide : impossible de forcer un écart PHP/MySQL d'au moins 3 h (MySQL {$mysqlOffset} s)");

        $shopAt = date('Y-m-d H:i:s', strtotime('+3 hours'));
        $res = (new ManualSendManager($module))->scheduleManual('vip', $email, '', '', [], $shopAt);
        neria_assert(($res['ok'] ?? false) === true, 'scheduleManual() refuse un envoi valide : ' . json_encode($res, JSON_UNESCAPED_UNICODE));

        $row = $db->getRow("SELECT send_at, TIMESTAMPDIFF(MINUTE, NOW(), send_at) AS minutes_left FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($email) . "'", false);
        neria_assert(is_array($row), 'Ligne de file introuvable');
        $left = (int) $row['minutes_left'];
        neria_assert(
            $left >= 178 && $left <= 182,
            "L'envoi planifié dans 3 h est dû dans {$left} min à l'horloge de la base (attendu ≈ 180) — l'heure saisie à l'horloge PHP n'est pas convertie (écart PHP/MySQL de " . round($skew / 3600, 1) . " h) — régression du bug corrigé le 24/09/2026"
        );

        $shown = null;
        foreach ((new QueueManager($module))->getPendingManual() as $q) {
            if (($q['recipient_email'] ?? '') === $email) {
                $shown = $q;
            }
        }
        neria_assert($shown !== null, 'Envoi planifié absent de la liste du back-office');
        neria_assert(
            $shown['send_at'] === $shopAt && $shown['send_at_fmt'] === date('d/m/Y H:i', strtotime($shopAt)),
            "La liste du back-office affiche « {$shown['send_at_fmt']} » (send_at {$shown['send_at']}) au lieu de l'heure saisie « {$shopAt} »"
        );
    } finally {
        date_default_timezone_set($phpTzBefore);
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($email) . "'");
    }

    return [
        'pass'    => true,
        'message' => "Un envoi manuel planifié dans 3 h est enregistré à l'horloge de la base (dû dans ≈ 180 min, pas tout de suite) et la liste du back-office l'affiche à l'heure saisie, malgré un écart de fuseau PHP/MySQL — bug corrigé le 24/09/2026",
    ];
}
