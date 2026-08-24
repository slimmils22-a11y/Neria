<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — MonthlyReportManager
 *
 * Génère et envoie le rapport mensuel de performance email au marchand.
 * Couvre : KPIs globaux, top/flop templates, langue championne, meilleur
 * moment d'envoi, résultats A/B tests, chiffre d'affaires attribué et
 * recommandations automatiques sur 19 langues.
 *
 * Déclenchement : hook displayHeader (front) vérifie le 1er de chaque mois
 * si le rapport du mois précédent n'a pas encore été envoyé.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MonthlyReportManager
{
    const CONFIG_ENABLED    = 'NERIA_REPORT_ENABLED';
    const CONFIG_RECIPIENTS = 'NERIA_REPORT_RECIPIENTS';
    const CONFIG_LAST_SENT  = 'NERIA_REPORT_LAST_SENT'; // 'YYYY-MM'
    const MIN_SENDS         = 5; // minimum d'envois pour figurer dans le classement

    private Neria $module;
    private \Db $db;
    private int $idShop;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    // ============================================================
    // POINT D'ENTRÉE — vérification automatique
    // ============================================================

    /**
     * Vérifie si le rapport du mois précédent est dû et l'envoie si oui.
     * Appelé depuis hookDisplayHeader (toutes les pages front).
     */
    public function checkAndSend(): void
    {
        // Round 145 : pas de "fast exit" global ici sur CONFIG_ENABLED —
        // celui-ci est désormais revérifié PAR BOUTIQUE dans la boucle
        // ci-dessous (comme isDue()/markSent()). Auparavant,
        // Configuration::get(self::CONFIG_ENABLED) sans idShop résolvait la
        // valeur de la boutique du VISITEUR ayant déclenché le hook, pas de
        // chaque boutique itérée : si cette boutique avait le rapport
        // désactivé, toute la boucle était court-circuitée avant même de
        // commencer — les autres boutiques (activées, rapport dû)
        // n'atteignaient jamais leur propre vérification, silencieusement.

        // Verrou MySQL : contrairement au cron comportemental (fenêtre de
        // course d'une fraction de seconde autour du seuil des 24h),
        // isDue() reste vrai pendant TOUTE la fenêtre de rattrapage (du 1er
        // au 7 du mois) — n'importe quelle paire de visites concurrentes
        // pendant ces 7 jours peut toutes les deux passer isDue() avant que
        // markSent() n'ait eu le temps de s'exécuter (le temps de générer +
        // envoyer le rapport), envoyant le rapport mensuel deux fois au
        // marchand. Même piège déjà corrigé pour la queue d'envoi, la queue
        // webhook et le cron comportemental.
        $db = $this->db;
        if ((int) $db->getValue("SELECT GET_LOCK('neria_monthly_report_check', 0)") !== 1) {
            return;
        }

        try {
            $prev  = new \DateTime('first day of last month');
            $year  = (int) $prev->format('Y');
            $month = (int) $prev->format('n');

            // Le rapport lui-même est entièrement scopé sur $this->idShop
            // (tous les KPI/requêtes ci-dessous filtrent par id_shop) — mais
            // isDue()/markSent() utilisaient un throttle GLOBAL (sans suffixe
            // id_shop). En multi-boutique, la 1ère boutique dont un visiteur
            // déclenchait ce hook recevait bien SON rapport, mais marquait le
            // mois comme "envoyé" pour TOUTES les boutiques — les autres ne
            // recevaient alors plus jamais leur rapport mensuel. On boucle
            // donc ici sur chaque boutique active, comme BehavioralCronManager
            // le fait déjà pour ses tâches comportementales.
            $originalShop = \Context::getContext()->shop;
            // Round 165 : $this->idShop n'était jamais restauré après la
            // boucle (seul Context::getContext()->shop l'était). Si la même
            // instance de MonthlyReportManager est réutilisée après
            // checkAndSend() dans la même requête, toute méthode privée
            // dépendant de $this->idShop opérait silencieusement sur la
            // DERNIÈRE boutique itérée plutôt que la boutique voulue.
            $originalIdShop = $this->idShop;
            $shops = \Shop::getShops(true, null, true) ?: [(int) $originalShop->id];

            foreach ($shops as $idShop) {
                $idShop = (int) $idShop;
                \Context::getContext()->shop = new \Shop($idShop);
                $this->idShop = $idShop;

                if (!(int) \Configuration::get(self::CONFIG_ENABLED, null, null, $idShop)) {
                    continue;
                }

                // Revérifie après obtention du verrou : un autre process a pu
                // terminer son propre envoi pendant qu'on attendait.
                if (!$this->isDue($idShop)) {
                    continue;
                }

                try {
                    if ($this->sendReport($year, $month)) {
                        $this->markSent($year, $month, $idShop);
                    }
                } catch (\Throwable $e) {
                    if (class_exists('WatchdogManager')) {
                        (new WatchdogManager($this->module))->error(
                            WatchdogManager::i18nMsg('watchdog.monthly_report_send_failed', ['error' => $e->getMessage()]),
                            '',
                            'MonthlyReportManager'
                        );
                    }
                }
            }

            \Context::getContext()->shop = $originalShop;
            $this->idShop = $originalIdShop;
        } finally {
            $db->execute("SELECT RELEASE_LOCK('neria_monthly_report_check')");
        }
    }

    // ============================================================
    // ENVOI DU RAPPORT
    // ============================================================

    /**
     * Génère et envoie le rapport pour l'année/mois donnés.
     * Peut être appelé manuellement depuis le BO (onglet Stats).
     */
    public function sendReport(int $year, int $month): bool
    {
        $report     = $this->buildReport($year, $month);
        $prevYear   = $month === 1 ? $year - 1 : $year;
        $prevMonth  = $month === 1 ? 12 : $month - 1;
        $prevReport = $this->buildReport($prevYear, $prevMonth);

        $report['prev']             = $prevReport;
        $report['recommendations']  = $this->generateRecommendations($report, $prevReport);
        $report['month_label']      = $this->formatMonthLabel($year, $month);
        $report['prev_month_label'] = $this->formatMonthLabel($prevYear, $prevMonth);

        return $this->deliverReport($report);
    }

    /**
     * Aperçu BO (onglets Design/Traductions) — même assemblage de données que
     * l'envoi réel (mois en cours, données live), mais sans envoi ni écriture
     * disque. Ce template a son propre rendu HTML autonome (renderHtml, page
     * complète indépendante de layout.html/core/*.html) : c'est pourquoi
     * l'aperçu générique (EmailRenderer::renderPreviewHtml) délègue ici plutôt
     * que de compiler core/monthly_report.html — fichier hérité d'une
     * architecture antérieure, jamais utilisé par l'envoi réel.
     */
    public function previewHtml(string $lang = 'fr'): string
    {
        $year  = (int) date('Y');
        $month = (int) date('n');
        $prevYear  = $month === 1 ? $year - 1 : $year;
        $prevMonth = $month === 1 ? 12 : $month - 1;

        $report     = $this->buildReport($year, $month);
        $prevReport = $this->buildReport($prevYear, $prevMonth);

        $report['prev']            = $prevReport;
        $report['recommendations'] = $this->generateRecommendations($report, $prevReport);
        $report['month_label']     = $this->formatMonthLabel($year, $month);

        $originalLang = class_exists('AdminTranslator') ? \AdminTranslator::currentLang() : null;
        if (class_exists('AdminTranslator')) {
            \AdminTranslator::setLang($lang);
        }

        try {
            return $this->renderHtml($report, $lang);
        } finally {
            if ($originalLang !== null && class_exists('AdminTranslator')) {
                \AdminTranslator::setLang($originalLang);
            }
        }
    }

    // ============================================================
    // CONSTRUCTION DU RAPPORT
    // ============================================================

    public function buildReport(int $year, int $month): array
    {
        $dateFrom = sprintf('%04d-%02d-01', $year, $month);
        $dateTo   = date('Y-m-t', strtotime($dateFrom)); // dernier jour du mois

        $kpis      = $this->getMonthKpis($dateFrom, $dateTo);
        $rankings  = $this->getTemplateRankings($dateFrom, $dateTo);
        $langPerf  = $this->getLangPerformance($dateFrom, $dateTo);
        $bestTime  = $this->getBestSendTime($dateFrom, $dateTo);
        $abSummary = $this->getABTestSummary($dateFrom, $dateTo);
        $revenue   = $this->getRevenueByTemplate($dateFrom, $dateTo);
        $unsub     = $this->getUnsubscribeCount($dateFrom, $dateTo);

        // Enrichit chaque template de son CA
        foreach ($rankings['all'] as &$row) {
            $row['revenue'] = $revenue[$row['template']] ?? 0.0;
        }
        foreach ($rankings['top3'] as &$row) {
            $row['revenue'] = $revenue[$row['template']] ?? 0.0;
        }
        foreach ($rankings['flop3'] as &$row) {
            $row['revenue'] = $revenue[$row['template']] ?? 0.0;
        }

        return [
            'year'               => $year,
            'month'              => $month,
            'date_from'          => $dateFrom,
            'date_to'            => $dateTo,
            'kpis'               => $kpis,
            'rankings'           => $rankings,
            'lang_perf'          => $langPerf,
            'best_time'          => $bestTime,
            'ab_summary'         => $abSummary,
            'revenue_total'      => array_sum($revenue),
            'revenue_by_template' => $revenue,
            'unsub'              => $unsub,
        ];
    }

    // ============================================================
    // AGRÉGATIONS SQL
    // ============================================================

    private function getMonthKpis(string $dateFrom, string $dateTo): array
    {
        $t = _DB_PREFIX_ . StatsManager::TABLE;

        $row = $this->db->getRow(
            "SELECT
                COUNT(CASE WHEN event_type = 'sent'  THEN 1 END) AS total_sent,
                COUNT(CASE WHEN event_type = 'open' AND is_mpp = 0 THEN 1 END) AS total_open,
                COUNT(CASE WHEN event_type = 'click' THEN 1 END) AS total_click,
                COUNT(DISTINCT CASE WHEN event_type = 'sent' THEN template END) AS templates_used,
                COUNT(DISTINCT CASE WHEN event_type = 'sent' THEN lang     END) AS langs_used
             FROM `{$t}`
             WHERE id_shop = {$this->idShop}
               AND date_add >= '{$dateFrom}'
               AND date_add <= '{$dateTo} 23:59:59'"
        );

        if (!$row || !(int) $row['total_sent']) {
            return [
                'total_sent' => 0, 'total_open' => 0, 'total_click' => 0,
                'rate_open' => 0.0, 'rate_click' => 0.0,
                'templates_used' => 0, 'langs_used' => 0,
            ];
        }

        $sent = (int) $row['total_sent'];
        return [
            'total_sent'     => $sent,
            'total_open'     => (int) $row['total_open'],
            'total_click'    => (int) $row['total_click'],
            'rate_open'      => round(((int) $row['total_open']  / $sent) * 100, 1),
            'rate_click'     => round(((int) $row['total_click'] / $sent) * 100, 1),
            'templates_used' => (int) $row['templates_used'],
            'langs_used'     => (int) $row['langs_used'],
        ];
    }

    private function getTemplateRankings(string $dateFrom, string $dateTo): array
    {
        $t    = _DB_PREFIX_ . StatsManager::TABLE;
        $minS = self::MIN_SENDS;

        $rows = $this->db->executeS(
            "SELECT
                template,
                COUNT(CASE WHEN event_type = 'sent'  THEN 1 END) AS total_sent,
                COUNT(CASE WHEN event_type = 'open' AND is_mpp = 0 THEN 1 END) AS total_open,
                COUNT(CASE WHEN event_type = 'click' THEN 1 END) AS total_click
             FROM `{$t}`
             WHERE id_shop = {$this->idShop}
               AND date_add >= '{$dateFrom}'
               AND date_add <= '{$dateTo} 23:59:59'
             GROUP BY template
             HAVING total_sent >= {$minS}
             ORDER BY total_sent DESC"
        ) ?: [];

        $labels = class_exists('AdminTranslator') ? AdminTranslator::templateLabels() : [];

        foreach ($rows as &$row) {
            $s = (int) $row['total_sent'];
            $row['rate_open']  = $s > 0 ? round(((int) $row['total_open']  / $s) * 100, 1) : 0.0;
            $row['rate_click'] = $s > 0 ? round(((int) $row['total_click'] / $s) * 100, 1) : 0.0;
            $row['label']      = $labels[$row['template']] ?? $row['template'];
        }

        $byOpen = $rows;
        usort($byOpen, fn($a, $b) => $b['rate_open'] <=> $a['rate_open']);

        $top3 = array_slice($byOpen, 0, 3);

        // Exclut du flop3 tout template déjà retenu dans top3 — sans ce
        // filtre, dès que moins de 6 templates atteignaient MIN_SENDS ce
        // mois-ci (fréquent sur une petite boutique ou un mois calme), les
        // deux listes se chevauchaient : un même template apparaissait à la
        // fois "top performer" et "flop performer" dans le rapport envoyé
        // au marchand.
        $top3Templates = array_column($top3, 'template');
        $flopPool = array_values(array_filter(
            $byOpen,
            fn($row) => !in_array($row['template'], $top3Templates, true)
        ));
        $flop3 = array_slice(array_reverse($flopPool), 0, 3);

        return [
            'all'   => $rows,
            'top3'  => $top3,
            'flop3' => $flop3,
        ];
    }

    private function getLangPerformance(string $dateFrom, string $dateTo): array
    {
        $t = _DB_PREFIX_ . StatsManager::TABLE;

        $rows = $this->db->executeS(
            "SELECT
                lang,
                COUNT(CASE WHEN event_type = 'sent'  THEN 1 END) AS total_sent,
                COUNT(CASE WHEN event_type = 'open' AND is_mpp = 0 THEN 1 END) AS total_open,
                COUNT(CASE WHEN event_type = 'click' THEN 1 END) AS total_click
             FROM `{$t}`
             WHERE id_shop = {$this->idShop}
               AND lang != ''
               AND date_add >= '{$dateFrom}'
               AND date_add <= '{$dateTo} 23:59:59'
             GROUP BY lang
             HAVING total_sent >= 3
             ORDER BY total_sent DESC"
        ) ?: [];

        $champion = null;
        $maxRate  = -1.0;

        foreach ($rows as &$row) {
            $s = (int) $row['total_sent'];
            $row['rate_open']  = $s > 0 ? round(((int) $row['total_open']  / $s) * 100, 1) : 0.0;
            $row['rate_click'] = $s > 0 ? round(((int) $row['total_click'] / $s) * 100, 1) : 0.0;

            if ((float) $row['rate_open'] > $maxRate) {
                $maxRate  = (float) $row['rate_open'];
                $champion = $row;
            }
        }

        return ['all' => $rows, 'champion' => $champion];
    }

    private function getBestSendTime(string $dateFrom, string $dateTo): array
    {
        $t = _DB_PREFIX_ . StatsManager::TABLE;

        // Groupé sur le COUPLE (jour, heure), pas sur chaque dimension
        // séparément : le rapport affiche "meilleur moment : {jour} à {heure}"
        // comme une combinaison mesurée conjointement — un calcul indépendant
        // par dimension pouvait recommander une combinaison qui n'existe même
        // pas dans les données réelles (ex: mardi meilleur jour toutes heures
        // confondues, mais 14h jamais un bon moment le mardi spécifiquement).
        // COUNT(DISTINCT s.tracking_token)/(... s.tracking_token END) plutôt
        // que COUNT(*)/SUM(CASE...) sur les lignes jointes : un même envoi
        // rechargeant plusieurs fois le pixel de tracking (fréquent) produit
        // plusieurs lignes 'open' pour le même tracking_token — le LEFT JOIN
        // les multipliait toutes, gonflant à la fois total_sent (!) et
        // total_open, et faussant le "meilleur moment d'envoi" recommandé.
        // is_mpp=0 : exclut les pré-chargements Apple Mail Privacy
        // Protection, cohérent avec StatsManager partout ailleurs.
        $combos = $this->db->executeS(
            "SELECT
                DAYOFWEEK(s.date_add) AS dow,
                HOUR(s.date_add)      AS hour_of_day,
                COUNT(DISTINCT s.tracking_token) AS total_sent,
                COUNT(DISTINCT CASE WHEN o.tracking_token IS NOT NULL THEN s.tracking_token END) AS total_open
             FROM `{$t}` s
             LEFT JOIN `{$t}` o
                ON o.tracking_token = s.tracking_token
               AND o.event_type = 'open'
               AND o.is_mpp = 0
             WHERE s.id_shop = {$this->idShop}
               AND s.event_type = 'sent'
               AND s.date_add >= '{$dateFrom}'
               AND s.date_add <= '{$dateTo} 23:59:59'
             GROUP BY DAYOFWEEK(s.date_add), HOUR(s.date_add)
             HAVING total_sent >= 3
             ORDER BY (COUNT(DISTINCT CASE WHEN o.tracking_token IS NOT NULL THEN s.tracking_token END) / COUNT(DISTINCT s.tracking_token)) DESC
             LIMIT 1"
        ) ?: [];

        return [
            'best_day'  => isset($combos[0]) ? (int) $combos[0]['dow']        : null,
            'best_hour' => isset($combos[0]) ? (int) $combos[0]['hour_of_day'] : null,
        ];
    }

    private function getABTestSummary(string $dateFrom, string $dateTo): array
    {
        $t = _DB_PREFIX_ . StatsManager::TABLE;

        // Seuil HAVING aligné sur StatsManager::SIG_MIN_SAMPLE (100) — la
        // valeur précédente (5 envois) déclarait un "gagnant" dans le
        // rapport mensuel sur un échantillon bien trop petit pour être
        // autre chose que du bruit statistique, alors que StatsManager
        // exige lui un z-test avec au moins 100 envois par variante avant
        // toute annonce de significativité côté dashboard BO. Incohérent
        // entre les deux, potentiellement trompeur pour le marchand.
        $rows = $this->db->executeS(
            "SELECT
                template,
                abtest_variant,
                COUNT(CASE WHEN event_type = 'sent'  THEN 1 END) AS total_sent,
                COUNT(CASE WHEN event_type = 'open' AND is_mpp = 0 THEN 1 END) AS total_open,
                COUNT(CASE WHEN event_type = 'click' THEN 1 END) AS total_click
             FROM `{$t}`
             WHERE id_shop = {$this->idShop}
               AND abtest_variant IN ('A', 'B')
               AND date_add >= '{$dateFrom}'
               AND date_add <= '{$dateTo} 23:59:59'
             GROUP BY template, abtest_variant
             HAVING total_sent >= " . StatsManager::SIG_MIN_SAMPLE
        ) ?: [];

        $labels = class_exists('AdminTranslator') ? AdminTranslator::templateLabels() : [];
        $tests  = [];

        foreach ($rows as $row) {
            $tpl = $row['template'];
            if (!isset($tests[$tpl])) {
                $tests[$tpl] = ['template' => $tpl, 'label' => $labels[$tpl] ?? $tpl, 'A' => null, 'B' => null];
            }
            $s = (int) $row['total_sent'];
            $tests[$tpl][$row['abtest_variant']] = [
                'total_sent'  => $s,
                'total_open'  => (int) $row['total_open'],
                'rate_open'   => $s > 0 ? round(((int) $row['total_open']  / $s) * 100, 1) : 0.0,
                'rate_click'  => $s > 0 ? round(((int) $row['total_click'] / $s) * 100, 1) : 0.0,
            ];
        }

        $results = [];
        foreach ($tests as $test) {
            if ($test['A'] && $test['B']) {
                $winner    = $test['A']['rate_open'] >= $test['B']['rate_open'] ? 'A' : 'B';
                $delta     = abs($test['A']['rate_open'] - $test['B']['rate_open']);
                $results[] = array_merge($test, ['winner' => $winner, 'delta' => round($delta, 1)]);
            }
        }

        return $results;
    }

    private function getRevenueByTemplate(string $dateFrom, string $dateTo): array
    {
        $st  = _DB_PREFIX_ . StatsManager::TABLE;
        $ord = _DB_PREFIX_ . 'orders';

        // Revenus directs : commandes liées à l'envoi (transactionnel)
        // Scopé o.valid = 1 : sans ce filtre, une commande annulée ou
        // intégralement remboursée après l'envoi de l'email transactionnel
        // restait comptée dans le CA "attribué" du rapport mensuel — même
        // garde-fou déjà appliqué partout ailleurs dans le module
        // (ClvManager, UpsellManager) mais oublié ici, surestimant le CA
        // affiché au marchand.
        // Round 197 : sous-requête DISTINCT (template, id_order) avant la
        // somme — absente jusqu'ici, contrairement à la requête "attribué"
        // juste en dessous qui dédoublonne explicitement. Un renvoi manuel
        // d'un email transactionnel pour la MÊME commande (2e ligne 'sent'
        // avec le même id_order+template) faisait compter le montant de
        // cette commande deux fois dans le SUM(), gonflant le CA "direct"
        // affiché au marchand.
        $direct = [];
        $rows = $this->db->executeS(
            "SELECT template, SUM(order_total) AS revenue
             FROM (
                 SELECT DISTINCT s.template, s.id_order, o.total_paid_tax_incl AS order_total
                 FROM `{$st}` s
                 JOIN `{$ord}` o ON o.id_order = s.id_order
                 WHERE s.id_shop = {$this->idShop}
                   AND s.event_type = 'sent'
                   AND s.id_order > 0
                   AND o.valid = 1
                   AND s.date_add >= '{$dateFrom}'
                   AND s.date_add <= '{$dateTo} 23:59:59'
             ) dedup
             GROUP BY template"
        ) ?: [];
        foreach ($rows as $row) {
            $direct[$row['template']] = (float) $row['revenue'];
        }

        // Revenus attribués : commandes passées dans les 7 jours après un clic —
        // Round 165 : la jointure vers `orders` ne filtrait pas o.id_shop
        // (seul s.id_shop l'était côté stats). En multi-boutique avec
        // comptes clients partagés, une commande passée sur une AUTRE
        // boutique par le même client (id_customer partagé) après un clic
        // enregistré ici pouvait être comptée comme "revenu attribué" de
        // CETTE boutique — sur-estimation du CA affiché au marchand, sans
        // rapport avec un email réellement envoyé par cette boutique.
        // exclut les commandes déjà comptées en "direct" ci-dessus (lien
        // transactionnel explicite via id_order), sinon un client qui clique
        // sur "suivre ma commande" dans l'email de confirmation lui-même fait
        // compter deux fois le même montant (direct + attribué).
        // StatsManager::recordClick() crée volontairement UN événement par
        // clic (contrairement aux ouvertures, dédoublonnées) — un client qui
        // clique plusieurs fois sur des liens de ce template avant de
        // commander faisait joindre la MÊME commande plusieurs fois, et son
        // montant était sommé autant de fois qu'il y avait de clics. La
        // sous-requête DISTINCT ramène chaque (template, commande) à une
        // seule ligne avant la somme — une commande née de deux clics sur le
        // même template ne compte qu'une fois.
        // Un client qui clique sur DEUX templates différents avant de passer
        // la même commande faisait auparavant compter cette commande pour
        // CHAQUE template (DISTINCT (template, id_order) laisse passer une
        // ligne par template) — le CA total du rapport (somme de toutes les
        // lignes) pouvait alors dépasser le CA réellement généré. On ne
        // retient désormais que le clic le PLUS RÉCENT avant la commande
        // (last-click), cohérent avec le principe déjà appliqué par
        // AttributionManager pour l'attribution de revenus.
        $attributed = [];
        $rows2 = $this->db->executeS(
            "SELECT winner.template, SUM(winner.total_paid_tax_incl) AS revenue FROM (
                SELECT s.template, o.id_order, o.total_paid_tax_incl
                FROM `{$st}` s
                JOIN `{$ord}` o
                  ON o.id_customer = s.id_customer
                 AND o.id_customer > 0
                 AND o.id_shop = {$this->idShop}
                 AND o.valid = 1
                 AND o.date_add >= s.date_add
                 AND o.date_add <= DATE_ADD(s.date_add, INTERVAL 7 DAY)
                WHERE s.id_shop = {$this->idShop}
                  AND s.event_type = 'click'
                  AND s.date_add >= '{$dateFrom}'
                  AND s.date_add <= '{$dateTo} 23:59:59'
                  AND NOT EXISTS (
                      SELECT 1 FROM `{$st}` s2
                      WHERE s2.id_order   = o.id_order
                        AND s2.event_type = 'sent'
                        AND s2.id_order   > 0
                  )
                  AND s.date_add = (
                      SELECT MAX(s3.date_add) FROM `{$st}` s3
                      WHERE s3.id_customer  = o.id_customer
                        AND s3.event_type   = 'click'
                        AND s3.id_shop      = {$this->idShop}
                        AND s3.date_add    <= o.date_add
                        AND s3.date_add    >= DATE_SUB(o.date_add, INTERVAL 7 DAY)
                  )
                GROUP BY o.id_order, s.template
             ) winner
             GROUP BY winner.template"
        ) ?: [];
        foreach ($rows2 as $row) {
            $attributed[$row['template']] = (float) $row['revenue'];
        }

        // Fusion : direct + attribué
        $result = $direct;
        foreach ($attributed as $tpl => $rev) {
            $result[$tpl] = ($result[$tpl] ?? 0.0) + $rev;
        }

        return $result;
    }

    private function getUnsubscribeCount(string $dateFrom, string $dateTo): int
    {
        $t = _DB_PREFIX_ . 'neria_log';

        return (int) $this->db->getValue(
            "SELECT COUNT(*)
             FROM `{$t}`
             WHERE id_shop = {$this->idShop}
               AND class = 'Unsubscribe'
               AND level = 'info'
               AND date_add >= '{$dateFrom}'
               AND date_add <= '{$dateTo} 23:59:59'"
        );
    }

    // ============================================================
    // RECOMMANDATIONS AUTOMATIQUES
    // ============================================================

    /**
     * Construit les recommandations avec des CODES BRUTS (template, jour de la
     * semaine) plutôt que des libellés déjà traduits : cette méthode est
     * appelée UNE SEULE FOIS pour tout le rapport (avant la boucle sur les
     * destinataires dans deliverReport()), alors que chaque destinataire peut
     * avoir sa propre langue. Si on résolvait ici le libellé de template ou le
     * nom du jour via AdminTranslator (état global dépendant de la langue
     * courante au moment de l'appel — souvent celle du visiteur front qui a
     * déclenché checkAndSend(), sans rapport avec la langue des employés
     * destinataires), TOUS les destinataires recevraient ces bouts de texte
     * dans la même langue figée, même si le reste de l'email est bien
     * localisé par destinataire. La résolution finale a lieu dans
     * renderHtml()/renderTxt(), appelées après que AdminTranslator::setLang()
     * ait été positionné sur la langue du destinataire courant.
     */
    private function generateRecommendations(array $report, array $prev): array
    {
        $recs   = [];
        $kpis   = $report['kpis'];
        $pkpis  = $prev['kpis'];
        $all    = $report['rankings']['all'];

        // Évolution du volume global
        if ($pkpis['total_sent'] > 0 && $kpis['total_sent'] > 0) {
            $delta = round((($kpis['total_sent'] - $pkpis['total_sent']) / $pkpis['total_sent']) * 100);
            if ($delta <= -20) {
                $recs[] = ['type' => 'warning', 'key' => 'rec_volume_drop', 'vars' => ['n' => abs($delta)]];
            } elseif ($delta >= 20) {
                $recs[] = ['type' => 'success', 'key' => 'rec_volume_rise', 'vars' => ['n' => $delta]];
            }
        }

        // Star performer du mois
        if (!empty($report['rankings']['top3'])) {
            $top = $report['rankings']['top3'][0];
            if ((float) $top['rate_open'] >= 30) {
                $recs[] = ['type' => 'success', 'key' => 'rec_star', 'vars' => [
                    'template' => $top['template'],
                    'rate'     => $top['rate_open'],
                ]];
            }
        }

        // Template avec faible taux d'ouverture
        foreach ($all as $row) {
            if ((int) $row['total_sent'] >= 20 && (float) $row['rate_open'] < 10) {
                $recs[] = ['type' => 'error', 'key' => 'rec_low_open', 'vars' => [
                    'template' => $row['template'],
                    'rate'     => $row['rate_open'],
                ]];
                break;
            }
        }

        // Bon taux d'ouverture, faible clic → améliorer le CTA
        foreach ($all as $row) {
            if ((float) $row['rate_open'] >= 25 && (float) $row['rate_click'] < 2 && (int) $row['total_sent'] >= 20) {
                $recs[] = ['type' => 'warning', 'key' => 'rec_low_click', 'vars' => [
                    'template' => $row['template'],
                ]];
                break;
            }
        }

        // Meilleur moment d'envoi
        $bt = $report['best_time'];
        if ($bt['best_day'] !== null && $bt['best_hour'] !== null) {
            $recs[] = ['type' => 'info', 'key' => 'rec_best_time', 'vars' => [
                'day_dow' => $bt['best_day'], // résolu en texte à l'affichage (par destinataire)
                'hour'    => $bt['best_hour'] . 'h',
            ]];
        }

        // Gagnant A/B test significatif
        foreach ($report['ab_summary'] as $ab) {
            if ((float) $ab['delta'] >= 5) {
                $recs[] = ['type' => 'info', 'key' => 'rec_ab_winner', 'vars' => [
                    'template' => $ab['template'],
                    'winner'   => $ab['winner'],
                    'delta'    => $ab['delta'],
                ]];
                break;
            }
        }

        // Désabonnements élevés
        if ($report['unsub'] >= 10) {
            $recs[] = ['type' => 'warning', 'key' => 'rec_unsub_high', 'vars' => ['n' => $report['unsub']]];
        }

        // CA attribué notable — montant brut stocké tel quel, formaté plus
        // tard dans resolveRecVars() au moment du rendu PAR DESTINATAIRE (même
        // logique que 'day_dow'/'template' ci-dessus) : ce rapport est envoyé
        // à plusieurs destinataires potentiellement dans des langues
        // différentes (NERIA_MONTHLY_REPORT_RECIPIENTS), donc un formatage
        // figé ici (virgule française codée en dur) serait faux pour tous
        // les destinataires non francophones.
        if ($report['revenue_total'] > 0) {
            $recs[] = ['type' => 'success', 'key' => 'rec_revenue', 'vars' => [
                'amount_raw' => (float) $report['revenue_total'],
            ]];
        }

        return $recs;
    }

    // ============================================================
    // LIVRAISON DE L'EMAIL
    // ============================================================

    /**
     * Round 157 : GET_LOCK() global (pas suffixé par idShop) — deliverReportLocked()
     * écrit le rendu compilé sur un chemin de fichier FIXE et partagé
     * (mails/{iso}/monthly_report.html|.txt, pas unique par exécution),
     * lu ensuite par Mail::Send(). checkAndSend() est déjà protégé par
     * GET_LOCK('neria_monthly_report_check', ...), mais l'action BO
     * "Envoyer maintenant" (neria.php::send_report_now) appelle
     * sendReport() → deliverReport() directement, SANS passer par ce
     * verrou. Sur une install multi-boutiques, un envoi automatique en
     * cours pour la Boutique A et un clic "Envoyer maintenant" pour la
     * Boutique B au même instant pouvaient écraser mutuellement le même
     * fichier compilé pendant que Mail::Send() de l'autre boutique était
     * encore en train de le lire — un destinataire de la Boutique A
     * pouvant recevoir le contenu (CA, KPI) de la Boutique B. Verrou
     * global (pas par boutique) car c'est le CHEMIN DE FICHIER partagé
     * entre TOUTES les boutiques qui est la ressource à protéger, pas une
     * donnée par-boutique.
     */
    private function deliverReport(array $data): bool
    {
        if ((int) $this->db->getValue("SELECT GET_LOCK('neria_monthly_report_deliver', 5)") !== 1) {
            return false;
        }
        try {
            return $this->deliverReportLocked($data);
        } finally {
            $this->db->execute("SELECT RELEASE_LOCK('neria_monthly_report_deliver')");
        }
    }

    private function deliverReportLocked(array $data): bool
    {
        $recipients = $this->getRecipients();
        if (empty($recipients)) {
            return false;
        }

        $shopName = (string) \Configuration::get('PS_SHOP_NAME');
        $mailDir     = _PS_MODULE_DIR_ . 'neria/mails/';
        $wd          = class_exists('WatchdogManager') ? new \WatchdogManager($this->module) : null;
        $defaultLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        // Résout la langue de chaque destinataire : si son email correspond à
        // un employé BO (cas courant — le rapport part surtout à l'équipe),
        // on utilise SA langue configurée plutôt que d'imposer la langue par
        // défaut de la boutique à tout le monde.
        // Round 180 : filtré par la boutique DU RAPPORT ($this->idShop), pas
        // tous les employés actifs de l'install — sans lui, sur une install
        // multi-boutiques où un employé n'a accès qu'à la Boutique A, un
        // rapport envoyé pour la Boutique B à cette même adresse utilisait
        // quand même la langue BO configurée de cet employé plutôt que la
        // langue par défaut de la boutique concernée. Mineur (repli de
        // langue seulement, pas de fuite de données) mais incohérent avec
        // le scoping id_shop appliqué partout ailleurs dans ce fichier.
        $employeeLangs = [];
        $empRows = $this->db->executeS(
            'SELECT e.`email`, e.`id_lang`
             FROM `' . _DB_PREFIX_ . 'employee` e
             INNER JOIN `' . _DB_PREFIX_ . 'employee_shop` es ON es.`id_employee` = e.`id_employee`
             WHERE e.`active` = 1 AND es.`id_shop` = ' . $this->idShop
        ) ?: [];
        foreach ($empRows as $row) {
            $employeeLangs[mb_strtolower(trim((string) $row['email']))] = (int) $row['id_lang'];
        }

        // Rendu + écriture disque une seule fois par langue réellement utilisée
        // (pas une fois par destinataire) — évite le travail redondant si
        // plusieurs destinataires partagent la même langue.
        $renderedLangs = [];
        $writtenFiles  = [];
        $originalLang  = class_exists('AdminTranslator') ? \AdminTranslator::currentLang() : null;

        // Round 157 : $ok distingue désormais "tous les destinataires ont
        // reçu le rapport" (utilisé pour le log Watchdog succès/erreur) de
        // $anySent, "au moins un destinataire l'a reçu" (utilisé par
        // checkAndSend() pour décider markSent()). Avant ce correctif, UN
        // SEUL destinataire en échec (ex. adresse mal configurée côté SMTP
        // relais) empêchait markSent() pour tout le mois — isDue() restait
        // vrai pendant toute la fenêtre de rattrapage (1er au 7), et
        // checkAndSend() régénérait puis renvoyait le rapport EN ENTIER à
        // TOUS les destinataires, y compris ceux qui l'avaient déjà reçu
        // correctement, à chaque chargement de page suivant.
        $ok         = true;
        $anySent    = false;
        $failEmail  = '';
        $failEmails = [];
        foreach ($recipients as $email) {
            if (!\Validate::isEmail($email)) {
                if ($wd) {
                    $wd->warning(
                        \WatchdogManager::i18nMsg('watchdog.invalid_email', ['email' => $email]),
                        '',
                        'MonthlyReportManager'
                    );
                }
                continue;
            }

            $recipientLangId = $employeeLangs[mb_strtolower(trim($email))] ?? $defaultLang;

            if (!isset($renderedLangs[$recipientLangId])) {
                $recipientIso = \Language::getIsoById($recipientLangId) ?: 'fr';
                $langDir      = $mailDir . $recipientIso . '/';

                // $t() (utilisé par renderHtml/renderTxt) traduit via l'état
                // global d'AdminTranslator, pas via un paramètre de langue —
                // on doit donc bien le faire pointer sur CETTE langue avant
                // de générer le contenu, sinon tous les destinataires
                // recevraient le même contenu quelle que soit leur langue.
                if (class_exists('AdminTranslator')) {
                    \AdminTranslator::setLang($recipientIso);
                }

                // Round 180 : month_label recalculé pour CETTE langue —
                // auparavant figé une seule fois dans sendReport() (langue
                // du contexte ayant déclenché le hook), donc jamais
                // retraduit ici malgré le setLang() juste au-dessus. Un
                // destinataire anglophone recevait "Janvier 2026" en titre
                // pendant que tout le reste de l'email était bien en
                // anglais. $data local à cette itération (pas de fuite
                // entre langues, chaque bloc de rendu utilise sa propre
                // valeur avant de passer à la langue suivante).
                $data['month_label'] = $this->formatMonthLabel($data['year'], $data['month'], $recipientIso);

                if (!is_dir($langDir) && !mkdir($langDir, 0755, true)) {
                    if ($wd) {
                        $wd->error(
                            \WatchdogManager::i18nMsg('watchdog.mkdir_error', ['lang' => $recipientIso]),
                            '',
                            'MonthlyReportManager'
                        );
                    }
                    continue;
                }

                $htmlFull = $this->renderHtml($data, $recipientIso);
                $txtFull  = $this->renderTxt($data, $recipientIso);

                if (file_put_contents($langDir . 'monthly_report.html', $htmlFull) === false) {
                    if ($wd) {
                        $wd->error(
                            \WatchdogManager::i18nMsg('watchdog.report_write_error', ['lang' => $recipientIso]),
                            '',
                            'MonthlyReportManager'
                        );
                    }
                    continue;
                }
                file_put_contents($langDir . 'monthly_report.txt', $txtFull);
                $writtenFiles[] = $langDir . 'monthly_report.html';
                $writtenFiles[] = $langDir . 'monthly_report.txt';

                // Sujet localisé lui aussi (utilise le même état AdminTranslator
                // qui vient d'être basculé sur $recipientIso ci-dessus).
                $renderedLangs[$recipientLangId] = $this->t('report.email_subject', [
                    'month' => $data['month_label'],
                    'shop'  => $shopName,
                ]);
            }

            $sent = \Mail::Send(
                $recipientLangId,
                'monthly_report',
                $renderedLangs[$recipientLangId],
                [],
                $email,
                $shopName,
                (string) \Configuration::get('PS_SHOP_EMAIL'),
                $shopName,
                null,
                null,
                $mailDir
            );

            if (!$sent) {
                $ok          = false;
                $failEmail   = $email;
                $failEmails[] = $email;
            } else {
                $anySent = true;
            }
        }

        // Nettoyage des fichiers temporaires compilés (une paire par langue
        // réellement utilisée, cf. boucle ci-dessus).
        foreach ($writtenFiles as $f) {
            @unlink($f);
        }

        // Restaure la langue d'origine — setLang() modifie un état global,
        // ne doit jamais fuiter sur le reste du rendu de la page BO courante.
        if ($originalLang !== null && class_exists('AdminTranslator')) {
            \AdminTranslator::setLang($originalLang);
        }

        if ($wd) {
            if ($ok) {
                $wd->info(
                    \WatchdogManager::i18nMsg('watchdog.report_sent', [
                        'month' => $data['month_label'],
                        'count' => $data['kpis']['total_sent'],
                    ]),
                    '',
                    'MonthlyReportManager',
                    ['recipients' => count($recipients)]
                );
            } elseif ($anySent) {
                // Échec partiel : au moins un envoi a réussi, mais pas tous
                // — averti en warning (pas error, pour ne pas déclencher les
                // mêmes alertes qu'un échec total) avec la liste des
                // adresses en échec, pour que le marchand puisse corriger
                // ces adresses précises sans que le mois entier ne soit
                // renvoyé à tout le monde.
                $wd->warning(
                    \WatchdogManager::i18nMsg('watchdog.report_partial_failure', [
                        'month' => $data['month_label'],
                        'list'  => implode(', ', $failEmails),
                    ]),
                    '',
                    'MonthlyReportManager'
                );
            } else {
                $smtpMethod = \Configuration::get('PS_MAIL_METHOD');
                $msgKey     = ($smtpMethod == 2) ? 'watchdog.report_failed_smtp' : 'watchdog.report_failed_mail';
                $wd->error(
                    \WatchdogManager::i18nMsg($msgKey, ['month' => $data['month_label'], 'email' => $failEmail]),
                    '',
                    'MonthlyReportManager'
                );
            }
        }

        // Round 157 : markSent() ne doit se déclencher que si AU MOINS UN
        // envoi a réussi (pas 100%) — sinon un seul destinataire en échec
        // provoquait un renvoi intégral du rapport à tout le monde à
        // chaque chargement de page pendant toute la fenêtre de rattrapage
        // (voir commentaire détaillé sur $anySent plus haut).
        return $anySent;
    }

    // ============================================================
    // RENDU HTML
    // ============================================================

    private function renderHtml(array $d, string $lang): string
    {
        $t        = fn(string $k, array $v = []) => $this->t('report.' . $k, $v);
        $kpis     = $d['kpis'];
        $prev     = $d['prev']['kpis'];
        // Round 188 : Configuration::get(..., $this->idShop) explicite —
        // Currency::getDefaultCurrency() lit PS_CURRENCY_DEFAULT sans idShop,
        // qui retombe sur Shop::$context_id_shop (contexte STATIQUE). La
        // boucle multi-boutique de checkAndSend() ne fait que réassigner
        // Context::getContext()->shop (round 165) — jamais Shop::setContext()
        // — donc ce contexte statique reste celui du visiteur front ayant
        // déclenché le hook, pas forcément la boutique DU RAPPORT en cours de
        // rendu. Sur une install multi-boutiques à devises différentes, le CA
        // s'affichait avec le symbole/format d'une AUTRE boutique.
        $idCurrency = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $this->idShop);
        $currency   = $idCurrency > 0 ? new \Currency($idCurrency) : false;
        $symbol   = $currency ? $currency->sign : 'EUR';
        // Formatage localisé du CA (séparateur décimal + position du symbole
        // selon $lang) — auparavant number_format(..., ',', ' ') codé en dur,
        // faux pour tout destinataire non francophone de ce rapport mensuel.
        $idLangReport = (int) \Language::getIdByIso($lang) ?: (int) \Configuration::get('PS_LANG_DEFAULT');
        $fmtAmount    = function (float $amount) use ($currency, $idLangReport, $symbol): string {
            return $currency ? \NeriaTools::displayPrice($amount, $currency, $idLangReport) : $symbol . $amount;
        };

        $delta = static function (float $curr, float $prev): string {
            if ($prev == 0 || $curr == $prev) { return ''; }
            $pct   = round((($curr - $prev) / $prev) * 100);
            $color = $pct >= 0 ? '#1a7a40' : '#c0392b';
            $arrow = $pct >= 0 ? '&uarr;' : '&darr;';
            return ' <span style="color:' . $color . ';font-size:11px;">' . $arrow . ' ' . abs($pct) . '%</span>';
        };

        // Résolus ICI (au rendu, par destinataire) plutôt qu'au moment du
        // build du rapport : AdminTranslator::templateLabels()/dayName()
        // dépendent de la langue globale courante, qui n'est positionnée sur
        // celle du destinataire courant qu'à ce stade (cf. deliverReport()).
        $labels = class_exists('AdminTranslator') ? AdminTranslator::templateLabels() : [];

        $thStyle    = 'padding:8px 12px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#8a8278;font-weight:normal;text-align:';
        $renderRows = function (array $rows) use ($fmtAmount, $labels): string {
            $html = '';
            foreach ($rows as $i => $row) {
                $num = ($i + 1) . '.';
                $label = $labels[$row['template']] ?? ($row['label'] ?? $row['template']);
                $rev = $row['revenue'] > 0
                    ? ' <span style="color:#b38b59;font-size:11px;">' . $fmtAmount((float) $row['revenue']) . '</span>'
                    : '';
                $html .= '<tr>'
                    . '<td style="padding:9px 12px;border-bottom:1px solid #f0ede6;font-size:13px;">' . $num . ' ' . htmlspecialchars($label) . '</td>'
                    . '<td style="padding:9px 12px;border-bottom:1px solid #f0ede6;text-align:center;font-size:13px;">' . $row['total_sent'] . '</td>'
                    . '<td style="padding:9px 12px;border-bottom:1px solid #f0ede6;text-align:center;font-size:13px;color:#1a7a40;font-weight:bold;">' . $row['rate_open'] . '%</td>'
                    . '<td style="padding:9px 12px;border-bottom:1px solid #f0ede6;text-align:center;font-size:13px;">' . $row['rate_click'] . '%' . $rev . '</td>'
                    . '</tr>';
            }
            return $html;
        };

        $recColors = ['success' => '#e8f5e9', 'warning' => '#fff8e1', 'error' => '#ffebee', 'info' => '#e3f2fd'];
        $recsHtml  = '';
        foreach ($d['recommendations'] as $rec) {
            $bg       = $recColors[$rec['type']] ?? '#f5f5f5';
            $msg      = $this->t('report.' . $rec['key'], $this->resolveRecVars($rec['vars'], $labels, $lang));
            $recsHtml .= '<p style="margin:6px 0;padding:10px 14px;background:' . $bg . ';border-radius:3px;font-size:13px;line-height:1.5;">' . htmlspecialchars($msg) . '</p>';
        }

        $abHtml = '';
        foreach ($d['ab_summary'] as $ab) {
            $tickA  = $ab['winner'] === 'A' ? ' &check;' : '';
            $tickB  = $ab['winner'] === 'B' ? ' &check;' : '';
            $abLabel = $labels[$ab['template']] ?? ($ab['label'] ?? $ab['template']);
            $abHtml .= '<tr>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #f0ede6;font-size:13px;">' . htmlspecialchars($abLabel) . '</td>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #f0ede6;text-align:center;font-size:13px;">' . $ab['A']['rate_open'] . '%' . $tickA . '</td>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #f0ede6;text-align:center;font-size:13px;">' . $ab['B']['rate_open'] . '%' . $tickB . '</td>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #f0ede6;text-align:center;font-size:13px;color:#b38b59;">+' . $ab['delta'] . '%</td>'
                . '</tr>';
        }

        $sec = 'margin:24px 0 10px;font-size:13px;letter-spacing:0.12em;text-transform:uppercase;color:#2c2c2c;border-bottom:1px solid #e8e4dc;padding-bottom:10px;';

        $h  = '<h1 class="neria-title-main">' . $t('header_title', ['month' => $d['month_label']]) . '</h1>';
        $h .= '<h2 style="' . $sec . '">' . $t('section_kpis') . '</h2>';
        $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;"><tr>';
        $h .= '<td style="text-align:center;padding:12px 6px;"><strong style="font-size:24px;color:#2c2c2c;">' . $kpis['total_sent'] . '</strong><br><span style="font-size:11px;color:#8a8278;">' . $t('kpi_sent') . '</span>' . $delta((float)$kpis['total_sent'], (float)$prev['total_sent']) . '</td>';
        $h .= '<td style="text-align:center;padding:12px 6px;border-left:1px solid #f0ede6;"><strong style="font-size:24px;color:#1a7a40;">' . $kpis['rate_open'] . '%</strong><br><span style="font-size:11px;color:#8a8278;">' . $t('kpi_open_rate') . '</span>' . $delta((float)$kpis['rate_open'], (float)$prev['rate_open']) . '</td>';
        $h .= '<td style="text-align:center;padding:12px 6px;border-left:1px solid #f0ede6;"><strong style="font-size:24px;color:#b38b59;">' . $kpis['rate_click'] . '%</strong><br><span style="font-size:11px;color:#8a8278;">' . $t('kpi_click_rate') . '</span>' . $delta((float)$kpis['rate_click'], (float)$prev['rate_click']) . '</td>';
        if ($d['revenue_total'] > 0) {
            $h .= '<td style="text-align:center;padding:12px 6px;border-left:1px solid #f0ede6;"><strong style="font-size:20px;color:#b38b59;">' . $fmtAmount((float) $d['revenue_total']) . '</strong><br><span style="font-size:11px;color:#8a8278;">' . $t('kpi_revenue') . '</span></td>';
        }
        $h .= '</tr></table>';

        if (!empty($d['rankings']['top3'])) {
            $h .= '<h2 style="' . $sec . '">' . $t('section_top3') . '</h2>';
            $h .= '<table width="100%" cellpadding="0" cellspacing="0"><tr style="background:#f8f7f4;"><th style="' . $thStyle . 'left;">' . $t('col_template') . '</th><th style="' . $thStyle . 'center;">' . $t('col_sent') . '</th><th style="' . $thStyle . 'center;">' . $t('col_open') . '</th><th style="' . $thStyle . 'center;">' . $t('col_click_rev') . '</th></tr>' . $renderRows($d['rankings']['top3']) . '</table>';
        }
        if (!empty($d['rankings']['flop3'])) {
            $h .= '<h2 style="' . $sec . '">' . $t('section_flop3') . '</h2>';
            $h .= '<table width="100%" cellpadding="0" cellspacing="0"><tr style="background:#f8f7f4;"><th style="' . $thStyle . 'left;">' . $t('col_template') . '</th><th style="' . $thStyle . 'center;">' . $t('col_sent') . '</th><th style="' . $thStyle . 'center;">' . $t('col_open') . '</th><th style="' . $thStyle . 'center;">' . $t('col_click_rev') . '</th></tr>' . $renderRows($d['rankings']['flop3']) . '</table>';
        }
        if (!empty($d['lang_perf']['champion'])) {
            $champ = $d['lang_perf']['champion'];
            $h .= '<h2 style="' . $sec . '">' . $t('section_lang') . '</h2>';
            $h .= '<p class="neria-text"><strong>' . strtoupper($champ['lang']) . '</strong> &mdash; ' . $champ['rate_open'] . '% ' . $t('open_rate_label') . ' &middot; ' . $champ['total_sent'] . ' ' . $t('emails_label') . '</p>';
        }
        if ($d['best_time']['best_day'] !== null && $d['best_time']['best_hour'] !== null) {
            $h .= '<h2 style="' . $sec . '">' . $t('section_best_time') . '</h2>';
            $h .= '<p class="neria-text"><strong>' . $this->dayName($d['best_time']['best_day'], $lang) . '</strong> &agrave; <strong>' . $d['best_time']['best_hour'] . 'h</strong></p>';
        }
        if (!empty($abHtml)) {
            $h .= '<h2 style="' . $sec . '">' . $t('section_abtest') . '</h2>';
            $h .= '<table width="100%" cellpadding="0" cellspacing="0"><tr style="background:#f8f7f4;"><th style="' . $thStyle . 'left;">' . $t('col_template') . '</th><th style="' . $thStyle . 'center;">A</th><th style="' . $thStyle . 'center;">B</th><th style="' . $thStyle . 'center;">' . $t('col_gap') . '</th></tr>' . $abHtml . '</table>';
        }
        if (!empty($recsHtml)) {
            $h .= '<h2 style="' . $sec . '">' . $t('section_recs') . '</h2>' . $recsHtml;
        }
        if ($d['unsub'] > 0) {
            $h .= '<p class="neria-text-note">' . $t('unsub_note', ['n' => $d['unsub']]) . '</p>';
        }

        $shopName = htmlspecialchars((string) \Configuration::get('PS_SHOP_NAME'));
        $shopUrl  = \Context::getContext()->link ? \Context::getContext()->link->getBaseLink() : '#';

        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<style>';
        $html .= 'body{margin:0;padding:0;background:#f4f1eb;font-family:Georgia,serif;}';
        $html .= 'table{border-collapse:collapse;}';
        $html .= '.wrap{background:#f4f1eb;padding:32px 0;}';
        $html .= '.container{max-width:600px;margin:0 auto;background:#fff;border-radius:4px;}';
        $html .= '.header{text-align:center;padding:28px 20px 20px;border-bottom:2px solid #b38b59;}';
        $html .= '.brand{font-size:13px;letter-spacing:0.18em;text-transform:uppercase;color:#b38b59;font-weight:bold;}';
        $html .= '.inner{padding:36px 40px 44px;}';
        $html .= '.neria-title-main{font-size:20px;font-weight:bold;letter-spacing:0.04em;text-align:center;color:#2b2520;margin:0 0 24px;}';
        $html .= '.neria-text{font-size:14px;line-height:1.8;color:#3a3530;margin:8px 0;}';
        $html .= '.neria-text-note{font-size:12px;color:#6f6a62;margin:12px 0;}';
        $html .= '.footer{text-align:center;padding:20px;font-size:11px;color:#6b6459;border-top:1px solid #f0e7db;line-height:2;}';
        $html .= '.footer a{color:#6b6459;}';
        $html .= '</style></head><body>';
        $html .= '<div class="wrap"><table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">';
        $html .= '<table class="container" width="600" cellpadding="0" cellspacing="0">';
        $html .= '<tr><td class="header"><div class="brand">' . $shopName . '</div></td></tr>';
        $html .= '<tr><td class="inner">' . $h . '</td></tr>';
        $html .= '<tr><td class="footer"><a href="' . htmlspecialchars($shopUrl) . '">' . $shopName . '</a></td></tr>';
        $html .= '</table></td></tr></table></div></body></html>';

        return $html;
    }

    // ============================================================
    // RENDU TXT
    // ============================================================

    private function renderTxt(array $d, string $lang): string
    {
        $t    = fn(string $k, array $v = []) => $this->t('report.' . $k, $v);
        $kpis = $d['kpis'];
        $sep  = str_repeat('-', 48);

        $lines = [
            $t('header_title', ['month' => $d['month_label']]),
            $sep,
            '',
            $t('section_kpis'),
            $t('kpi_sent')       . ' : ' . $kpis['total_sent'],
            $t('kpi_open_rate')  . ' : ' . $kpis['rate_open'] . '%',
            $t('kpi_click_rate') . ' : ' . $kpis['rate_click'] . '%',
            '',
        ];

        if (!empty($d['rankings']['top3'])) {
            $lines[] = $t('section_top3');
            foreach ($d['rankings']['top3'] as $i => $row) {
                $lines[] = ($i + 1) . '. ' . $row['label'] . ' - ' . $row['rate_open'] . '% ' . $t('open_rate_label');
            }
            $lines[] = '';
        }

        if (!empty($d['recommendations'])) {
            $labels = class_exists('AdminTranslator') ? AdminTranslator::templateLabels() : [];
            $lines[] = $t('section_recs');
            foreach ($d['recommendations'] as $rec) {
                $lines[] = '- ' . $this->t('report.' . $rec['key'], $this->resolveRecVars($rec['vars'], $labels, $lang));
            }
        }

        return implode("\n", $lines);
    }

    // ============================================================
    // UTILITAIRES
    // ============================================================

    public function isDue(?int $idShop = null): bool
    {
        $key = $idShop !== null ? (self::CONFIG_LAST_SENT . '_' . $idShop) : self::CONFIG_LAST_SENT;
        // Round 111 : $idShop transmis explicitement en 4e argument.
        // Context::getContext()->shop = new \Shop($idShop) (checkAndSend())
        // ne modifie PAS Shop::$context_id_shop (seul Shop::setContext() le
        // fait) — Configuration::get() sans $idShop explicite résolvait donc
        // toujours la boutique RÉELLE du visiteur ayant déclenché le hook,
        // pas celle en cours d'itération dans la boucle. Le nom de clé était
        // déjà suffixé par boutique, mais la ligne était lue/écrite sous le
        // mauvais id_shop, cassant la déduplication entre boutiques.
        $last   = (string) \Configuration::get($key, null, null, $idShop);
        $target = date('Y-m', strtotime('last month'));

        if ($last === $target) {
            return false;
        }

        // Round 165 : isDue() ne compare qu'au mois précédent immédiat et
        // n'autorise l'envoi que du 1er au 7 du mois courant. Si aucun hook
        // ne se déclenche pour cette boutique pendant TOUTE cette fenêtre
        // de 7 jours (boutique fermée temporairement, site en maintenance,
        // trafic front nul), le rapport du mois manqué n'est jamais
        // rattrapé : au mois suivant, $target a déjà glissé et l'écart se
        // referme silencieusement, sans trace. Un vrai rattrapage rétroactif
        // serait risqué (pourrait renvoyer plusieurs rapports d'un coup à
        // la première visite) — on se contente ici de rendre l'incident
        // visible plutôt que silencieux.
        if ($last !== '' && $last !== '0') {
            try {
                $lastDate   = \DateTime::createFromFormat('Y-m', $last);
                $targetDate = \DateTime::createFromFormat('Y-m', $target);
                if ($lastDate !== false && $targetDate !== false) {
                    $monthsGap = ((int) $targetDate->format('Y') - (int) $lastDate->format('Y')) * 12
                        + ((int) $targetDate->format('n') - (int) $lastDate->format('n'));
                    if ($monthsGap > 1 && class_exists('WatchdogManager')) {
                        (new \WatchdogManager($this->module))->warning(
                            \WatchdogManager::i18nMsg('watchdog.monthly_report_gap_detected', [
                                'last'   => $last,
                                'target' => $target,
                                'months' => $monthsGap,
                            ]),
                            '',
                            'MonthlyReportManager'
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Best-effort — ne doit jamais empêcher isDue() de répondre.
            }
        }

        // Fenêtre d'envoi : 1er au 7 du mois courant (rattrapage inclus)
        return (int) date('j') <= 7;
    }

    private function markSent(int $year, int $month, ?int $idShop = null): void
    {
        $key = $idShop !== null ? (self::CONFIG_LAST_SENT . '_' . $idShop) : self::CONFIG_LAST_SENT;
        \Configuration::updateValue(
            $key,
            sprintf('%04d-%02d', $year, $month),
            false,
            null,
            $idShop
        );
    }

    private function getRecipients(): array
    {
        // Round 145 : $this->idShop transmis explicitement aux deux lectures
        // — contrairement à toutes les requêtes KPI de ce fichier (déjà
        // scopées id_shop), cette méthode ignorait la boutique et résolvait
        // systématiquement la config de la boutique du contexte ambiant.
        // Dans la boucle multi-boutique de checkAndSend(), le rapport de la
        // Boutique B pouvait ainsi être envoyé aux destinataires configurés
        // pour la Boutique A (ou l'inverse) — fuite d'information commerciale
        // entre équipes gérant des boutiques distinctes de la même install.
        $stored = (string) \Configuration::get(self::CONFIG_RECIPIENTS, null, null, $this->idShop);
        if ($stored) {
            $emails = array_values(array_filter(
                array_map('trim', explode(',', $stored)),
                fn($e) => \Validate::isEmail($e)
            ));
            if (!empty($emails)) {
                return $emails;
            }
        }

        $default = (string) \Configuration::get('PS_SHOP_EMAIL', null, null, $this->idShop);
        return $default ? [$default] : [];
    }

    // Round 180 : $lang optionnel ajouté — auparavant toujours résolu via
    // AdminTranslator::currentLang() (état global au moment de l'appel),
    // ce qui figeait month_label/prev_month_label dans UNE SEULE langue
    // dès sendReport()/previewHtml(), avant même que deliverReportLocked()
    // ne bascule AdminTranslator::setLang() par destinataire. Un
    // destinataire anglophone recevait donc "Janvier 2026" en titre alors
    // que le reste du contenu (labels, recommandations) était bien
    // retraduit en anglais — month_label n'était jamais recalculé dans la
    // boucle par langue. $lang=null préserve le comportement historique
    // (état global courant) pour les appelants qui ne le passent pas.
    private function formatMonthLabel(int $year, int $month, ?string $lang = null): string
    {
        $lang = $lang ?? (class_exists('AdminTranslator') ? AdminTranslator::currentLang() : 'fr');

        $months = [
            'fr' => ['', 'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'],
            'en' => ['', 'January','February','March','April','May','June','July','August','September','October','November','December'],
            'de' => ['', 'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'],
            'it' => ['', 'Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'],
            'es' => ['', 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'],
            'pt' => ['', 'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],
            'br' => ['', 'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],
            'ar' => ['', 'يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'],
            'ja' => ['', '1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'],
            'ko' => ['', '1월','2월','3월','4월','5월','6월','7월','8월','9월','10월','11월','12월'],
            'zh' => ['', '1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'],
            'tw' => ['', '1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'],
            'ru' => ['', 'Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'],
            'tr' => ['', 'Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'],
            'sv' => ['', 'Januari','Februari','Mars','April','Maj','Juni','Juli','Augusti','September','Oktober','November','December'],
            'no' => ['', 'Januar','Februar','Mars','April','Mai','Juni','Juli','August','September','Oktober','November','Desember'],
            'da' => ['', 'Januar','Februar','Marts','April','Maj','Juni','Juli','August','September','Oktober','November','December'],
            'nl' => ['', 'Januari','Februari','Maart','April','Mei','Juni','Juli','Augustus','September','Oktober','November','December'],
        ];

        $m = ($months[$lang] ?? $months['en'])[$month] ?? $month;
        return "{$m} {$year}";
    }

    private function dayName(int $dow, string $lang): string
    {
        // DAYOFWEEK MySQL : 1=Dimanche … 7=Samedi
        $days = [
            'fr' => ['', 'Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'],
            'en' => ['', 'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],
            'de' => ['', 'Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'],
            'it' => ['', 'Domenica','Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato'],
            'es' => ['', 'Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'],
            'pt' => ['', 'Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'],
            'br' => ['', 'Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'],
            'ar' => ['', 'الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'],
            'ja' => ['', '日曜日','月曜日','火曜日','水曜日','木曜日','金曜日','土曜日'],
            'ko' => ['', '일요일','월요일','화요일','수요일','목요일','금요일','토요일'],
            'zh' => ['', '周日','周一','周二','周三','周四','周五','周六'],
            'tw' => ['', '週日','週一','週二','週三','週四','週五','週六'],
            'ru' => ['', 'Воскресенье','Понедельник','Вторник','Среда','Четверг','Пятница','Суббота'],
            'tr' => ['', 'Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'],
            'sv' => ['', 'Söndag','Måndag','Tisdag','Onsdag','Torsdag','Fredag','Lördag'],
            'no' => ['', 'Søndag','Mandag','Tirsdag','Onsdag','Torsdag','Fredag','Lørdag'],
            'da' => ['', 'Søndag','Mandag','Tirsdag','Onsdag','Torsdag','Fredag','Lørdag'],
            'nl' => ['', 'Zondag','Maandag','Dinsdag','Woensdag','Donderdag','Vrijdag','Zaterdag'],
        ];

        return ($days[$lang] ?? $days['en'])[$dow] ?? (string) $dow;
    }

    /**
     * Résout, au moment du rendu (langue déjà positionnée sur le
     * destinataire courant), les codes bruts stockés dans generateRecommendations()
     * : code de template -> libellé localisé, index de jour -> nom du jour localisé.
     */
    private function resolveRecVars(array $vars, array $labels, string $lang): array
    {
        if (isset($vars['template'])) {
            $vars['template'] = $labels[$vars['template']] ?? $vars['template'];
        }
        if (isset($vars['day_dow'])) {
            $vars['day'] = $this->dayName((int) $vars['day_dow'], $lang);
            unset($vars['day_dow']);
        }
        if (isset($vars['amount_raw'])) {
            // Round 188 : même correctif que renderHtml() ci-dessus —
            // Configuration::get(..., $this->idShop) explicite au lieu de
            // Currency::getDefaultCurrency() (retombe sur le contexte
            // boutique statique, pas forcément celui du rapport en cours).
            $idCurrencyRec = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $this->idShop);
            $currency = $idCurrencyRec > 0 ? new \Currency($idCurrencyRec) : false;
            $idLang   = (int) \Language::getIdByIso($lang) ?: (int) \Configuration::get('PS_LANG_DEFAULT');
            $vars['amount'] = $currency
                ? \NeriaTools::displayPrice((float) $vars['amount_raw'], $currency, $idLang)
                : (string) $vars['amount_raw'];
            unset($vars['amount_raw']);
        }
        return $vars;
    }

    private function t(string $key, array $vars = []): string
    {
        $str = class_exists('AdminTranslator') ? AdminTranslator::t($key) : $key;
        foreach ($vars as $k => $v) {
            $str = str_replace('{' . $k . '}', (string) $v, $str);
        }
        return $str;
    }
}
