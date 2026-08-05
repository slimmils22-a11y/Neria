<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — SeasonalCampaignManager
 *
 * Gère les campagnes saisonnières récurrentes (soldes, Black Friday, Noël…).
 * Le marchand configure une fois : nom, template, date MM-JJ, ciblage.
 * Neria envoie automatiquement chaque année via le cron neria_behavioral.
 *
 * Déduplication : ps_neria_behavioral_sent avec
 *   template = 'seasonal_{id_campaign}' et ref_id = YEAR courant.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SeasonalCampaignManager
{
    const TABLE = 'neria_seasonal_campaign';

    // Genres PrestaShop
    const GENDER_ALL    = 0;
    const GENDER_MALE   = 1;
    const GENDER_FEMALE = 2;

    private Neria $module;
    private \Db $db;
    private int $idShop;
    private string $prefix;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(Neria $module)
    {
        $this->module  = $module;
        $this->db      = \Db::getInstance();
        $this->idShop  = (int) \Context::getContext()->shop->id;
        $this->prefix  = _DB_PREFIX_;
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // CRUD
    // ============================================================

    public function getAll(): array
    {
        return $this->db->executeS(
            "SELECT * FROM `{$this->prefix}" . self::TABLE . "`
             WHERE id_shop = " . (int) $this->idShop . "
             ORDER BY annual_date ASC, id_campaign ASC"
        ) ?: [];
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->getRow(
            "SELECT * FROM `{$this->prefix}" . self::TABLE . "`
             WHERE id_campaign = " . (int) $id . "
               AND id_shop = " . (int) $this->idShop
        );
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO `{$this->prefix}" . self::TABLE . "`
                (id_shop, name, template, annual_date, days_before, is_active,
                 target_segment, target_gender, target_lang, min_age, max_age,
                 gift_mode, date_add, date_upd)
             VALUES (
                " . (int) $this->idShop . ",
                '" . pSQL($data['name']           ?? '') . "',
                '" . pSQL($data['template']        ?? '') . "',
                '" . pSQL($data['annual_date']     ?? '01-01') . "',
                " . (int) ($data['days_before']    ?? 0) . ",
                " . (int) ($data['is_active']      ?? 1) . ",
                '" . pSQL($data['target_segment']  ?? '') . "',
                " . (int) ($data['target_gender']  ?? 0) . ",
                '" . pSQL($data['target_lang']     ?? '') . "',
                " . (int) ($data['min_age']        ?? 0) . ",
                " . (int) ($data['max_age']        ?? 0) . ",
                " . (int) ($data['gift_mode']      ?? 0) . ",
                NOW(), NOW()
             )"
        );
        return (int) $this->db->Insert_ID();
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            "UPDATE `{$this->prefix}" . self::TABLE . "` SET
                name            = '" . pSQL($data['name']          ?? '') . "',
                template        = '" . pSQL($data['template']       ?? '') . "',
                annual_date     = '" . pSQL($data['annual_date']    ?? '01-01') . "',
                days_before     = " . (int) ($data['days_before']   ?? 0) . ",
                is_active       = " . (int) ($data['is_active']     ?? 1) . ",
                target_segment  = '" . pSQL($data['target_segment'] ?? '') . "',
                target_gender   = " . (int) ($data['target_gender'] ?? 0) . ",
                target_lang     = '" . pSQL($data['target_lang']    ?? '') . "',
                min_age         = " . (int) ($data['min_age']       ?? 0) . ",
                max_age         = " . (int) ($data['max_age']       ?? 0) . ",
                gift_mode       = " . (int) ($data['gift_mode']     ?? 0) . ",
                date_upd        = NOW()
             WHERE id_campaign  = " . (int) $id . "
               AND id_shop      = " . (int) $this->idShop
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute(
            "DELETE FROM `{$this->prefix}" . self::TABLE . "`
             WHERE id_campaign = " . (int) $id . "
               AND id_shop = " . (int) $this->idShop
        );
    }

    public function toggle(int $id): void
    {
        $this->db->execute(
            "UPDATE `{$this->prefix}" . self::TABLE . "`
             SET is_active = 1 - is_active, date_upd = NOW()
             WHERE id_campaign = " . (int) $id . "
               AND id_shop = " . (int) $this->idShop
        );
    }

    // ============================================================
    // EXÉCUTION CRON
    // ============================================================

    /**
     * Vérifie les campagnes dues aujourd'hui et envoie aux clients éligibles.
     * Appelé quotidiennement depuis neria.php (hookDisplayHeader, throttlé).
     */
    public function runDueCampaigns(): int
    {
        $campaigns = $this->getAll();
        $totalSent = 0;

        foreach ($campaigns as $campaign) {
            if (!(bool) $campaign['is_active']) {
                continue;
            }

            // La campagne se déclenche si : date(today + days_before) = annual_date
            $targetTs   = strtotime('+' . (int) $campaign['days_before'] . ' days');
            $fireDate   = date('m-d', $targetTs);
            $annualDate = $campaign['annual_date'];
            // Repli sur le 28 février une année NON bissextile (même correctif
            // que CalendarManager::resolveMonthDay()) : date('m-d', ...) ne
            // peut jamais produire '02-29' un jour où l'année courante n'a pas
            // de 29 février — une campagne configurée sur cette date précise
            // n'était donc jamais déclenchée 3 années sur 4, sans erreur ni
            // log visible.
            if ($annualDate === '02-29' && !\checkdate(2, 29, (int) date('Y', $targetTs))) {
                $annualDate = '02-28';
            }
            if ($fireDate !== $annualDate) {
                continue;
            }

            $idCampaign = (int) $campaign['id_campaign'];
            $year       = (int) date('Y');
            $sentKey    = 'seasonal_' . $idCampaign;

            // Mode "idées cadeaux" : on force template gift_ideas + segments fidèles
            $isGiftMode = (bool) ($campaign['gift_mode'] ?? false);
            if ($isGiftMode) {
                $campaign['target_segment'] = 'ambassador,loyal';
            }

            // Isole chaque campagne : une erreur ici (ciblage, dédup…) ne doit
            // jamais empêcher les campagnes saisonnières suivantes de se déclencher.
            try {
                $customers = $this->getEligibleCustomers($campaign);
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.seasonal_targeting_failed', [
                        'campaign' => $campaign['name'],
                        'error'    => $e->getMessage(),
                    ]),
                    $campaign['template'] ?? '', 'SeasonalCampaign'
                );
                continue;
            }
            $sentCount = 0;

            foreach ($customers as $customer) {
                $idCustomer = (int) $customer['id_customer'];

                // Déduplication annuelle
                $alreadySent = (int) $this->db->getValue(
                    "SELECT COUNT(*) FROM `{$this->prefix}neria_behavioral_sent`
                     WHERE id_customer = {$idCustomer}
                       AND template    = '" . pSQL($sentKey) . "'
                       AND ref_id      = {$year}"
                );
                if ($alreadySent > 0) {
                    continue;
                }

                try {
                    $idLang   = (int) $customer['id_lang'] ?: (int) \Configuration::get('PS_LANG_DEFAULT');
                    $link     = \Context::getContext()->link;
                    $template = $isGiftMode ? 'gift_ideas' : $campaign['template'];

                    // getEligibleCustomers() ne filtre QUE sur les critères de
                    // ciblage (genre/langue/âge/segment) — aucun filtre de
                    // préférence, pas même le flag newsletter global. Sans ce
                    // contrôle, un client désabonné recevait quand même
                    // n'importe quelle campagne saisonnière correspondant à
                    // son ciblage. Même garde-fou que
                    // BehavioralCronManager/SegmentManager/CalendarManager.
                    if (class_exists('PreferencesManager')
                        && !(new \PreferencesManager($this->module))->isAllowed($idCustomer, $template, $this->idShop)
                    ) {
                        continue;
                    }

                    // Bloc upsell injecté pour le mode cadeaux
                    $upsellHtml = '';
                    $upsellTxt  = '';
                    if ($isGiftMode && class_exists('UpsellManager')) {
                        try {
                            $upsellMgr = new \UpsellManager($this->module);
                            $upsellHtml = $upsellMgr->renderUpsellBlock((int) $idCustomer, $idLang, (int) $this->idShop);
                            $upsellTxt  = $upsellMgr->renderUpsellBlockTxt((int) $idCustomer, $idLang, (int) $this->idShop);
                        } catch (\Throwable $ue) {
                            // Pas bloquant — on envoie sans le bloc
                        }
                    }

                    $ok = \Mail::Send(
                        $idLang,
                        $template,
                        '',
                        [
                            '{firstname}'     => $customer['firstname'],
                            '{lastname}'      => $customer['lastname'],
                            '{shop_name}'     => \Configuration::get('PS_SHOP_NAME'),
                            '{shop_url}'      => $link->getBaseLink($this->idShop),
                            '{history_url}'   => $link->getPageLink('history', true, $idLang, null, false, $this->idShop),
                            '{campaign_name}' => $campaign['name'],
                            '{upsell_block}'  => $upsellHtml,
                            '{upsell_block_txt}' => $upsellTxt,
                        ],
                        $customer['email'],
                        $customer['firstname'] . ' ' . $customer['lastname'],
                        null, null, null, null,
                        _PS_MODULE_DIR_ . 'neria/mails/',
                        false,
                        $this->idShop
                    );

                    // Ne pose la déduplication annuelle que si l'envoi a
                    // réellement réussi — sinon (échec SMTP transitoire,
                    // config mail invalide) le client était marqué "déjà
                    // servi" pour l'année et ne recevait plus jamais cette
                    // campagne saisonnière, sans qu'aucune alerte ne le
                    // signale.
                    if (!$ok) {
                        continue;
                    }

                    $this->db->execute(
                        "INSERT IGNORE INTO `{$this->prefix}neria_behavioral_sent`
                            (id_customer, template, ref_id, sent_at)
                         VALUES ({$idCustomer}, '" . pSQL($sentKey) . "', {$year}, NOW())"
                    );

                    $sentCount++;
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        \WatchdogManager::i18nMsg('watchdog.seasonal_send_error', [
                            'campaign' => $campaign['name'],
                            'customer' => $idCustomer,
                            'error'    => $e->getMessage(),
                        ]),
                        $campaign['template'], 'SeasonalCampaign'
                    );
                }
            }

            if ($sentCount > 0) {
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.seasonal_sent_summary', [
                        'campaign' => $campaign['name'],
                        'n'        => $sentCount,
                    ]),
                    $campaign['template'], 'SeasonalCampaign'
                );
            }

            $totalSent += $sentCount;
        }

        return $totalSent;
    }

    // ============================================================
    // CIBLAGE
    // ============================================================

    public function getEligibleCustomers(array $campaign): array
    {
        $where   = [];
        $joins   = '';

        // Filtre segment
        $segments = array_filter(array_map('trim', explode(',', $campaign['target_segment'] ?? '')));
        if (!empty($segments)) {
            $safeSegs = implode(',', array_map(fn($s) => "'" . pSQL($s) . "'", $segments));
            $joins   .= " INNER JOIN `{$this->prefix}neria_customer_segment` seg
                              ON seg.id_customer = c.id_customer
                             AND seg.id_shop = " . (int) $this->idShop . "
                             AND seg.segment IN ({$safeSegs})";
        }

        // Filtre genre (0 = tous, 1 = M, 2 = F)
        $gender = (int) ($campaign['target_gender'] ?? 0);
        if ($gender > 0) {
            $where[] = 'c.id_gender = ' . $gender;
        }

        // Filtre langue (codes Neria → id_lang PS)
        $langs = array_filter(array_map('trim', explode(',', $campaign['target_lang'] ?? '')));
        if (!empty($langs)) {
            $langIds = $this->resolveLanguageIds($langs);
            if (!empty($langIds)) {
                $where[] = 'c.id_lang IN (' . implode(',', $langIds) . ')';
            }
        }

        // Filtre âge min — TIMESTAMPDIFF (pas YEAR(NOW())-YEAR(birthday), qui
        // ignore le mois/jour et surestime l'âge d'un an tant que
        // l'anniversaire de l'année en cours n'est pas encore passé).
        $minAge = (int) ($campaign['min_age'] ?? 0);
        if ($minAge > 0) {
            $where[] = "c.birthday IS NOT NULL AND c.birthday != '0000-00-00'";
            $where[] = "TIMESTAMPDIFF(YEAR, c.birthday, CURDATE()) >= {$minAge}";
        }

        // Filtre âge max
        $maxAge = (int) ($campaign['max_age'] ?? 0);
        if ($maxAge > 0) {
            $where[] = "c.birthday IS NOT NULL AND c.birthday != '0000-00-00'";
            $where[] = "TIMESTAMPDIFF(YEAR, c.birthday, CURDATE()) <= {$maxAge}";
        }

        $whereStr = $where ? ('AND ' . implode(' AND ', $where)) : '';

        // c.id_shop obligatoire : sans lui, une campagne sans segment ciblé
        // (cas fréquent) n'avait AUCUNE contrainte de boutique et partait à
        // tous les clients actifs de TOUTES les boutiques de l'install —
        // fuite de ciblage cross-boutique (RGPD/branding), même avec un
        // segment renseigné (le JOIN restreint mais ne remplace pas un
        // filtre client manquant sur ses propres colonnes).
        return $this->db->executeS(
            "SELECT DISTINCT c.id_customer, c.id_lang, c.firstname, c.lastname, c.email
             FROM `{$this->prefix}customer` c
             {$joins}
             WHERE c.active   = 1
               AND c.deleted  = 0
               AND c.is_guest = 0
               AND c.email    != ''
               AND c.id_shop  = " . (int) $this->idShop . "
               {$whereStr}
             ORDER BY c.id_customer ASC"
        ) ?: [];
    }

    private function resolveLanguageIds(array $isoCodes): array
    {
        $ids = [];
        $allLangs = \Language::getLanguages(false);
        foreach ($allLangs as $lang) {
            if (in_array($lang['iso_code'], $isoCodes, true)) {
                $ids[] = (int) $lang['id_lang'];
            }
        }
        return $ids;
    }

    // ============================================================
    // CALENDRIER ANNUEL
    // ============================================================

    /**
     * Retourne les campagnes organisées par mois (1→12) pour l'affichage BO.
     */
    public function getCalendarData(): array
    {
        $months = array_fill(1, 12, []);
        foreach ($this->getAll() as $c) {
            $parts = explode('-', $c['annual_date']);
            $month = (int) ($parts[0] ?? 1);
            $day   = (int) ($parts[1] ?? 1);
            if ($month >= 1 && $month <= 12) {
                $months[$month][] = array_merge($c, ['day' => $day]);
            }
        }
        return $months;
    }

    /**
     * Compte les clients éligibles sans envoyer (pour l'aperçu BO).
     */
    public function countEligible(array $campaign): int
    {
        return count($this->getEligibleCustomers($campaign));
    }
}
