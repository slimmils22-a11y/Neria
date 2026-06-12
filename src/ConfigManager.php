<?php
/**
 * NERIA — ConfigManager
 *
 * Gestionnaire centralisé de toute la configuration du module.
 * Gère les réglages du panneau back-office :
 * — Design global (couleurs, logo, mode sombre, largeur)
 * — Typographie par langue
 * — Variables personnalisées du marchand
 * — Réseaux sociaux
 * — Paramètres avancés (stats, A/B testing)
 *
 * Deux niveaux de stockage :
 * 1. Configuration::get/updateValue → réglages simples (couleurs, flags)
 * 2. Table ps_neria_config → réglages complexes par boutique
 * 3. Table ps_neria_custom_variable → variables personnalisées
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ConfigManager
{
    // ============================================================
    // CONSTANTES — Clés de configuration
    // ============================================================

    // ── Design global ────────────────────────────────────────────
    const KEY_COLOR_BACKGROUND  = 'NERIA_COLOR_BACKGROUND';
    const KEY_COLOR_CONTAINER   = 'NERIA_COLOR_CONTAINER';
    const KEY_COLOR_ACCENT      = 'NERIA_COLOR_ACCENT';
    const KEY_COLOR_TEXT        = 'NERIA_COLOR_TEXT';
    const KEY_DARK_MODE         = 'NERIA_DARK_MODE';
    const KEY_CONTAINER_WIDTH   = 'NERIA_CONTAINER_WIDTH';
    const KEY_LOGO_WIDTH        = 'NERIA_LOGO_WIDTH';
    const KEY_LOGO_PATH         = 'NERIA_LOGO_PATH';

    // ── Typographie ───────────────────────────────────────────────
    const KEY_FONT_LATIN        = 'NERIA_FONT_LATIN';
    const KEY_FONT_ARABIC       = 'NERIA_FONT_ARABIC';
    const KEY_FONT_JAPANESE     = 'NERIA_FONT_JAPANESE';
    const KEY_FONT_KOREAN       = 'NERIA_FONT_KOREAN';
    const KEY_FONT_ZH_SIMPLIFIED  = 'NERIA_FONT_ZH_SIMPLIFIED';
    const KEY_FONT_ZH_TRADITIONAL = 'NERIA_FONT_ZH_TRADITIONAL';
    const KEY_FONT_CYRILLIC     = 'NERIA_FONT_CYRILLIC';

    // ── Réseaux sociaux ───────────────────────────────────────────
    const KEY_SOCIAL_INSTAGRAM  = 'NERIA_SOCIAL_INSTAGRAM';
    const KEY_SOCIAL_PINTEREST  = 'NERIA_SOCIAL_PINTEREST';
    const KEY_SOCIAL_FACEBOOK   = 'NERIA_SOCIAL_FACEBOOK';
    const KEY_SOCIAL_TWITTER    = 'NERIA_SOCIAL_TWITTER';
    const KEY_SOCIAL_YOUTUBE    = 'NERIA_SOCIAL_YOUTUBE';
    const KEY_SOCIAL_TIKTOK     = 'NERIA_SOCIAL_TIKTOK';

    // ── Fonctionnalités avancées ──────────────────────────────────
    const KEY_STATS_ENABLED     = 'NERIA_STATS_ENABLED';
    const KEY_ABTEST_ENABLED    = 'NERIA_ABTEST_ENABLED';
    const KEY_ACTIVE            = 'NERIA_ACTIVE';

    // ── Valeurs par défaut ────────────────────────────────────────
    const DEFAULTS = [
        self::KEY_COLOR_BACKGROUND    => '#f4f1eb',
        self::KEY_COLOR_CONTAINER     => '#ffffff',
        self::KEY_COLOR_ACCENT        => '#b38b59',
        self::KEY_COLOR_TEXT          => '#2c2c2c',
        self::KEY_DARK_MODE           => 0,
        self::KEY_CONTAINER_WIDTH     => 620,
        self::KEY_LOGO_WIDTH          => 160,
        self::KEY_LOGO_PATH           => '',
        self::KEY_FONT_LATIN          => 'Cormorant Garamond, Georgia, Times New Roman, serif',
        self::KEY_FONT_ARABIC         => 'Noto Naskh Arabic, Traditional Arabic, serif',
        self::KEY_FONT_JAPANESE       => 'Noto Serif JP, Hiragino Mincho Pro, serif',
        self::KEY_FONT_KOREAN         => 'Noto Serif KR, Batang, serif',
        self::KEY_FONT_ZH_SIMPLIFIED  => 'Noto Serif SC, SimSun, serif',
        self::KEY_FONT_ZH_TRADITIONAL => 'Noto Serif TC, PMingLiU, serif',
        self::KEY_FONT_CYRILLIC       => 'EB Garamond, Cormorant Garamond, serif',
        self::KEY_SOCIAL_INSTAGRAM    => '',
        self::KEY_SOCIAL_PINTEREST    => '',
        self::KEY_SOCIAL_FACEBOOK     => '',
        self::KEY_SOCIAL_TWITTER      => '',
        self::KEY_SOCIAL_YOUTUBE      => '',
        self::KEY_SOCIAL_TIKTOK       => '',
        self::KEY_STATS_ENABLED       => 1,
        self::KEY_ABTEST_ENABLED      => 0,
        self::KEY_ACTIVE              => 1,
    ];

    // Polices disponibles pour le sélecteur back-office
    const FONT_OPTIONS_LATIN = [
        'Cormorant Garamond, Georgia, Times New Roman, serif' => 'Cormorant Garamond',
        'EB Garamond, Georgia, serif'                         => 'EB Garamond',
        'Playfair Display, Georgia, serif'                    => 'Playfair Display',
        'Libre Baskerville, Georgia, serif'                   => 'Libre Baskerville',
        'Georgia, Times New Roman, serif'                     => 'Georgia (système)',
    ];

    // ============================================================
    // PROPRIÉTÉS
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var \Db Instance de la base de données */
    private \Db $db;

    /** @var int ID de la boutique courante */
    private int $idShop;

    /** Cache mémoire des valeurs lues */
    private array $cache = [];

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
    // LECTURE
    // ============================================================

    /**
     * Retourne la valeur d'une clé de configuration
     * Cherche d'abord dans le cache, puis dans Configuration::get()
     *
     * @param string $key     Constante de clé (ex: self::KEY_COLOR_ACCENT)
     * @param mixed  $default Valeur par défaut si clé introuvable
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        // Cache mémoire
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        // Lecture depuis Configuration
        $value = \Configuration::get($key);

        // Si vide, utilise le default fourni ou celui des DEFAULTS
        if ($value === false || $value === '') {
            $value = $default ?? self::DEFAULTS[$key] ?? '';
        }

        $this->cache[$key] = $value;
        return $value;
    }

    /**
     * Retourne toute la configuration design en un seul appel
     * Utilisé par EmailRenderer pour construire les variables CSS
     *
     * @return array
     */
    public function getDesignConfig(): array
    {
        return [
            'color_background' => $this->get(self::KEY_COLOR_BACKGROUND),
            'color_container'  => $this->get(self::KEY_COLOR_CONTAINER),
            'color_accent'     => $this->get(self::KEY_COLOR_ACCENT),
            'color_text'       => $this->get(self::KEY_COLOR_TEXT),
            'dark_mode'        => (bool) $this->get(self::KEY_DARK_MODE),
            'container_width'  => (int) $this->get(self::KEY_CONTAINER_WIDTH),
            'logo_width'       => (int) $this->get(self::KEY_LOGO_WIDTH),
            'logo_path'        => $this->get(self::KEY_LOGO_PATH),
        ];
    }

    /**
     * Retourne la police appropriée selon la langue
     * Utilisé par EmailRenderer pour injecter la bonne font-family
     *
     * @param string $lang Code langue Neria (ex: ja, ar, ru)
     * @return string Font-family CSS complète
     */
    public function getFontForLang(string $lang): string
    {
        $fontMap = [
            'ar'   => $this->get(self::KEY_FONT_ARABIC),
            'ja'   => $this->get(self::KEY_FONT_JAPANESE),
            'ko'   => $this->get(self::KEY_FONT_KOREAN),
            'zh'   => $this->get(self::KEY_FONT_ZH_SIMPLIFIED),
            'tw'   => $this->get(self::KEY_FONT_ZH_TRADITIONAL),
            'ru'   => $this->get(self::KEY_FONT_CYRILLIC),
        ];

        return $fontMap[$lang] ?? $this->get(self::KEY_FONT_LATIN);
    }

    /**
     * Retourne les réseaux sociaux configurés (non vides uniquement)
     * Utilisé dans les templates email pour afficher les liens
     *
     * @return array ['instagram' => 'https://...', ...]
     */
    public function getSocialLinks(): array
    {
        $links = [
            'instagram' => $this->get(self::KEY_SOCIAL_INSTAGRAM),
            'pinterest' => $this->get(self::KEY_SOCIAL_PINTEREST),
            'facebook'  => $this->get(self::KEY_SOCIAL_FACEBOOK),
            'twitter'   => $this->get(self::KEY_SOCIAL_TWITTER),
            'youtube'   => $this->get(self::KEY_SOCIAL_YOUTUBE),
            'tiktok'    => $this->get(self::KEY_SOCIAL_TIKTOK),
        ];

        // Retourne uniquement les liens renseignés
        return array_filter($links, fn($url) => !empty(trim($url)));
    }

    /**
     * Indique si le module est actif
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool) $this->get(self::KEY_ACTIVE, 1);
    }

    /**
     * Indique si les statistiques sont activées
     *
     * @return bool
     */
    public function isStatsEnabled(): bool
    {
        return (bool) $this->get(self::KEY_STATS_ENABLED, 1);
    }

    /**
     * Indique si l'A/B testing est activé
     *
     * @return bool
     */
    public function isAbtestEnabled(): bool
    {
        return (bool) $this->get(self::KEY_ABTEST_ENABLED, 0);
    }

    // ============================================================
    // ÉCRITURE
    // ============================================================

    /**
     * Met à jour une valeur de configuration
     *
     * @param string $key   Clé de configuration
     * @param mixed  $value Nouvelle valeur
     * @return bool
     */
    public function set(string $key, $value): bool
    {
        $result = \Configuration::updateValue($key, $value);

        if ($result) {
            $this->cache[$key] = $value;
        }

        return $result;
    }

    /**
     * Sauvegarde toute la configuration design depuis le formulaire
     * back-office. Valide chaque valeur avant de l'enregistrer.
     *
     * @param array $data Données POST du formulaire
     * @return bool
     */
    public function saveDesignConfig(array $data): bool
    {
        $success = true;

        // Couleurs — validation format hexadécimal
        $colorKeys = [
            self::KEY_COLOR_BACKGROUND,
            self::KEY_COLOR_CONTAINER,
            self::KEY_COLOR_ACCENT,
            self::KEY_COLOR_TEXT,
        ];

        foreach ($colorKeys as $key) {
            $postKey = strtolower(str_replace('NERIA_', '', $key));
            if (isset($data[$postKey])) {
                $color = $this->sanitizeColor($data[$postKey]);
                if ($color) {
                    $success = $success && $this->set($key, $color);
                }
            }
        }

        // Mode sombre — booléen
        if (isset($data['dark_mode'])) {
            $success = $success && $this->set(
                self::KEY_DARK_MODE,
                (int) (bool) $data['dark_mode']
            );
        }

        // Largeur conteneur — entier entre 480 et 800
        if (isset($data['container_width'])) {
            $width = (int) $data['container_width'];
            $width = max(480, min(800, $width));
            $success = $success && $this->set(self::KEY_CONTAINER_WIDTH, $width);
        }

        // Largeur logo — entier entre 80 et 320
        if (isset($data['logo_width'])) {
            $logoWidth = (int) $data['logo_width'];
            $logoWidth = max(80, min(320, $logoWidth));
            $success = $success && $this->set(self::KEY_LOGO_WIDTH, $logoWidth);
        }

        return $success;
    }

    /**
     * Sauvegarde la configuration typographie
     *
     * @param array $data Données POST du formulaire
     * @return bool
     */
    public function saveTypographyConfig(array $data): bool
    {
        $success  = true;
        $fontKeys = [
            'font_latin'          => self::KEY_FONT_LATIN,
            'font_arabic'         => self::KEY_FONT_ARABIC,
            'font_japanese'       => self::KEY_FONT_JAPANESE,
            'font_korean'         => self::KEY_FONT_KOREAN,
            'font_zh_simplified'  => self::KEY_FONT_ZH_SIMPLIFIED,
            'font_zh_traditional' => self::KEY_FONT_ZH_TRADITIONAL,
            'font_cyrillic'       => self::KEY_FONT_CYRILLIC,
        ];

        foreach ($fontKeys as $postKey => $configKey) {
            if (!empty($data[$postKey])) {
                $success = $success && $this->set(
                    $configKey,
                    \Tools::safeOutput($data[$postKey])
                );
            }
        }

        return $success;
    }

    /**
     * Sauvegarde les liens réseaux sociaux
     *
     * @param array $data Données POST du formulaire
     * @return bool
     */
    public function saveSocialConfig(array $data): bool
    {
        $success    = true;
        $socialKeys = [
            'social_instagram' => self::KEY_SOCIAL_INSTAGRAM,
            'social_pinterest' => self::KEY_SOCIAL_PINTEREST,
            'social_facebook'  => self::KEY_SOCIAL_FACEBOOK,
            'social_twitter'   => self::KEY_SOCIAL_TWITTER,
            'social_youtube'   => self::KEY_SOCIAL_YOUTUBE,
            'social_tiktok'    => self::KEY_SOCIAL_TIKTOK,
        ];

        foreach ($socialKeys as $postKey => $configKey) {
            $url = isset($data[$postKey]) ? trim($data[$postKey]) : '';

            // Valide l'URL si non vide
            if (!empty($url) && !$this->sanitizeUrl($url)) {
                continue; // URL invalide → on ignore
            }

            $success = $success && $this->set($configKey, $url);
        }

        return $success;
    }

    // ============================================================
    // VARIABLES PERSONNALISÉES
    // ============================================================

    /**
     * Retourne toutes les variables personnalisées du marchand
     *
     * @return array [['key' => '...', 'value' => '...', 'description' => '...'], ...]
     */
    public function getCustomVariables(): array
    {
        $table = _DB_PREFIX_ . 'neria_custom_variable';

        $rows = $this->db->executeS(
            "SELECT `id_variable`, `variable_key`, `variable_value`, `description`
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
             ORDER BY `id_variable` ASC"
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Met à jour une variable personnalisée
     *
     * @param string $key   Clé de la variable (ex: maison_name)
     * @param string $value Valeur (ex: Maison Dupont)
     * @return bool
     */
    public function setCustomVariable(string $key, string $value): bool
    {
        $table = _DB_PREFIX_ . 'neria_custom_variable';
        $now   = date('Y-m-d H:i:s');

        $sql = sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `variable_key`, `variable_value`, `description`, `date_add`, `date_upd`)
             VALUES (%d, '%s', '%s', '', '%s', '%s')
             ON DUPLICATE KEY UPDATE
                `variable_value` = VALUES(`variable_value`),
                `date_upd`       = VALUES(`date_upd`)",
            $table,
            $this->idShop,
            pSQL($key),
            pSQL($value),
            $now,
            $now
        );

        return $this->db->execute($sql) !== false;
    }

    /**
     * Sauvegarde toutes les variables personnalisées depuis le formulaire
     *
     * @param array $data Données POST ['maison_name' => '...', 'slogan' => '...']
     * @return bool
     */
    public function saveCustomVariables(array $data): bool
    {
        $success          = true;
        $allowedVariables = [
            'maison_name',
            'slogan',
            'signature_closing',
            'founder_name',
            'founder_title',
            'return_address',
            'return_deadline_days',
            'return_processing_days',
        ];

        foreach ($allowedVariables as $varKey) {
            if (array_key_exists($varKey, $data)) {
                $success = $success && $this->setCustomVariable(
                    $varKey,
                    \Tools::safeOutput($data[$varKey])
                );
            }
        }

        return $success;
    }

    // ============================================================
    // UPLOAD LOGO
    // ============================================================

    /**
     * Gère l'upload du logo depuis le formulaire back-office
     * Valide le type, redimensionne si nécessaire, sauvegarde
     *
     * @param array $file  Entrée $_FILES['logo']
     * @return string|false Chemin relatif du logo sauvegardé, false si erreur
     */
    public function uploadLogo(array $file)
    {
        // Vérifie qu'un fichier a bien été envoyé
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            return false;
        }

        // Types MIME acceptés
        $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        $mime         = mime_content_type($file['tmp_name']);

        if (!in_array($mime, $allowedMimes, true)) {
            $this->module->log(
                'ConfigManager: type de fichier logo refusé → ' . $mime,
                2
            );
            return false;
        }

        // Taille max : 2 Mo
        if ($file['size'] > 2 * 1024 * 1024) {
            $this->module->log('ConfigManager: logo trop lourd (max 2 Mo)', 2);
            return false;
        }

        // Destination
        $uploadDir  = $this->module->getModulePath('data/signatures');
        $ext        = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename   = 'logo_' . $this->idShop . '.' . $ext;
        $dest       = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->module->log('ConfigManager: échec déplacement fichier logo', 3);
            return false;
        }

        // Sauvegarde le chemin en configuration
        $relativePath = 'data/signatures/' . $filename;
        $this->set(self::KEY_LOGO_PATH, $relativePath);

        return $relativePath;
    }

    // ============================================================
    // RÉINITIALISATION
    // ============================================================

    /**
     * Réinitialise toute la configuration aux valeurs par défaut
     * Utilisé depuis le back-office (bouton "Réinitialiser")
     *
     * @return bool
     */
    public function resetToDefaults(): bool
    {
        $success = true;

        foreach (self::DEFAULTS as $key => $value) {
            $success = $success && $this->set($key, $value);
        }

        $this->cache = [];
        return $success;
    }

    /**
     * Retourne la configuration typographie courante (polices par script)
     * Utilisé par neria.php → getContent() pour l'onglet typography
     *
     * @return array
     */
    public function getTypographyConfig(): array
    {
        return [
            'latin'              => $this->get(self::KEY_FONT_LATIN),
            'arabic'             => $this->get(self::KEY_FONT_ARABIC),
            'japanese'           => $this->get(self::KEY_FONT_JAPANESE),
            'korean'             => $this->get(self::KEY_FONT_KOREAN),
            'chinese_simplified' => $this->get(self::KEY_FONT_ZH_SIMPLIFIED),
            'chinese_traditional'=> $this->get(self::KEY_FONT_ZH_TRADITIONAL),
            'cyrillic'           => $this->get(self::KEY_FONT_CYRILLIC),
        ];
    }

    /**
     * Retourne la configuration de la signature active
     * Utilisé par neria.php → getContent() pour l'onglet configure
     *
     * @return array
     */
    public function getSignatureConfig(): array
    {
        return [
            'style'        => $this->get('NERIA_SIGNATURE_STYLE', 'great_vibes'),
            'founder_name' => $this->get('NERIA_SIGNATURE_NAME', ''),
            'founder_title'=> $this->get('NERIA_SIGNATURE_TITLE', ''),
            'color'        => $this->get(self::KEY_COLOR_ACCENT),
            'enabled'      => (bool) $this->get('NERIA_SIGNATURE_ENABLED', 0),
        ];
    }

    /**
     * Supprime toutes les clés de configuration du module
     * Appelé depuis neria.php → deleteConfiguration()
     *
     * @return bool
     */
    public function deleteAll(): bool
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            \Configuration::deleteByName($key);
        }

        $this->cache = [];
        return true;
    }

    // ============================================================
    // VALIDATION
    // ============================================================

    /**
     * Valide et normalise une couleur hexadécimale
     * Accepte #fff et #ffffff, retourne false si invalide
     *
     * @param string $color Couleur brute (ex: '#b38b59')
     * @return string|false Couleur normalisée ou false
     */
    private function sanitizeColor(string $color)
    {
        $color = trim($color);

        // Ajoute # si manquant
        if (substr($color, 0, 1) !== '#') {
            $color = '#' . $color;
        }

        // Valide le format hexadécimal (#fff ou #ffffff)
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return false;
        }

        // Normalise en 6 caractères
        if (strlen($color) === 4) {
            $color = '#'
                . str_repeat($color[1], 2)
                . str_repeat($color[2], 2)
                . str_repeat($color[3], 2);
        }

        return strtolower($color);
    }

    /**
     * Valide une URL
     *
     * @param string $url URL à valider
     * @return string|false URL validée ou false
     */
    private function sanitizeUrl(string $url)
    {
        $url = filter_var(trim($url), FILTER_VALIDATE_URL);
        return $url ?: false;
    }
}