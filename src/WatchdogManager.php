<?php
/**
 * NERIA — WatchdogManager
 *
 * Système de surveillance et journal des erreurs du module.
 * Enregistre tous les événements dans ps_neria_log.
 * Accessible depuis l'onglet Aide du back-office.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class WatchdogManager
{
    const TABLE             = 'neria_log';
    const MAX_LOGS          = 500;
    const DEFAULT_RETENTION = 30; // jours

    const LEVEL_INFO     = 'info';
    const LEVEL_WARNING  = 'warning';
    const LEVEL_ERROR    = 'error';
    const LEVEL_CRITICAL = 'critical';

    private Neria $module;
    private \Db   $db;
    private int   $idShop;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    // ============================================================
    // ENREGISTREMENT
    // ============================================================

    public function info(string $message, string $template = '', string $class = '', array $context = []): void
    {
        $this->record(self::LEVEL_INFO, $message, $template, $class, $context);
    }

    public function warning(string $message, string $template = '', string $class = '', array $context = []): void
    {
        $this->record(self::LEVEL_WARNING, $message, $template, $class, $context);
    }

    public function error(string $message, string $template = '', string $class = '', array $context = []): void
    {
        $this->record(self::LEVEL_ERROR, $message, $template, $class, $context);
        \PrestaShopLogger::addLog('[Neria] ' . $message, 3, null, 'Neria', 0, true);
    }

    public function critical(string $message, string $template = '', string $class = '', array $context = []): void
    {
        $this->record(self::LEVEL_CRITICAL, $message, $template, $class, $context);
        \PrestaShopLogger::addLog('[Neria CRITICAL] ' . $message, 4, null, 'Neria', 0, true);
    }

    private function record(
        string $level,
        string $message,
        string $template,
        string $class,
        array  $context
    ): void {
        $table       = _DB_PREFIX_ . self::TABLE;
        $contextSql  = !empty($context)
            ? "'" . pSQL(json_encode($context, JSON_UNESCAPED_UNICODE)) . "'"
            : 'NULL';

        $this->db->execute(sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `level`, `template`, `class`, `message`, `context`, `date_add`)
             VALUES (%d, '%s', '%s', '%s', '%s', %s, '%s')",
            $table,
            $this->idShop,
            pSQL($level),
            pSQL($template),
            pSQL($class),
            pSQL($message),
            $contextSql,
            date('Y-m-d H:i:s')
        ));

        $this->pruneOldLogs();
    }

    // ============================================================
    // LECTURE — BACK-OFFICE
    // ============================================================

    public function getLogs(
        int    $limit    = 100,
        string $level    = '',
        string $template = ''
    ): array {
        $table       = _DB_PREFIX_ . self::TABLE;
        $levelFilter = $level    ? "AND `level` = '" . pSQL($level) . "'"       : '';
        $tplFilter   = $template ? "AND `template` = '" . pSQL($template) . "'" : '';

        $rows = $this->db->executeS(
            "SELECT *
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
               {$levelFilter}
               {$tplFilter}
             ORDER BY `date_add` DESC
             LIMIT {$limit}"
        );

        return is_array($rows) ? $rows : [];
    }

    public function getCountByLevel(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT `level`, COUNT(*) AS total
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
             GROUP BY `level`"
        );

        $counts = [
            'info'     => 0,
            'warning'  => 0,
            'error'    => 0,
            'critical' => 0,
        ];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $counts[$row['level']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function getTemplatesWithErrors(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT DISTINCT `template`
             FROM `{$table}`
             WHERE `id_shop`  = {$this->idShop}
               AND `template` != ''
               AND `level`    IN ('warning', 'error', 'critical')
             ORDER BY `template` ASC"
        );

        return is_array($rows) ? array_column($rows, 'template') : [];
    }

    // ============================================================
    // MAINTENANCE
    // ============================================================

    public function clearLogs(): bool
    {
        $table = _DB_PREFIX_ . self::TABLE;
        return $this->db->execute(
            "DELETE FROM `{$table}` WHERE `id_shop` = {$this->idShop}"
        ) !== false;
    }

    private function pruneOldLogs(): void
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $this->db->execute(
            "DELETE FROM `{$table}`
             WHERE `id_shop`  = {$this->idShop}
               AND `date_add` < DATE_SUB(NOW(), INTERVAL " . self::DEFAULT_RETENTION . " DAY)"
        );

        $count = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE `id_shop` = {$this->idShop}"
        );

        if ($count > self::MAX_LOGS) {
            $toDelete = $count - self::MAX_LOGS;
            $this->db->execute(
                "DELETE FROM `{$table}`
                 WHERE `id_shop` = {$this->idShop}
                 ORDER BY `date_add` ASC
                 LIMIT {$toDelete}"
            );
        }
    }
}
