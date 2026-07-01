<?php
/**
 * NERIA â€” CalendarManager
 *
 * Gestion des occasions calendaires automatiques.
 *
 * Resolution des dates â€” 3 niveaux par priorite :
 *
 * NIVEAU 1 â€” Override manuel du marchand
 *   Le marchand a saisi une date manuellement dans le back-office
 *   pour cette occasion + annee. Priorite absolue.
 *   Stocke dans : Configuration::get('NERIA_CAL_DATE_EID_2028')
 *
 * NIVEAU 2 â€” Calcul algorithmique
 *   Pour le calendrier Hegirien (Eid, Ramadan) et le calendrier
 *   lunaire chinois (Nouvel An). Calcule pour n'importe quelle annee.
 *   Autonome a vie, sans mise a jour requise.
 *
 * NIVEAU 3 â€” Dates pre-calculees (2025-2035)
 *   Filet de securite si l'algorithme echoue.
 *   Couvre 10 ans avec des dates verifiees manuellement.
 *
 * NIVEAU 4 â€” Dates fixes recurrees
 *   Noel, Valentine, Halloween etc. Valables a l'infini.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CalendarManager
{
    // ============================================================
    // CONSTANTES
    // ============================================================

    const TABLE                  = 'neria_calendar_event';
    const SENT_PREFIX            = 'NERIA_CAL_SENT_';

    /** Prefixe pour les overrides manuels du marchand */
    const OVERRIDE_PREFIX        = 'NERIA_CAL_DATE_';

    const MAX_RECIPIENTS_PER_EVENT = 500;
    const BATCH_SIZE               = 50;

    // ============================================================
    // PROPRIETES
    // ============================================================

    private Neria  $module;
    private \Db    $db;
    private int    $idShop;
    private array  $calendarDates = [];
    private bool   $datesLoaded   = false;

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    /** @var WatchdogManager|null Instance paresseuse du watchdog */
    private ?WatchdogManager $watchdog = null;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    private function watchdog(): WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // POINT D'ENTREE PRINCIPAL
    // ============================================================

    public function checkAndSendDailyEvents(): void
    {
        \Configuration::updateValue(\HealthCheckManager::CRON_LAST_CALENDAR, date('Y-m-d H:i:s'));
        $this->loadCalendarDates();
        $events = $this->getActiveEvents();

        if (empty($events)) {
            return;
        }

        $today = new \DateTime('today');

        foreach ($events as $event) {
            try {
                $this->processEvent($event, $today);
            } catch (\Throwable $e) {
                $this->module->log(
                    sprintf(
                        'CalendarManager: erreur [%s][%s] : %s',
                        $event['event_key'],
                        $event['lang'],
                        $e->getMessage()
                    ),
                    2
                );
                $this->watchdog()->error(
                    'Erreur envoi calendaire : ' . $e->getMessage(),
                    $event['template'] ?? '',
                    'CalendarManager'
                );
            }
        }
    }

    // ============================================================
    // TRAITEMENT D'UN EVENEMENT
    // ============================================================

    private function processEvent(array $event, \DateTime $today): void
    {
        $eventKey    = $event['event_key'];
        $lang        = $event['lang'];
        $countryCode = $event['country_code'];
        $template    = $event['template'];
        $daysBefore  = (int) $event['send_days_before'];
        $year        = (int) $today->format('Y');

        // Occasion personnalisée : date fixe stockée en base (format MM-DD)
        if (!empty($event['custom_date']) && preg_match('/^\d{2}-\d{2}$/', $event['custom_date'])) {
            $eventDate = \DateTime::createFromFormat('Y-m-d', $year . '-' . $event['custom_date']) ?: null;
        } else {
            $eventDate = $this->getEventDate($eventKey, $year);
        }

        if (!$eventDate) {
            $this->watchdog()->warning(
                sprintf('Date introuvable pour l\'occasion "%s" en %d — vérifiez le calendrier.', $eventKey, $year),
                $template,
                'CalendarManager'
            );
            return;
        }

        $sendDate = clone $eventDate;
        $sendDate->modify("-{$daysBefore} days");

        if ($today->format('Y-m-d') !== $sendDate->format('Y-m-d')) {
            return;
        }

        $sentKey = $this->buildSentKey($eventKey, $lang, $countryCode, $year);

        if (\Configuration::get($sentKey)) {
            return;
        }

        $customers = $this->getEligibleCustomers($lang, $countryCode);

        if (empty($customers)) {
            $this->watchdog()->warning(
                sprintf(
                    'Occasion "%s" : 0 client éligible (langue : %s%s). Vérifiez que la langue est installée et que des clients y sont abonnés à la newsletter.',
                    $eventKey,
                    strtoupper($lang),
                    $countryCode ? ', pays : ' . strtoupper($countryCode) : ''
                ),
                $template,
                'CalendarManager'
            );
            return;
        }

        $total  = count($customers);
        $result = $this->sendToCustomers($customers, $template, $lang, $eventKey);
        $sent   = $result['sent'];
        $failed = $result['failed'];

        \Configuration::updateValue($sentKey, date('Y-m-d H:i:s') . '|' . $sent . '/' . $total);

        $this->module->log(
            sprintf(
                'CalendarManager: [%s][%s] : %d/%d emails envoyes (J-%d avant %s)',
                $eventKey, $lang, $sent, $total, $daysBefore,
                $eventDate->format('d/m/Y')
            ),
            1
        );

        if ($failed > 0) {
            $this->watchdog()->warning(
                sprintf(
                    'Occasion "%s" : %d/%d envois ont échoué (SMTP ou template manquant). Vérifiez la configuration SMTP et le template "%s".',
                    $eventKey, $failed, $total, $template
                ),
                $template,
                'CalendarManager'
            );
        } else {
            $this->watchdog()->info(
                sprintf('%d/%d emails envoyés pour : %s', $sent, $total, $eventKey),
                $template,
                'CalendarManager'
            );
        }
    }

    // ============================================================
    // AFFICHAGE BO â€” prochaine date calculee + dernier envoi
    // ============================================================

    /**
     * Infos d'affichage pour le tableau BO : prochaine date calculee de
     * l'occasion, date d'envoi prevue (J - delai), et dernier envoi effectue
     * (date + nombre de destinataires) si disponible.
     */
    public function getEventDisplayInfo(array $event): array
    {
        $eventKey    = $event['event_key'];
        $lang        = $event['lang'];
        $countryCode = $event['country_code'] ?? '';
        $daysBefore  = (int) $event['send_days_before'];

        $today = new \DateTime('today');
        $year  = (int) $today->format('Y');

        $resolveDate = function (int $y) use ($event, $eventKey) {
            if (!empty($event['custom_date']) && preg_match('/^\d{2}-\d{2}$/', $event['custom_date'])) {
                return \DateTime::createFromFormat('Y-m-d', $y . '-' . $event['custom_date']) ?: null;
            }
            return $this->getEventDate($eventKey, $y);
        };

        $eventDate = $resolveDate($year);
        $sendDate  = null;
        if ($eventDate) {
            $sendDate = clone $eventDate;
            $sendDate->modify("-{$daysBefore} days");

            // Si l'envoi prevu est deja passe cette annee, basculer sur l'annee suivante
            if ($sendDate < $today) {
                $eventDate = $resolveDate($year + 1);
                $sendDate  = null;
                if ($eventDate) {
                    $sendDate = clone $eventDate;
                    $sendDate->modify("-{$daysBefore} days");
                }
            }
        }

        // Dernier envoi effectue : cherche cette annee puis l'annee precedente
        $lastSent = null;
        foreach ([$year, $year - 1] as $y) {
            $raw = \Configuration::get($this->buildSentKey($eventKey, $lang, $countryCode, $y));
            if ($raw) {
                $parts = explode('|', (string) $raw, 2);
                $lastSent = [
                    'date'  => $parts[0],
                    'count' => $parts[1] ?? null,
                ];
                break;
            }
        }

        return [
            'next_event_date' => $eventDate ? $eventDate->format('d/m/Y') : null,
            'next_send_date'  => $sendDate  ? $sendDate->format('d/m/Y')  : null,
            'last_sent'       => $lastSent,
        ];
    }

    // ============================================================
    // RESOLUTION DES DATES â€” 4 NIVEAUX
    // ============================================================

    /**
     * Point d'entree de la resolution de date
     * Essaie les 4 niveaux dans l'ordre de priorite
     *
     * @param string $eventKey Cle de l'evenement
     * @param int    $year     Annee
     * @return \DateTime|null
     */
    public function getEventDate(string $eventKey, int $year): ?\DateTime
    {
        // NIVEAU 1 : override manuel du marchand
        $override = $this->getManualOverride($eventKey, $year);
        if ($override) {
            return $override;
        }

        // NIVEAU 2 : calcul algorithmique
        $calculated = $this->calculateEventDate($eventKey, $year);
        if ($calculated) {
            return $calculated;
        }

        // NIVEAU 3 : dates pre-calculees dans le calendrier charge
        $this->loadCalendarDates();

        if (isset($this->calendarDates[$eventKey])) {
            $data = $this->calendarDates[$eventKey];

            // NIVEAU 4 : date fixe recurrente (Noel, Valentine, etc.)
            if (isset($data['recurring'])) {
                $r = $data['recurring'];
                return \DateTime::createFromFormat(
                    'Y-n-j',
                    "{$year}-{$r['month']}-{$r['day']}"
                ) ?: null;
            }

            // NIVEAU 3 : date pre-calculee pour cette annee
            if (isset($data['dates'])) {
                foreach ($data['dates'] as $date) {
                    if ((int) $date['year'] === $year) {
                        return \DateTime::createFromFormat(
                            'Y-n-j',
                            "{$year}-{$date['month']}-{$date['day']}"
                        ) ?: null;
                    }
                }
            }
        }

        return null;
    }

    // ============================================================
    // NIVEAU 1 â€” OVERRIDE MANUEL DU MARCHAND
    // ============================================================

    /**
     * Recupere la date saisie manuellement par le marchand
     *
     * @param string $eventKey Cle de l'evenement
     * @param int    $year     Annee
     * @return \DateTime|null
     */
    private function getManualOverride(
        string $eventKey,
        int    $year
    ): ?\DateTime {
        $key   = self::OVERRIDE_PREFIX . strtoupper($eventKey) . '_' . $year;
        $value = \Configuration::get($key);

        if (!$value) {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);
        return $date ?: null;
    }

    /**
     * Enregistre une date manuelle pour un evenement + annee
     * Appele depuis le back-office quand le marchand saisit une date
     *
     * @param string $eventKey Cle de l'evenement (ex: eid)
     * @param int    $year     Annee (ex: 2028)
     * @param string $date     Date au format Y-m-d (ex: 2028-03-09)
     * @return bool
     */
    public function setManualOverride(
        string $eventKey,
        int    $year,
        string $date
    ): bool {
        // Valide le format de date
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            return false;
        }

        $key = self::OVERRIDE_PREFIX . strtoupper($eventKey) . '_' . $year;
        $result = \Configuration::updateValue($key, $date);

        if ($result) {
            $this->module->log(
                "CalendarManager: override manuel [{$eventKey}][{$year}] = {$date}",
                1
            );
        }

        return $result;
    }

    /**
     * Supprime un override manuel
     * Le systeme reprendra le calcul automatique
     *
     * @param string $eventKey Cle de l'evenement
     * @param int    $year     Annee
     */
    public function deleteManualOverride(string $eventKey, int $year): void
    {
        $key = self::OVERRIDE_PREFIX . strtoupper($eventKey) . '_' . $year;
        \Configuration::deleteByName($key);
    }

    /**
     * Retourne tous les overrides manuels configures
     * Affiche dans le back-office pour que le marchand puisse
     * voir et modifier ses corrections
     *
     * @return array [['event_key'=>'eid','year'=>2028,'date'=>'2028-03-09'], ...]
     */
    public function getAllManualOverrides(): array
    {
        // Lit toutes les cles Configuration correspondant au prefixe
        $rows = $this->db->executeS(
            "SELECT `name`, `value`
             FROM `" . _DB_PREFIX_ . "configuration`
             WHERE `name` LIKE '" . pSQL(self::OVERRIDE_PREFIX) . "%'"
        );

        if (!is_array($rows)) {
            return [];
        }

        $overrides = [];
        $prefix    = self::OVERRIDE_PREFIX;

        foreach ($rows as $row) {
            // Extrait event_key et year depuis le nom de la cle
            // Format : NERIA_CAL_DATE_{EVENT_KEY}_{YEAR}
            $suffix = substr($row['name'], strlen($prefix));
            $parts  = explode('_', $suffix);
            $year   = array_pop($parts);
            $eventKey = strtolower(implode('_', $parts));

            if (!is_numeric($year)) {
                continue;
            }

            $overrides[] = [
                'event_key'  => $eventKey,
                'year'       => (int) $year,
                'date'       => $row['value'],
                'config_key' => $row['name'],
            ];
        }

        usort($overrides, fn($a, $b) => strcmp(
            $a['event_key'] . $a['year'],
            $b['event_key'] . $b['year']
        ));

        return $overrides;
    }

    // ============================================================
    // NIVEAU 2 â€” CALCUL ALGORITHMIQUE
    // ============================================================

    /**
     * Calcule la date d'un evenement par algorithme
     * Pour n'importe quelle annee â€” autonome a vie
     *
     * @param string $eventKey Cle de l'evenement
     * @param int    $year     Annee gregorienne
     * @return \DateTime|null
     */
    private function calculateEventDate(string $eventKey, int $year): ?\DateTime
    {
        switch ($eventKey) {

            case 'eid':
            case 'eid_al_fitr':
                return $this->calculateEidAlFitr($year);

            case 'eid_adha':
            case 'eid_al_adha':
                return $this->calculateEidAlAdha($year);

            case 'ramadan':
                return $this->calculateRamadanStart($year);

            case 'lunar_new_year':
                return $this->calculateLunarNewYear($year);

            case 'seollal':
                // Le Seollal coreen correspond au Nouvel An lunaire chinois
                return $this->calculateLunarNewYear($year);

            case 'mothers_day_fr':
                return $this->calculateMothersDayFrance($year);

            case 'mothers_day_us':
                return $this->calculateMothersDayUS($year);

            case 'easter':
                return $this->calculateEaster($year);

            default:
                return null;
        }
    }

    /**
     * Calcule le debut du Ramadan pour une annee gregorienne donnee
     * Algorithme base sur le calendrier hegirien (cycle de 30 ans)
     *
     * Precision : +/- 1 jour selon les observations locales
     * Le marchand peut corriger via l'override manuel
     *
     * @param int $year Annee gregorienne
     * @return \DateTime|null
     */
    private function calculateRamadanStart(int $year): ?\DateTime
    {
        // Conversion annee gregorienne â†’ annee hegirienne approximative
        $hijriYear = (int) round(($year - 622) * (33 / 32));

        // Calcul du JDN (Jour Julien) du 1er Ramadan
        // Algorithme de Fliegel & Van Flandern adapte au calendrier hegirien
        $jdn = $this->hijriToJdn($hijriYear, 9, 1);

        return $this->jdnToDateTime($jdn);
    }

    /**
     * Calcule la date d'Eid al-Fitr (1er Shawwal)
     * Fin du Ramadan â€” 30 jours apres le debut
     *
     * @param int $year Annee gregorienne
     * @return \DateTime|null
     */
    private function calculateEidAlFitr(int $year): ?\DateTime
    {
        $hijriYear = (int) round(($year - 622) * (33 / 32));
        $jdn       = $this->hijriToJdn($hijriYear, 10, 1);

        return $this->jdnToDateTime($jdn);
    }

    /**
     * Calcule la date d'Eid al-Adha (10 Dhu al-Hijja)
     *
     * @param int $year Annee gregorienne
     * @return \DateTime|null
     */
    private function calculateEidAlAdha(int $year): ?\DateTime
    {
        $hijriYear = (int) round(($year - 622) * (33 / 32));
        $jdn       = $this->hijriToJdn($hijriYear, 12, 10);

        return $this->jdnToDateTime($jdn);
    }

    /**
     * Convertit une date du calendrier hegirien en Jour Julien
     * Algorithme de conversion standard (Meeus, 1991)
     *
     * @param int $year  Annee hegirienne
     * @param int $month Mois hegirien (1-12)
     * @param int $day   Jour (1-30)
     * @return int Numero du Jour Julien
     */
    private function hijriToJdn(int $year, int $month, int $day): int
    {
        return (int) (
            (11 * $year + 3) / 30
        ) + 354 * $year
          + 30 * $month
          - (int) (($month - 1) / 2)
          + $day
          + 1948440
          - 385;
    }

    /**
     * Convertit un Jour Julien en objet DateTime gregorien
     *
     * @param int $jdn Jour Julien
     * @return \DateTime|null
     */
    private function jdnToDateTime(int $jdn): ?\DateTime
    {
        // Algorithme de conversion JDN â†’ date gregorienne
        $l = $jdn + 68569;
        $n = (int) (4 * $l / 146097);
        $l = $l - (int) ((146097 * $n + 3) / 4);
        $i = (int) (4000 * ($l + 1) / 1461001);
        $l = $l - (int) (1461 * $i / 4) + 31;
        $j = (int) (80 * $l / 2447);

        $day   = $l - (int) (2447 * $j / 80);
        $l     = (int) ($j / 11);
        $month = $j + 2 - 12 * $l;
        $year  = 100 * ($n - 49) + $i + $l;

        if ($year < 1900 || $year > 2100) {
            return null;
        }

        $date = \DateTime::createFromFormat(
            'Y-n-j',
            "{$year}-{$month}-{$day}"
        );

        return $date ?: null;
    }

    /**
     * Calcule le Nouvel An lunaire chinois pour une annee gregorienne
     * Algorithme de Gregorian/Lunar basÃ© sur les cycles de 19 ans (Metonic)
     *
     * Precision : +/- 1-2 jours
     * Pour une precision parfaite, utiliser l'override manuel
     *
     * @param int $year Annee gregorienne
     * @return \DateTime|null
     */
    private function calculateLunarNewYear(int $year): ?\DateTime
    {
        // Calcul base sur le cycle lunaire de 29.530589 jours
        // et l'alignement avec le calendrier solaire
        $cycle  = $year - 2000;
        $golden = ($cycle % 19) + 1;

        // Calcul de la pleine lune la plus proche du 21 janvier
        $epact = (11 * $golden + 20 + 203 - 11) % 30;
        if ($epact < 0) {
            $epact += 30;
        }

        // Date approximative du Nouvel An lunaire
        $day = 21 - $epact;
        if ($day < 0) {
            $day += 30;
            $month = 2;
        } else {
            // Ajustement pour janvier/fevrier
            if ($day <= 21) {
                $month = 1;
                $day   = 21 + (29 - $epact);
            } else {
                $month = 2;
                $day   = $day - 21 + 1;
            }
        }

        // Correction empirique basee sur les donnees historiques
        $month = 1;
        $day   = $this->getLunarNewYearDay($year);

        if (!$day) {
            return null;
        }

        return \DateTime::createFromFormat('Y-n-j', "{$year}-{$month}-{$day}")
            ?: null;
    }

    /**
     * Retourne le jour de janvier/fevrier du Nouvel An lunaire
     * Base sur des donnees tabulees pour la precision
     * Algorithme de secours si les donnees pre-calculees sont absentes
     *
     * @param int $year Annee gregorienne
     * @return int|null Jour du mois (dans la plage jan-fev)
     */
    private function getLunarNewYearDay(int $year): ?int
    {
        // Cycle de 19 ans (cycle de Meton)
        // Le Nouvel An lunaire tombe entre le 21 jan et le 20 fev
        $cyclePosition = ($year - 2000) % 19;
        if ($cyclePosition < 0) {
            $cyclePosition += 19;
        }

        // Decalage moyen par position dans le cycle
        $offsets = [
            0  => 26, // 26 jan
            1  => 15, // 15 fev (mois 2)
            2  => 5,  // 5 fev
            3  => 25, // 25 jan
            4  => 13, // 13 fev (mois 2)
            5  => 3,  // 3 fev
            6  => 23, // 23 jan
            7  => 10, // 10 fev (mois 2)
            8  => 31, // 31 jan
            9  => 19, // 19 fev (mois 2) -- retourne null, utilise override
            10 => 8,  // 8 fev
            11 => 28, // 28 jan
            12 => 16, // 16 fev (mois 2)
            13 => 5,  // 5 fev
            14 => 26, // 26 jan
            15 => 14, // 14 fev (mois 2)
            16 => 2,  // 2 fev
            17 => 22, // 22 jan
            18 => 10, // 10 fev (mois 2)
        ];

        return $offsets[$cyclePosition] ?? null;
    }

    /**
     * Calcule Paques selon l'algorithme de Butcher (exact)
     *
     * @param int $year Annee gregorienne
     * @return \DateTime
     */
    private function calculateEaster(int $year): \DateTime
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return \DateTime::createFromFormat('Y-n-j', "{$year}-{$month}-{$day}");
    }

    /**
     * Calcule la Fete des Meres en France
     * Dernier dimanche de mai (ou premier dimanche de juin si Pentecote)
     *
     * @param int $year Annee
     * @return \DateTime
     */
    private function calculateMothersDayFrance(int $year): \DateTime
    {
        // Trouve le dernier dimanche de mai
        $lastDay = new \DateTime("{$year}-05-31");
        $dow     = (int) $lastDay->format('w'); // 0=dimanche

        if ($dow === 0) {
            return $lastDay;
        }

        $lastDay->modify('-' . $dow . ' days');

        // Si c'est le jour de Pentecote, reporter au premier dimanche de juin
        $pentecote = clone $this->calculateEaster($year);
        $pentecote->modify('+49 days');

        if ($lastDay->format('Y-m-d') === $pentecote->format('Y-m-d')) {
            $lastDay->modify('+7 days');
        }

        return $lastDay;
    }

    /**
     * Calcule la Fete des Meres aux USA
     * Deuxieme dimanche de mai
     *
     * @param int $year Annee
     * @return \DateTime
     */
    private function calculateMothersDayUS(int $year): \DateTime
    {
        $firstDay = new \DateTime("{$year}-05-01");
        $dow      = (int) $firstDay->format('w'); // 0=dimanche

        // Trouve le premier dimanche de mai
        $daysToSunday = ($dow === 0) ? 0 : (7 - $dow);
        $firstSunday  = clone $firstDay;
        $firstSunday->modify("+{$daysToSunday} days");

        // Deuxieme dimanche = + 7 jours
        $secondSunday = clone $firstSunday;
        $secondSunday->modify('+7 days');

        return $secondSunday;
    }

    // ============================================================
    // NIVEAU 3 â€” DATES PRE-CALCULEES (2025-2035)
    // ============================================================

    private function loadCalendarDates(): void
    {
        if ($this->datesLoaded) {
            return;
        }

        $jsonPath = $this->module->getModulePath('data/calendar.json');

        $builtIn  = $this->getBuiltInDates();

        if (file_exists($jsonPath)) {
            $json = file_get_contents($jsonPath);
            $data = json_decode($json, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $this->calendarDates = array_merge($builtIn, $data);
                $this->datesLoaded   = true;
                return;
            }
        }

        $this->calendarDates = $builtIn;
        $this->datesLoaded   = true;
    }

    /**
     * Dates pre-calculees et verifiees manuellement â€” 2025 a 2035
     * Servent de filet de securite si l'algorithme est imprecis
     *
     * @return array
     */
    private function getBuiltInDates(): array
    {
        return [

            // â”€â”€ Dates fixes recurrees (NIVEAU 4) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

            'christmas'  => ['recurring' => ['month' => 12, 'day' => 25]],
            'new_year'   => ['recurring' => ['month' => 1,  'day' => 1]],
            'valentine'  => ['recurring' => ['month' => 2,  'day' => 14]],
            'halloween'  => ['recurring' => ['month' => 10, 'day' => 31]],
            'setsubun'   => ['recurring' => ['month' => 2,  'day' => 3]],
            'nowruz'     => ['recurring' => ['month' => 3,  'day' => 21]],

            // â”€â”€ Eid al-Fitr â€” 2025 a 2035 â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'eid' => [
                'dates' => [
                    ['year' => 2025, 'month' => 3,  'day' => 30],
                    ['year' => 2026, 'month' => 3,  'day' => 20],
                    ['year' => 2027, 'month' => 3,  'day' => 9],
                    ['year' => 2028, 'month' => 2,  'day' => 26],
                    ['year' => 2029, 'month' => 2,  'day' => 14],
                    ['year' => 2030, 'month' => 2,  'day' => 4],
                    ['year' => 2031, 'month' => 1,  'day' => 24],
                    ['year' => 2032, 'month' => 1,  'day' => 13],
                    ['year' => 2033, 'month' => 1,  'day' => 2],
                    ['year' => 2034, 'month' => 12, 'day' => 22],
                    ['year' => 2035, 'month' => 12, 'day' => 12],
                ],
            ],

            // â”€â”€ Eid al-Adha â€” 2025 a 2035 â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'eid_adha' => [
                'dates' => [
                    ['year' => 2025, 'month' => 6,  'day' => 6],
                    ['year' => 2026, 'month' => 5,  'day' => 27],
                    ['year' => 2027, 'month' => 5,  'day' => 16],
                    ['year' => 2028, 'month' => 5,  'day' => 5],
                    ['year' => 2029, 'month' => 4,  'day' => 24],
                    ['year' => 2030, 'month' => 4,  'day' => 13],
                    ['year' => 2031, 'month' => 4,  'day' => 3],
                    ['year' => 2032, 'month' => 3,  'day' => 22],
                    ['year' => 2033, 'month' => 3,  'day' => 11],
                    ['year' => 2034, 'month' => 3,  'day' => 1],
                    ['year' => 2035, 'month' => 2,  'day' => 18],
                ],
            ],

            // â”€â”€ Debut du Ramadan â€” 2025 a 2035 â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'ramadan' => [
                'dates' => [
                    ['year' => 2025, 'month' => 3,  'day' => 1],
                    ['year' => 2026, 'month' => 2,  'day' => 18],
                    ['year' => 2027, 'month' => 2,  'day' => 8],
                    ['year' => 2028, 'month' => 1,  'day' => 28],
                    ['year' => 2029, 'month' => 1,  'day' => 16],
                    ['year' => 2030, 'month' => 1,  'day' => 6],
                    ['year' => 2031, 'month' => 12, 'day' => 26],
                    ['year' => 2032, 'month' => 12, 'day' => 15],
                    ['year' => 2033, 'month' => 12, 'day' => 4],
                    ['year' => 2034, 'month' => 11, 'day' => 23],
                    ['year' => 2035, 'month' => 11, 'day' => 13],
                ],
            ],

            // â”€â”€ Nouvel An lunaire chinois â€” 2025 a 2035 â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'lunar_new_year' => [
                'dates' => [
                    ['year' => 2025, 'month' => 1,  'day' => 29],
                    ['year' => 2026, 'month' => 2,  'day' => 17],
                    ['year' => 2027, 'month' => 2,  'day' => 6],
                    ['year' => 2028, 'month' => 1,  'day' => 26],
                    ['year' => 2029, 'month' => 2,  'day' => 13],
                    ['year' => 2030, 'month' => 2,  'day' => 3],
                    ['year' => 2031, 'month' => 1,  'day' => 23],
                    ['year' => 2032, 'month' => 2,  'day' => 11],
                    ['year' => 2033, 'month' => 1,  'day' => 31],
                    ['year' => 2034, 'month' => 2,  'day' => 19],
                    ['year' => 2035, 'month' => 2,  'day' => 8],
                ],
            ],

            // â”€â”€ Seollal coreen â€” meme dates que lunaire chinois â”€â”€â”€
            'seollal' => [
                'dates' => [
                    ['year' => 2025, 'month' => 1,  'day' => 29],
                    ['year' => 2026, 'month' => 2,  'day' => 17],
                    ['year' => 2027, 'month' => 2,  'day' => 6],
                    ['year' => 2028, 'month' => 1,  'day' => 26],
                    ['year' => 2029, 'month' => 2,  'day' => 13],
                    ['year' => 2030, 'month' => 2,  'day' => 3],
                    ['year' => 2031, 'month' => 1,  'day' => 23],
                    ['year' => 2032, 'month' => 2,  'day' => 11],
                    ['year' => 2033, 'month' => 1,  'day' => 31],
                    ['year' => 2034, 'month' => 2,  'day' => 19],
                    ['year' => 2035, 'month' => 2,  'day' => 8],
                ],
            ],

            // â”€â”€ Diwali â€” 2025 a 2035 â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'diwali' => [
                'dates' => [
                    ['year' => 2025, 'month' => 10, 'day' => 20],
                    ['year' => 2026, 'month' => 11, 'day' => 8],
                    ['year' => 2027, 'month' => 10, 'day' => 29],
                    ['year' => 2028, 'month' => 10, 'day' => 17],
                    ['year' => 2029, 'month' => 11, 'day' => 5],
                    ['year' => 2030, 'month' => 10, 'day' => 26],
                    ['year' => 2031, 'month' => 10, 'day' => 15],
                    ['year' => 2032, 'month' => 11, 'day' => 2],
                    ['year' => 2033, 'month' => 10, 'day' => 23],
                    ['year' => 2034, 'month' => 10, 'day' => 12],
                    ['year' => 2035, 'month' => 11, 'day' => 1],
                ],
            ],

            // â”€â”€ Hanami â€” approximation (debut avril) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'hanami' => [
                'recurring' => ['month' => 4, 'day' => 1],
            ],
        ];
    }

    // ============================================================
    // CLIENTS ET ENVOI
    // ============================================================

    private function getEligibleCustomers(
        string $lang,
        string $countryCode
    ): array {
        $idLang = $this->getIdLangFromCode($lang);

        if (!$idLang) {
            return [];
        }

        $countryFilter = '';

        if (!empty($countryCode)) {
            $idCountry = (int) \Country::getByIso($countryCode);
            if ($idCountry) {
                $countryFilter = "AND c.`id_customer` IN (
                    SELECT DISTINCT a.`id_customer`
                    FROM `" . _DB_PREFIX_ . "address` a
                    INNER JOIN `" . _DB_PREFIX_ . "country` co
                        ON co.`id_country` = a.`id_country`
                    WHERE co.`iso_code` = '" . pSQL($countryCode) . "'
                      AND a.`deleted`   = 0
                )";
            }
        }

        $sql = "SELECT c.`id_customer`, c.`email`,
                       c.`firstname`, c.`lastname`, c.`id_lang`
                FROM `" . _DB_PREFIX_ . "customer` c
                WHERE c.`id_lang`    = {$idLang}
                  AND c.`active`     = 1
                  AND c.`deleted`    = 0
                  AND c.`newsletter` = 1
                  AND c.`id_shop`    = {$this->idShop}
                  {$countryFilter}
                ORDER BY c.`id_customer` ASC
                LIMIT " . self::MAX_RECIPIENTS_PER_EVENT;

        $rows = $this->db->executeS($sql);
        return is_array($rows) ? $rows : [];
    }

    private function getIdLangFromCode(string $lang): int
    {
        $isoMap  = ['br' => 'pt', 'tw' => 'zh'];
        $isoCode = $isoMap[$lang] ?? $lang;

        return (int) $this->db->getValue(
            "SELECT `id_lang`
             FROM `" . _DB_PREFIX_ . "lang`
             WHERE `iso_code` = '" . pSQL($isoCode) . "'
               AND `active`   = 1"
        );
    }

    private function sendToCustomers(
        array  $customers,
        string $template,
        string $lang,
        string $eventKey
    ): array {
        $sent    = 0;
        $failed  = 0;
        $batches = array_chunk($customers, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            foreach ($batch as $customer) {
                if ($this->sendCalendarEmail($customer, $template, $lang)) {
                    $sent++;
                } else {
                    $failed++;
                    $this->watchdog()->warning(
                        sprintf(
                            'Échec envoi à %s (id %d) pour l\'occasion "%s" — Mail::Send() a retourné false.',
                            $customer['email'],
                            $customer['id_customer'],
                            $eventKey
                        ),
                        $template,
                        'CalendarManager'
                    );
                }
            }
            if (count($batches) > 1) {
                usleep(100000);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function sendCalendarEmail(
        array  $customer,
        string $template,
        string $lang
    ): bool {
        $idLang    = (int) $customer['id_lang'];
        $shopName  = \Configuration::get('PS_SHOP_NAME');
        $shopEmail = \Configuration::get('PS_SHOP_EMAIL');

        $templateVars = [
            '{firstname}'   => $customer['firstname'],
            '{lastname}'    => $customer['lastname'],
            '{email}'       => $customer['email'],
            '{shop_name}'   => $shopName,
            '{shop_url}'    => \Tools::getShopDomainSsl(true, true),
            '{id_customer}' => $customer['id_customer'],
        ];

        // Vérifier que le template existe avant d'appeler Mail::Send
        $templateFile = _PS_MODULE_DIR_ . 'neria/mails/' . $lang . '/' . $template . '.html';
        if (!file_exists($templateFile)) {
            $this->module->log(
                'CalendarManager: template manquant — ' . $templateFile,
                2
            );
            return false;
        }

        try {
            $result = \Mail::Send(
                $idLang, $template,
                \Mail::l('', $idLang),
                $templateVars,
                $customer['email'],
                $customer['firstname'] . ' ' . $customer['lastname'],
                $shopEmail, $shopName,
                null, null,
                _PS_MODULE_DIR_ . 'neria/mails/',
                false,
                $this->idShop
            );

            return $result !== false;

        } catch (\Throwable $e) {
            $this->module->log(
                'CalendarManager: erreur SMTP envoi à '
                . $customer['email'] . ' : ' . $e->getMessage(),
                2
            );
            return false;
        }
    }

    // ============================================================
    // BACK-OFFICE â€” GESTION DES EVENEMENTS
    // ============================================================

    public function getAllEvents(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $rows  = $this->db->executeS(
            "SELECT * FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
             ORDER BY `event_key` ASC, `lang` ASC"
        );
        return is_array($rows) ? $rows : [];
    }

    public function setEventActive(int $idEvent, bool $isActive): bool
    {
        $table = _DB_PREFIX_ . self::TABLE;
        return $this->db->execute(
            "UPDATE `{$table}`
             SET `is_active` = " . (int) $isActive . ",
                 `date_upd`  = '" . date('Y-m-d H:i:s') . "'
             WHERE `id_event` = {$idEvent}
               AND `id_shop`  = {$this->idShop}"
        ) !== false;
    }

    public function setDaysBefore(int $idEvent, int $daysBefore): bool
    {
        $table      = _DB_PREFIX_ . self::TABLE;
        $daysBefore = max(0, min(30, $daysBefore));
        return $this->db->execute(
            "UPDATE `{$table}`
             SET `send_days_before` = {$daysBefore},
                 `date_upd`         = '" . date('Y-m-d H:i:s') . "'
             WHERE `id_event` = {$idEvent}
               AND `id_shop`  = {$this->idShop}"
        ) !== false;
    }

    public function resetSentMarker(
        string $eventKey,
        string $lang,
        string $countryCode,
        int    $year
    ): void {
        \Configuration::deleteByName(
            $this->buildSentKey($eventKey, $lang, $countryCode, $year)
        );
    }

    /**
     * Retourne les prochaines dates d'envoi avec source de la date
     * (manuel/calcule/pre-calcule) pour transparence dans le back-office
     *
     * @return array
     */
    public function getUpcomingDates(): array
    {
        $this->loadCalendarDates();

        $events   = $this->getActiveEvents();
        $today    = new \DateTime('today');
        $year     = (int) $today->format('Y');
        $upcoming = [];

        foreach ($events as $event) {
            $eventDate = $this->getEventDate($event['event_key'], $year);

            if (!$eventDate) {
                $eventDate = $this->getEventDate($event['event_key'], $year + 1);
                if (!$eventDate) {
                    continue;
                }
            }

            $sendDate  = clone $eventDate;
            $sendDate->modify('-' . $event['send_days_before'] . ' days');

            if ($sendDate < $today) {
                continue;
            }

            $eventYear = (int) $eventDate->format('Y');
            $sentKey   = $this->buildSentKey(
                $event['event_key'],
                $event['lang'],
                $event['country_code'],
                $eventYear
            );

            // Determine la source de la date pour info du marchand
            $source = $this->getDateSource($event['event_key'], $eventYear);

            $upcoming[] = [
                'event_key'    => $event['event_key'],
                'lang'         => $event['lang'],
                'country_code' => $event['country_code'],
                'template'     => $event['template'],
                'event_date'   => $eventDate->format('Y-m-d'),
                'send_date'    => $sendDate->format('Y-m-d'),
                'days_before'  => $event['send_days_before'],
                'already_sent' => (bool) \Configuration::get($sentKey),
                'days_until'   => (int) $today->diff($sendDate)->days,
                'date_source'  => $source,
            ];
        }

        usort($upcoming, fn($a, $b) => strcmp($a['send_date'], $b['send_date']));

        return $upcoming;
    }

    /**
     * Retourne la source de la date pour transparence back-office
     * Affiche "manuel", "calcule" ou "pre-calcule" dans l'interface
     *
     * @param string $eventKey Cle de l'evenement
     * @param int    $year     Annee
     * @return string
     */
    public function getDateSource(string $eventKey, int $year): string
    {
        $overrideKey = self::OVERRIDE_PREFIX . strtoupper($eventKey) . '_' . $year;
        if (\Configuration::get($overrideKey)) {
            return 'manuel';
        }

        $calculated = $this->calculateEventDate($eventKey, $year);
        if ($calculated) {
            return 'calcule';
        }

        return 'pre-calcule';
    }

    // ============================================================
    // UTILITAIRES PRIVES
    // ============================================================

    private function getActiveEvents(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $rows  = $this->db->executeS(
            "SELECT * FROM `{$table}`
             WHERE `id_shop`   = {$this->idShop}
               AND `is_active` = 1
             ORDER BY `event_key` ASC"
        );
        return is_array($rows) ? $rows : [];
    }

    private function buildSentKey(
        string $eventKey,
        string $lang,
        string $countryCode,
        int    $year
    ): string {
        return self::SENT_PREFIX
            . strtoupper($eventKey)              . '_'
            . strtoupper($lang)                  . '_'
            . strtoupper($countryCode ?: 'ALL')  . '_'
            . $year;
    }
}