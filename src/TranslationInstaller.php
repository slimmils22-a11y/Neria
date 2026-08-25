<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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

    /** Round 139 : SET NAMES exécuté une seule fois par opération d'import — voir flushBatch(). */
    private bool $charsetSet = false;

    /**
     * Round 204 : logs Watchdog en attente, journalisés seulement APRÈS
     * résolution de la transaction (COMMIT/ROLLBACK) — voir queueWatchdogError()
     * ci-dessous pour le pourquoi.
     */
    private array $pendingWatchdogLogs = [];

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

    /**
     * Round 204 : WatchdogManager utilise la MÊME connexion Db::getInstance()
     * singleton que TranslationInstaller — un watchdog()->error() appelé
     * DANS la transaction START TRANSACTION/ROLLBACK (import échoué) était
     * donc lui-même annulé par le ROLLBACK, effaçant précisément le log
     * censé expliquer pourquoi l'import a échoué (erreur SQL réelle du
     * bulk insert, doublon de clé détecté). Les appels concernés (dans
     * flushBatch() et la détection de doublon d'importFromJson()) sont
     * désormais mis en attente ici, puis réellement journalisés par
     * flushPendingWatchdogLogs() une fois la transaction résolue.
     */
    private function queueWatchdogError(string $msg, string $template = ''): void
    {
        $this->pendingWatchdogLogs[] = ['msg' => $msg, 'template' => $template, 'moduleLog' => null];
    }

    /**
     * Même piège que queueWatchdogError() ci-dessus : Neria::log() écrit via
     * PrestaShopLogger::addLog(), lui aussi sur la connexion Db::getInstance()
     * singleton — un appel DANS la transaction est tout aussi annulé par un
     * ROLLBACK que le serait un watchdog()->error() direct.
     */
    private function queueModuleLog(string $msg, int $severity = 3): void
    {
        $this->pendingWatchdogLogs[] = ['msg' => null, 'template' => '', 'moduleLog' => ['msg' => $msg, 'severity' => $severity]];
    }

    private function flushPendingWatchdogLogs(): void
    {
        foreach ($this->pendingWatchdogLogs as $log) {
            if ($log['msg'] !== null) {
                $this->watchdog()->error($log['msg'], $log['template'], 'TranslationInstaller');
            }
            if ($log['moduleLog'] !== null) {
                $this->module->log($log['moduleLog']['msg'], $log['moduleLog']['severity']);
            }
        }
        $this->pendingWatchdogLogs = [];
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
        // Round 140 : compteurs réinitialisés en tout début d'appel — garde
        // toute réutilisation de la même instance (ex. reloadDefaultTranslations()
        // suivi d'un second import) contre un résidu de compteurs d'un appel
        // précédent, y compris sur les branches d'échec précoce ci-dessous
        // (fichier introuvable, JSON invalide, racine non-tableau) qui
        // retournaient jusqu'ici sans jamais y toucher.
        $this->countInserted = 0;
        $this->countSkipped  = 0;
        $this->countErrors   = 0;

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

        // json_last_error() ne détecte QUE les erreurs de syntaxe — un JSON
        // syntaxiquement valide mais dont la racine n'est pas un objet/tableau
        // (null, un nombre, une chaîne — ex: fichier vidé par erreur, réponse
        // d'erreur d'un CDN livrée à la place du vrai fichier) passe cette
        // vérification. Sans ce garde-fou, le foreach ci-dessous ne s'exécute
        // simplement pas (aucune erreur PHP fatale), $failed reste false, la
        // transaction est COMMIT — effaçant silencieusement tout le
        // dictionnaire par défaut sans jamais réinsérer quoi que ce soit.
        if (!is_array($translations)) {
            $this->module->log(
                'TranslationInstaller: structure JSON invalide (racine non-tableau) → ' . $jsonPath,
                3
            );
            return false;
        }

        // ── 3-5. Purge + import par lots, DANS UNE TRANSACTION ────
        // Auparavant clearDefaultTranslations() vidait la table
        // IMMÉDIATEMENT, avant même la première tentative d'insertion, et
        // rien n'encadrait l'ensemble (pas de START TRANSACTION/ROLLBACK).
        // Un échec d'un seul lot en cours de route (timeout hébergement,
        // verrou transitoire — ce cas est appelé aussi bien à l'install
        // qu'au clic BO "Réinitialiser les textes") arrêtait l'import net :
        // les lots déjà traités restaient en base, mais tous les
        // templates/langues suivants dans l'ordre du foreach n'étaient
        // jamais réinsérés — table dans un état PIRE qu'avant l'appel,
        // avec des blocs de texte manquants pouvant partir en production
        // avant que quiconque s'en aperçoive. La transaction garantit
        // désormais que soit TOUT l'import réussit, soit rien n'est modifié
        // (la table retrouve son état d'avant l'appel).
        $this->db->execute('START TRANSACTION');

        $this->clearDefaultTranslations();

        $batch = [];
        $now   = date('Y-m-d H:i:s');
        $failed = false;
        // Round 161 : la contrainte UNIQUE (template, lang, translation_key)
        // fait que l'INSERT IGNORE en bulk (flushBatch) déduplique déjà les
        // doublons éventuels du JSON source SANS jamais le signaler — la
        // 1ère valeur des deux gagne silencieusement, la 2e (souvent la
        // correction la plus récente) est perdue sans passer par
        // countSkipped/countErrors ni aucun log. Ce garde-fou détecte le
        // doublon AVANT l'insertion et le journalise, sans changer le
        // comportement (la 1ère valeur continue de gagner).
        $seenKeys = [];

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

                    $dedupKey = $template . '|' . $lang . '|' . $key;
                    if (isset($seenKeys[$dedupKey])) {
                        $this->queueWatchdogError(
                            \WatchdogManager::i18nMsg('watchdog.translation_duplicate_key', ['template' => (string) $template, 'lang' => (string) $lang, 'key' => (string) $key])
                        );
                        continue;
                    }
                    $seenKeys[$dedupKey] = true;

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
                            $failed = true;
                            break 3;
                        }
                        $batch = [];
                    }
                }
            }
        }

        // Flush le dernier batch (< BATCH_SIZE lignes)
        if (!$failed && !empty($batch)) {
            if (!$this->flushBatch($batch)) {
                $failed = true;
            }
        }

        if ($failed) {
            $this->db->execute('ROLLBACK');
            // Round 204 : journalisé maintenant que ROLLBACK est passé —
            // voir queueWatchdogError()/flushPendingWatchdogLogs().
            $this->flushPendingWatchdogLogs();
            // Round 140 : compteurs remis à zéro — ROLLBACK annule TOUTES
            // les insertions en base, y compris celles des lots flushés
            // avec succès avant l'échec, mais countInserted/countSkipped/
            // countErrors restaient à leur valeur accumulée pendant
            // l'import. Un appelant affichant getSummary() après un import
            // raté voyait "X traductions importées" alors que la table
            // avait été intégralement restaurée à son état antérieur (0
            // insertion réelle) — rapport BO trompeur.
            $this->countInserted = 0;
            $this->countSkipped  = 0;
            $this->countErrors   = 0;
            return false;
        }

        $this->db->execute('COMMIT');
        // Round 204 : les logs de doublon détectés en cours d'import (s'il
        // y en a eu, sans que cela fasse échouer l'import) sont journalisés
        // maintenant que COMMIT est passé.
        $this->flushPendingWatchdogLogs();

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
            \WatchdogManager::i18nMsg('watchdog.translation_install_summary', ['n' => $this->countInserted]),
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
        // Round 140 : compteurs réinitialisés en début d'appel — contrairement
        // à reloadDefaultTranslations() (qui les reset avant d'appeler
        // importFromJson()), importTemplate() ne le faisait jamais. Rien
        // n'empêche l'API publique de réutiliser la même instance pour
        // réinitialiser plusieurs templates successivement (ex. boucle BO) ;
        // sans ce reset, getSummary() après le 2e appel additionnait les
        // résultats du 1er import au lieu de refléter uniquement le dernier.
        $this->countInserted = 0;
        $this->countSkipped  = 0;
        $this->countErrors   = 0;

        // Round 161 : contrairement à importFromJson(), ces 3 branches
        // d'échec retournaient jusqu'ici `false` sans aucune trace — ni
        // module->log(), ni Watchdog. Un reset de template raté depuis le
        // BO (clé mal orthographiée côté appelant, translations.json
        // temporairement corrompu par un déploiement interrompu) devenait
        // indiagnosticable après coup.
        if (!file_exists($jsonPath)) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.translation_import_template_source_unreadable', ['template' => $template, 'reason' => 'file not found']),
                '', 'TranslationInstaller'
            );
            return false;
        }

        $json = file_get_contents($jsonPath);

        if ($json === false) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.translation_import_template_source_unreadable', ['template' => $template, 'reason' => 'read failed']),
                '', 'TranslationInstaller'
            );
            return false;
        }

        $all = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($all[$template])) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.translation_import_template_source_unreadable', ['template' => $template, 'reason' => json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'template not found in source']),
                '', 'TranslationInstaller'
            );
            return false;
        }

        $batch = [];
        $now   = date('Y-m-d H:i:s');

        foreach ($all[$template] as $lang => $fields) {
            // Un bloc de langue malformé (scalaire au lieu d'un tableau de clés)
            // ne doit pas faire planter un simple "réinitialiser ce template"
            // dans le BO — même garde-fou que importFromJson().
            if (!is_array($fields)) {
                $this->countSkipped++;
                continue;
            }
            foreach ($fields as $key => $value) {
                if (is_string($value)) {
                    $batch[] = $this->buildRow($template, $lang, $key, $value, $now);
                } else {
                    $this->countSkipped++;
                }
            }
        }

        // Suppression + réinsertion encadrées par une transaction — même
        // correctif que importFromJson() (cf. son commentaire) : sans elle,
        // un échec de flushBatch() après la suppression laissait ce
        // template sans AUCUNE traduction par défaut, cassant les envois
        // qui l'utilisent tant qu'un nouvel essai réussi n'était pas
        // relancé. NB : Db::delete() préfixe lui-même la table — passer
        // self::TABLE SANS _DB_PREFIX_ (sinon double préfixe → table
        // inexistante).
        // Round 140 : un $batch vide n'est PLUS traité comme un succès
        // automatique — auparavant, si tous les blocs langue du template
        // étaient malformés (scalaire au lieu de tableau) ou toutes leurs
        // valeurs non-string, $batch restait vide, $ok valait true par
        // défaut, et le DELETE ci-dessus était validé (COMMIT) SANS
        // réinsertion : le template perdait définitivement ses traductions
        // par défaut, silencieusement, sans aucune trace Watchdog — même
        // famille de bug que les erreurs réseau avalées des rounds 131/135.
        // Un $batch vide est désormais systématiquement traité comme un
        // échec (ROLLBACK), la suppression n'est jamais validée sans
        // réinsertion effective.
        $this->db->execute('START TRANSACTION');
        $this->db->delete(
            self::TABLE,
            '`template` = \'' . pSQL($template) . '\' AND `is_custom` = 0'
        );
        $batchWasEmpty = empty($batch);
        $ok = !$batchWasEmpty && $this->flushBatch($batch);
        if ($ok) {
            $this->db->execute('COMMIT');
        } else {
            $this->db->execute('ROLLBACK');
            // flushBatch() journalise déjà sa propre erreur Watchdog en cas
            // d'échec SQL réel (watchdog.translation_install_bulk_error,
            // avec la vraie erreur MySQL) — ne pas dupliquer ce log ici. Le
            // SEUL cas qui n'avait jusqu'ici AUCUNE trace est batch vide
            // (données source malformées), d'où ce log dédié.
            if ($batchWasEmpty) {
                $this->queueWatchdogError(
                    \WatchdogManager::i18nMsg('watchdog.translation_import_template_empty', ['template' => $template])
                );
            }
        }
        // Round 204 : journalisé seulement maintenant que la transaction
        // (COMMIT ou ROLLBACK) est résolue — voir queueWatchdogError().
        $this->flushPendingWatchdogLogs();

        return $ok;
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
        // NB : Db::delete() préfixe lui-même la table — passer self::TABLE
        // SANS _DB_PREFIX_ (sinon double préfixe → table inexistante).
        $this->db->delete(
            self::TABLE,
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

        // Round 139 : SET NAMES exécuté UNE SEULE FOIS par opération
        // d'import (flag d'instance), pas à chaque lot — sur un import de
        // plusieurs milliers de clés × 19 langues (potentiellement 50-100+
        // lots de BATCH_SIZE), c'était une requête réseau superflue répétée
        // à chaque flushBatch(), alors que l'encodage de session ne change
        // jamais en cours de route.
        if (!$this->charsetSet) {
            $this->db->execute("SET NAMES 'utf8mb4'");
            $this->charsetSet = true;
        }

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
            // Affected_Rows() reflète le nombre RÉEL de lignes insérées par
            // ce INSERT IGNORE, pas count($batch) — auparavant tout le lot
            // était compté comme inséré même si une partie avait été
            // silencieusement ignorée pour cause de doublon de clé unique
            // (template+lang+translation_key). Le résumé BO pouvait ainsi
            // annoncer plus de traductions importées qu'il n'y en avait
            // réellement, masquant une perte de données sans que le
            // marchand ni le Watchdog ne la détectent.
            $this->countInserted += (int) $this->db->Affected_Rows();
        } else {
            $this->countErrors += count($batch);
            $this->queueModuleLog(
                'TranslationInstaller: erreur bulk insert → ' .
                $this->db->getMsgError(),
                3
            );
            $this->queueWatchdogError(
                \WatchdogManager::i18nMsg('watchdog.translation_install_bulk_error', ['error' => $this->db->getMsgError()])
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