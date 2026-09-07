<?php
/**
 * Régression : CustomerEmailHistoryManager::computeAlerts() calculait la
 * date de référence pour l'alerte "client inactif" via
 * `end($emails)['sent_at']` quand le client n'avait jamais ouvert un seul
 * email — $emails est pourtant trié `ORDER BY s.date_add DESC` (le plus
 * RÉCENT en premier), donc end($emails) renvoie le tout PREMIER email
 * jamais envoyé au client, pas le plus récent. Un client inscrit depuis
 * longtemps mais ayant reçu un email récemment (pas encore ouvert, ce qui
 * est normal) voyait l'alerte "inactif depuis ~400 jours" se déclencher à
 * tort, basée sur son tout premier email, alors qu'il est en réalité actif
 * dans le funnel (dernier envoi il y a 2 jours).
 *
 * Corrigé le 06/09/2026 (round 313) : reset($emails) (email le plus
 * RÉCENT) au lieu de end($emails) (email le plus ANCIEN).
 *
 * Test comportemental réel : insère 2 vrais envois en base (aucun jamais
 * ouvert) — un très ancien (400j) et un très récent (2j) — récupère les
 * lignes via getEmails() (triées DESC comme en production), puis vérifie
 * que computeAlerts() ne déclenche PAS alert_inactive (le client est en
 * réalité actif, dernier envoi il y a 2 jours seulement).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $token      = 'regtest602old';
    $token2     = 'regtest602recent';

    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'sent', '{$token}', DATE_SUB(NOW(), INTERVAL 400 DAY))");
    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'sent', '{$token2}', DATE_SUB(NOW(), INTERVAL 2 DAY))");

    try {
        $mgr = new CustomerEmailHistoryManager(neria_test_module());

        $refGet = new ReflectionMethod($mgr, 'getEmails');
        $refGet->setAccessible(true);
        $emails = $refGet->invoke($mgr, $idCustomer);
        $emails = array_values(array_filter($emails, fn ($e) => in_array($e['tracking_token'], [$token, $token2], true)));
        neria_assert(count($emails) === 2, "Jeu de test invalide : les emails de test n'ont pas été retrouvés via getEmails() (" . count($emails) . " au lieu de 2)");

        // getEmails() trie par date_add DESC : le plus récent (token2, 2j)
        // doit être en premier.
        neria_assert(
            $emails[0]['tracking_token'] === $token2,
            "Jeu de test invalide : getEmails() ne renvoie pas les emails triés par date décroissante (premier élément = " . var_export($emails[0]['tracking_token'], true) . ")"
        );

        $refBadge = new ReflectionMethod($mgr, 'computeEngagementBadge');
        $refBadge->setAccessible(true);
        $badge = $refBadge->invoke($mgr, $emails);

        $alerts = $mgr->computeAlerts($emails, $badge);

        $inactiveDays = null;
        foreach ($alerts as $a) {
            if ($a['key'] === 'alert_inactive') {
                $inactiveDays = $a['vars']['days'];
            }
        }

        neria_assert(
            $inactiveDays === null,
            "CustomerEmailHistoryManager::computeAlerts() déclenche à tort l'alerte 'client inactif' (jours rapportés : " . var_export($inactiveDays, true) . ") alors que le dernier email a été envoyé il y a seulement 2 jours — régression du bug corrigé le 06/09/2026 (round 313) : la date de référence est de nouveau calculée sur le PREMIER email jamais envoyé (end(\$emails)) au lieu du DERNIER (reset(\$emails)), quand aucun email n'a jamais été ouvert"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token IN ('{$token}', '{$token2}')");
    }

    return [
        'pass'    => true,
        'message' => "CustomerEmailHistoryManager::computeAlerts() calcule bien sa date de référence 'inactif' sur le DERNIER email envoyé (pas le premier) quand aucun email n'a jamais été ouvert — bug corrigé le 06/09/2026 (round 313)",
    ];
}
