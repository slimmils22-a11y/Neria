<?php
/**
 * Régression : LoyaltyManager::computeRecapWindowDays() doit calculer la
 * fenêtre de comptage des points du récap mensuel sur le délai RÉEL écoulé
 * depuis le dernier envoi, pas une fenêtre fixe de 30 jours.
 *
 * Bug réel corrigé le 08/08/2026 (round 121) : sendMonthlyRecaps() utilise
 * un throttle par MOIS CALENDAIRE ('Y-m', comme MonthlyReportManager::isDue()
 * — intentionnellement, pour éviter la dérive d'une fenêtre glissante fixe
 * qui finirait par envoyer le récap 13 fois par an). Mais l'écart réel entre
 * deux envois consécutifs varie ainsi d'environ 1 à 31 jours selon le jour
 * du mois où le cron quotidien tourne après le changement de mois — alors
 * que sendRecapToCustomer() sommait toujours les points sur une fenêtre
 * FIXE de 30 jours glissants depuis maintenant, indépendamment du délai réel
 * écoulé depuis le dernier envoi. Un envoi tardif (fin de mois) suivi d'un
 * envoi précoce (1 jour plus tard) recomptait presque les MÊMES points dans
 * deux emails successifs ; un envoi précoce suivi d'un envoi tardif (jusqu'à
 * 31 jours plus tard) faisait disparaître silencieusement les points gagnés
 * juste après le précédent envoi (hors de la fenêtre glissante de 30 jours).
 *
 * Test fonctionnel réel (méthode privée, testée via Reflection) : vérifie
 * que le nombre de jours retourné correspond bien au délai réel écoulé
 * depuis $lastSentRaw (borné à [1, 60]), et que le comportement historique
 * (fenêtre de 30 jours) est préservé quand aucun envoi précédent n'existe.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $ref = new ReflectionMethod(LoyaltyManager::class, 'computeRecapWindowDays');
    $ref->setAccessible(true);

    // Jamais envoyé (chaîne vide) → comportement historique inchangé (30j).
    $neverSent = $ref->invoke(null, '');
    neria_assert(
        $neverSent === 30,
        "computeRecapWindowDays('') renvoie {$neverSent} au lieu de 30 — le comportement historique pour un premier envoi (jamais de lastSent) a changé"
    );

    // Envoi tardif la veille (1 jour d'écart réel) → fenêtre ~1 jour, PAS 30
    // (sans quoi les points du mois précédent, déjà annoncés hier, seraient
    // de nouveau comptés aujourd'hui).
    $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
    $oneDayAgo = $ref->invoke(null, $yesterday);
    neria_assert(
        $oneDayAgo >= 1 && $oneDayAgo <= 2,
        "computeRecapWindowDays() renvoie {$oneDayAgo} pour un dernier envoi d'hier — régression du bug corrigé le 08/08/2026 (round 121) : la fenêtre retomberait de nouveau sur 30 jours fixes, recomptant les points déjà annoncés hier"
    );

    // Envoi précoce il y a 45 jours (dépasse la borne max) → plafonné à 60,
    // pas illimité (protège contre un très long silence après une panne du
    // cron ou une désactivation prolongée du module).
    $longAgo = date('Y-m-d H:i:s', strtotime('-90 days'));
    $capped  = $ref->invoke(null, $longAgo);
    neria_assert(
        $capped === 60,
        "computeRecapWindowDays() renvoie {$capped} au lieu de 60 pour un dernier envoi vieux de 90 jours — le plafond de sécurité a disparu"
    );

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::computeRecapWindowDays() calcule bien la fenêtre du récap sur le délai réel écoulé depuis le dernier envoi (borné à [1,60]), pas une fenêtre fixe de 30 jours",
    ];
}
