<?php
/**
 * Régression : StatsManager::getKpiTrends() calculait ses bornes de
 * fenêtre ('current' = 7 derniers jours, 'previous' = 7 jours avant) via
 * date('Y-m-d', strtotime(...)) PHP, comparées à DATE(date_add) rempli
 * par MySQL — même piège horloge PHP/MySQL déjà corrigé ailleurs dans ce
 * même fichier (detectMpp()/detectAnomalies() utilisent déjà
 * TIMESTAMPDIFF()/DATE_SUB(NOW(), ...) côté SQL). Si le serveur PHP et le
 * serveur MySQL n'ont pas le même fuseau horaire, un événement proche de
 * la borne (jour 6 ou jour 7) pouvait basculer dans la mauvaise période,
 * faussant systématiquement le delta % de tendance semaine-sur-semaine
 * affiché au marchand.
 *
 * Corrigé le 06/09/2026 (round 310) : bornes calculées entièrement côté
 * SQL via DATE_SUB(CURDATE(), INTERVAL ... DAY).
 *
 * Test comportemental réel : insère un événement 'sent' exactement à la
 * borne la plus ancienne de la période 'current' (jour -6, doit être
 * compté dans 'current') puis appelle getKpiTrends() sous deux fuseaux
 * horaires PHP extrêmes opposés (UTC+14 et UTC-12) — le delta de comptage
 * ('current'.sent avant/après insertion) doit être IDENTIQUE (+1) dans
 * les deux cas, la décision ne dépendant plus que de l'horloge MySQL.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $mgr    = new StatsManager(neria_test_module());

    $token = bin2hex(random_bytes(16));
    $originalTz = date_default_timezone_get();

    try {
        $before = [];
        foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $tz) {
            date_default_timezone_set($tz);
            $trends = $mgr->getKpiTrends();
            date_default_timezone_set($originalTz);
            $before[$tz] = (int) ($trends['sent']['current'] ?? 0);
        }

        // Événement 'sent' exactement à la borne la plus ancienne de la
        // fenêtre 'current' (jour -6, milieu de journée pour une marge de
        // 12h de chaque côté de minuit).
        $db->execute(
            "INSERT INTO {$prefix}neria_stat (id_shop, template, lang, country_code, tracking_token, event_type, ip_address, user_agent, date_add)
             VALUES ({$idShop}, 'regtest594', 'fr', '', '{$token}', 'sent', '', '', DATE_SUB(CURDATE(), INTERVAL 6 DAY) + INTERVAL 12 HOUR)"
        );

        foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $tz) {
            date_default_timezone_set($tz);
            $trends = $mgr->getKpiTrends();
            date_default_timezone_set($originalTz);
            $after = (int) ($trends['sent']['current'] ?? 0);
            $delta = $after - $before[$tz];

            neria_assert(
                $delta === 1,
                "StatsManager::getKpiTrends() compte un delta de {$delta} pour 'sent'.'current' sous le fuseau PHP '{$tz}' (1 attendu) après insertion d'un événement à la borne exacte de la fenêtre — régression du bug corrigé le 06/09/2026 (round 310) : la fenêtre pourrait de nouveau dépendre de l'horloge PHP au lieu de MySQL"
            );
        }

        return [
            'pass'    => true,
            'message' => "StatsManager::getKpiTrends() calcule ses bornes de fenêtre entièrement côté SQL (DATE_SUB(CURDATE(), ...)), résultat identique et correct sous deux fuseaux horaires PHP extrêmes opposés — bug corrigé le 06/09/2026 (round 310)",
        ];
    } finally {
        date_default_timezone_set($originalTz);
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$token}'");
    }
}
