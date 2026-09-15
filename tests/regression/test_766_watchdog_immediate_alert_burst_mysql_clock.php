<?php
/**
 * Régression : `WatchdogManager::sendImmediateAlert()` calculait le
 * `$burstCount` (nombre d'ERROR/CRITICAL depuis la dernière alerte, pour
 * ne pas faire taire silencieusement une rafale) en comparant `date_add`
 * (colonne TOUJOURS écrite via `NOW()` MySQL dans `record()`) à une borne
 * de fenêtre calculée côté PHP (`date('Y-m-d H:i:s', $lastSent)`) — un
 * décalage d'horloge PHP/MySQL, même de quelques secondes, pouvait
 * sous- ou sur-compter les erreurs affichées dans l'email d'alerte au
 * marchand (`wd_alert.subject_burst`/`burst_notice`).
 *
 * Bug identifié le 15/09/2026 (round 360, audit dédié WatchdogManager).
 *
 * Corrigé le 15/09/2026 : la fenêtre est désormais bornée côté SQL via
 * `DATE_SUB(NOW(), INTERVAL <secondes écoulées> SECOND)` — les secondes
 * écoulées (time() - $lastSent) restent une DURÉE calculée entièrement en
 * PHP (les deux bornes proviennent de l'horloge PHP), appliquée ensuite
 * comme un décalage relatif depuis le NOW() MySQL réel, éliminant toute
 * dépendance à la concordance absolue des 2 horloges.
 *
 * Test structurel (déclencher une vraie rafale d'alertes email
 * nécessiterait un serveur SMTP réel et desservirait la boîte du
 * marchand configuré) : vérifie que le calcul de la fenêtre utilise
 * désormais DATE_SUB(NOW(), INTERVAL ... SECOND) et non plus une chaîne
 * de date PHP absolue comparée à date_add.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WatchdogManager.php');

    neria_assert(
        strpos($src, "date_add >= DATE_SUB(NOW(), INTERVAL ' . (int) \$secondsSinceLastAlert . ' SECOND)") !== false,
        "sendImmediateAlert() ne borne plus la fenêtre de burstCount via DATE_SUB(NOW(), INTERVAL ... SECOND) — régression du bug corrigé le 15/09/2026 (round 360) : un décalage d'horloge PHP/MySQL pourrait de nouveau fausser le comptage de la rafale affichée dans l'email d'alerte"
    );
    neria_assert(
        strpos($src, '$secondsSinceLastAlert = $lastSent > 0 ? (time() - $lastSent) : 86400;') !== false,
        "sendImmediateAlert() ne calcule plus \$secondsSinceLastAlert comme une durée PHP pure avant de l'appliquer côté SQL — régression du bug corrigé le 15/09/2026 (round 360)"
    );

    return [
        'pass'    => true,
        'message' => "WatchdogManager::sendImmediateAlert() borne désormais la fenêtre de comptage du burst via DATE_SUB(NOW(), INTERVAL ... SECOND) côté SQL, insensible à un décalage d'horloge PHP/MySQL — bug corrigé le 15/09/2026 (round 360)",
    ];
}
