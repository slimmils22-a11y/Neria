<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA â€” ABTestManager
 *
 * Gestion des tests A/B sur les templates email.
 *
 * Principe :
 * â€” Le marchand cree deux versions d'un email (A = original, B = variante)
 * â€” Chaque client recoit toujours la meme variante (coherence)
 * â€” La repartition est configurable (ex: 50/50, 60/40)
 * â€” Les resultats sont mesures via StatsManager
 * â€” Le marchand declare un gagnant et desactive le test
 *
 * Algorithme de repartition :
 * La variante est determinee par un hash du couple (id_customer + template).
 * Cela garantit qu'un client recoit toujours la meme variante
 * sans stocker d'affectation en base â€” zero table supplementaire.
 *
 * Exemple :
 *   hash('abandoned_cart_1' + id_customer=42) % 100 = 37
 *   split_percent(A) = 60 â†’ 37 < 60 â†’ variante A
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ABTestManager
{
    // ============================================================
    // CONSTANTES
    // ============================================================

    const TABLE            = 'neria_abtest';
    const TABLE_TRAD       = 'neria_abtest_translation';

    const VARIANT_A        = 'A';
    const VARIANT_B        = 'B';

    const STATUS_DRAFT     = 'draft';
    const STATUS_ACTIVE    = 'active';
    const STATUS_PAUSED    = 'paused';
    const STATUS_COMPLETED = 'completed';

    // ============================================================
    // PROPRIETES
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var \Db Instance de la base de donnees */
    private \Db $db;

    /** @var int ID boutique courante */
    private int $idShop;

    private array $activeTestsCache = [];
    private bool  $cacheLoaded      = false;
    private ?WatchdogManager $wdm   = null;

    private function wd(): WatchdogManager
    {
        if ($this->wdm === null) {
            $this->wdm = new WatchdogManager($this->module);
        }
        return $this->wdm;
    }

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    // ============================================================
    // SELECTION DE LA VARIANTE
    // ============================================================

    /**
     * Determine la variante A ou B a envoyer pour un client donne
     * Retourne '' si aucun test n'est actif pour ce template
     *
     * Appele par EmailRenderer::resolveABVariant()
     *
     * @param string $template   Nom du template (ex: abandoned_cart_1)
     * @param int    $idCustomer ID client PrestaShop (0 = invite)
     * @return string 'A', 'B' ou ''
     */
    public function getVariantForEmail(string $template, int $idCustomer): string
    {
        // Charge les tests actifs si pas encore fait
        $this->loadActiveTests();

        // Aucun test actif pour ce template
        if (!isset($this->activeTestsCache[$template])) {
            return '';
        }

        $test = $this->activeTestsCache[$template];

        // Clients invites (id=0) : toujours variante A
        // (pas d'identifiant stable pour garantir la coherence)
        if ($idCustomer === 0) {
            return self::VARIANT_A;
        }

        // Algorithme de repartition deterministe
        $variant = $this->assignVariant($template, $idCustomer, (int) $test['split_percent']);

        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.abtest_assigned', ['template' => $template, 'customer' => $idCustomer, 'variant' => $variant]),
            $template, 'ABTestManager'
        );

        return $variant;
    }

    /**
     * Algorithme de repartition par hash
     *
     * Le hash de (template + id_customer) produit un nombre
     * entre 0 et 99. Si ce nombre est inferieur au split_percent
     * de A, le client recoit A. Sinon il recoit B.
     *
     * Avantages :
     * - Deterministe : meme resultat a chaque appel
     * - Sans stockage : pas de table d'affectation
     * - Bien reparti : crc32 donne une distribution uniforme
     *
     * @param string $template     Nom du template
     * @param int    $idCustomer   ID client
     * @param int    $splitPercent Pourcentage envoye en variante A (0-100)
     * @return string 'A' ou 'B'
     */
    private function assignVariant(
        string $template,
        int    $idCustomer,
        int    $splitPercent
    ): string {
        // crc32 produit un entier signe â€” abs() pour le positiver
        $hash    = abs(crc32($template . '|' . $idCustomer));
        $bucket  = $hash % 100; // Valeur entre 0 et 99

        return $bucket < $splitPercent
            ? self::VARIANT_A
            : self::VARIANT_B;
    }

    // ============================================================
    // GESTION DES TESTS (BACK-OFFICE)
    // ============================================================

    /**
     * Cree un nouveau test A/B pour un template
     *
     * @param string $template     Template concerne (ex: abandoned_cart_1)
     * @param string $variantAName Nom de la variante A (ex: "Ton discret")
     * @param string $variantBName Nom de la variante B (ex: "Ton urgent")
     * @param int    $splitPercent Pourcentage envoye en A (defaut: 50)
     * @param string $description  Description du test
     * @return int|false ID du test cree ou false si erreur
     */
    public function createTest(
        string $template,
        string $variantAName,
        string $variantBName,
        int    $splitPercent = 50,
        string $description  = ''
    ) {
        // Valide le split (entre 10 et 90 pour eviter les cas extremes)
        $splitPercent = max(10, min(90, $splitPercent));

        $table = _DB_PREFIX_ . self::TABLE;
        $now   = date('Y-m-d H:i:s');

        // Cree la variante A
        $sqlA = sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `template`, `variant`, `variant_name`,
                 `description`, `split_percent`, `is_active`,
                 `date_add`, `date_upd`)
             VALUES (%d, '%s', 'A', '%s', '%s', %d, 0, '%s', '%s')",
            $table,
            $this->idShop,
            pSQL($template),
            pSQL($variantAName),
            pSQL($description),
            $splitPercent,
            $now, $now
        );

        if (!$this->db->execute($sqlA)) {
            return false;
        }

        $idAbtestA = (int) $this->db->Insert_ID();

        // Cree la variante B
        $sqlB = sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `template`, `variant`, `variant_name`,
                 `description`, `split_percent`, `is_active`,
                 `date_add`, `date_upd`)
             VALUES (%d, '%s', 'B', '%s', '%s', %d, 0, '%s', '%s')",
            $table,
            $this->idShop,
            pSQL($template),
            pSQL($variantBName),
            pSQL($description),
            100 - $splitPercent, // Complement pour B
            $now, $now
        );

        if (!$this->db->execute($sqlB)) {
            return false;
        }

        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.abtest_created', [
                'template' => $template,
                'nameA'    => $variantAName,
                'nameB'    => $variantBName,
                'splitA'   => $splitPercent,
                'splitB'   => 100 - $splitPercent,
            ]),
            $template, 'ABTestManager'
        );

        return $idAbtestA;
    }

    /**
     * Active un test A/B pour un template
     * Desactive automatiquement tout test precedent sur ce template
     *
     * @param string $template Nom du template
     * @return bool
     */
    public function activateTest(string $template): bool
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $now   = date('Y-m-d H:i:s');

        // Desactive les anciens tests actifs sur ce template
        $this->db->execute(
            "UPDATE `{$table}`
             SET `is_active` = 0, `date_upd` = '{$now}'
             WHERE `id_shop`  = {$this->idShop}
               AND `template` = '" . pSQL($template) . "'
               AND `is_active` = 1"
        );

        // Active les deux variantes du nouveau test
        $result = $this->db->execute(
            "UPDATE `{$table}`
             SET `is_active`  = 1,
                 `date_start` = '{$now}',
                 `date_upd`   = '{$now}'
             WHERE `id_shop`  = {$this->idShop}
               AND `template` = '" . pSQL($template) . "'"
        );

        // Invalide le cache
        $this->cacheLoaded = false;
        $this->activeTestsCache = [];

        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.abtest_activated', ['template' => $template]),
            $template, 'ABTestManager'
        );

        return $result !== false;
    }

    /**
     * Desactive un test A/B
     *
     * @param string $template Nom du template
     * @return bool
     */
    public function deactivateTest(string $template): bool
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $now   = date('Y-m-d H:i:s');

        $result = $this->db->execute(
            "UPDATE `{$table}`
             SET `is_active` = 0,
                 `date_end`  = '{$now}',
                 `date_upd`  = '{$now}'
             WHERE `id_shop`  = {$this->idShop}
               AND `template` = '" . pSQL($template) . "'"
        );

        // Invalide le cache
        $this->cacheLoaded = false;
        $this->activeTestsCache = [];

        if ($result !== false) {
            $this->wd()->info(
                \WatchdogManager::i18nMsg('watchdog.abtest_stopped', ['template' => $template]),
                $template, 'ABTestManager'
            );
        }

        return $result !== false;
    }

    /**
     * Supprime tous les tests d'un template
     *
     * @param string $template Nom du template
     * @return bool
     */
    public function deleteTests(string $template): bool
    {
        $table     = _DB_PREFIX_ . self::TABLE;
        $tableTrad = _DB_PREFIX_ . self::TABLE_TRAD;

        // Recupere les IDs des tests a supprimer
        $ids = $this->db->executeS(
            "SELECT `id_abtest` FROM `{$table}`
             WHERE `id_shop`  = {$this->idShop}
               AND `template` = '" . pSQL($template) . "'"
        );

        if (!$ids) {
            return true;
        }

        $idList = implode(',', array_column($ids, 'id_abtest'));

        // Supprime les traductions associees
        $this->db->execute(
            "DELETE FROM `{$tableTrad}`
             WHERE `id_abtest` IN ({$idList})"
        );

        // Supprime les tests
        $this->db->execute(
            "DELETE FROM `{$table}`
             WHERE `id_abtest` IN ({$idList})"
        );

        $this->cacheLoaded = false;
        $this->activeTestsCache = [];

        return true;
    }

    // ============================================================
    // TRADUCTIONS DE LA VARIANTE B
    // ============================================================

    /**
     * Sauvegarde les textes de la variante B
     * Ces textes remplacent les textes standard quand la variante B est active
     *
     * @param int    $idAbtest ID du test (variante B)
     * @param string $lang     Code langue
     * @param array  $fields   ['greeting_main' => 'Texte B...', ...]
     * @return bool
     */
    public function saveVariantBTranslations(
        int    $idAbtest,
        string $lang,
        array  $fields
    ): bool {
        $table = _DB_PREFIX_ . self::TABLE_TRAD;
        $now   = date('Y-m-d H:i:s');
        $batch = [];

        foreach ($fields as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $batch[] = sprintf(
                "(%d, '%s', '%s', '%s', '%s', '%s')",
                $idAbtest,
                pSQL($lang),
                pSQL($key),
                pSQL($value, true),
                $now,
                $now
            );
        }

        if (empty($batch)) {
            return true;
        }

        $sql = sprintf(
            "INSERT INTO `%s`
                (`id_abtest`, `lang`, `translation_key`,
                 `translation_value`, `date_add`, `date_upd`)
             VALUES %s
             ON DUPLICATE KEY UPDATE
                `translation_value` = VALUES(`translation_value`),
                `date_upd`          = VALUES(`date_upd`)",
            $table,
            implode(', ', $batch)
        );

        return $this->db->execute($sql) !== false;
    }

    /**
     * Recupere la valeur d'un champ pour la variante B
     * Retourne null si aucune traduction B n'existe pour cette cle
     *
     * Appele par EmailRenderer dans la closure {neria_trad}
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     * @param string $key      Cle de traduction
     * @return string|null
     */
    public function getVariantBValue(
        string $template,
        string $lang,
        string $key
    ): ?string {
        $this->loadActiveTests();

        if (!isset($this->activeTestsCache[$template])) {
            return null;
        }

        $idAbtest = (int) ($this->activeTestsCache[$template]['id_abtest_b'] ?? 0);

        if (!$idAbtest) {
            return null;
        }

        $table = _DB_PREFIX_ . self::TABLE_TRAD;

        $value = $this->db->getValue(
            "SELECT `translation_value`
             FROM `{$table}`
             WHERE `id_abtest`       = {$idAbtest}
               AND `lang`            = '" . pSQL($lang) . "'
               AND `translation_key` = '" . pSQL($key) . "'"
        );

        return $value !== false ? (string) $value : null;
    }

    // ============================================================
    // LECTURE â€” BACK-OFFICE
    // ============================================================

    /**
     * Retourne tous les tests d'un template (actifs et inactifs)
     *
     * @param string $template Nom du template
     * @return array
     */
    public function getTestsByTemplate(string $template): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT *
             FROM `{$table}`
             WHERE `id_shop`  = {$this->idShop}
               AND `template` = '" . pSQL($template) . "'
             ORDER BY `date_add` DESC"
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Retourne tous les tests actifs pour tous les templates
     *
     * @return array
     */
    public function getAllActiveTests(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT *
             FROM `{$table}`
             WHERE `id_shop`   = {$this->idShop}
               AND `is_active` = 1
             ORDER BY `template` ASC, `variant` ASC"
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Retourne le statut d'un test pour un template donne
     *
     * @param string $template Nom du template
     * @return string 'active', 'paused', 'draft' ou 'none'
     */
    public function getTestStatus(string $template): string
    {
        $this->loadActiveTests();

        if (isset($this->activeTestsCache[$template])) {
            return self::STATUS_ACTIVE;
        }

        $table = _DB_PREFIX_ . self::TABLE;
        $count = (int) $this->db->getValue(
            "SELECT COUNT(*)
             FROM `{$table}`
             WHERE `id_shop`  = {$this->idShop}
               AND `template` = '" . pSQL($template) . "'"
        );

        return $count > 0 ? self::STATUS_DRAFT : 'none';
    }

    /**
     * Retourne la liste des templates ayant un test configure
     * Avec leur statut respectif
     *
     * @return array [['template' => '...', 'status' => '...'], ...]
     */
    public function getTemplatesWithTests(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT
                `template`,
                MAX(`is_active`) AS has_active,
                COUNT(*)         AS variant_count,
                MAX(`date_start`) AS date_start
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
             GROUP BY `template`
             ORDER BY `template` ASC"
        );

        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['status'] = $row['has_active']
                ? self::STATUS_ACTIVE
                : self::STATUS_DRAFT;
        }

        return $rows;
    }

    // ============================================================
    // CACHE INTERNE
    // ============================================================

    /**
     * Charge en memoire tous les tests actifs
     * Structure du cache :
     * [
     *   'abandoned_cart_1' => [
     *     'id_abtest_a'  => 1,
     *     'id_abtest_b'  => 2,
     *     'split_percent' => 50,
     *     'variant_a_name' => 'Ton discret',
     *     'variant_b_name' => 'Ton urgent',
     *   ]
     * ]
     */
    private function loadActiveTests(): void
    {
        if ($this->cacheLoaded) {
            return;
        }

        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT *
             FROM `{$table}`
             WHERE `id_shop`   = {$this->idShop}
               AND `is_active` = 1
             ORDER BY `template` ASC, `variant` ASC"
        );

        $this->activeTestsCache = [];

        if (!is_array($rows)) {
            $this->cacheLoaded = true;
            return;
        }

        // Regroupe A et B par template
        foreach ($rows as $row) {
            $tpl     = $row['template'];
            $variant = $row['variant'];

            if (!isset($this->activeTestsCache[$tpl])) {
                $this->activeTestsCache[$tpl] = [
                    'split_percent' => (int) $row['split_percent'],
                ];
            }

            if ($variant === self::VARIANT_A) {
                $this->activeTestsCache[$tpl]['id_abtest_a']    = (int) $row['id_abtest'];
                $this->activeTestsCache[$tpl]['variant_a_name'] = $row['variant_name'];
            } else {
                $this->activeTestsCache[$tpl]['id_abtest_b']    = (int) $row['id_abtest'];
                $this->activeTestsCache[$tpl]['variant_b_name'] = $row['variant_name'];
            }
        }

        $this->cacheLoaded = true;
    }

    // ============================================================
    // APPLICATION DU GAGNANT
    // ============================================================

    /**
     * Applique la variante gagnante comme template par défaut.
     * Si B gagne : copie ses traductions vers neria_translation.
     * Si A gagne : rien à faire (A est déjà le défaut).
     * Dans les deux cas : désactive le test.
     */
    public function applyWinner(string $template, string $winner): bool
    {
        if ($winner === self::VARIANT_B) {
            $this->copyVariantBToDefault($template);
        }
        return $this->deactivateTest($template);
    }

    private function copyVariantBToDefault(string $template): void
    {
        $tableAb    = _DB_PREFIX_ . self::TABLE;
        $tableTradB = _DB_PREFIX_ . self::TABLE_TRAD;
        $tableTrad  = _DB_PREFIX_ . 'neria_translation';
        $now        = date('Y-m-d H:i:s');

        $idAbtestB = (int) $this->db->getValue(
            "SELECT `id_abtest` FROM `{$tableAb}`
             WHERE `id_shop`   = {$this->idShop}
               AND `template`  = '" . pSQL($template) . "'
               AND `variant`   = 'B'
               AND `is_active` = 1"
        );

        if (!$idAbtestB) {
            return;
        }

        $this->db->execute(
            "INSERT INTO `{$tableTrad}`
                 (`template`, `lang`, `translation_key`, `translation_value`, `date_add`, `date_upd`)
             SELECT '" . pSQL($template) . "', `lang`, `translation_key`, `translation_value`,
                    '{$now}', '{$now}'
             FROM `{$tableTradB}`
             WHERE `id_abtest` = {$idAbtestB}
             ON DUPLICATE KEY UPDATE
                `translation_value` = VALUES(`translation_value`),
                `date_upd`          = VALUES(`date_upd`)"
        );

        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.abtest_variant_b_promoted', ['template' => $template]),
            $template, 'ABTestManager'
        );
    }

    // ============================================================
    // HISTORIQUE
    // ============================================================

    const TABLE_HISTORY = 'neria_abtest_history';

    /**
     * Sauvegarde un snapshot du test terminé dans l'historique.
     * À appeler AVANT deactivateTest().
     */
    public function archiveTest(string $template, array $report, string $winner, int $confidence, bool $applied = false): void
    {
        $tableAb = _DB_PREFIX_ . self::TABLE;
        $rows = $this->db->executeS(
            "SELECT `variant`, `variant_name`, `split_percent`, `date_start`
             FROM `{$tableAb}`
             WHERE `id_shop`   = {$this->idShop}
               AND `template`  = '" . pSQL($template) . "'
               AND `is_active` = 1"
        );

        $variantAName = '';
        $variantBName = '';
        $splitPercent = 50;
        $dateStart    = null;

        foreach ((array) $rows as $row) {
            if ($row['variant'] === 'A') {
                $variantAName = $row['variant_name'];
                $splitPercent = (int) $row['split_percent'];
                $dateStart    = $row['date_start'];
            } else {
                $variantBName = $row['variant_name'];
            }
        }

        $a = $report['A'] ?? [];
        $b = $report['B'] ?? [];

        $table = _DB_PREFIX_ . self::TABLE_HISTORY;
        $sql = sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `template`, `variant_a_name`, `variant_b_name`, `split_percent`,
                 `sent_a`, `sent_b`,
                 `rate_open_a`, `rate_open_b`, `rate_click_a`, `rate_click_b`,
                 `revenue_a`, `revenue_b`,
                 `winner`, `confidence`, `applied`, `date_start`, `date_end`)
             VALUES (%d, '%s', '%s', '%s', %d,
                     %d, %d,
                     %.1f, %.1f, %.1f, %.1f,
                     %.2f, %.2f,
                     %s, %s, %d, %s, NOW())",
            $table,
            $this->idShop,
            pSQL($template),
            pSQL($variantAName),
            pSQL($variantBName),
            $splitPercent,
            (int) ($a['total_sent']  ?? 0),
            (int) ($b['total_sent']  ?? 0),
            (float) ($a['rate_open']   ?? 0),
            (float) ($b['rate_open']   ?? 0),
            (float) ($a['rate_click']  ?? 0),
            (float) ($b['rate_click']  ?? 0),
            (float) ($a['total_revenue'] ?? 0),
            (float) ($b['total_revenue'] ?? 0),
            $winner !== '' ? "'" . pSQL($winner) . "'" : 'NULL',
            $confidence > 0 ? (int) $confidence : 'NULL',
            $applied ? 1 : 0,
            $dateStart ? "'" . pSQL($dateStart) . "'" : 'NULL'
        );

        $this->db->execute($sql);

        $prevLang = \AdminTranslator::currentLang();
        \AdminTranslator::setLang(\WatchdogManager::shopLang());
        $winnerLabel = $winner !== ''
            ? \AdminTranslator::tVars('watchdog.abtest_winner_label', ['winner' => $winner, 'confidence' => $confidence])
            : \AdminTranslator::t('watchdog.abtest_no_winner_label');
        $appliedLabel = $applied ? \AdminTranslator::t('watchdog.abtest_applied_label') : '';
        \AdminTranslator::setLang($prevLang);
        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.abtest_archived', ['template' => $template, 'winnerLabel' => $winnerLabel, 'appliedLabel' => $appliedLabel]),
            $template, 'ABTestManager'
        );
    }

    /**
     * Retourne les derniers tests archivés.
     */
    public function getHistory(int $limit = 20): array
    {
        $table = _DB_PREFIX_ . self::TABLE_HISTORY;
        $rows  = $this->db->executeS(
            "SELECT * FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
             ORDER BY `date_end` DESC
             LIMIT " . (int) $limit
        );
        return is_array($rows) ? $rows : [];
    }

    // ============================================================
    // DURÉE ESTIMÉE AVANT RÉSULTAT
    // ============================================================

    /**
     * Estime le nombre de jours restants avant la significativité.
     * Retourne 0 si déjà significatif, null si impossible à calculer.
     */
    public function estimateDaysRemaining(string $template, array $report): ?int
    {
        $sig = $report['significance'] ?? [];

        if ($sig['significant'] ?? false) {
            return 0;
        }

        $sentA     = (int) ($sig['sent_a']     ?? 0);
        $sentB     = (int) ($sig['sent_b']     ?? 0);
        $minSample = (int) ($sig['min_sample'] ?? 100);
        $minSent   = min($sentA, $sentB);

        if ($minSent < 1) {
            return null;
        }

        $tableAb   = _DB_PREFIX_ . self::TABLE;
        $dateStart = $this->db->getValue(
            "SELECT `date_start` FROM `{$tableAb}`
             WHERE `id_shop`   = {$this->idShop}
               AND `template`  = '" . pSQL($template) . "'
               AND `is_active` = 1
               AND `variant`   = 'A'"
        );

        if (!$dateStart) {
            return null;
        }

        $daysElapsed = max(1, (int) ceil((time() - strtotime($dateStart)) / 86400));
        $dailyRate   = $minSent / $daysElapsed;

        if ($dailyRate < 0.01) {
            return null;
        }

        $remaining = max(0, $minSample - $minSent);

        if ($remaining === 0) {
            return 0;
        }

        return (int) ceil($remaining / $dailyRate);
    }

    // ============================================================
    // UTILITAIRES PUBLICS
    // ============================================================

    /**
     * Indique si un test est actif pour un template donne
     *
     * @param string $template Nom du template
     * @return bool
     */
    public function hasActiveTest(string $template): bool
    {
        $this->loadActiveTests();
        return isset($this->activeTestsCache[$template]);
    }

    /**
     * Retourne la liste des templates eligibles aux tests A/B
     * Seuls les templates marketing sont concernes
     * (pas les transactionnels obligatoires comme order_conf)
     *
     * @return array
     */
    public function getEligibleTemplates(): array
    {
        // Templates marketing éligibles à l'A/B testing.
        $eligible = [
            // Paniers & relances comportementales
            'abandoned_cart_1',
            'abandoned_cart_2',
            'abandoned_cart_3',
            'checkout_abandonment',
            'win_back',
            'reorder_reminder',
            'wishlist_reminder',
            // Post-achat & fidélité
            'post_purchase_care',
            'post_purchase_review',
            'birthday',
            'first_anniversary',
            'relationship_anniversary',
            'milestone_order',
            'back_in_stock',
            'loyalty_recap',
            'loyalty_tier_upgrade',
            'loyalty_reward_expiry',
            // Réconciliation post-remboursement
            'refund_reconciliation_1',
            'refund_reconciliation_2',
            'refund_reconciliation_3',
            // Comportemental produit
            'product_lifespan_reminder',
            // Acquisition & engagement
            'newsletter_conf',
            'newsletter_voucher',
            'referral_invitation',
            'private_invitation',
            'private_sale',
            'early_access',
            'vip',
            'voucher',
            'voucher_new',
        ];

        // Libellés traduits dans la langue du back-office (repli FR canonique)
        $labels = class_exists('AdminTranslator')
            ? AdminTranslator::templateLabels()
            : NeriaTools::getTemplateLabels();

        $out = [];
        foreach ($eligible as $key) {
            $out[$key] = $labels[$key] ?? $key;
        }

        return $out;
    }
}