<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — AdminTranslator
 *
 * Traduction du back-office dans les 19 langues du module.
 *
 * Contrairement aux emails (table SQL, éditables par le marchand), les libellés
 * de l'interface d'administration sont figés et vivent dans un dictionnaire JSON
 * livré avec le module : data/admin_translations.json.
 *
 * La langue affichée est celle de l'employé connecté au back-office
 * (Context::getContext()->language). Si elle ne fait pas partie des 19 langues
 * supportées, on retombe sur l'anglais.
 *
 * Utilisation :
 *   - Côté Smarty  : {neria_admin key='design.colors_title'}
 *   - Côté PHP     : AdminTranslator::t('msg.design_saved')
 *
 * @author  Neria
 * @license AFL
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminTranslator
{
    /** Langue de repli si la langue de l'employé n'est pas couverte */
    const FALLBACK_LANG = 'en';

    /**
     * Dictionnaire chargé depuis le JSON, mis en cache pour la requête.
     * Structure : ['cle.semantique' => ['fr' => '...', 'en' => '...', ...], ...]
     *
     * @var array|null
     */
    private static ?array $dict = null;

    /** Code langue résolu pour le back-office courant (cache requête). */
    private static ?string $lang = null;

    /**
     * Dictionnaire des noms de templates (data/template_labels_i18n.json).
     * Structure : ['order_conf' => ['fr' => '...', 'en' => '...', ...], ...]
     *
     * @var array|null
     */
    private static ?array $tplDict = null;

    // ============================================================
    // API PUBLIQUE
    // ============================================================

    /**
     * Traduit une clé dans la langue du back-office courant.
     *
     * Ordre de résolution : langue employé → anglais → français → la clé.
     * Ne plante jamais : renvoie au pire la clé elle-même.
     *
     * @param string $key Clé sémantique (ex: 'design.colors_title')
     * @return string     Libellé traduit
     */
    public static function t(string $key): string
    {
        $dict = self::dict();
        $lang = self::currentLang();

        if (isset($dict[$key][$lang]) && $dict[$key][$lang] !== '') {
            return $dict[$key][$lang];
        }

        if (isset($dict[$key][self::FALLBACK_LANG]) && $dict[$key][self::FALLBACK_LANG] !== '') {
            return $dict[$key][self::FALLBACK_LANG];
        }

        if (isset($dict[$key]['fr']) && $dict[$key]['fr'] !== '') {
            return $dict[$key]['fr'];
        }

        // Rien trouvé : on renvoie la clé pour repérer visuellement l'oubli.
        return $key;
    }

    /**
     * Traduit une clé dans une langue explicite, sans dépendre de la langue
     * de l'employé connecté (ex: sujet d'un email de test envoyé dans une
     * langue choisie par le marchand, indépendamment de sa propre session BO).
     *
     * @param string $key Clé sémantique
     * @param string $iso Code langue (doit faire partie de TranslationEngine::SUPPORTED_LANGS)
     */
    public static function tLang(string $key, string $iso): string
    {
        $dict = self::dict();

        // Round 177 : contrairement à setLang() (ligne ~252), $iso n'était
        // jamais validé contre TranslationEngine::SUPPORTED_LANGS avant
        // d'être utilisé comme clé de tableau — écart entre le contrat
        // documenté ci-dessus et l'implémentation. Sans impact de sécurité
        // (simple lookup de tableau), mais une clé orpheline dans le
        // dictionnaire JSON (langue retirée de SUPPORTED_LANGS mais restée
        // dans translations.json, cf. feedback_orphan_language_keys) aurait
        // pu être retournée silencieusement pour un code langue qui n'est
        // plus officiellement supporté.
        if (!in_array($iso, TranslationEngine::SUPPORTED_LANGS, true)) {
            $iso = self::FALLBACK_LANG;
        }

        if (isset($dict[$key][$iso]) && $dict[$key][$iso] !== '') {
            return $dict[$key][$iso];
        }

        if (isset($dict[$key][self::FALLBACK_LANG]) && $dict[$key][self::FALLBACK_LANG] !== '') {
            return $dict[$key][self::FALLBACK_LANG];
        }

        if (isset($dict[$key]['fr']) && $dict[$key]['fr'] !== '') {
            return $dict[$key]['fr'];
        }

        return $key;
    }

    /**
     * Traduction avec substitution de variables ({var} → valeur), pour les
     * messages composés en PHP (alertes, watchdog...) avant assignation à
     * Smarty ou au journal.
     *
     * @param string $key  Clé sémantique
     * @param array  $vars Paires {placeholder} => valeur
     */
    // Round 304 : strtr() (et non str_replace() en boucle) — même
    // correctif déjà appliqué à TranslationEngine::resolveVariables() pour
    // ce même piège. str_replace() en boucle enchaîne les remplacements
    // SÉQUENTIELLEMENT sur le résultat déjà transformé : si la valeur d'UNE
    // variable contient littéralement le texte "{autre_clé}" (ex. un
    // extrait de réponse d'erreur d'une API tierce injecté tel quel dans
    // 'detail', voir neria.php::msg.deepl_zero_translated), ce texte
    // injecté se faisait à son tour remplacer par la valeur de l'autre
    // variable lors du passage suivant — corruption silencieuse du message
    // affiché au marchand, dépendante de l'ordre d'itération du tableau.
    // strtr() avec un tableau effectue un seul passage simultané sur le
    // texte ORIGINAL, sans jamais rescanner une portion déjà substituée.
    public static function tVars(string $key, array $vars = []): string
    {
        $str = self::t($key);
        if (empty($vars)) {
            return $str;
        }
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{' . $k . '}'] = (string) $v;
        }
        return strtr($str, $replace);
    }

    /**
     * Helper Smarty pour {neria_admin key='...'}.
     *
     * Paramètres optionnels :
     *  - 'n'   : substitue '%d' dans la chaîne traduite (clés de comptage/
     *            pluriel comme 'help.wd_ago_min' = "Il y a %d min").
     *  - 'esc' : échappe la sortie ('html' ou 'javascript') pour une
     *            injection sûre dans un attribut HTML ou une chaîne JS.
     *
     * Ces deux paramètres existent parce que les modificateurs Smarty
     * (|replace, |escape...) placés après un paramètre NOMMÉ de fonction
     * s'appliquent à la VALEUR de ce paramètre, pas à la sortie de la
     * fonction — {neria_admin key='x'|escape:'html'} échappe la chaîne
     * littérale 'x' (le nom de la clé, sans effet), pas la traduction
     * réellement affichée. D'où l'échappement fait ici, côté PHP, via le
     * modificateur Smarty officiel (fidélité garantie avec |escape natif).
     *
     * @param array  $params  Paramètres Smarty (attend 'key', 'n' et 'esc' optionnels)
     * @param object $smarty  Instance Smarty (non utilisée)
     * @return string
     */
    public static function smartyHelper(array $params, $smarty): string
    {
        $key = isset($params['key']) ? (string) $params['key'] : '';

        if ($key === '') {
            return '';
        }

        $str = self::t($key);

        if (isset($params['n'])) {
            $str = str_replace('%d', (string) $params['n'], $str);
        }

        if (isset($params['esc']) && in_array($params['esc'], ['html', 'javascript'], true)) {
            if (!function_exists('smarty_modifier_escape') && defined('SMARTY_PLUGINS_DIR')) {
                require_once SMARTY_PLUGINS_DIR . 'modifier.escape.php';
            }
            if (function_exists('smarty_modifier_escape')) {
                $str = smarty_modifier_escape($str, $params['esc']);
            }
        }

        return $str;
    }

    /**
     * Enregistre le plugin Smarty {neria_admin} sur l'instance fournie.
     * Idempotent : ignore l'erreur si déjà enregistré dans la requête.
     *
     * @param object $smarty Instance Smarty (context->smarty)
     */
    public static function register($smarty): void
    {
        if (!is_object($smarty) || !method_exists($smarty, 'registerPlugin')) {
            return;
        }

        try {
            $smarty->registerPlugin('function', 'neria_admin', ['AdminTranslator', 'smartyHelper']);
        } catch (\Throwable $e) {
            // Déjà enregistré dans cette requête : sans gravité.
        }
    }

    /**
     * Retourne les noms des 108 templates traduits dans la langue du
     * back-office courant, en conservant l'ordre canonique de
     * NeriaTools::getTemplateLabels().
     *
     * Repli par template : langue employé → anglais → nom français canonique.
     * Le repli FR garantit qu'un template sans traduction reste lisible.
     *
     * @return array ['order_conf' => 'Order confirmation', ...]
     */
    public static function templateLabels(): array
    {
        $raw  = NeriaTools::getTemplateLabels();
        $dict = self::tplDict();
        $lang = self::currentLang();

        $out = [];
        foreach ($raw as $key => $frLabel) {
            if (isset($dict[$key][$lang]) && $dict[$key][$lang] !== '') {
                $out[$key] = $dict[$key][$lang];
            } elseif (isset($dict[$key][self::FALLBACK_LANG]) && $dict[$key][self::FALLBACK_LANG] !== '') {
                $out[$key] = $dict[$key][self::FALLBACK_LANG];
            } else {
                $out[$key] = $frLabel;
            }
        }

        return $out;
    }

    /**
     * Indique si la langue courante du back-office s'écrit de droite à gauche.
     */
    public static function isRtl(): bool
    {
        return in_array(self::currentLang(), TranslationEngine::RTL_LANGS, true);
    }

    /**
     * Direction d'écriture du back-office : 'rtl' (arabe…) ou 'ltr'.
     * Utilisé pour l'attribut dir="" du conteneur principal.
     */
    public static function dir(): string
    {
        return self::isRtl() ? 'rtl' : 'ltr';
    }

    /**
     * Force la langue (ex : front controller de désabonnement, qui résout la
     * langue du destinataire plutôt que celle de l'employé). Ignore une langue
     * non supportée.
     */
    public static function setLang(string $lang): void
    {
        // Round 246 : mb_strtolower (pas strtolower) -- strtolower() est
        // sensible à setlocale(LC_CTYPE, ...) ; si un AUTRE module/le
        // serveur a positionné une locale turque pour la boutique,
        // strtolower('IT') retourne 'ıt' (i sans point) au lieu de 'it',
        // cassant silencieusement in_array(..., true) contre
        // SUPPORTED_LANGS et faisant retomber en anglais un override de
        // langue pourtant valide.
        $lang = mb_strtolower(trim($lang), 'UTF-8');
        if ($lang !== '' && in_array($lang, TranslationEngine::SUPPORTED_LANGS, true)) {
            self::$lang = $lang;
        }
    }

    /**
     * Code langue effectivement utilisé pour le back-office.
     * Exposé pour le diagnostic / les tests.
     */
    public static function currentLang(): string
    {
        if (self::$lang !== null) {
            return self::$lang;
        }

        // Aperçu QA : &neria_bo_lang=XX force une langue — le commentaire
        // d'origine supposait ce paramètre "réservé au back-office, déjà
        // protégé par l'authentification admin", mais currentLang() est
        // aussi appelée côté FRONT (controllers/front/preferences.php,
        // unsubscribe.php), où aucune authentification n'existe. Sans cette
        // vérification, un visiteur anonyme pouvait forcer la langue
        // affichée sur une page publique via ce paramètre. Employee connecté
        // = garde réelle de contexte back-office.
        $employee = \Context::getContext()->employee ?? null;
        if ($employee !== null && \Validate::isLoadedObject($employee)) {
            // Round 246 : mb_strtolower (voir justification dans setLang()).
            $override = mb_strtolower((string) \Tools::getValue('neria_bo_lang'), 'UTF-8');
            if ($override !== '' && in_array($override, TranslationEngine::SUPPORTED_LANGS, true)) {
                return self::$lang = $override;
            }
        }

        $iso = self::FALLBACK_LANG;

        $context = \Context::getContext();
        if ($context !== null
            && isset($context->language)
            && !empty($context->language->iso_code)
        ) {
            // Round 246 : mb_strtolower (voir justification dans setLang()).
            $iso = mb_strtolower((string) $context->language->iso_code, 'UTF-8');
        }

        // On ne garde que les 19 langues réellement traduites ; sinon anglais.
        if (!in_array($iso, TranslationEngine::SUPPORTED_LANGS, true)) {
            $iso = self::FALLBACK_LANG;
        }

        return self::$lang = $iso;
    }

    // ============================================================
    // INTERNE
    // ============================================================

    /**
     * Charge (une seule fois) le dictionnaire JSON.
     *
     * @return array
     */
    private static function dict(): array
    {
        if (self::$dict !== null) {
            return self::$dict;
        }

        $path = __DIR__ . '/../data/admin_translations.json';

        if (!is_file($path)) {
            return self::$dict = [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return self::$dict = [];
        }

        $data = json_decode($raw, true);

        return self::$dict = is_array($data) ? $data : [];
    }

    /**
     * Charge (une seule fois) le dictionnaire des noms de templates.
     *
     * @return array
     */
    private static function tplDict(): array
    {
        if (self::$tplDict !== null) {
            return self::$tplDict;
        }

        $path = __DIR__ . '/../data/template_labels_i18n.json';

        if (!is_file($path)) {
            return self::$tplDict = [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return self::$tplDict = [];
        }

        $data = json_decode($raw, true);

        return self::$tplDict = is_array($data) ? $data : [];
    }

    /**
     * Réinitialise les caches (utile pour les tests / changement de langue).
     */
    public static function reset(): void
    {
        self::$dict    = null;
        self::$lang    = null;
        self::$tplDict = null;
    }
}
