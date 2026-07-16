<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — GoldenHourManager
 *
 * Analyse les ouvertures enregistrées dans ps_neria_stat et détermine
 * la meilleure plage horaire (jour + heure) par langue.
 *
 * V1 : analyse pure (lecture seule sur ps_neria_stat). Affichage dans
 * l'onglet Statistiques avec indicateur de confiance basé sur le volume.
 *
 * V2 prévue : envoi programmé — retarder les emails marketing pour qu'ils
 * partent à l'heure optimale (requiert queue + cron, même infra que Silence
 * Mode V2 digest, à construire ensemble).
 *
 * Note technique : les heures analysées sont en heure serveur. Pour des
 * clients dans d'autres fuseaux horaires, c'est une approximation fiable
 * pour les tendances jour/nuit et les habitudes culturelles larges.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GoldenHourManager
{
    const TABLE = 'neria_stat';

    // Seuil minimal d'ouvertures pour afficher une recommandation
    const MIN_OPENS = 10;

    // Niveaux de confiance
    const CONFIDENCE_LOW    = 'low';    // 10–49 opens
    const CONFIDENCE_MEDIUM = 'medium'; // 50–199 opens
    const CONFIDENCE_HIGH   = 'high';   // 200+ opens

    /** @var \Db */
    private \Db $db;

    /** @var int */
    private int $idShop;

    public function __construct()
    {
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    /**
     * Retourne les recommandations d'heure d'or par langue.
     *
     * @param int $days Fenêtre d'analyse (défaut 90 jours)
     * @return array [
     *   'lang' => string,
     *   'best_day' => int (1=dim, 2=lun ... 7=sam),
     *   'best_hour' => int (0–23),
     *   'opens_at_peak' => int,
     *   'total_opens' => int,
     *   'confidence' => string (low|medium|high),
     * ][]
     */
    public function getRecommendations(int $days = 90): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = pSQL(date('Y-m-d', strtotime("-{$days} days")));

        // Le taux par créneau doit répondre à "si j'envoie à cette heure,
        // quelle proportion de mes emails est ouverte ?" — pas "combien
        // d'ouvertures se produisent à cette heure" (un email envoyé à 8h
        // peut très bien être ouvert à 14h : ce n'est pas le même
        // événement). On relie donc chaque ouverture à l'heure d'ENVOI de
        // son propre email via tracking_token (jointure), et on groupe par
        // (lang, jour, heure) de l'envoi — pas de l'ouverture.
        $rows = $this->db->executeS(
            "SELECT
                s.`lang`,
                DAYOFWEEK(s.`date_add`) AS dow,
                HOUR(s.`date_add`)      AS hour,
                COUNT(DISTINCT s.`tracking_token`) AS sent_count,
                COUNT(DISTINCT o.`tracking_token`) AS opened_count
             FROM `{$table}` s
             LEFT JOIN `{$table}` o
                    ON o.`tracking_token` = s.`tracking_token`
                   AND o.`event_type`     = 'open'
                   AND o.`is_mpp`         = 0
             WHERE s.`event_type` = 'sent'
               AND s.`id_shop`    = {$this->idShop}
               AND s.`date_add`   >= '{$dateFrom}'
               AND s.`lang`       != ''
             GROUP BY s.`lang`, dow, hour"
        );

        if (!$rows) {
            return [];
        }

        // Agrège les totaux d'ouvertures par langue
        $totalByLang = [];
        $peakByLang  = []; // [lang => best row by open rate]

        foreach ($rows as $r) {
            $lang        = $r['lang'];
            $sentCount   = (int) $r['sent_count'];
            $openedCount = (int) $r['opened_count'];

            $totalByLang[$lang] = ($totalByLang[$lang] ?? 0) + $openedCount;

            $rate = $sentCount > 0 ? ($openedCount / $sentCount) : 0;
            $r['rate']  = $rate;
            $r['opens'] = $openedCount;

            // Garde le meilleur créneau par taux d'ouverture (min 3 envois
            // pour ce créneau, sinon le taux n'est pas significatif — un
            // seul envoi ouvert donnerait 100% par pur hasard)
            if ($sentCount >= 3 && (!isset($peakByLang[$lang]) || $rate > $peakByLang[$lang]['rate'])) {
                $peakByLang[$lang] = $r;
            }
        }

        $result = [];
        foreach ($peakByLang as $lang => $peak) {
            $total = $totalByLang[$lang] ?? 0;
            if ($total < self::MIN_OPENS) {
                continue;
            }
            $result[] = [
                'lang'         => $lang,
                'best_day'     => (int) $peak['dow'],
                'best_hour'    => (int) $peak['hour'],
                'opens_at_peak'=> (int) $peak['opens'],
                'total_opens'  => $total,
                'confidence'   => $this->confidence($total),
            ];
        }

        // Tri par volume décroissant
        usort($result, fn($a, $b) => $b['total_opens'] <=> $a['total_opens']);

        return $result;
    }

    private function confidence(int $total): string
    {
        if ($total >= 200) {
            return self::CONFIDENCE_HIGH;
        }
        if ($total >= 50) {
            return self::CONFIDENCE_MEDIUM;
        }
        return self::CONFIDENCE_LOW;
    }
}
