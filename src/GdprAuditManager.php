<?php
/**
 * © 2026 Neria.software - All rights reserved
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class GdprAuditManager
{
    /**
     * SOURCE DE VÉRITÉ UNIQUE — toutes les tables Neria concernées par le RGPD.
     *
     * Champs :
     *   table        — nom sans préfixe
     *   date_col     — colonne de date pour purge par ancienneté
     *   months       — durée de rétention légale
     *   label        — libellé affiché dans l'onglet RGPD
     *   note         — explication affichée au marchand
     *   customer_col — colonne id_customer pour purge individuelle (null = pas de PII client)
     *   has_pii      — true si la table contient des données personnelles nominatives
     *
     * Pour ajouter une nouvelle table : ajouter UNE entrée ici, c'est tout.
     */
    const REGISTRY = [
        [
            'table'        => 'neria_stat',
            'date_col'     => 'date_add',
            'months'       => 36,
            'label'        => 'Statistiques d\'envoi (tracking)',
            'note'         => 'Données comportementales des destinataires.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_log',
            'date_col'     => 'date_add',
            'months'       => 12,
            'label'        => 'Journal système (watchdog)',
            'note'         => 'Journaux techniques internes.',
            'customer_col' => null,
            'has_pii'      => false,
        ],
        [
            'table'        => 'neria_behavioral_sent',
            'date_col'     => 'sent_at',
            'months'       => 36,
            'label'        => 'Emails comportementaux (déduplication)',
            'note'         => 'Horodatages d\'envoi liés aux clients.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_customer_segment',
            'date_col'     => 'computed_at',
            'months'       => 36,
            'label'        => 'Segments comportementaux clients',
            'note'         => 'Profils de comportement par client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_churn_score',
            'date_col'     => 'computed_at',
            'months'       => 36,
            'label'        => 'Scores de churn clients',
            'note'         => 'Scores de risque calculés par client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_webhook_queue',
            'date_col'     => 'date_add',
            'months'       => 12,
            'label'        => 'File d\'attente webhooks',
            'note'         => 'Payload JSON peut contenir email/nom client — purgé par ancienneté.',
            'customer_col' => null,
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_translation_history',
            'date_col'     => 'date_add',
            'months'       => 36,
            'label'        => 'Historique des modifications de textes',
            'note'         => 'Changelog interne — ne contient pas de données clients.',
            'customer_col' => null,
            'has_pii'      => false,
        ],
        [
            'table'        => 'neria_preferences',
            'date_col'     => 'date_upd',
            'months'       => 36,
            'label'        => 'Préférences email clients',
            'note'         => 'Choix opt-in/out par catégorie. Contient l\'email en clair.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_attribution',
            'date_col'     => 'created_at',
            'months'       => 36,
            'label'        => 'Attribution de revenus (last-click)',
            'note'         => 'Pivot id_order → token de clic. Purgé par ancienneté (pas d\'id_customer direct).',
            'customer_col' => null,
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_loyalty_points',
            'date_col'     => 'date_add',
            'months'       => 36,
            'label'        => 'Points de fidélité clients',
            'note'         => 'Solde et historique des points par client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_loyalty_rewards',
            'date_col'     => 'sent_at',
            'months'       => 36,
            'label'        => 'Récompenses fidélité envoyées',
            'note'         => 'Bons de réduction générés par palier atteint.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_waitlist',
            'date_col'     => 'registered_at',
            'months'       => 24,
            'label'        => 'Liste d\'attente produits',
            'note'         => 'Demandes de notification retour en stock.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_queue',
            'date_col'     => 'send_at',
            'months'       => 3,
            'label'        => 'File d\'envoi (fenêtre d\'achat)',
            'note'         => 'Emails en attente d\'envoi programmé — contient email et nom destinataire.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_quote',
            'date_col'     => 'date_add',
            'months'       => 60,
            'label'        => 'Devis B2B',
            'note'         => 'Devis suivis manuellement par le marchand.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_upsell',
            'date_col'     => 'sent_at',
            'months'       => 36,
            'label'        => 'Upsell post-achat (historique envois)',
            'note'         => 'Enregistrements d\'envoi d\'upsell par client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_collection_sent',
            'date_col'     => 'sent_at',
            'months'       => 36,
            'label'        => 'Complétion collection (déduplication)',
            'note'         => 'Horodatages d\'envoi par client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_look_sent',
            'date_col'     => 'sent_at',
            'months'       => 36,
            'label'        => 'Complétez votre look (déduplication)',
            'note'         => 'Horodatages d\'envoi par client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_reconciliation',
            'date_col'     => 'date_add',
            'months'       => 36,
            'label'        => 'Réconciliation remboursements',
            'note'         => 'Suivi des emails de réconciliation par commande/client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_propensity_score',
            'date_col'     => 'date_upd',
            'months'       => 36,
            'label'        => 'Scores de propension d\'achat',
            'note'         => 'Scores comportementaux estimés par client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_bounces',
            'date_col'     => 'date_add',
            'months'       => 36,
            'label'        => 'Adresses en rebond (bounces)',
            'note'         => 'Emails invalides détectés — purgés par ancienneté (pas d\'id_customer).',
            'customer_col' => null,
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_certificate',
            'date_col'     => 'date_add',
            'months'       => 60,
            'label'        => 'Certificats d\'authenticité',
            'note'         => 'Contient le nom client et la référence commande.',
            'customer_col' => null,
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_abtest',
            'date_col'     => 'date_add',
            'months'       => 36,
            'label'        => 'Tests A/B',
            'note'         => 'Configuration des tests — pas de données clients directes.',
            'customer_col' => null,
            'has_pii'      => false,
        ],
        // Round 184 : entrée manquante — la table est créée par
        // upgrade-1.0.14.php (TABLE 34) et alimentée par ABTestManager,
        // mais absente du registre RGPD : purgeAllRegistryTables() ne la
        // purgeait jamais, contrairement à toutes les autres tables qui
        // ont chacune une politique de rétention. Aucune donnée nominative
        // (agrégats de variantes A/B uniquement), mais rupture de la
        // politique de rétention annoncée par upgrade-1.0.34.php.
        [
            'table'        => 'neria_abtest_history',
            'date_col'     => 'date_end',
            'months'       => 36,
            'label'        => 'Historique des tests A/B',
            'note'         => 'Agrégats de tests A/B terminés — pas de données clients directes.',
            'customer_col' => null,
            'has_pii'      => false,
        ],
        [
            'table'        => 'neria_seasonal_campaign',
            'date_col'     => 'date_add',
            'months'       => 36,
            'label'        => 'Campagnes saisonnières',
            'note'         => 'Configuration des campagnes — pas de données clients.',
            'customer_col' => null,
            'has_pii'      => false,
        ],
        [
            'table'        => 'neria_birthday_voucher',
            'date_col'     => 'created_at',
            'months'       => 36,
            'label'        => 'Bons de réduction anniversaire',
            'note'         => 'Code de bon nominatif lié au client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
        [
            'table'        => 'neria_milestone_voucher',
            'date_col'     => 'created_at',
            'months'       => 36,
            'label'        => 'Bons de réduction — paliers de commandes',
            'note'         => 'Code de bon nominatif lié au client.',
            'customer_col' => 'id_customer',
            'has_pii'      => true,
        ],
    ];

    /** Rétrocompatibilité — TABLES dérive de REGISTRY */
    public static function getTables(): array
    {
        return array_map(function ($r) {
            return [
                'table'    => $r['table'],
                'date_col' => $r['date_col'],
                'label'    => $r['label'],
                'months'   => $r['months'],
                'note'     => $r['note'],
            ];
        }, self::REGISTRY);
    }

    /** Rétrocompatibilité — PII_TABLES_BY_CUSTOMER dérive de REGISTRY */
    public static function getPiiTablesByCustomer(): array
    {
        $result = [];
        foreach (self::REGISTRY as $r) {
            if ($r['customer_col'] !== null) {
                $result[$r['table']] = $r['customer_col'];
            }
        }
        return $result;
    }

    const CONSENT_TEMPLATES = [
        'newsletter', 'newsletter_conf', 'birthday', 'win_back',
        'abandoned_cart_1', 'abandoned_cart_2', 'abandoned_cart_3',
        'black_friday', 'christmas', 'valentine', 'halloween',
    ];

    // ── Variables PS contenant des données personnelles ──────────
    // Liste codée en dur, non dérivée automatiquement des templates réels —
    // à mettre à jour manuellement si un futur template introduit une
    // nouvelle variable nominative (aucune source de vérité commune avec le
    // moteur de rendu). {shopper_name} ajouté le 2026-08-01 (nom complet du
    // client, trouvé utilisé dans plusieurs templates sans être couvert
    // jusqu'ici par cette cartographie).
    const PII_VARS = [
        '{firstname}'     => 'Prénom',
        '{lastname}'      => 'Nom de famille',
        '{shopper_name}'  => 'Nom complet (prénom + nom)',
        '{email}'         => 'Adresse e-mail',
        '{phone}'         => 'Téléphone',
        '{address1}'      => 'Adresse postale',
        '{address2}'      => 'Complément d\'adresse',
        '{birthday}'      => 'Date de naissance',
    ];

    private \Db   $db;
    private int   $idShop;
    private string $modulePath;

    public function __construct(string $modulePath)
    {
        $this->db         = \Db::getInstance();
        $this->idShop     = (int) \Context::getContext()->shop->id;
        $this->modulePath = rtrim($modulePath, '/\\');
    }

    // ============================================================
    // AUDIT PRINCIPAL
    // ============================================================

    public function runAudit(): array
    {
        $unsub     = $this->auditUnsubscribe();
        $retention = $this->auditRetention();
        $pii       = $this->auditPersonalData();
        $crypto    = $this->auditEncryption();

        $issues = $unsub['issues'] + $retention['issues'] + $pii['issues'] + $crypto['issues'];

        // Grade basé sur l'axe le PLUS DÉGRADÉ (pourcentage d'issues sur le
        // nombre de contrôles réels de CET axe), pas sur la somme brute des
        // 4 axes. Auparavant un axe à 3 checks (désabonnement) et un axe à
        // ~26 checks (rétention, une entrée par table du registre) étaient
        // additionnés au même niveau : l'absence totale du header
        // List-Unsubscribe (RFC 8058, obligatoire Gmail/Outlook) ne comptait
        // que pour 2 points sur 4 axes → grade "B" trompeur, alors que l'axe
        // légalement le plus critique du module était en échec quasi total.
        // À l'inverse, 6 tables juste au-delà de leur seuil de rétention
        // (issue mineure, purge automatique existante) pouvait à elle seule
        // faire tomber le score en "D", pire note que le cas précédent.
        $axisPct = function (int $axisIssues, int $axisTotal): float {
            return $axisTotal > 0 ? max(0.0, 1 - $axisIssues / $axisTotal) * 100 : 100.0;
        };
        $worstAxisPct = min(
            $axisPct($unsub['issues'], 3),
            $axisPct($retention['issues'], max(1, count($retention['rows']))),
            $axisPct($pii['issues'], 1),
            $axisPct($crypto['issues'], 1)
        );
        $score = $this->gradeFromPercent($worstAxisPct);

        $gradeColors = ['A' => '#4a9e6b', 'B' => '#b8600a', 'C' => '#e05c5c', 'D' => '#8b0000'];

        return [
            'unsubscribe'  => $unsub,
            'retention'    => $retention,
            'pii'          => $pii,
            'crypto'       => $crypto,
            'score'        => $score,
            'grade_color'  => $gradeColors[$score] ?? '#888',
            'issues'       => $issues,
            'generated_at' => \NeriaTools::formatDate('now', \AdminTranslator::currentLang(), true),
        ];
    }

    // ============================================================
    // AXE 1 — DÉSABONNEMENT
    // ============================================================

    private function auditUnsubscribe(): array
    {
        $checks = [];
        $issues = 0;

        // 1a. {unsubscribe_url} dans le layout global
        $layoutPath = $this->modulePath . '/mails/themes/neria_global/layout.html';
        $layoutOk   = file_exists($layoutPath)
            && stripos((string) file_get_contents($layoutPath), '{unsubscribe_url}') !== false;
        if (!$layoutOk) { $issues++; }
        $checks[] = [
            'label'  => 'Lien de désabonnement dans le layout global',
            'ok'     => $layoutOk,
            'detail' => $layoutOk
                ? 'Le placeholder {unsubscribe_url} est présent dans layout.html.'
                : 'Le placeholder {unsubscribe_url} est absent du layout — tous les emails sont non conformes.',
        ];

        // 1b. Header List-Unsubscribe (RFC 2369 / RFC 8058) — injecté dans neria.php
        $neriaPhpPath = $this->modulePath . '/neria.php';
        $headerOk = file_exists($neriaPhpPath)
            && stripos((string) file_get_contents($neriaPhpPath), 'List-Unsubscribe') !== false;
        if (!$headerOk) { $issues++; }
        $checks[] = [
            'label'  => 'Header List-Unsubscribe (RFC 2369 / One-Click RFC 8058)',
            'ok'     => $headerOk,
            'detail' => $headerOk
                ? 'Le header est injecté automatiquement sur chaque envoi.'
                : 'Le header List-Unsubscribe n\'est pas configuré — requis par Gmail, Apple Mail, Outlook.',
        ];

        // 1c. Endpoint de désabonnement
        $endpointPath = $this->modulePath . '/controllers/front/unsubscribe.php';
        $endpointOk   = file_exists($endpointPath);
        if (!$endpointOk) { $issues++; }
        $checks[] = [
            'label'  => 'Endpoint de désabonnement (controllers/front/unsubscribe.php)',
            'ok'     => $endpointOk,
            'detail' => $endpointOk
                ? 'Le contrôleur de désabonnement est bien présent.'
                : 'Le fichier controllers/front/unsubscribe.php est manquant — les liens ne fonctionnent pas.',
        ];

        // 1d. Centre de préférences email (consentement granulaire)
        $prefsLayout = file_exists($layoutPath)
            && stripos((string) file_get_contents($layoutPath), '{preferences_url}') !== false;
        $prefsCtrl   = file_exists($this->modulePath . '/controllers/front/preferences.php');
        $prefsOk     = $prefsLayout && $prefsCtrl;
        $checks[] = [
            'label'  => 'Centre de préférences email (consentement granulaire)',
            'ok'     => $prefsOk,
            'detail' => $prefsOk
                ? 'Le lien {preferences_url} est présent dans le layout et le contrôleur est en place.'
                : 'Le centre de préférences n\'est pas configuré — recommandé pour un consentement granulaire conforme.',
            'info'   => !$prefsOk,
        ];

        // 1e. Taille de la blacklist (information, pas une issue)
        $blacklistCount = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_blacklist` WHERE `id_shop` = " . $this->idShop
        );
        $checks[] = [
            'label'  => 'Blacklist de désabonnement',
            'ok'     => true,
            'detail' => $blacklistCount . ' adresse(s) désabonnée(s). La blacklist doit être conservée indéfiniment (preuve de conformité) — aucune purge ne doit être effectuée.',
            'info'   => true,
        ];

        return ['checks' => $checks, 'issues' => $issues];
    }

    // ============================================================
    // AXE 2 — RÉTENTION DES DONNÉES
    // ============================================================

    private function auditRetention(): array
    {
        $rows   = [];
        $issues = 0;

        foreach (self::getTables() as $def) {
            $table  = _DB_PREFIX_ . $def['table'];
            $dcol   = $def['date_col'];
            $months = $def['months'];

            // Vérifie que la table existe
            // Round 214 : $use_cache=false — un résultat périmé pourrait
            // faire sauter à tort l'audit d'une table nouvellement créée
            // (après une mise à jour de module) tant que le cache n'expire
            // pas.
            $exists = (bool) $this->db->getValue(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = '" . pSQL($table) . "'",
                false
            );
            if (!$exists) {
                continue;
            }

            // Certaines tables n'ont pas de colonne id_shop — sur celles qui
            // en ont une, on scope l'audit à la boutique courante comme le
            // fait déjà purgeTable(), sinon le rapport d'une boutique compte
            // aussi les données d'une autre boutique sur une install
            // multi-boutiques (chiffres faussés, désaccord avec la purge).
            $hasShopCol = (bool) $this->db->executeS(
                "SHOW COLUMNS FROM `{$table}` LIKE 'id_shop'"
            );
            $shopFilter = $hasShopCol ? " AND `id_shop` = {$this->idShop}" : '';
            $shopWhere  = $hasShopCol ? " WHERE `id_shop` = {$this->idShop}" : '';

            $total = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$table}`{$shopWhere}");

            $oldest = $this->db->getValue(
                "SELECT MIN(`{$dcol}`) FROM `{$table}`{$shopWhere}"
            );

            $overdue = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$table}`
                 WHERE `{$dcol}` < DATE_SUB(NOW(), INTERVAL {$months} MONTH){$shopFilter}"
            );

            $isIssue = $overdue > 0;
            if ($isIssue) { $issues++; }

            $rows[] = [
                'table'   => $def['table'],
                'date_col'=> $dcol,
                'label'   => $def['label'],
                'note'    => $def['note'],
                'months'  => $months,
                'total'   => $total,
                'oldest'  => $oldest ? \NeriaTools::formatDate($oldest, \AdminTranslator::currentLang()) : '—',
                'overdue' => $overdue,
                'ok'      => !$isIssue,
            ];
        }

        return ['rows' => $rows, 'issues' => $issues];
    }

    // ============================================================
    // AXE 3 — DONNÉES PERSONNELLES DANS LES TEMPLATES
    // ============================================================

    private function auditPersonalData(): array
    {
        $corePath = $this->modulePath . '/mails/themes/neria_global/core';
        $map      = [];

        if (!is_dir($corePath)) {
            return ['map' => [], 'issues' => 0];
        }

        foreach (glob($corePath . '/*.html') as $file) {
            $name    = basename($file, '.html');
            $content = (string) file_get_contents($file);
            $found   = [];
            foreach (self::PII_VARS as $var => $varLabel) {
                if (stripos($content, $var) !== false) {
                    $found[] = $varLabel;
                }
            }
            if ($found) {
                $map[] = [
                    'template'    => $name,
                    'vars'        => $found,
                    'vars_str'    => implode(', ', $found),
                    'legal_basis' => in_array($name, self::CONSENT_TEMPLATES, true)
                        ? 'Consentement'
                        : 'Contrat / intérêt légitime',
                ];
            }
        }

        // Pas d'issue automatique ici — c'est une cartographie informative.
        // L'issue serait l'absence de mentions légales, mais le layout inclut
        // déjà un lien vers les mentions légales de la boutique.
        $layoutPath   = $this->modulePath . '/mails/themes/neria_global/layout.html';
        $legalInLayout = file_exists($layoutPath)
            && stripos((string) file_get_contents($layoutPath), 'mentions-legales') !== false;

        $issues = $legalInLayout ? 0 : 1;

        return [
            'map'           => $map,
            'legal_in_layout' => $legalInLayout,
            'issues'        => $issues,
        ];
    }

    // ============================================================
    // AXE 4 — CHIFFREMENT DES DONNÉES AU REPOS
    // ============================================================

    public function auditEncryption(): array
    {
        $opensslOk = class_exists('CryptoManager') && \CryptoManager::isAvailable();
        // ctype_xdigit() en plus de la longueur : CryptoManager::loadKey()
        // (privée, non appelable ici) exige les deux avant de considérer la
        // clé utilisable — se contenter de la longueur faisait déclarer
        // "actif"/Grade A un chiffrement en réalité cassé (clé corrompue en
        // base mais toujours longue de 64 caractères), alors que decrypt()
        // échoue systématiquement et rend les stats déjà chiffrées
        // définitivement illisibles, silencieusement.
        $rawKey    = (string) \Configuration::get(\CryptoManager::CONFIG_KEY);
        $keyOk     = $opensslOk && strlen($rawKey) === 64 && ctype_xdigit($rawKey);
        $active    = $opensslOk && $keyOk;

        $table     = _DB_PREFIX_ . 'neria_stat';
        // neria_stat a une colonne id_shop — on scope l'audit à la boutique
        // courante comme auditRetention()/purgeTable(), sinon le score RGPD
        // d'une boutique dépend des données en clair d'une autre boutique
        // sur une install multi-boutiques (chiffres et grade faussés).
        $totalVars = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE `rendered_vars` IS NOT NULL AND `rendered_vars` != '' AND `id_shop` = {$this->idShop}"
        );
        $encrypted = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE `rendered_vars` LIKE 'ENC:%' AND `id_shop` = {$this->idShop}"
        );
        $plain = $totalVars - $encrypted;

        // Issue si la clé n'est pas opérationnelle (ce qui couvre aussi le cas
        // openssl indisponible, puisque $keyOk = $opensslOk && ...) ou si des
        // enregistrements restent en clair. Auparavant, `$opensslOk &&` en
        // tête neutralisait l'issue à 0 précisément quand openssl est
        // indisponible — le pire des cas (aucune capacité de chiffrement)
        // était donc reporté comme "conforme" (0 issue), faisant afficher un
        // grade A/100% trompeur sur l'axe légalement le plus sensible
        // (chiffrement des données au repos).
        $issues = (!$keyOk || $plain > 0) ? 1 : 0;

        // Portée réelle de cet axe : seule neria_stat.rendered_vars supporte le
        // chiffrement (déchiffrable à la volée pour l'affichage BO). Plusieurs
        // autres tables du REGISTRY contiennent pourtant de la donnée
        // personnelle en clair par nécessité fonctionnelle (email utilisé pour
        // les recherches/jointures — le chiffrer casserait ces requêtes sans
        // une refonte majeure hors de portée d'un simple correctif). Sans
        // cette liste, le marchand pouvait lire "100% chiffré" sur cet axe et
        // en conclure à tort que TOUTES les données personnelles du module le
        // sont — informatif seulement, non compté dans $issues (l'écart est
        // documenté et intentionnel, pas une anomalie corrigible ici).
        // Filtre resserré sur les notes documentant explicitement un champ
        // texte email/nom en clair — pas simplement `has_pii` (bien plus
        // large : quasi toutes les tables ont un `id_customer`, ce qui les
        // rend "personnelles" au sens RGPD mais ne signifie pas qu'elles
        // stockent une adresse email en texte brut hors structure).
        $otherPiiTables = [];
        foreach (self::REGISTRY as $entry) {
            if ($entry['table'] === 'neria_stat') {
                continue;
            }
            $note = $entry['note'] ?? '';
            if (($entry['has_pii'] ?? false) && (stripos($note, 'email') !== false || stripos($note, 'clair') !== false)) {
                $otherPiiTables[] = [
                    'table' => $entry['table'],
                    'label' => $entry['label'],
                    'note'  => $note,
                ];
            }
        }

        return [
            'openssl_ok'        => $opensslOk,
            'key_ok'            => $keyOk,
            'active'            => $active,
            'cipher'            => 'AES-256-GCM',
            'total'             => $totalVars,
            'encrypted'         => $encrypted,
            'plain'             => $plain,
            'issues'            => $issues,
            'other_pii_tables'  => $otherPiiTables,
        ];
    }

    /**
     * Chiffre en masse les enregistrements rendered_vars encore en clair.
     * Traite par lots de 200 pour ne pas saturer la mémoire.
     *
     * @return int Nombre d'enregistrements chiffrés
     */
    public function encryptExistingRecords(): int
    {
        if (!class_exists('CryptoManager') || !\CryptoManager::isAvailable()) {
            return 0;
        }

        // CryptoManager::encrypt() retourne la valeur EN CLAIR inchangée si la
        // clé maîtresse est illisible (absente/corrompue) — sans ce contrôle,
        // la boucle ci-dessous réécrit indéfiniment les mêmes lignes non
        // préfixées 'ENC:%' (la condition de sortie du WHERE n'est jamais
        // satisfaite), bloquant la requête BO jusqu'au timeout serveur.
        $keyProbe = \CryptoManager::encrypt('neria_key_probe');
        if (!\CryptoManager::isEncrypted($keyProbe)) {
            if (class_exists('WatchdogManager') && class_exists('Module')) {
                $neria = \Module::getInstanceByName('neria');
                if ($neria) {
                    (new \WatchdogManager($neria))->error(
                        'Chiffrement rétroactif RGPD annulé : clé de chiffrement illisible (NERIA_ENCRYPTION_KEY absente ou corrompue).',
                        '', 'GdprAuditManager'
                    );
                }
            }
            return 0;
        }

        $table = _DB_PREFIX_ . 'neria_stat';
        $done  = 0;

        do {
            // Round 144 : scopé par id_shop — contrairement au reste du
            // fichier (auditRetention(), purgeTable(), auditEncryption()),
            // cette méthode chiffrait TOUTES les boutiques dès qu'un
            // marchand déclenchait l'action depuis la sienne, effet de bord
            // cross-boutique non maîtrisé et incohérent avec la discipline
            // appliquée partout ailleurs dans ce même fichier.
            $rows = $this->db->executeS(
                "SELECT `id_stat`, `rendered_vars` FROM `{$table}`
                 WHERE `id_shop` = " . (int) $this->idShop . "
                   AND `rendered_vars` IS NOT NULL
                   AND `rendered_vars` != ''
                   AND `rendered_vars` NOT LIKE 'ENC:%'
                 LIMIT 200"
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $encrypted = \CryptoManager::encrypt($row['rendered_vars']);
                // Garde-fou supplémentaire (défense en profondeur) : si le
                // chiffrement n'a produit aucun changement malgré la sonde
                // ci-dessus (ex: clé devenue illisible EN COURS de boucle),
                // on arrête immédiatement plutôt que de tourner sans fin.
                if ($encrypted === $row['rendered_vars']) {
                    return $done;
                }
                $this->db->execute(
                    "UPDATE `{$table}` SET `rendered_vars` = '" . pSQL($encrypted) . "'
                     WHERE `id_stat` = " . (int) $row['id_stat']
                );
                $done++;
            }
        } while (count($rows) === 200);

        return $done;
    }

    // ============================================================
    // PURGE
    // ============================================================

    public function purgeTable(string $table, string $dateCol, int $months): int
    {
        $fullTable = _DB_PREFIX_ . $table;

        // Sécurité : on ne purge que les tables Neria connues
        $allowed = array_column(self::getTables(), 'table');
        if (!in_array($table, $allowed, true)) {
            return 0;
        }

        // Certaines tables du registre n'ont pas de colonne id_shop (ex:
        // neria_upsell, neria_loyalty_points, neria_bounces) — sur celles
        // qui en ont une, on scope la purge à la boutique courante : sans
        // ce filtre, un marchand restreint à la boutique A purgerait aussi
        // les données conservées de la boutique B sur une install
        // multi-boutiques (suppression irréversible cross-boutique).
        $hasShopCol = (bool) $this->db->executeS(
            "SHOW COLUMNS FROM `{$fullTable}` LIKE 'id_shop'"
        );
        $shopFilter = $hasShopCol ? " AND `id_shop` = {$this->idShop}" : '';

        // Compte avant purge
        // Round 214 : $use_cache=false — le DELETE ci-dessous s'exécute de
        // toute façon (pas de check-then-act sur l'action elle-même), mais
        // ce compte est retourné et affiché comme preuve d'exécution de la
        // purge automatique quotidienne ; un résultat périmé afficherait un
        // chiffre trompeur (typiquement 0) au marchand.
        $count = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$fullTable}`
             WHERE `{$dateCol}` < DATE_SUB(NOW(), INTERVAL {$months} MONTH){$shopFilter}",
            false
        );

        $this->db->execute(
            "DELETE FROM `{$fullTable}`
             WHERE `{$dateCol}` < DATE_SUB(NOW(), INTERVAL {$months} MONTH){$shopFilter}"
        );

        return $count;
    }

    /**
     * Purge automatiquement TOUTES les tables du registre selon leur durée
     * de rétention (`months`) — appelée quotidiennement par
     * BehavioralCronManager::run() si NERIA_GDPR_AUTO_PURGE_ENABLED est
     * activé (activé par défaut).
     *
     * Avant ce correctif, aucun mécanisme automatique n'existait : le seul
     * chemin de purge réel était le bouton manuel du BO (une table à la
     * fois), et StatsManager::cleanup()/DEFAULT_RETENTION_DAYS (365 jours)
     * n'était appelée nulle part dans le module — code mort, supprimé.
     * neria_stat pouvait donc grossir indéfiniment sur une boutique jamais
     * entretenue manuellement, aggravant directement les coûts de requêtes
     * qui en dépendent (CLV, score de churn, audit RGPD lui-même).
     *
     * @return array<string,int> table (sans préfixe) => nombre de lignes purgées
     */
    public function purgeAllRegistryTables(): array
    {
        $results = [];
        foreach (self::getTables() as $def) {
            $table = _DB_PREFIX_ . $def['table'];
            $exists = $this->db->executeS("SHOW TABLES LIKE '" . pSQL($table) . "'");
            if (!is_array($exists) || empty($exists)) {
                continue;
            }
            $purged = $this->purgeTable($def['table'], $def['date_col'], $def['months']);
            if ($purged > 0) {
                $results[$def['table']] = $purged;
            }
        }

        return $results;
    }

    /**
     * Purge toutes les données personnelles Neria d'un client (hook RGPD PS).
     * Appelé par hookActionDeleteGDPRCustomer quand un marchand supprime un compte.
     */
    public function purgeCustomerData(int $idCustomer, string $email): int
    {
        // Round 258 : l'ensemble de cette méthode encadre désormais TOUTES
        // les suppressions dans une transaction START TRANSACTION/COMMIT/
        // ROLLBACK, avec vérification explicite du retour de CHAQUE
        // execute() -- même piège que TranslationInstaller (round 140/204) :
        // avant, un DELETE pouvait échouer (deadlock, verrou transitoire
        // sous charge concurrente avec la purge automatique par ancienneté,
        // NERIA_GDPR_AUTO_PURGE_ENABLED, qui touche les MÊMES tables
        // neria_certificate/neria_attribution) SANS que son retour soit
        // vérifié -- $total était quand même incrémenté du $count
        // pré-calculé, et purgeCustomerData() retournait un succès complet
        // au marchand alors qu'une partie des données personnelles du
        // client (potentiellement neria_certificate, qui contient son nom
        // en clair) n'avait en réalité JAMAIS été supprimée. Un droit à
        // l'effacement RGPD confirmé au marchand mais partiellement non
        // honoré, sans aucune trace. Toute table InnoDB (défaut PS) rend
        // ce module transactionnellement cohérent : soit la purge complète
        // réussit, soit rien n'est modifié et l'échec remonte bruyamment
        // (exception, catchée et journalisée par
        // NeriaErrorHandler::wrapHookVoid() côté appelant) plutôt qu'un
        // succès silencieux et faux.
        $total = 0;
        $this->db->execute('START TRANSACTION');

        try {
        foreach (self::getPiiTablesByCustomer() as $table => $col) {
            $full = _DB_PREFIX_ . $table;
            // Vérifie que la table existe avant de DELETE
            $exists = $this->db->executeS("SHOW TABLES LIKE '" . pSQL($full) . "'");
            if (!is_array($exists) || empty($exists)) {
                continue;
            }
            // Round 214 : $use_cache=false — même famille de bug que les
            // rounds 210-213. Sans lui, un COUNT périmé mis en cache SQL
            // (résultat 0) ferait sauter silencieusement la suppression
            // réelle de données personnelles, alors que purgeCustomerData()
            // retournerait quand même un total "succès" sans erreur — le
            // marchand croirait le droit à l'effacement RGPD honoré.
            $count = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$full}` WHERE `{$col}` = " . (int) $idCustomer,
                false
            );
            if ($count > 0) {
                $this->execOrFail(
                    "DELETE FROM `{$full}` WHERE `{$col}` = " . (int) $idCustomer,
                    $table
                );
                $total += $count;
            }
        }
        // Purge par email : neria_preferences + neria_bounces.
        //
        // Round 187 : neria_preferences a désormais AUSSI un filtre
        // id_customer (en plus de l'email) — absent jusqu'ici. Sa clé unique
        // est (id_shop, id_customer, email, category) : deux CLIENTS
        // DIFFÉRENTS sur deux boutiques distinctes d'une même install
        // multi-boutiques peuvent légitimement partager le même email
        // (boutiques indépendantes, ou coïncidence). Sans le filtre
        // id_customer, une demande d'effacement RGPD traitée pour le client
        // de la Boutique A supprimait AUSSI, silencieusement, la ligne
        // préférences (opt-in/out) d'un client totalement différent sur la
        // Boutique B qui partage juste cet email — une suppression non
        // autorisée des données d'un tiers. id_customer identifie de façon
        // unique le VRAI client PrestaShop (même à travers plusieurs
        // boutiques d'un même groupe), contrairement à l'email seul.
        //
        // neria_bounces reste purgée par email SEUL, sans changement : cette
        // table n'a pas de colonne id_customer (ni id_shop) — un rebond est
        // par nature attaché à une adresse email, pas à un compte client
        // précis, et reste volontairement global (clé unique sur `email`).
        if ($email !== '') {
            $emailSql = pSQL(strtolower($email));

            $prefTable = _DB_PREFIX_ . 'neria_preferences';
            $exists = $this->db->executeS("SHOW TABLES LIKE '" . pSQL($prefTable) . "'");
            if (is_array($exists) && !empty($exists)) {
                // Round 214 : $use_cache=false, même risque de purge RGPD
                // silencieusement neutralisée qu'au bloc ci-dessus.
                $n = (int) $this->db->getValue(
                    "SELECT COUNT(*) FROM `{$prefTable}` WHERE `email` = '{$emailSql}' AND `id_customer` = " . (int) $idCustomer,
                    false
                );
                if ($n > 0) {
                    $this->execOrFail(
                        "DELETE FROM `{$prefTable}` WHERE `email` = '{$emailSql}' AND `id_customer` = " . (int) $idCustomer,
                        'neria_preferences'
                    );
                    $total += $n;
                }
            }

            $bouncesTable = _DB_PREFIX_ . 'neria_bounces';
            $exists = $this->db->executeS("SHOW TABLES LIKE '" . pSQL($bouncesTable) . "'");
            if (is_array($exists) && !empty($exists)) {
                // Round 214 : $use_cache=false, même risque.
                $n = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$bouncesTable}` WHERE `email` = '{$emailSql}'", false);
                if ($n > 0) {
                    $this->execOrFail("DELETE FROM `{$bouncesTable}` WHERE `email` = '{$emailSql}'", 'neria_bounces');
                    $total += $n;
                }
            }
        }
        // Purge neria_certificate : id_customer stocké directement depuis
        // l'upgrade-1.0.39 (au lieu d'un JOIN sur ps_orders) — sans cette
        // colonne, un certificat (nom client en clair) survivait
        // indéfiniment à une demande d'effacement RGPD dès que la commande
        // liée avait été supprimée du BO PrestaShop, le JOIN ne matchant
        // alors plus rien alors que purgeCustomerData() retournait quand
        // même un total sans erreur (le marchand croyait l'effacement
        // complet). Complété par le JOIN en repli, pour les rares
        // certificats émis avant la migration et jamais backfillés (commande
        // déjà supprimée au moment de l'upgrade — id_customer resté à 0).
        $fullCert = _DB_PREFIX_ . 'neria_certificate';
        $certExists = $this->db->executeS("SHOW TABLES LIKE '" . pSQL($fullCert) . "'");
        if (is_array($certExists) && !empty($certExists)) {
            // Round 214 : $use_cache=false, même risque.
            $n = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$fullCert}` nc
                 WHERE nc.id_customer = " . (int) $idCustomer . "
                    OR nc.id_order IN (
                        SELECT o.id_order FROM `" . _DB_PREFIX_ . "orders` o WHERE o.id_customer = " . (int) $idCustomer . "
                    )",
                false
            );
            if ($n > 0) {
                $this->execOrFail(
                    "DELETE FROM `{$fullCert}`
                     WHERE id_customer = " . (int) $idCustomer . "
                        OR id_order IN (
                            SELECT id_order FROM `" . _DB_PREFIX_ . "orders` WHERE id_customer = " . (int) $idCustomer . "
                        )",
                    'neria_certificate'
                );
                $total += $n;
            }
        }
        // neria_attribution : pas de colonne id_customer directe, mais
        // id_order l'est — même situation que neria_certificate ci-dessus,
        // via JOIN sur orders. Auparavant non purgée du tout : le token de
        // tracking et l'id_order du client restaient en base jusqu'à 36 mois
        // (retention du registre) malgré une demande RGPD explicite.
        $fullAttr = _DB_PREFIX_ . 'neria_attribution';
        $attrExists = $this->db->executeS("SHOW TABLES LIKE '" . pSQL($fullAttr) . "'");
        if (is_array($attrExists) && !empty($attrExists)) {
            // Round 214 : $use_cache=false, même risque.
            $n = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$fullAttr}` na
                 INNER JOIN `" . _DB_PREFIX_ . "orders` o ON o.id_order = na.id_order
                 WHERE o.id_customer = " . (int) $idCustomer,
                false
            );
            if ($n > 0) {
                $this->execOrFail(
                    "DELETE na FROM `{$fullAttr}` na
                     INNER JOIN `" . _DB_PREFIX_ . "orders` o ON o.id_order = na.id_order
                     WHERE o.id_customer = " . (int) $idCustomer,
                    'neria_attribution'
                );
                $total += $n;
            }
        }

        // neria_webhook_queue : pas de référence client directe, mais le
        // payload JSON peut contenir l'email/customer_id du client
        // (has_pii=true dans REGISTRY). Purge best-effort — auparavant SEULE
        // la purge automatique par ancienneté (12 mois, si
        // NERIA_GDPR_AUTO_PURGE_ENABLED activé) y touchait, jamais une
        // demande RGPD explicite : ce n'est pas un substitut légal au droit
        // à l'effacement immédiat.
        //
        // Round 144 : le matching se faisait par SQL LIKE '%email%' — une
        // simple recherche de SOUS-CHAÎNE, sans ancrage sur les délimiteurs
        // JSON. Un client B dont l'email contient celui du client A comme
        // sous-chaîne (ex. "bigjean@x.com" contient "jean@x.com") voyait sa
        // propre ligne supprimée par la demande d'effacement de A — perte
        // de données irréversible pour un tiers non consentant. Corrigé en
        // décodant chaque payload en PHP et en comparant les valeurs EXACTES
        // (customer_id numérique en priorité — c'est la seule donnée
        // réellement présente dans les payloads actuels ; repli sur une
        // comparaison stricte, pas une sous-chaîne, sur toute valeur string
        // du payload égale à $email, pour couvrir un futur événement qui
        // embarquerait l'email).
        if ($idCustomer > 0 || $email !== '') {
            $fullWh = _DB_PREFIX_ . 'neria_webhook_queue';
            $whExists = $this->db->executeS("SHOW TABLES LIKE '" . pSQL($fullWh) . "'");
            if (is_array($whExists) && !empty($whExists)) {
                $emailLower = strtolower($email);
                // Round 214 : $use_cache=false — ce texte SQL (sans WHERE
                // filtrant sur le client) est identique à CHAQUE appel de
                // purgeCustomerData(), quel que soit le client traité :
                // sans ce paramètre, une purge pourrait ne jamais voir un
                // webhook inséré après la mise en cache d'un appel
                // précédent.
                $rows = $this->db->executeS("SELECT `id_webhook`, `payload` FROM `{$fullWh}`", true, false);
                $idsToDelete = [];
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $decoded = json_decode((string) $row['payload'], true);
                        if (!is_array($decoded)) {
                            continue;
                        }
                        $matches = ($idCustomer > 0 && (int) ($decoded['customer_id'] ?? 0) === $idCustomer)
                            || ($emailLower !== '' && in_array($emailLower, array_map('strtolower', array_filter($decoded, 'is_string')), true));
                        if ($matches) {
                            $idsToDelete[] = (int) $row['id_webhook'];
                        }
                    }
                }
                if (!empty($idsToDelete)) {
                    $this->execOrFail(
                        "DELETE FROM `{$fullWh}` WHERE `id_webhook` IN (" . implode(',', $idsToDelete) . ")",
                        'neria_webhook_queue'
                    );
                    $total += count($idsToDelete);
                }
            }
        }

            $this->db->execute('COMMIT');
        } catch (\Throwable $e) {
            $this->db->execute('ROLLBACK');

            throw $e;
        }

        return $total;
    }

    /**
     * Round 258 : exécute un DELETE et lève une exception si l'exécution
     * échoue (Db::execute() de PrestaShop renvoie simplement `false` sur
     * échec, sans exception) -- utilisé exclusivement dans
     * purgeCustomerData() pour garantir qu'aucun échec SQL individuel ne
     * puisse passer inaperçu et gonfler silencieusement le total renvoyé
     * comme "succès" au marchand.
     */
    private function execOrFail(string $sql, string $context): void
    {
        if (!$this->db->execute($sql)) {
            throw new \RuntimeException(
                "GdprAuditManager::purgeCustomerData() : échec SQL sur '{$context}' — " . $this->db->getMsgError()
            );
        }
    }

    // ============================================================
    // RAPPORT PDF — retourne un HTML complet print-ready
    // ============================================================

    public function generateReport(array $audit, string $shopName): string
    {
        $score   = $audit['score'];
        $date    = $audit['generated_at'];
        $issues  = $audit['issues'];

        $gradeColors = ['A' => '#4a9e6b', 'B' => '#b8600a', 'C' => '#e05c5c', 'D' => '#8b0000'];
        $gradeColor  = $gradeColors[$score] ?? '#888';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Rapport RGPD — <?= htmlspecialchars($shopName) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Georgia, 'Times New Roman', serif; font-size: 13px; color: #2c2c2c; background: #fff; padding: 40px; max-width: 860px; margin: auto; }
  h1 { font-size: 24px; letter-spacing: .05em; margin-bottom: 4px; }
  h2 { font-size: 15px; text-transform: uppercase; letter-spacing: .08em; margin: 28px 0 12px; padding-bottom: 6px; border-bottom: 1px solid #e0d8cc; color: #7a6a55; }
  h3 { font-size: 13px; font-weight: bold; margin-bottom: 6px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; border-bottom: 2px solid #b38b59; padding-bottom: 20px; }
  .logo { font-size: 28px; color: #b38b59; }
  .meta { font-size: 11px; color: #888; text-align: right; line-height: 1.8; }
  .score-badge { display: inline-block; width: 54px; height: 54px; border-radius: 50%; line-height: 54px; text-align: center; font-size: 28px; font-weight: bold; color: #fff; background: <?= $gradeColor ?>; }
  .summary { display: flex; align-items: center; gap: 20px; background: #faf8f5; border: 1px solid #e8e0d5; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px; }
  .summary-text { flex: 1; }
  .summary-text strong { font-size: 15px; }
  .summary-text p { font-size: 12px; color: #666; margin-top: 4px; }
  .check { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0ebe4; }
  .check:last-child { border-bottom: 0; }
  .check-icon { width: 18px; font-size: 13px; flex-shrink: 0; margin-top: 1px; }
  .ok { color: #4a9e6b; }
  .warn { color: #e05c5c; }
  .info-icon { color: #b38b59; }
  .check-label { font-weight: bold; font-size: 12px; }
  .check-detail { font-size: 11px; color: #666; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 8px; }
  th { text-align: left; padding: 6px 8px; background: #f5f0ea; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #7a6a55; }
  td { padding: 7px 8px; border-bottom: 1px solid #f0ebe4; vertical-align: top; }
  tr:last-child td { border-bottom: 0; }
  .tag-ok { background: #e8f5ee; color: #2d7a4f; padding: 2px 7px; border-radius: 10px; font-size: 10px; font-weight: bold; }
  .tag-warn { background: #fde8e8; color: #c0392b; padding: 2px 7px; border-radius: 10px; font-size: 10px; font-weight: bold; }
  .pii-list { font-size: 11px; color: #666; }
  .footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #e0d8cc; font-size: 10px; color: #aaa; display: flex; justify-content: space-between; }
  .disclaimer { font-size: 10px; color: #aaa; background: #faf8f5; border: 1px solid #e8e0d5; padding: 10px 14px; border-radius: 6px; margin-top: 20px; line-height: 1.6; }
  @media print {
    body { padding: 20px; }
    @page { margin: 1.5cm; }
    .no-print { display: none !important; }
  }
  .print-btn {
    position: fixed; top: 20px; right: 20px;
    background: #1a1a1a; color: #fff; border: none; border-radius: 6px;
    padding: 10px 18px; font-family: Georgia, 'Times New Roman', serif;
    font-size: 12px; letter-spacing: .04em; cursor: pointer;
  }
  .print-btn:hover { background: #333; }
</style>
</head>
<body>

<button type="button" class="print-btn no-print" onclick="window.print();">
  ⬇ Enregistrer en PDF
</button>

<div class="header">
  <div>
    <div class="logo">✦ Neria</div>
    <h1>Rapport de conformité RGPD</h1>
    <p style="font-size:12px;color:#888;margin-top:4px;"><?= htmlspecialchars($shopName) ?></p>
  </div>
  <div class="meta">
    Généré le <?= $date ?><br>
    Neria — Luxury Email Suite<br>
    Document à usage interne
  </div>
</div>

<div class="summary">
  <div class="score-badge"><?= $score ?></div>
  <div class="summary-text">
    <strong>Score de conformité : <?= $score ?></strong>
    <?php /* Round 144 : dénominateur corrigé — 4 axes (unsub=3, retention=N, pii=1, crypto=1), il en manquait un ; $issues (numérateur) est bien la somme des 4 axes. */ ?>
    <p><?= $issues ?> point(s) d'attention identifié(s) sur les <?= count($audit['retention']['rows']) + 3 + 1 + 1 ?> critères analysés.</p>
  </div>
</div>

<!-- AXE 1 : DÉSABONNEMENT -->
<h2>1 — Système de désabonnement</h2>
<?php foreach ($audit['unsubscribe']['checks'] as $c): ?>
<div class="check">
  <span class="check-icon <?= isset($c['info']) ? 'info-icon' : ($c['ok'] ? 'ok' : 'warn') ?>">
    <?= isset($c['info']) ? '·' : ($c['ok'] ? '✓' : '✕') ?>
  </span>
  <div>
    <div class="check-label"><?= htmlspecialchars($c['label']) ?></div>
    <div class="check-detail"><?= htmlspecialchars($c['detail']) ?></div>
  </div>
</div>
<?php endforeach; ?>

<!-- AXE 2 : RÉTENTION -->
<h2>2 — Rétention des données</h2>
<table>
  <thead>
    <tr>
      <th>Table</th>
      <th>Limite légale</th>
      <th>Plus ancienne donnée</th>
      <th>Enregistrements hors délai</th>
      <th>Statut</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($audit['retention']['rows'] as $r): ?>
    <tr>
      <td><strong><?= htmlspecialchars($r['label']) ?></strong><br><span style="font-size:10px;color:#aaa;"><?= htmlspecialchars($r['note']) ?></span></td>
      <td><?= $r['months'] ?> mois</td>
      <td><?= $r['oldest'] ?></td>
      <td><?= $r['overdue'] > 0 ? $r['overdue'] : '0' ?></td>
      <td><?= $r['ok'] ? '<span class="tag-ok">CONFORME</span>' : '<span class="tag-warn">À PURGER</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- AXE 3 : DONNÉES PERSONNELLES -->
<h2>3 — Cartographie des données personnelles</h2>
<?php if ($audit['pii']['legal_in_layout']): ?>
<div class="check">
  <span class="check-icon ok">✓</span>
  <div>
    <div class="check-label">Mentions légales dans le layout global</div>
    <div class="check-detail">Un lien vers les mentions légales de la boutique est présent dans le pied de page de tous les emails.</div>
  </div>
</div>
<?php else: ?>
<div class="check">
  <span class="check-icon warn">✕</span>
  <div>
    <div class="check-label">Mentions légales absentes du layout</div>
    <div class="check-detail">Aucun lien vers les mentions légales n'a été détecté dans layout.html.</div>
  </div>
</div>
<?php endif; ?>

<?php if ($audit['pii']['map']): ?>
<table style="margin-top:12px;">
  <thead>
    <tr>
      <th>Template</th>
      <th>Données personnelles utilisées</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($audit['pii']['map'] as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['template']) ?></td>
      <td class="pii-list"><?= htmlspecialchars(implode(', ', $row['vars'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="disclaimer">
  <strong>Avis de limitation :</strong> Ce rapport est généré automatiquement par Neria à partir de l'analyse des fichiers et des données stockées.
  Il ne constitue pas un avis juridique et ne remplace pas l'intervention d'un délégué à la protection des données (DPO) ou d'un conseil spécialisé RGPD.
  La conformité RGPD dépend également de votre politique de confidentialité, de votre registre des traitements et de vos contrats sous-traitants.
</div>

<div class="footer">
  <span>Neria — Luxury Email Suite</span>
  <span>Rapport généré le <?= $date ?> — Confidentiel</span>
</div>

</body>
</html>
        <?php
        return ob_get_clean();
    }

    // ============================================================
    // SCORE
    // ============================================================

    /**
     * Grade à partir d'un pourcentage de conformité de l'axe le plus
     * dégradé (cf. runAudit()). 100% = tous les contrôles de cet axe OK.
     */
    private function gradeFromPercent(float $pct): string
    {
        if ($pct >= 100.0) { return 'A'; }
        if ($pct >= 65.0)  { return 'B'; }
        if ($pct >= 35.0)  { return 'C'; }
        return 'D';
    }

    public static function getTableDef(string $table): ?array
    {
        foreach (self::getTables() as $def) {
            if ($def['table'] === $table) {
                return $def;
            }
        }
        return null;
    }
}
