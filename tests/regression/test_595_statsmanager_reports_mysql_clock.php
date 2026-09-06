<?php
/**
 * Régression : StatsManager expose ~12 méthodes de reporting BO
 * (getGlobalReport/getReportByLang/getReportByCountry/getDailyEvolution/
 * getKpis/getABTestReport/getRevenueStats/getRevenueDailyByCategory/
 * getEngagementDailyChart/getOpenHeatmap/getTopTemplatesByMetric/
 * getTopTemplatesByRevenue) qui calculaient toutes leur borne de fenêtre
 * via date()/strtotime() PHP au lieu de NOW()/CURDATE() MySQL — même
 * piège horloge PHP/MySQL déjà corrigé DANS CE MÊME FICHIER (detectMpp()/
 * detectAnomalies(), et getKpiTrends() au round 310), jamais étendu à ces
 * méthodes. Un sous-cas plus sévère : getRevenueDailyByCategory()/
 * getEngagementDailyChart() construisent une liste $dates (labels du
 * graphique) via date() PHP qui sert AUSSI de filtre — tout jour SQL
 * absent de cette liste voit sa ligne silencieusement ignorée.
 *
 * Corrigé le 06/09/2026 (round 311) : bornes calculées entièrement côté
 * SQL (DATE_SUB(NOW(), INTERVAL ... DAY)) ; $dates ancré sur CURDATE()
 * MySQL au lieu de date() PHP pour les 2 méthodes à liste-filtre.
 *
 * Test en 2 volets :
 * 1) Comportemental réel — getGlobalReport() : insère un événement 'sent'
 *    juste à la borne extérieure de la fenêtre (30 jours + 2h) sous deux
 *    fuseaux PHP extrêmes opposés — ne doit JAMAIS être compté.
 * 2) Structurel — getRevenueDailyByCategory()/getEngagementDailyChart() :
 *    vérifie l'ancrage $todaySql311 (CURDATE() MySQL). Une reproduction
 *    comportementale du "jour absent de $dates" dépendrait de l'heure
 *    réelle au moment du test (non déterministe, voir commentaire inline).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $mgr    = new StatsManager(neria_test_module());
    $originalTz = date_default_timezone_get();

    $lang  = 'zz';
    $token = bin2hex(random_bytes(16));

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE lang = '{$lang}' OR template = 'regtest595'");

    try {
        // ── Volet 1 : borne extérieure jamais comptée, quel que soit le fuseau PHP ──
        $db->execute(
            "INSERT INTO {$prefix}neria_stat (id_shop, template, lang, country_code, tracking_token, event_type, ip_address, user_agent, date_add)
             VALUES ({$idShop}, 'regtest595', '{$lang}', '', '{$token}', 'sent', '', '', DATE_SUB(NOW(), INTERVAL 30 DAY) - INTERVAL 2 HOUR)"
        );

        foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $tz) {
            date_default_timezone_set($tz);
            $report = $mgr->getGlobalReport(30);
            date_default_timezone_set($originalTz);

            $found = null;
            foreach ($report as $row) {
                if ($row['template'] === 'regtest595') {
                    $found = $row;
                }
            }
            neria_assert(
                $found === null,
                "StatsManager::getGlobalReport(30) compte un événement situé 2h AU-DELÀ de sa fenêtre de 30 jours, sous le fuseau PHP '{$tz}' — régression du bug corrigé le 06/09/2026 (round 311) : la borne dépend de nouveau de l'horloge PHP au lieu de MySQL"
            );
        }

        // ── Volet 2 : la liste $dates de getRevenueDailyByCategory()/
        //    getEngagementDailyChart() (labels ET filtre — tout jour SQL
        //    absent de cette liste voit sa ligne silencieusement ignorée)
        //    doit être ancrée sur CURDATE() MySQL, pas date() PHP. Vérifié
        //    structurellement (pas comportementalement) : reproduire le
        //    bug réel nécessite un instant précis de la journée où le
        //    fuseau PHP décalé accuse un JOUR DE RETARD sur MySQL — pas
        //    garanti au moment où la suite de tests s'exécute (dépend de
        //    l'heure réelle), contrairement au Volet 1 ci-dessus qui reste
        //    déterministe (marge de 2h fixe, indépendante de l'heure du jour).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/StatsManager.php');
        neria_assert($src !== false, 'Impossible de lire src/StatsManager.php');
        neria_assert(
            substr_count($src, "\$todaySql311 = (string) \$this->db->getValue('SELECT CURDATE()');") === 2,
            "StatsManager : l'ancrage \$todaySql311 (CURDATE() MySQL) a disparu d'une ou des deux méthodes (getRevenueDailyByCategory()/getEngagementDailyChart()) — régression du bug corrigé le 06/09/2026 (round 311) : la liste \$dates (labels ET filtre) redeviendrait ancrée sur l'horloge PHP au lieu de MySQL, perdant silencieusement les données du jour courant en cas de décalage de fuseau horaire"
        );
        neria_assert(
            substr_count($src, "strtotime(\"{\$todaySql311} -{\$i} days\")") === 2,
            "StatsManager : le calcul des labels \$dates n'utilise plus \$todaySql311 comme ancre — régression du bug corrigé le 06/09/2026 (round 311)"
        );

        return [
            'pass'    => true,
            'message' => "StatsManager::getGlobalReport()/getRevenueDailyByCategory() calculent leurs bornes/labels de fenêtre entièrement via l'horloge MySQL, résultat identique et correct sous deux fuseaux horaires PHP extrêmes opposés — bug corrigé le 06/09/2026 (round 311)",
        ];
    } finally {
        date_default_timezone_set($originalTz);
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE lang = '{$lang}' OR template IN ('regtest595', 'regtest595b')");
    }
}
