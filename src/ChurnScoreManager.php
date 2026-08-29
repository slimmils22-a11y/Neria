<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — ChurnScoreManager
 *
 * Calcule un score de risque de désabonnement (0-100) pour chaque client,
 * basé sur l'évolution du taux d'ouverture sur 3 périodes de 30 jours.
 *
 * Algorithme (3 composantes) :
 *   Récence       (0-40 pts) — nombre de jours depuis la dernière ouverture
 *   Taux récent   (0-30 pts) — taux d'ouverture des 30 derniers jours inversé
 *   Tendance      (0-30 pts) — déclin relatif du taux P3→P1 (ancien→récent)
 *
 * Score > 70 = risque élevé → alerte visible sur la fiche client.
 * Recalcul quotidien via BehavioralCronManager::run().
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ChurnScoreManager
{
    const TABLE              = 'neria_churn_score';
    const HIGH_RISK_THRESHOLD = 70;

    private \Neria $module;
    private \Db    $db;
    private int    $idShop;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(\Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // RECALCUL
    // ============================================================

    /**
     * Recalcule les scores de tous les clients actifs ayant reçu au moins
     * un email. Traitement en PHP pour lisibilité de l'algorithme.
     *
     * @return int Nombre de clients mis à jour
     */
    public function recomputeAll(): int
    {
        $stat  = _DB_PREFIX_ . 'neria_stat';
        $shop  = $this->idShop;

        // AND is_mpp = 0 sur les 3 open_pX : exclut les pré-chargements
        // automatiques d'Apple Mail Privacy Protection (le pixel de tracking
        // est chargé par le proxy Apple dès réception, pas à l'ouverture
        // réelle) — même filtre que SegmentManager/StatsManager/
        // MonthlyReportManager partout ailleurs, absent ici jusqu'à
        // aujourd'hui. Sans lui, un client Apple Mail qui n'ouvre jamais
        // réellement ses emails gardait un rate_p1 artificiellement élevé,
        // sous-estimant son score de churn — l'inverse exact du bug déjà
        // corrigé dans SegmentManager (ghost/dormant classé ambassador/loyal).
        //
        // Métriques sent/open par période (0-90 j) — scindées en une requête
        // BORNÉE par date_add, séparée de la requête "tous temps" ci-dessous.
        // Auparavant une seule requête sans aucune borne de date scannait la
        // table neria_stat ENTIÈRE à chaque exécution du cron quotidien
        // (le filtre par période était fait via CASE WHEN dans le SELECT,
        // pas dans le WHERE), alors que sent_p1/p2/p3 et open_p1/p2/p3 ne
        // portent que sur les 90 derniers jours. Sur une boutique de
        // plusieurs années avec des millions de lignes, ce cron scannait
        // chaque jour l'intégralité de l'historique au lieu des 90 derniers
        // jours seulement — coût croissant indéfiniment avec l'ancienneté.
        $rowsPeriods = $this->db->executeS("
            SELECT
                id_customer,
                -- Période 1 : 0-30 j (la plus récente)
                SUM(CASE WHEN date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND event_type = 'sent' THEN 1 ELSE 0 END) AS sent_p1,
                SUM(CASE WHEN date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND event_type = 'open' AND is_mpp = 0 THEN 1 ELSE 0 END) AS open_p1,
                -- Période 2 : 31-60 j
                SUM(CASE WHEN date_add < DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND date_add >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                          AND event_type = 'sent' THEN 1 ELSE 0 END) AS sent_p2,
                SUM(CASE WHEN date_add < DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND date_add >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                          AND event_type = 'open' AND is_mpp = 0 THEN 1 ELSE 0 END) AS open_p2,
                -- Période 3 : 61-90 j (la plus ancienne)
                SUM(CASE WHEN date_add < DATE_SUB(NOW(), INTERVAL 60 DAY)
                          AND date_add >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                          AND event_type = 'sent' THEN 1 ELSE 0 END) AS sent_p3,
                SUM(CASE WHEN date_add < DATE_SUB(NOW(), INTERVAL 60 DAY)
                          AND date_add >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                          AND event_type = 'open' AND is_mpp = 0 THEN 1 ELSE 0 END) AS open_p3
            FROM `{$stat}`
            WHERE id_shop = {$shop} AND id_customer > 0
              AND date_add >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY id_customer
        ");

        // Le cron a bien tourné même si aucune ligne sent/open n'existe dans
        // la fenêtre de 90 jours (boutique tout juste installée) — tracé
        // AVANT ce early return (pas seulement plus bas, ligne ~171) pour
        // que checkChurnPropensityFreshness() distingue "rien à recalculer"
        // d'un cron réellement en échec, à l'image de
        // PropensityScoreManager::recalculateAll() qui écrit son propre
        // repère inconditionnellement. Avant ce correctif, une boutique sans
        // aucune donnée neria_stat sortait ici sans jamais écrire
        // NERIA_CHURN_LAST_RUN, laissant ce garde-fou aveugle indéfiniment.
        \Configuration::updateValue('NERIA_CHURN_LAST_RUN', date('Y-m-d H:i:s'), false, null, $this->idShop);

        // Round 176 : ce early return sortait AVANT le bloc de purge
        // (ci-dessous, ~ligne 176) dès que $rowsPeriods était vide (aucun
        // événement sent/open dans les 90 derniers jours — boutique
        // dormante, ou toutes ses lignes neria_stat purgées/RGPD). Sur une
        // boutique qui redevient dormante après avoir eu des clients à
        // risque, les anciennes lignes neria_churn_score (score ≥70
        // compris) n'étaient donc JAMAIS supprimées et continuaient
        // d'apparaître indéfiniment dans getHighRiskCustomers() — exactement
        // le bug que le correctif round 166 (voir plus bas) visait à
        // couvrir, mais qui ne traitait que le cas "$rows vide APRÈS
        // filtrage", pas "$rowsPeriods vide DÈS la requête SQL". On
        // normalise en tableau vide et on laisse le flux normal (filtrage
        // puis purge) s'exécuter, plutôt que de sortir prématurément.
        $rowsPeriods = is_array($rowsPeriods) ? $rowsPeriods : [];

        // last_open et tranches horaires : nécessitent bien tout l'historique
        // ("tous temps", cf. calcul du créneau d'envoi préféré) — mais scopés
        // à event_type = 'open' uniquement (sous-ensemble bien plus restreint
        // que la table entière), pas de régression de comportement par
        // rapport à l'ancienne requête combinée. AND is_mpp = 0 : même
        // raison que ci-dessus — un pré-chargement Apple MPP fausserait aussi
        // last_open (faux "récent") et les tranches horaires (heure du
        // pré-chargement automatique, pas de l'ouverture réelle par le
        // client).
        $rowsOpenAllTime = [];
        foreach ($this->db->executeS("
            SELECT
                id_customer,
                MAX(date_add) AS last_open,
                TIMESTAMPDIFF(SECOND, MAX(date_add), NOW()) AS seconds_since_open,
                SUM(HOUR(date_add) >= 6  AND HOUR(date_add) < 12) AS open_morning,
                SUM(HOUR(date_add) >= 12 AND HOUR(date_add) < 18) AS open_afternoon,
                SUM(HOUR(date_add) >= 18 AND HOUR(date_add) < 23) AS open_evening,
                SUM(HOUR(date_add) >= 23 OR HOUR(date_add) < 6)   AS open_night
            FROM `{$stat}`
            WHERE id_shop = {$shop} AND id_customer > 0 AND event_type = 'open' AND is_mpp = 0
            GROUP BY id_customer
        ") ?: [] as $r) {
            $rowsOpenAllTime[(int) $r['id_customer']] = $r;
        }

        // Round 237 : seconds_since_open calculé côté SQL via TIMESTAMPDIFF
        // (horloge MySQL des deux côtés), pas via time() - strtotime()
        // (mélange horloge PHP / horloge MySQL) — même correctif déjà
        // appliqué dans BounceManager::isBounced() pour la même raison :
        // évite une dérive silencieuse du score de récence si le serveur
        // web et le serveur MySQL n'ont pas le même fuseau horaire.
        $emptyOpenStats = ['last_open' => null, 'seconds_since_open' => null, 'open_morning' => 0, 'open_afternoon' => 0, 'open_evening' => 0, 'open_night' => 0];
        $rows = [];
        foreach ($rowsPeriods as $r) {
            $rows[] = $r + ($rowsOpenAllTime[(int) $r['id_customer']] ?? $emptyOpenStats);
        }

        // Bug du 2026-07-21 : un client tout juste inscrit (aucun envoi
        // au-delà des 30 derniers jours, donc sent_p2 = sent_p3 = 0) n'a
        // logiquement pas encore ouvert grand-chose — mais computeScore()
        // traitait ce manque de données comme un signal de risque MAXIMAL
        // (récence=40 car jamais ouvert, taux récent=30 car 0 ouverture,
        // tendance=10 par défaut) : score ≈ 80, "risque élevé" immédiat.
        // Un score de CHURN suppose un déclin depuis un engagement passé —
        // sans au moins 30 jours d'historique antérieur, il n'y a rien à
        // comparer. On exclut donc ces clients du recalcul (aucune ligne
        // insérée = getCustomerScore()/getHighRiskCustomers() les ignorent
        // naturellement), plutôt que de leur assigner un faux risque élevé
        // qui pollue la liste des clients réellement à relancer.
        $rows = array_values(array_filter($rows, function (array $r): bool {
            return (int) $r['sent_p2'] > 0 || (int) $r['sent_p3'] > 0;
        }));

        $table = _DB_PREFIX_ . self::TABLE;

        // Purge les lignes des clients qui ne sont PLUS dans le recalcul de
        // ce run (plus aucun envoi/ouverture dans la fenêtre de 90 jours, ou
        // historique insuffisant filtré ci-dessus) — sans ça, une ligne
        // neria_churn_score reste figée indéfiniment avec le dernier score
        // calculé alors que le client est sorti de la fenêtre d'analyse. Un
        // client resté au-dessus de HIGH_RISK_THRESHOLD la dernière fois
        // qu'il était dans la fenêtre continuait sinon à apparaître dans
        // getHighRiskCustomers() (fiche BO "clients à risque") pendant des
        // mois/années sans jamais être ni recalculé ni retiré.
        $keepIds = array_map(static fn (array $r): int => (int) $r['id_customer'], $rows);
        $purgeSql = "DELETE FROM `{$table}` WHERE `id_shop` = {$shop}";
        if (!empty($keepIds)) {
            $purgeSql .= ' AND `id_customer` NOT IN (' . implode(',', $keepIds) . ')';
        }
        // Round 148 : $sqlOk accumule le résultat réel du DELETE puis de
        // chaque lot INSERT ci-dessous — auparavant tous ignorés (ni log
        // d'échec nulle part dans ce fichier), et le résumé final était
        // journalisé comme un succès inconditionnel basé sur un simple
        // count($chunk) plutôt que sur le résultat réel des requêtes.
        $sqlOk = $this->db->execute($purgeSql);

        // Round 166 : quand $rows est vide (boutique jeune où le filtre
        // sent_p2/sent_p3 ci-dessus élimine tous les clients), la fonction
        // retournait immédiatement 0 SANS jamais vérifier $sqlOk ni logger
        // — un DELETE réellement en échec (verrou concurrent, table
        // corrompue) restait donc totalement invisible dans ce cas précis,
        // alors que le commentaire du round 148 promet explicitement que
        // $sqlOk sert à tracer ces échecs. Le check est désormais fait
        // AVANT ce retour anticipé, pas seulement sur le chemin normal.
        if (!$sqlOk) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.churn_score_partial_failure', ['n' => 0]),
                '', 'ChurnScore'
            );
        }

        if (empty($rows)) {
            return 0;
        }

        $now      = date('Y-m-d H:i:s');
        $inserted = 0;

        // Batch INSERT de 50 en 50 pour éviter les requêtes trop lourdes
        $chunks = array_chunk($rows, 50);

        foreach ($chunks as $chunk) {
            $values = [];
            foreach ($chunk as $r) {
                [$score, $rate1, $rate2, $rate3] = $this->computeScore($r);
                $slot     = $this->computePreferredSlot($r);
                $lastOpen = $r['last_open'] ? "'" . pSQL($r['last_open']) . "'" : 'NULL';
                $slotSql  = $slot ? "'" . pSQL($slot) . "'" : 'NULL';
                $values[] = sprintf(
                    '(%d, %d, %d, %.4f, %.4f, %.4f, %s, %s, \'%s\')',
                    $shop,
                    (int) $r['id_customer'],
                    $score,
                    $rate1,
                    $rate2,
                    $rate3,
                    $lastOpen,
                    $slotSql,
                    pSQL($now)
                );
            }

            $chunkOk = $this->db->execute(
                "INSERT INTO `{$table}`
                    (`id_shop`, `id_customer`, `score`, `rate_p1`, `rate_p2`, `rate_p3`,
                     `last_open`, `preferred_slot`, `computed_at`)
                 VALUES " . implode(',', $values) . "
                 ON DUPLICATE KEY UPDATE
                    `score`          = VALUES(`score`),
                    `rate_p1`        = VALUES(`rate_p1`),
                    `rate_p2`        = VALUES(`rate_p2`),
                    `rate_p3`        = VALUES(`rate_p3`),
                    `last_open`      = VALUES(`last_open`),
                    `preferred_slot` = VALUES(`preferred_slot`),
                    `computed_at`    = VALUES(`computed_at`)"
            );
            $sqlOk = $sqlOk && $chunkOk;

            if ($chunkOk !== false) {
                $inserted += count($chunk);
            }
        }

        $atRisk   = $this->countHighRisk();
        $withSlot = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . self::TABLE . "`
             WHERE id_shop = {$this->idShop} AND preferred_slot IS NOT NULL"
        );

        if (!$sqlOk) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.churn_score_partial_failure', ['n' => $inserted]),
                '', 'ChurnScore'
            );
        }

        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.churn_score_summary', [
                'n'        => $inserted,
                'atRisk'   => $atRisk,
                'threshold' => self::HIGH_RISK_THRESHOLD,
                'withSlot' => $withSlot,
            ]),
            '', 'ChurnScore'
        );

        return $inserted;
    }

    /**
     * Calcule le score et les taux pour une ligne de métriques.
     *
     * @param array $r Ligne avec sent_p1, open_p1, sent_p2, open_p2, sent_p3, open_p3, last_open
     * @return array [score(int), rate1(float), rate2(float), rate3(float)]
     */
    /**
     * Détermine la tranche horaire préférée d'un client selon ses ouvertures historiques.
     * Nécessite au moins 2 ouvertures pour être significatif.
     *
     * @return string|null 'morning'|'afternoon'|'evening'|'night' ou null si insuffisant
     */
    private function computePreferredSlot(array $r): ?string
    {
        $counts = [
            'morning'   => (int) ($r['open_morning']   ?? 0),
            'afternoon' => (int) ($r['open_afternoon'] ?? 0),
            'evening'   => (int) ($r['open_evening']   ?? 0),
            'night'     => (int) ($r['open_night']     ?? 0),
        ];
        if (array_sum($counts) < 2) {
            return null;
        }
        arsort($counts);
        return (string) array_key_first($counts);
    }

    private function computeScore(array $r): array
    {
        // Round 143 : clampés à 1.0 — un même email peut générer plusieurs
        // événements 'open' (réouverture, plusieurs appareils/clients mail)
        // pour un seul 'sent', donc ces taux ne sont pas structurellement
        // bornés à 1.0. Même clamp déjà appliqué pour ce pattern identique
        // dans ClvManager::getEngagementRate()/getTopCustomers() — sans lui,
        // recentRisk = (1.0 - $rate1) * 30 ci-dessous pouvait sortir de sa
        // plage documentée [0, 30] (ex. rate1=5.0 → recentRisk=-120).
        $rate1 = min(1.0, (int) $r['sent_p1'] > 0 ? (int) $r['open_p1'] / (int) $r['sent_p1'] : 0.0);
        $rate2 = min(1.0, (int) $r['sent_p2'] > 0 ? (int) $r['open_p2'] / (int) $r['sent_p2'] : 0.0);
        $rate3 = min(1.0, (int) $r['sent_p3'] > 0 ? (int) $r['open_p3'] / (int) $r['sent_p3'] : 0.0);

        // Composante 1 — Récence (0-40 pts)
        if ($r['last_open']) {
            $days    = (int) $r['seconds_since_open'] / 86400;
            $recency = min(1.0, max(0.0, $days / 90)) * 40;
        } else {
            $recency = 40; // jamais ouvert
        }

        // Composante 2 — Taux récent inversé (0-30 pts)
        if ((int) $r['sent_p1'] === 0) {
            // Aucun envoi dans les 30 derniers jours (client simplement pas
            // ciblé récemment — segmentation, pause volontaire...) : $rate1
            // vaut mécaniquement 0.0, ce qui plaçait cette composante à son
            // maximum (30 pts, "n'ouvre jamais") sans qu'aucune ouverture
            // manquée ne se soit réellement produite. Même traitement que
            // la composante Tendance ci-dessous pour sent_p3 === 0 : risque
            // modéré par défaut plutôt que le pire cas.
            $recentRisk = 15.0;
        } else {
            $recentRisk = (1.0 - $rate1) * 30;
        }

        // Composante 3 — Tendance de déclin P3 → P1 (0-30 pts)
        if ((int) $r['sent_p3'] === 0) {
            // Pas d'historique 61-90j : client récent, risque modéré par défaut
            $trend = 10.0;
        } elseif ($rate3 > 0.0) {
            $decline = max(0.0, $rate3 - $rate1) / $rate3;
            $trend   = $decline * 30;
        } else {
            // Taux ancien = 0 (n'ouvrait déjà pas) → déclin neutre
            $trend = 15.0;
        }

        $score = (int) round($recency + $recentRisk + $trend);
        $score = max(0, min(100, $score));

        return [$score, round($rate1, 4), round($rate2, 4), round($rate3, 4)];
    }

    // ============================================================
    // LECTURE
    // ============================================================

    /**
     * Score d'un client donné (pour la fiche client).
     */
    public function getCustomerScore(int $idCustomer): ?array
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $row   = $this->db->getRow(sprintf(
            "SELECT * FROM `%s` WHERE `id_shop` = %d AND `id_customer` = %d",
            $table, $this->idShop, $idCustomer
        ));
        return $row ?: null;
    }

    /**
     * Clients dont le score dépasse le seuil de risque élevé.
     */
    public function getHighRiskCustomers(int $limit = 50): array
    {
        $table  = _DB_PREFIX_ . self::TABLE;
        $cTable = _DB_PREFIX_ . 'customer';

        $rows = $this->db->executeS(sprintf(
            "SELECT s.id_customer, s.score, s.rate_p1, s.rate_p2, s.rate_p3, s.last_open,
                    c.firstname, c.lastname, c.email
             FROM `%s` s
             INNER JOIN `%s` c ON c.id_customer = s.id_customer
             WHERE s.id_shop = %d AND s.score >= %d
               AND c.active = 1 AND c.deleted = 0
             ORDER BY s.score DESC
             LIMIT %d",
            $table, $cTable,
            $this->idShop,
            self::HIGH_RISK_THRESHOLD,
            $limit
        ));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Nombre de clients à risque élevé.
     */
    public function countHighRisk(): int
    {
        // Round 209 : même filtre que getHighRiskCustomers() (jointure
        // customer + active=1/deleted=0) — neria_churn_score n'est jamais
        // purgée quand un client est désactivé ou soft-supprimé (RGPD),
        // elle ne l'est que par la fenêtre glissante de 90 jours de
        // recomputeAll(), indépendante du statut du client. Sans ce
        // filtre, le "atRisk" du résumé de cron (watchdog.churn_score_summary)
        // pouvait dépasser le nombre de clients réellement listés/
        // actionnables dans getHighRiskCustomers() — écart trompeur pour
        // le marchand sur une boutique à fort taux de suppression de comptes.
        $table  = _DB_PREFIX_ . self::TABLE;
        $cTable = _DB_PREFIX_ . 'customer';
        return (int) $this->db->getValue(sprintf(
            "SELECT COUNT(*) FROM `%s` s
             INNER JOIN `%s` c ON c.id_customer = s.id_customer
             WHERE s.id_shop = %d AND s.score >= %d
               AND c.active = 1 AND c.deleted = 0",
            $table, $cTable, $this->idShop, self::HIGH_RISK_THRESHOLD
        ));
    }

    // ============================================================
    // HELPERS STATIQUES
    // ============================================================

    /**
     * Label couleur selon le score.
     */
    public static function getRiskLevel(int $score): string
    {
        if ($score >= 85) {
            return 'critical';
        }
        if ($score >= 70) {
            return 'high';
        }
        if ($score >= 50) {
            return 'medium';
        }
        return 'low';
    }
}
