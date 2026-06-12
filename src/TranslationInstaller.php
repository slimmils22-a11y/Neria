<?php
/**
 * NERIA — TranslationInstaller
 *
 * Importe le dictionnaire translations.json en base de données
 * lors de l'installation du module.
 *
 * Stratégie : Bulk Insert par lots de 500 lignes
 * → Beaucoup plus rapide qu'un INSERT par ligne
 * → Évite les timeouts sur les hébergements mutualisés
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TranslationInstaller
{
    // ============================================================
    // CONSTANTES
    // ============================================================

    /** Nombre de lignes insérées par requête SQL */
    const BATCH_SIZE = 500;

    /** Nom de la table sans préfixe */
    const TABLE = 'neria_translation';

    // ============================================================
    // PROPRIÉTÉS
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var \Db Instance de la base de données PrestaShop */
    private \Db $db;

    /** Compteurs pour le rapport d'installation */
    private int $countInserted = 0;
    private int $countSkipped  = 0;
    private int $countErrors   = 0;

    /** @var WatchdogManager|null Instance paresseuse du watchdog */
    private ?WatchdogManager $watchdog = null;

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
    }

    private function watchdog(): WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // MÉTHODE PRINCIPALE
    // ============================================================

    /**
     * Point d'entrée : importe translations.json en base de données
     *
     * @param string $jsonPath Chemin absolu vers translations.json
     * @return bool true si succès, false si erreur critique
     */
    public function importFromJson(string $jsonPath): bool
    {
        // ── 1. Vérification du fichier ───────────────────────────
        if (!file_exists($jsonPath)) {
            $this->module->log(
                'TranslationInstaller: fichier introuvable → ' . $jsonPath,
                3
            );
            return false;
        }

        // ── 2. Lecture et décodage JSON ──────────────────────────
        $json = file_get_contents($jsonPath);

        if ($json === false) {
            $this->module->log(
                'TranslationInstaller: impossible de lire ' . $jsonPath,
                3
            );
            return false;
        }

        $translations = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->module->log(
                'TranslationInstaller: JSON invalide → ' . json_last_error_msg(),
                3
            );
            return false;
        }

        // ── 3. Vide la table avant import (installation propre) ──
        // On vide uniquement si la table est vide ou si c'est
        // une réinstallation (is_custom = 0 uniquement)
        $this->clearDefaultTranslations();

        // ── 4. Import par lots ───────────────────────────────────
        $batch = [];
        $now   = date('Y-m-d H:i:s');

        foreach ($translations as $template => $langs) {
            // Vérifie que la structure est valide
            if (!is_array($langs)) {
                $this->countSkipped++;
                continue;
            }

            foreach ($langs as $lang => $fields) {
                if (!is_array($fields)) {
                    $this->countSkipped++;
                    continue;
                }

                foreach ($fields as $key => $value) {
                    // Ignore les valeurs non-string
                    if (!is_string($value)) {
                        $this->countSkipped++;
                        continue;
                    }

                    // Ajoute la ligne au batch courant
                    $batch[] = $this->buildRow(
                        $template,
                        $lang,
                        $key,
                        $value,
                        $now
                    );

                    // Flush le batch quand il atteint BATCH_SIZE
                    if (count($batch) >= self::BATCH_SIZE) {
                        if (!$this->flushBatch($batch)) {
                            return false;
                        }
                        $batch = [];
                    }
                }
            }
        }

        // ── 5. Flush le dernier batch (< BATCH_SIZE lignes) ─────
        if (!empty($batch)) {
            if (!$this->flushBatch($batch)) {
                return false;
            }
        }

        // ── 6. Log du résultat ───────────────────────────────────
        $this->module->log(
            sprintf(
                'TranslationInstaller: import terminé → %d insérées, %d ignorées, %d erreurs',
                $this->countInserted,
                $this->countSkipped,
                $this->countErrors
            ),
            1
        );
        $this->watchdog()->info(
            sprintf('Import terminé — %d traductions insérées', $this->countInserted),
            '',
            'TranslationInstaller'
        );

        return $this->countErrors === 0;
    }

    // ============================================================
    // RÉINSTALLATION / MISE À JOUR
    // ============================================================

    /**
     * Recharge les traductions par défaut depuis translations.json
     * Utilisé depuis le back-office (bouton "Réinitialiser les textes")
     * NE touche PAS aux traductions personnalisées (is_custom = 1)
     *
     * @param string $jsonPath Chemin absolu vers translations.json
     * @return bool
     */
    public function reloadDefaultTranslations(string $jsonPath): bool
    {
        // Reset des compteurs
        $this->countInserted = 0;
        $this->countSkipped  = 0;
        $this->countErrors   = 0;

        return $this->importFromJson($jsonPath);
    }

    /**
     * Importe uniquement les traductions d'un template spécifique
     * Utilisé quand le marchand réinitialise un seul template
     *
     * @param string $jsonPath Chemin absolu vers translations.json
     * @param string $template Nom du template (ex: order_conf)
     * @return bool
     */
    public function importTemplate(string $jsonPath, string $template): bool
    {
        if (!file_exists($jsonPath)) {
            return false;
        }

        $json = file_get_contents($jsonPath);
        $all  = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($all[$template])) {
            return false;
        }

        // Supprime uniquement les traductions par défaut de ce template
        $this->db->delete(
            _DB_PREFIX_ . self::TABLE,
            '`template` = \'' . pSQL($template) . '\' AND `is_custom` = 0'
        );

        $batch = [];
        $now   = date('Y-m-d H:i:s');

        foreach ($all[$template] as $lang => $fields) {
            foreach ($fields as $key => $value) {
                if (is_string($value)) {
                    $batch[] = $this->buildRow($template, $lang, $key, $value, $now);
                }
            }
        }

        return !empty($batch) ? $this->flushBatch($batch) : true;
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Supprime toutes les traductions par défaut (is_custom = 0)
     * Préserve les traductions personnalisées du marchand (is_custom = 1)
     */
    private function clearDefaultTranslations(): void
    {
        $this->db->delete(
            _DB_PREFIX_ . self::TABLE,
            '`is_custom` = 0'
        );
    }

    /**
     * Construit un tableau représentant une ligne à insérer
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     * @param string $key      Clé de traduction
     * @param string $value    Texte traduit
     * @param string $now      Datetime courante
     * @return array
     */
    private function buildRow(
        string $template,
        string $lang,
        string $key,
        string $value,
        string $now
    ): array {
        return [
            'template'          => pSQL($template),
            'lang'              => pSQL($lang),
            'translation_key'   => pSQL($key),
            'translation_value' => pSQL($value, true), // true = allow HTML
            'is_custom'         => 0,
            'date_add'          => $now,
            'date_upd'          => $now,
        ];
    }

    /**
     * Exécute un INSERT en bulk pour un lot de lignes
     * Utilise INSERT IGNORE pour éviter les doublons
     * sans provoquer d'erreur SQL
     *
     * @param array $batch Tableau de lignes à insérer
     * @return bool
     */
    private function flushBatch(array $batch): bool
    {
        if (empty($batch)) {
            return true;
        }

        // Forcer l'encodage UTF-8mb4 pour les traductions multilingues
        $this->db->execute("SET NAMES 'utf8mb4'");

        // Construction de la requête INSERT bulk
        $table   = _DB_PREFIX_ . self::TABLE;
        $columns = '(`template`, `lang`, `translation_key`, `translation_value`, `is_custom`, `date_add`, `date_upd`)';
        $values  = [];

        foreach ($batch as $row) {
            $values[] = sprintf(
                "('%s', '%s', '%s', '%s', %d, '%s', '%s')",
                $row['template'],
                $row['lang'],
                $row['translation_key'],
                $row['translation_value'],
                (int) $row['is_custom'],
                $row['date_add'],
                $row['date_upd']
            );
        }

        $sql = sprintf(
            'INSERT IGNORE INTO `%s` %s VALUES %s',
            $table,
            $columns,
            implode(', ', $values)
        );

        $result = $this->db->execute($sql);

        if ($result) {
            $this->countInserted += count($batch);
        } else {
            $this->countErrors += count($batch);
            $this->module->log(
                'TranslationInstaller: erreur bulk insert → ' .
                $this->db->getMsgError(),
                3
            );
            $this->watchdog()->error(
                'Erreur bulk insert : ' . $this->db->getMsgError(),
                '',
                'TranslationInstaller'
            );
        }

        return $result !== false;
    }

    // ============================================================
    // GETTERS — Stats d'installation
    // ============================================================

    /** Retourne le nombre de lignes insérées */
    public function getCountInserted(): int
    {
        return $this->countInserted;
    }

    /** Retourne le nombre de lignes ignorées */
    public function getCountSkipped(): int
    {
        return $this->countSkipped;
    }

    /** Retourne le nombre d'erreurs rencontrées */
    public function getCountErrors(): int
    {
        return $this->countErrors;
    }

    /**
     * Retourne un résumé lisible de l'import
     * Affiché dans le back-office après une réinstallation
     */
    public function getSummary(): string
    {
        return sprintf(
            '%d traductions importées — %d ignorées — %d erreurs',
            $this->countInserted,
            $this->countSkipped,
            $this->countErrors
        );
    }
}