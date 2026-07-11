<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — TranslationEngine
 *
 * Moteur de traduction central du module Neria.
 * Récupère les textes depuis la base de données et les fournit
 * aux templates email via la fonction Smarty {neria_trad}.
 *
 * Fonctionnalités :
 * — Cache mémoire par requête (évite les requêtes SQL répétées)
 * — Fallback automatique vers l'anglais si langue introuvable
 * — Fallback vers PrestaShop natif en dernier recours
 * — Injection des variables personnalisées du marchand
 * — Support RTL (arabe)
 * — Résolution des variables PrestaShop ({shop_name}, etc.)
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TranslationEngine
{
    // ============================================================
    // CONSTANTES
    // ============================================================

    /** Langue de fallback si la langue demandée est introuvable */
    const FALLBACK_LANG = 'en';

    /** Nom de la table sans préfixe */
    const TABLE = 'neria_translation';

    /** Langues RTL — nécessitent dir="rtl" dans le HTML */
    const RTL_LANGS = ['ar'];

    /** Les 19 langues supportées par Neria (codes normalisés) */
    const SUPPORTED_LANGS = [
        'fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja',
        'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl',
    ];

    // ============================================================
    // PROPRIÉTÉS
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var \Db Instance de la base de données */
    private \Db $db;

    /**
     * Cache mémoire des traductions déjà chargées
     * Structure : ['template:lang' => ['key' => 'value', ...]]
     * Évite de requêter la BDD plusieurs fois pour le même template
     */
    private array $cache = [];

    /**
     * Cache des variables personnalisées du marchand
     * Structure : ['{maison_name}' => 'Maison Dupont', ...]
     */
    private array $customVarsCache = [];

    /** @var bool Indique si les variables custom ont été chargées */
    private bool $customVarsLoaded = false;

    /** @var array|null Cache du mapping pays ISO → langue Neria */
    private ?array $countryLangMap = null;

    /** @var int|null Cache du nombre de langues installées (actives) de la boutique */
    private ?int $installedLangCount = null;

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
     * Récupère une traduction pour un template, une clé et une langue
     *
     * Ordre de résolution :
     * 1. Cache mémoire (si déjà chargé dans cette requête)
     * 2. Base de données (traduction personnalisée ou par défaut)
     * 3. Fallback anglais (si langue demandée introuvable)
     * 4. Chaîne vide (si rien trouvé — ne plante pas)
     *
     * @param string $template Nom du template (ex: order_conf)
     * @param string $key      Clé de traduction (ex: greeting_main)
     * @param string $lang     Code langue ISO (ex: fr, ja, ar)
     * @return string          Texte traduit, prêt à être injecté
     */
    public function get(string $template, string $key, string $lang): string
    {
        // ── Normalise la langue ──────────────────────────────────
        $lang = $this->normalizeLang($lang);

        // ── Charge le bloc template+lang en cache ────────────────
        $this->loadBlock($template, $lang);

        $cacheKey = $template . ':' . $lang;

        // ── Cherche dans le cache ────────────────────────────────
        if (isset($this->cache[$cacheKey][$key])) {
            return $this->resolveVariables(
                $this->cache[$cacheKey][$key]
            );
        }

        // ── Fallback anglais ─────────────────────────────────────
        if ($lang !== self::FALLBACK_LANG) {
            $this->loadBlock($template, self::FALLBACK_LANG);
            $fallbackKey = $template . ':' . self::FALLBACK_LANG;

            if (isset($this->cache[$fallbackKey][$key])) {
                $this->module->log(
                    sprintf(
                        'TranslationEngine: fallback EN pour [%s][%s][%s]',
                        $template, $lang, $key
                    ),
                    2
                );
                $this->watchdog()->warning(
                    \WatchdogManager::i18nMsg('watchdog.translation_fallback_en', ['lang' => $lang, 'key' => $key]),
                    $template,
                    'TranslationEngine'
                );
                return $this->resolveVariables(
                    $this->cache[$fallbackKey][$key]
                );
            }
        }

        // ── Fallback _global (clés partagées : footer, etc.) ─────
        if ($template !== '_global') {
            $this->loadBlock('_global', $lang);
            $globalKey = '_global:' . $lang;
            if (isset($this->cache[$globalKey][$key])) {
                return $this->resolveVariables($this->cache[$globalKey][$key]);
            }
            $this->loadBlock('_global', self::FALLBACK_LANG);
            $globalFallbackKey = '_global:' . self::FALLBACK_LANG;
            if (isset($this->cache[$globalFallbackKey][$key])) {
                return $this->resolveVariables($this->cache[$globalFallbackKey][$key]);
            }
        }

        // ── Rien trouvé : log et retourne chaîne vide ────────────
        $this->module->log(
            sprintf(
                'TranslationEngine: clé introuvable [%s][%s][%s]',
                $template, $lang, $key
            ),
            2
        );
        $this->watchdog()->warning(
            \WatchdogManager::i18nMsg('watchdog.translation_key_missing', ['key' => $key]),
            $template,
            'TranslationEngine'
        );

        return '';
    }

    /**
     * Charge toutes les traductions d'un template pour une langue
     * Retourne un tableau associatif [key => value]
     * Utile pour le back-office (affichage de tous les champs)
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     * @return array
     */
    public function getAll(string $template, string $lang): array
    {
        $lang = $this->normalizeLang($lang);
        $this->loadBlock($template, $lang);

        $cacheKey = $template . ':' . $lang;
        return $this->cache[$cacheKey] ?? [];
    }

    /**
     * Met à jour une traduction en base de données
     * Marque la traduction comme personnalisée (is_custom = 1)
     * Appelé depuis le back-office quand le marchand modifie un texte
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     * @param string $key      Clé de traduction
     * @param string $value    Nouveau texte
     * @return bool
     */
    public function update(
        string $template,
        string $lang,
        string $key,
        string $value
    ): bool {
        $table = _DB_PREFIX_ . self::TABLE;
        $now   = date('Y-m-d H:i:s');

        // INSERT OR UPDATE (ON DUPLICATE KEY)
        $sql = sprintf(
            "INSERT INTO `%s`
                (`template`, `lang`, `translation_key`, `translation_value`,
                 `is_custom`, `date_add`, `date_upd`)
             VALUES ('%s', '%s', '%s', '%s', 1, '%s', '%s')
             ON DUPLICATE KEY UPDATE
                `translation_value` = VALUES(`translation_value`),
                `is_custom`         = 1,
                `date_upd`          = VALUES(`date_upd`)",
            $table,
            pSQL($template),
            pSQL($lang),
            pSQL($key),
            pSQL($value, true),
            $now,
            $now
        );

        $result = $this->db->execute($sql);

        // Invalide le cache pour ce bloc
        if ($result) {
            $this->invalidateCache($template, $lang);
        }

        return $result !== false;
    }

    // ============================================================
    // SMARTY — Enregistrement de la fonction {neria_trad}
    // ============================================================

    /**
     * Enregistre la fonction Smarty {neria_trad key='...'} dans le moteur
     * Appelé depuis EmailRenderer avant le rendu du template
     *
     * @param \Smarty $smarty  Instance Smarty de PrestaShop
     * @param string  $template Nom du template en cours de rendu
     * @param string  $lang     Langue du destinataire
     */
    public function registerSmartyFunction(
        \Smarty $smarty,
        string $template,
        string $lang
    ): void {
        // Capture $this, $template, $lang dans la closure
        $engine = $this;

        $smarty->registerPlugin(
            'function',
            'neria_trad',
            function (array $params) use ($engine, $template, $lang): string {
                if (empty($params['key'])) {
                    return '';
                }
                return $engine->get($template, $params['key'], $lang);
            }
        );
    }

    // ============================================================
    // RTL
    // ============================================================

    /**
     * Indique si une langue s'écrit de droite à gauche
     * Utilisé par EmailRenderer pour ajouter dir="rtl" au HTML
     *
     * @param string $lang Code langue
     * @return bool
     */
    public function isRtl(string $lang): bool
    {
        return in_array($lang, self::RTL_LANGS, true);
    }

    // ============================================================
    // DÉTECTION AUTOMATIQUE DE LA LANGUE
    // ============================================================

    /**
     * Résout la langue optimale pour un destinataire.
     *
     * Ordre de résolution (décidé avec le marchand — agnostique au pays
     * du marchand : fonctionne pour une boutique FR, DE, ES, JP, etc.) :
     *  1. Boutique MULTI-langues — la langue du compte reflète un vrai
     *     choix (sélectionnée par le client ou détectée depuis son
     *     navigateur) : on la respecte.
     *  2. Boutique MONO-langue — le pays du client décide (facturation
     *     prioritaire, fourni par EmailRenderer). Pays couvert par le
     *     mapping → sa langue (ex. japonais sur une boutique francophone).
     *     Pays connu mais non couvert (Finlande, Grèce…) → anglais, le
     *     meilleur choix international, plutôt que la langue du marchand.
     *  3. Aucun pays connu — client supposé local : langue du compte,
     *     puis langue par défaut de la boutique.
     *  4. Fallback absolu — anglais.
     *
     * Les pays multilingues (Belgique, Suisse) sont affinés par code postal
     * quand il est fourni — voir refineMultilingualCountry().
     *
     * @param int    $idLang            id_lang PrestaShop du destinataire
     * @param string $customerCountryIso Code ISO pays du client (résolu par EmailRenderer)
     * @param string $postalCode        Code postal de l'adresse (pour BE/CH)
     * @return string Code langue Neria (ex: 'ja', 'fr')
     */
    public function resolveOptimalLang(
        int $idLang,
        string $customerCountryIso = '',
        string $postalCode = ''
    ): string {
        $supported   = self::SUPPORTED_LANGS;
        $accountLang = $idLang > 0 ? $this->langFromId($idLang) : '';
        $defaultLang = $this->langFromId(
            (int) \Configuration::get('PS_LANG_DEFAULT')
        );

        // 1. Boutique multi-langues : la langue du compte est un vrai choix,
        //    on la respecte (quel que soit le pays du marchand).
        if ($this->isMultilingualShop()
            && in_array($accountLang, $supported, true)
        ) {
            return $accountLang;
        }

        // 2. Boutique mono-langue : le pays du client décide.
        if ($customerCountryIso !== '') {
            $mapped = $this->langForCountry($customerCountryIso, $postalCode);
            if ($mapped !== null && in_array($mapped, $supported, true)) {
                return $mapped;
            }
            // Pays connu mais non couvert (Finlande, Grèce…) : l'anglais est
            // le meilleur choix international, plutôt que d'imposer la langue
            // locale du marchand à un client manifestement étranger.
            return self::FALLBACK_LANG;
        }

        // 3. Aucun pays connu (pas d'adresse) : client supposé local →
        //    langue du compte, puis langue par défaut de la boutique.
        if ($accountLang !== '' && in_array($accountLang, $supported, true)) {
            return $accountLang;
        }
        if (in_array($defaultLang, $supported, true)) {
            return $defaultLang;
        }

        // 4. Fallback absolu
        return self::FALLBACK_LANG;
    }

    /**
     * Indique si la boutique propose plusieurs langues actives au client.
     * Sur une boutique multi-langues, la langue du compte reflète un choix
     * réel ; sur une boutique mono-langue, elle est imposée par le marchand.
     *
     * @return bool
     */
    public function isMultilingualShop(): bool
    {
        if ($this->installedLangCount === null) {
            $idShop = (int) \Context::getContext()->shop->id;
            $langs  = \Language::getLanguages(true, $idShop);
            $this->installedLangCount = is_array($langs) ? count($langs) : 1;
        }

        return $this->installedLangCount > 1;
    }

    /**
     * Charge le mapping pays ISO → langue Neria depuis
     * data/country_lang_map.json. Résultat mis en cache pour la requête.
     *
     * @return array
     */
    private function loadCountryLangMap(): array
    {
        if ($this->countryLangMap !== null) {
            return $this->countryLangMap;
        }

        $mapFile = __DIR__ . '/../data/country_lang_map.json';
        if (!is_file($mapFile)) {
            $this->watchdog()->warning(
                'Mapping pays→langue introuvable : data/country_lang_map.json',
                '',
                'TranslationEngine'
            );
            $this->countryLangMap = [];
            return $this->countryLangMap;
        }

        $decoded = json_decode((string) file_get_contents($mapFile), true);
        $this->countryLangMap = is_array($decoded) ? $decoded : [];

        return $this->countryLangMap;
    }

    /**
     * Résout la langue d'un pays. Pour les pays multilingues (BE, CH), affine
     * d'abord par code postal ; sinon utilise le mapping pays → langue.
     *
     * @param string $iso        Code ISO pays
     * @param string $postalCode Code postal (optionnel)
     * @return string|null Code langue Neria ou null si pays inconnu
     */
    private function langForCountry(string $iso, string $postalCode): ?string
    {
        $iso = strtoupper($iso);

        $refined = $this->refineMultilingualCountry($iso, $postalCode);
        if ($refined !== null) {
            return $refined;
        }

        return $this->loadCountryLangMap()[$iso] ?? null;
    }

    /**
     * Affine la langue d'un pays multilingue d'après le code postal de
     * l'adresse. Retourne null si le pays n'est pas concerné ou si le code
     * postal est absent/inexploitable (on retombe alors sur le mapping par
     * défaut, qui pointe vers la langue majoritaire du pays).
     *
     * Belgique (précis) : néerlandais en Flandre et Brabant flamand
     * (1500-3999, 8000-9999), français à Bruxelles et en Wallonie.
     * Suisse (approximatif) : français en Suisse romande (1000-2999),
     * italien au Tessin (6500-6999), allemand ailleurs.
     *
     * @param string $iso        Code ISO pays (déjà en majuscules)
     * @param string $postalCode Code postal
     * @return string|null Code langue, ou null si non applicable
     */
    private function refineMultilingualCountry(string $iso, string $postalCode): ?string
    {
        if ($iso !== 'BE' && $iso !== 'CH') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $postalCode);
        if (strlen($digits) < 4) {
            return null; // pas de code postal exploitable → mapping par défaut
        }
        $n = (int) substr($digits, 0, 4);

        if ($iso === 'BE') {
            // Néerlandais : Brabant flamand, Anvers, Limbourg, Flandres
            if (($n >= 1500 && $n <= 3999) || ($n >= 8000 && $n <= 9999)) {
                return 'nl';
            }
            // Français : Bruxelles (1000-1499) + Wallonie (4000-7999)
            return 'fr';
        }

        // Suisse
        if ($n >= 1000 && $n <= 2999) {
            return 'fr'; // Suisse romande
        }
        if ($n >= 6500 && $n <= 6999) {
            return 'it'; // Tessin
        }
        return 'de'; // Suisse alémanique (+ Grisons, majorité allemande)
    }

    // ============================================================
    // VARIABLES PERSONNALISÉES
    // ============================================================

    /**
     * Charge les variables personnalisées du marchand depuis la BDD
     * Structure en cache : ['{maison_name}' => 'Maison Dupont']
     */
    private function loadCustomVars(): void
    {
        if ($this->customVarsLoaded) {
            return;
        }

        $table  = _DB_PREFIX_ . 'neria_custom_variable';
        $idShop = (int) \Context::getContext()->shop->id;

        $rows = $this->db->executeS(
            "SELECT `variable_key`, `variable_value`
             FROM `{$table}`
             WHERE `id_shop` = {$idShop}"
        );

        if (is_array($rows)) {
            foreach ($rows as $row) {
                // Stocke avec les accolades pour la résolution directe
                $this->customVarsCache['{' . $row['variable_key'] . '}'] =
                    $row['variable_value'];
            }
        }

        $this->customVarsLoaded = true;
    }

    /**
     * Résout les variables dans un texte traduit
     * Remplace {maison_name}, {slogan}, {founder_name}, etc.
     * par les valeurs définies par le marchand dans le back-office
     *
     * @param string $text Texte brut contenant éventuellement des variables
     * @return string Texte avec variables résolues
     */
    private function resolveVariables(string $text): string
    {
        // Optimisation : si pas de { dans le texte, rien à résoudre
        if (strpos($text, '{') === false) {
            return $text;
        }

        // Charge les variables custom si pas encore fait
        $this->loadCustomVars();

        // Résout les variables custom du marchand
        if (!empty($this->customVarsCache)) {
            $text = str_replace(
                array_keys($this->customVarsCache),
                array_values($this->customVarsCache),
                $text
            );
        }

        return $text;
    }

    // ============================================================
    // CACHE
    // ============================================================

    /**
     * Charge en cache toutes les traductions d'un bloc template+lang
     * Une seule requête SQL par bloc — résultat mis en cache mémoire
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     */
    private function loadBlock(string $template, string $lang): void
    {
        $cacheKey = $template . ':' . $lang;

        // Déjà en cache → rien à faire
        if (array_key_exists($cacheKey, $this->cache)) {
            return;
        }

        $table = _DB_PREFIX_ . self::TABLE;

        // Une seule requête pour tout le bloc template+lang
        // ORDER BY is_custom DESC → les traductions custom
        // écrasent les traductions par défaut si même clé
        $rows = $this->db->executeS(
            "SELECT `translation_key`, `translation_value`
             FROM `{$table}`
             WHERE `template` = '" . pSQL($template) . "'
               AND `lang`     = '" . pSQL($lang) . "'
             ORDER BY `is_custom` ASC"
        );

        $this->cache[$cacheKey] = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                // La dernière valeur gagne (is_custom=1 écrase is_custom=0)
                $this->cache[$cacheKey][$row['translation_key']] =
                    $row['translation_value'];
            }
        }
    }

    /**
     * Invalide le cache pour un bloc spécifique
     * Appelé après une mise à jour via update()
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     */
    private function invalidateCache(string $template, string $lang): void
    {
        $cacheKey = $template . ':' . $lang;
        unset($this->cache[$cacheKey]);
    }

    /**
     * Vide entièrement le cache mémoire
     * Utile après une réinstallation des traductions
     */
    public function clearCache(): void
    {
        $this->cache          = [];
        $this->customVarsCache = [];
        $this->customVarsLoaded = false;
    }

    // ============================================================
    // UTILITAIRES
    // ============================================================

    /**
     * Normalise un code langue PrestaShop vers le format Neria
     *
     * PrestaShop utilise des codes comme 'pt-br', 'zh-tw'
     * Neria utilise 'br' et 'tw'
     *
     * @param string $lang Code langue brut
     * @return string Code langue normalisé
     */
    private function normalizeLang(string $lang): string
    {
        $lang = strtolower(trim($lang));

        // Mapping des codes PS vers codes Neria
        $map = [
            'pt-br' => 'br',
            'zh-tw' => 'tw',
            'zh-cn' => 'zh',
            'zh-hk' => 'tw',
            'cn'    => 'zh',  // PS stocke parfois le chinois simplifié avec l'ISO court 'cn'
            'nb'    => 'no',  // Norvégien Bokmål → code Neria 'no'
            'nn'    => 'no',  // Norvégien Nynorsk → code Neria 'no'
            'us'    => 'en',  // Pack de langue PrestaShop "United States" → ISO 'us', mappé vers 'en' (anglais américain)
            // 'gb' n'est plus mappé vers 'en' : c'est maintenant un code Neria à part entière
            // (anglais britannique), au même titre que 'br' pour le portugais brésilien.
        ];

        return $map[$lang] ?? $lang;
    }

    /**
     * Convertit un id_lang PrestaShop en code ISO Neria
     * Utile quand on n'a que l'id_lang et pas le code ISO
     *
     * @param int $idLang ID de langue PrestaShop
     * @return string Code langue Neria (ex: 'fr', 'ja')
     */
    public function langFromId(int $idLang): string
    {
        $language = \Language::getLanguage($idLang);

        if (!$language) {
            return self::FALLBACK_LANG;
        }

        return $this->normalizeLang($language['iso_code']);
    }

    /**
     * Retourne la liste de tous les templates disponibles en BDD
     * Utilisé par le back-office pour construire la liste déroulante
     *
     * @return array Liste des noms de templates
     */
    public function getAvailableTemplates(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT DISTINCT `template`
             FROM `{$table}`
             ORDER BY `template` ASC"
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_column($rows, 'template');
    }

    /**
     * Retourne les langues disponibles pour un template donné
     *
     * @param string $template Nom du template
     * @return array Liste des codes langue
     */
    public function getAvailableLangs(string $template): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT DISTINCT `lang`
             FROM `{$table}`
             WHERE `template` = '" . pSQL($template) . "'
             ORDER BY `lang` ASC"
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_column($rows, 'lang');
    }
}