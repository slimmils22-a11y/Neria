<?php
/**
 * Régression : CustomerEmailHistoryManager::computeAlerts() calculait
 * $daysSinceLastOpen via `time() - strtotime($lastOpen)` côté PHP — même
 * piège déjà identifié et corrigé dans StatsManager::detectMpp() (round
 * antérieur) : time() lit l'horloge PHP (fuseau date.timezone), alors que
 * strtotime($lastOpen) réinterprète une valeur produite par NOW() MySQL.
 * Si les deux serveurs ne partagent pas le même fuseau (fréquent en
 * mutualisé/containers), l'écart décale $daysSinceLastOpen et peut
 * déclencher/manquer l'alerte "client inactif" au mauvais moment.
 *
 * Bug identifié le 31/08/2026 (round 257, audit fuseau horaire cron/dates
 * stockées). Corrigé le 31/08/2026 (round 257) : $daysSinceLastOpen est
 * désormais calculé via TIMESTAMPDIFF(SECOND, ..., NOW()) côté MySQL,
 * insensible au fuseau PHP configuré.
 *
 * Note vérifiée empiriquement avant correctif (script scratch) :
 * detectStorm() et buildCsv() du même fichier NE SONT PAS concernés par ce
 * piège malgré leur usage de strtotime() — ils ne font que des différences
 * ou reformats entre deux valeurs MySQL réinterprétées de façon identique,
 * ce qui annule tout décalage de fuseau (pas de mélange avec l'horloge PHP
 * live comme ici). Non corrigés, à raison.
 *
 * Test comportemental réel : insère un vrai événement 'open' daté
 * NOW() - 65 jours en base, vérifie que l'alerte 'alert_inactive' (seuil
 * INACTIVE_DAYS=60) se déclenche bien avec le nombre de jours correct —
 * PUIS reproduit le calcul sous un fuseau PHP radicalement différent
 * (America/New_York) et vérifie que le résultat ne change PAS (preuve que
 * le calcul est bien insensible à date.timezone, donc calculé côté MySQL).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $token      = 'regtest496';
    $token2     = 'regtest496b';

    // 2 emails envoyés (badge['total_sent'] >= 2 requis pour déclencher
    // alert_inactive, cf. computeAlerts()), le 1er ouvert il y a 65 jours.
    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'sent', '{$token}', DATE_SUB(NOW(), INTERVAL 65 DAY))");
    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'open', '{$token}', DATE_SUB(NOW(), INTERVAL 65 DAY))");
    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'sent', '{$token2}', DATE_SUB(NOW(), INTERVAL 70 DAY))");

    $originalTz = date_default_timezone_get();

    try {
        $mgr = new CustomerEmailHistoryManager(neria_test_module());
        $refGet = new ReflectionMethod($mgr, 'getEmails');
        $refGet->setAccessible(true);
        $emails = $refGet->invoke($mgr, $idCustomer);
        $emails = array_values(array_filter($emails, fn($e) => in_array($e['tracking_token'], [$token, $token2], true)));
        neria_assert(count($emails) === 2, "Jeu de test invalide : les emails de test n'ont pas été retrouvés via getEmails() (" . count($emails) . " au lieu de 2)");

        $refBadge = new ReflectionMethod($mgr, 'computeEngagementBadge');
        $refBadge->setAccessible(true);
        $badge = $refBadge->invoke($mgr, $emails);

        date_default_timezone_set('Europe/Paris');
        $alertsParis = $mgr->computeAlerts($emails, $badge);
        $inactiveParis = null;
        foreach ($alertsParis as $a) {
            if ($a['key'] === 'alert_inactive') {
                $inactiveParis = $a['vars']['days'];
            }
        }
        neria_assert(
            $inactiveParis !== null && $inactiveParis >= 60 && $inactiveParis <= 66,
            "computeAlerts() ne déclenche plus alert_inactive (ou avec un nombre de jours incohérent = " . var_export($inactiveParis, true) . ") pour un email ouvert il y a 65 jours — régression du bug corrigé le 31/08/2026 (round 257)"
        );

        // Fuseau PHP radicalement différent : si le calcul dépendait encore
        // de time()/strtotime() côté PHP, ce nombre de jours changerait
        // (ou pourrait même changer le jour affiché autour de minuit).
        // TIMESTAMPDIFF côté MySQL doit rester invariant.
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14, écart extrême avec Europe/Paris
        $alertsExotic = $mgr->computeAlerts($emails, $badge);
        $inactiveExotic = null;
        foreach ($alertsExotic as $a) {
            if ($a['key'] === 'alert_inactive') {
                $inactiveExotic = $a['vars']['days'];
            }
        }

        neria_assert(
            $inactiveExotic === $inactiveParis,
            "computeAlerts() renvoie un nombre de jours différent selon le fuseau PHP configuré (Europe/Paris={$inactiveParis} vs Pacific/Kiritimati={$inactiveExotic}) — régression du bug corrigé le 31/08/2026 (round 257) : le calcul dépend de nouveau de l'horloge PHP au lieu de TIMESTAMPDIFF() côté MySQL"
        );
    } finally {
        date_default_timezone_set($originalTz);
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token IN ('{$token}', '{$token2}')");
    }

    return [
        'pass'    => true,
        'message' => "CustomerEmailHistoryManager::computeAlerts() calcule désormais \$daysSinceLastOpen via TIMESTAMPDIFF() côté MySQL, insensible au fuseau horaire PHP configuré (vérifié sous Europe/Paris ET Pacific/Kiritimati, résultat identique) — bug corrigé le 31/08/2026 (round 257)",
    ];
}
