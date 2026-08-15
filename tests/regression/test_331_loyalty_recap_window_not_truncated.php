<?php
/**
 * Régression : LoyaltyManager::computeRecapWindowDays() plafonnait la
 * fenêtre de comptage des points à 60 jours. Après une panne du cron de
 * plus de 60 jours (serveur arrêté, tâche désactivée par erreur), l'écart
 * réel depuis le dernier récap (ex. 90 jours) était tronqué à 60 : les
 * points gagnés entre le 61e et le 90e jour disparaissaient silencieusement
 * et DÉFINITIVEMENT du récap — CONFIG_RECAP_LAST_SENT étant remis à
 * "maintenant" juste après l'envoi, aucune fenêtre future ne les recompte
 * jamais.
 *
 * Corrigé le 15/08/2026 (round 172) : plafond relevé à 400 jours (marge
 * large au-delà d'une année complète d'inactivité) — la fenêtre reste
 * toujours dérivée de l'horodatage réel du dernier envoi, jamais d'une
 * fenêtre glissante fixe, donc aucun risque de double-comptage.
 *
 * Test comportemental réel : appelle computeRecapWindowDays() via
 * réflexion avec un horodatage vieux de 90 jours, vérifie que la fenêtre
 * retournée est bien 90 (pas plafonnée à 60). Vérifie aussi le cas
 * "jamais envoyé" (30 jours, comportement historique inchangé) et le
 * plafond de sécurité à 400 jours pour un écart extrême.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $ref = new ReflectionMethod(LoyaltyManager::class, 'computeRecapWindowDays');
    $ref->setAccessible(true);

    // Jamais envoyé — comportement historique inchangé (30 jours).
    $neverSent = $ref->invoke(null, '');
    neria_assert($neverSent === 30, "computeRecapWindowDays('') ne retourne plus 30 — comportement historique cassé (obtenu {$neverSent})");

    // Panne de 90 jours — ne doit plus être tronquée à 60.
    $ninetyDaysAgo = date('Y-m-d H:i:s', strtotime('-90 days'));
    $window90 = $ref->invoke(null, $ninetyDaysAgo);
    neria_assert(
        $window90 >= 89 && $window90 <= 91,
        "computeRecapWindowDays() sur un écart de 90 jours retourne {$window90} au lieu d'environ 90 — régression du bug corrigé le 15/08/2026 (round 172) : le plafond de 60 jours tronquerait de nouveau la fenêtre, faisant disparaître silencieusement et définitivement les points gagnés entre le 61e et le 90e jour du prochain récap"
    );

    // Écart extrême (2 ans) — le plafond de sécurité (400j) doit toujours
    // s'appliquer, pour ne jamais construire une plage SQL déraisonnable.
    $twoYearsAgo = date('Y-m-d H:i:s', strtotime('-730 days'));
    $windowExtreme = $ref->invoke(null, $twoYearsAgo);
    neria_assert(
        $windowExtreme === 400,
        "computeRecapWindowDays() sur un écart de 2 ans ne plafonne plus à 400 jours (obtenu {$windowExtreme}) — le garde-fou contre une plage SQL déraisonnable aurait disparu"
    );

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::computeRecapWindowDays() ne tronque plus la fenêtre à 60 jours (relevé à 400), les points d'une longue panne cron restent comptés dans le prochain récap — bug corrigé le 15/08/2026 (round 172)",
    ];
}
