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
    private ?\Neria $module;

    // Round 151 : $module optionnel — permet de journaliser au Watchdog les
    // échecs SQL/verrou de record() (voir plus bas), auparavant totalement
    // silencieux (aucune instance WatchdogManager possible sans référence
    // au module). Reste optionnel pour ne pas casser un appelant existant
    // qui n'aurait pas ce contexte.
    public function __construct(?\Neria $module = null)
    {
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
        $this->module = $module;
    }

    private function wd(): ?\WatchdogManager
    {
        return $this->module ? new \WatchdogManager($this->module) : null;
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

        // Round 138 : verrou MySQL autour de l'INSERT + pruneKey() — sans
        // lui, deux requêtes HTTP concurrentes modifiant la même clé
        // pouvaient chacune insérer puis exécuter leur propre SELECT/DELETE
        // de purge en parallèle, laissant transitoirement plus de
        // MAX_PER_KEY lignes conservées (pas de perte de données ni
        // suppression de la mauvaise ligne, juste une limite non garantie
        // sous forte concurrence — même famille de correctif que
        // toggleMenuItemVisibility(), round 123/127).
        $lockName = 'neria_trad_hist_' . md5($template . '|' . $lang . '|' . $key);
        // Round 151 : le retour de GET_LOCK() est désormais vérifié —
        // auparavant ignoré, un échec (0 = timeout de 3s atteint, verrou
        // déjà tenu ailleurs ; NULL = erreur) laissait passer l'INSERT +
        // pruneKey() exactement comme si le verrou avait été obtenu,
        // recréant sous forte concurrence la race condition que ce verrou
        // (round 138) est censé empêcher.
        $acquired = (int) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 3)");
        if ($acquired !== 1) {
            $this->wd()?->warning(
                \WatchdogManager::i18nMsg('watchdog.translation_history_lock_failed', [
                    'template' => $template,
                    'lang'     => $lang,
                    'key'      => $key,
                ]),
                $template, 'TranslationHistoryManager'
            );
            // Round 175 : le `return` manquait ici — l'échec (ou le refus)
            // du verrou était bien journalisé, mais l'INSERT + pruneKey()
            // s'exécutaient quand même juste en dessous, exactement comme si
            // le verrou avait été obtenu. La vérification du round 151 ne
            // protégeait donc RIEN en pratique : elle se contentait de
            // loguer un warning avant de laisser la race condition du round
            // 138 (double insertion + double purge sous forte concurrence)
            // se reproduire dès que GET_LOCK() time-out (3s) ou échoue.
            return;
        }
        try {
            $inserted = $this->db->insert(self::TABLE, [
                'id_shop'         => $this->idShop,
                'template_key'    => pSQL($template),
                'lang_code'       => pSQL($lang),
                'translation_key' => pSQL($key),
                'old_value'       => pSQL($oldValue, true),
                'new_value'       => pSQL($newValue, true),
                'author'          => pSQL($author),
                'date_add'        => date('Y-m-d H:i:s'),
            ]);

            // Round 151 : échec SQL désormais journalisé — auparavant
            // totalement silencieux (fonction void, aucun log Watchdog dans
            // tout ce fichier), contrairement à TranslationInstaller qui
            // logue systématiquement ses échecs SQL.
            if (!$inserted) {
                $this->wd()?->error(
                    \WatchdogManager::i18nMsg('watchdog.translation_history_insert_failed', [
                        'template' => $template,
                        'lang'     => $lang,
                        'key'      => $key,
                    ]),
                    $template, 'TranslationHistoryManager'
                );
                return;
            }

            $this->pruneKey($template, $lang, $key);
        } finally {
            // Round 175 : le `return` ajouté ci-dessus quand $acquired !== 1
            // rend ce garde redondant (on n'atteint plus jamais ce bloc
            // sans verrou obtenu, $acquired vaut toujours 1 ici) — simplifié
            // en conséquence (PHPStan : comparaison toujours vraie).
            $this->db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        }
    }

    /**
     * Round 138 : lecture/purge NON scopées par id_shop — la table
     * neria_translation (traductions réelles, éditées par ce manager) n'a
     * elle-même AUCUNE colonne id_shop, une modification de traduction est
     * globale à toute l'installation multi-boutique. Filtrer l'HISTORIQUE
     * par id_shop était donc trompeur : un marchand éditant une traduction
     * depuis le contexte boutique B modifiait la valeur globalement (donc
     * visible pour toutes les boutiques), mais l'entrée d'historique
     * n'était visible/restaurable que depuis ce même contexte B — un
     * opérateur consultant l'historique depuis la boutique A ne voyait
     * jamais ce changement, alors qu'il affectait pourtant sa boutique
     * aussi. La colonne id_shop reste écrite à l'INSERT (record() —
     * information de traçabilité : depuis quel contexte l'édition a eu
     * lieu) mais n'est plus utilisée pour filtrer la visibilité ni la
     * rétention.
     */
    public function getHistoryForTemplate(string $template, string $lang, int $limit = 40): array
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $rows  = $this->db->executeS(sprintf(
            "SELECT * FROM `%s`
             WHERE `template_key` = '%s' AND `lang_code` = '%s'
             ORDER BY `date_add` DESC
             LIMIT %d",
            $table,
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
            "SELECT * FROM `%s` WHERE `id_history` = %d",
            $table,
            $idHistory
        ));
        return $row ?: null;
    }

    private function pruneKey(string $template, string $lang, string $key): void
    {
        $table = _DB_PREFIX_ . self::TABLE;

        // Départage sur id_history DESC en cas de date_add identique
        // (résolution à la seconde) : sans lui, un import en masse dans la
        // même seconde pouvait faire exclure du top MAX_PER_KEY conservé
        // la ligne réellement la plus récente (ordre non déterministe entre
        // lignes de date_add égale), qui était alors supprimée par le
        // DELETE ci-dessous au lieu d'une entrée plus ancienne.
        $keep = $this->db->executeS(sprintf(
            "SELECT `id_history` FROM `%s`
             WHERE `template_key` = '%s'
               AND `lang_code` = '%s' AND `translation_key` = '%s'
             ORDER BY `date_add` DESC, `id_history` DESC
             LIMIT %d",
            $table,
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
             WHERE `template_key` = '%s'
               AND `lang_code` = '%s' AND `translation_key` = '%s'
               AND `id_history` NOT IN (%s)",
            $table,
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
