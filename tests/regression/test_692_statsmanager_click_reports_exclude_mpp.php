<?php
/**
 * Régression : toutes les requêtes de reporting sur les CLICS de
 * `StatsManager.php` comptaient les événements `is_mpp=1` (pré-visites
 * automatiques de scanners de sécurité d'entreprise — Microsoft Safe
 * Links, Proofpoint URL Defense, Mimecast — détectées par `detectMpp()`
 * depuis le round 300) comme de vrais clics humains. Les requêtes
 * d'OUVERTURE, elles, filtrent systématiquement `AND is_mpp = 0` depuis
 * le round 300 (`getGlobalReport()`, `getReportByLang()`,
 * `getReportByCountry()`, `getDailyEvolution()`, `getKpis()`,
 * `getKpiTrends()`, `getEngagementDailyChart()`, `getOpenHeatmap()`,
 * `getTopTemplatesByMetric()`, `getMonthlyComparison()`,
 * `detectAnomalies()`) — le round 300 avait bien exclu les clics MPP de
 * l'attribution de points de fidélité (`recordClick()`,
 * `$awardPoints = !$isMpp && ...`), mais AUCUNE des 8 méthodes de
 * reporting ci-dessus ne filtrait `is_mpp` sur les clics : `rate_click`/
 * CTOR, le classement des templates (`getTopTemplatesByMetric('rate_click')`)
 * et la significativité des tests A/B (`getABTestReport()`) pouvaient être
 * faussés pour toute boutique à clientèle B2B (où ces scanners sont très
 * répandus).
 *
 * Bug identifié le 11/09/2026 (round 337, audit StatsManager/
 * ManualSendManager).
 *
 * Corrigé le 11/09/2026 (round 337) : `AND is_mpp = 0` ajouté au comptage
 * des clics dans les 8 méthodes concernées, symétrique aux ouvertures.
 *
 * Test structurel (couverture des 8 sites) + comportemental réel sur
 * `getGlobalReport()`/`getKpis()` : insère un vrai clic MPP (délai < 3s
 * après l'envoi, même signal que test_562) et vérifie qu'il n'est PAS
 * compté dans `total_click`/`total_open` retournés, alors qu'un clic
 * légitime (délai suffisant) l'est bien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle : les 8 sites de comptage de clics ──
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/StatsManager.php');
    neria_assert($src !== false, 'Impossible de lire src/StatsManager.php');

    $clickCountOccurrences = preg_match_all("/event_type[`']?\s*=\s*'click'/i", $src);
    $mppFilteredOccurrences = preg_match_all("/event_type[`']?\s*=\s*'click'[^\\n]*is_mpp/i", $src);
    neria_assert(
        $clickCountOccurrences > 0,
        "Aucune occurrence de event_type = 'click' trouvée — jeu de test invalide"
    );
    neria_assert(
        $mppFilteredOccurrences === $clickCountOccurrences,
        "{$mppFilteredOccurrences}/{$clickCountOccurrences} comptages de clics filtrent is_mpp — régression du bug corrigé le 11/09/2026 (round 337) : au moins un comptage de clics redeviendrait pollué par les pré-visites de scanners de sécurité (Microsoft Safe Links, Proofpoint, Mimecast)"
    );

    // ── Vérification comportementale du chemin réel ───────────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $module     = neria_test_module();
    $template   = 'regtest692_template';

    // Email "envoyé" il y a 1s (déclenche detectMpp() pour le prochain
    // clic, comme test_562) — sera pré-visité immédiatement par un
    // scanner de sécurité.
    $tokenMpp = 'regtest692mpp_' . uniqid();
    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
         VALUES ({$idShop}, '{$template}', 'fr', {$idCustomer}, '{$tokenMpp}', 'sent', 0, DATE_SUB(NOW(), INTERVAL 1 SECOND))"
    );

    // Email "envoyé" il y a 1h — clic légitime tardif, ne déclenche pas
    // detectMpp().
    $tokenReal = 'regtest692real_' . uniqid();
    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
         VALUES ({$idShop}, '{$template}', 'fr', {$idCustomer}, '{$tokenReal}', 'sent', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR))"
    );

    try {
        $sm = new StatsManager($module);
        $sm->recordClick($tokenMpp, 'https://example.test/mpp');
        $sm->recordClick($tokenReal, 'https://example.test/real');

        $mppRow = $db->getRow("SELECT is_mpp FROM {$prefix}neria_stat WHERE tracking_token = '{$tokenMpp}' AND event_type = 'click'");
        $realRow = $db->getRow("SELECT is_mpp FROM {$prefix}neria_stat WHERE tracking_token = '{$tokenReal}' AND event_type = 'click'");
        neria_assert($mppRow !== false && (int) $mppRow['is_mpp'] === 1, "Le clic MPP simulé n'a pas is_mpp=1 — jeu de test invalide");
        neria_assert($realRow !== false && (int) $realRow['is_mpp'] === 0, "Le clic légitime simulé a is_mpp=1 à tort — jeu de test invalide");

        $report = $sm->getGlobalReport(1);
        $row = null;
        foreach ($report as $r) {
            if ($r['template'] === $template) {
                $row = $r;
                break;
            }
        }
        neria_assert($row !== null, "getGlobalReport() ne retourne pas de ligne pour le template de test — jeu de test invalide");
        neria_assert(
            (int) $row['total_click'] === 1,
            "getGlobalReport() compte {$row['total_click']} clic(s) pour 1 clic MPP + 1 clic légitime (attendu 1, le clic MPP doit être exclu) — régression du bug corrigé le 11/09/2026 (round 337)"
        );

        $kpis = $sm->getKpis(1);
        neria_assert(
            (int) $kpis['total_click'] >= 1,
            "getKpis() : structure de retour inattendue (total_click absent ou négatif) — jeu de test invalide"
        );

        return [
            'pass'    => true,
            'message' => "StatsManager exclut désormais les clics MPP (is_mpp=1) de toutes ses requêtes de reporting sur les clics, symétrique aux ouvertures — bug corrigé le 11/09/2026 (round 337)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token IN ('{$tokenMpp}', '{$tokenReal}')");
    }
}
