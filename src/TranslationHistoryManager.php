<?php
/**
 * © 2026 Neria.software - All rights reserved
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class TranslationHistoryManager
{
    const TABLE       = 'neria_translation_history';
    const MAX_PER_KEY = 50;

    private \Db $db;
    private int $idShop;

    public function __construct()
    {
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    public function record(
        string $template,
        string $lang,
        string $key,
        string $oldValue,
        string $newValue,
        string $author
    ): void {
        if ($oldValue === $newValue) {
            return;
        }

        $this->db->insert(self::TABLE, [
            'id_shop'         => $this->idShop,
            'template_key'    => pSQL($template),
            'lang_code'       => pSQL($lang),
            'translation_key' => pSQL($key),
            'old_value'       => pSQL($oldValue, true),
            'new_value'       => pSQL($newValue, true),
            'author'          => pSQL($author),
            'date_add'        => date('Y-m-d H:i:s'),
        ]);

        $this->pruneKey($template, $lang, $key);
    }

    public function getHistoryForTemplate(string $template, string $lang, int $limit = 40): array
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $rows  = $this->db->executeS(sprintf(
            "SELECT * FROM `%s`
             WHERE `id_shop` = %d AND `template_key` = '%s' AND `lang_code` = '%s'
             ORDER BY `date_add` DESC
             LIMIT %d",
            $table,
            $this->idShop,
            pSQL($template),
            pSQL($lang),
            $limit
        ));
        return is_array($rows) ? $rows : [];
    }

    public function getById(int $idHistory): ?array
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $row   = $this->db->getRow(sprintf(
            "SELECT * FROM `%s` WHERE `id_history` = %d AND `id_shop` = %d",
            $table,
            $idHistory,
            $this->idShop
        ));
        return $row ?: null;
    }

    private function pruneKey(string $template, string $lang, string $key): void
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $keep = $this->db->executeS(sprintf(
            "SELECT `id_history` FROM `%s`
             WHERE `id_shop` = %d AND `template_key` = '%s'
               AND `lang_code` = '%s' AND `translation_key` = '%s'
             ORDER BY `date_add` DESC
             LIMIT %d",
            $table,
            $this->idShop,
            pSQL($template),
            pSQL($lang),
            pSQL($key),
            self::MAX_PER_KEY
        ));

        if (empty($keep)) {
            return;
        }

        $ids = implode(',', array_map(fn($r) => (int) $r['id_history'], $keep));

        $this->db->execute(sprintf(
            "DELETE FROM `%s`
             WHERE `id_shop` = %d AND `template_key` = '%s'
               AND `lang_code` = '%s' AND `translation_key` = '%s'
               AND `id_history` NOT IN (%s)",
            $table,
            $this->idShop,
            pSQL($template),
            pSQL($lang),
            pSQL($key),
            $ids
        ));
    }

    public static function createTable(): bool
    {
        return \Db::getInstance()->execute("
            CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . self::TABLE . "` (
                `id_history`      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `id_shop`         INT UNSIGNED  NOT NULL DEFAULT 1,
                `template_key`    VARCHAR(100)  NOT NULL,
                `lang_code`       VARCHAR(5)    NOT NULL,
                `translation_key` VARCHAR(150)  NOT NULL,
                `old_value`       MEDIUMTEXT    NOT NULL,
                `new_value`       MEDIUMTEXT    NOT NULL,
                `author`          VARCHAR(200)  NOT NULL DEFAULT '',
                `date_add`        DATETIME      NOT NULL,
                PRIMARY KEY (`id_history`),
                KEY `idx_lookup` (`id_shop`, `template_key`, `lang_code`, `translation_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public static function dropTable(): bool
    {
        return \Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`'
        );
    }
}
