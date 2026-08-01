<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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

    // ── Variables personnalisées (mono-langue, saisies par le marchand) ──
    // Source unique pour saveCustomVariables() ET
    // HealthCheckManager::checkCustomVarsCompleteness() — évite que les
    // deux listes divergent au fil du temps.
    const CUSTOM_VARIABLE_KEYS = [
        'maison_name',
        'slogan',
        'signature_closing',
        'founder_name',
        'founder_title',
        'return_address',
        'return_deadline_days',
        'return_processing_days',
    ];

    // ── Design global ────────────────────────────────────────────
    const KEY_COLOR_BACKGROUND  = 'NERIA_COLOR_BACKGROUND';
    const KEY_COLOR_CONTAINER   = 'NERIA_COLOR_CONTAINER';
    const KEY_COLOR_ACCENT      = 'NERIA_COLOR_ACCENT';
    const KEY_COLOR_TEXT        = 'NERIA_COLOR_TEXT';
    const KEY_DARK_MODE         = 'NERIA_DARK_MODE';
    const KEY_CONTAINER_WIDTH   = 'NERIA_CONTAINER_WIDTH';
    const KEY_LOGO_WIDTH        = 'NERIA_LOGO_WIDTH';
    const KEY_LOGO_PATH         = 'NERIA_LOGO_PATH';
    // ── Design avancé ────────────────────────────────────────────
    const KEY_FONT_HEADING      = 'NERIA_FONT_HEADING';
    const KEY_BTN_RADIUS        = 'NERIA_BTN_RADIUS';
    const KEY_BTN_COLOR         = 'NERIA_BTN_COLOR';
    const KEY_COLOR_HEADER_BG   = 'NERIA_COLOR_HEADER_BG';
    const KEY_COLOR_FOOTER_BG   = 'NERIA_COLOR_FOOTER_BG';
    const KEY_COLOR_FOOTER_TEXT = 'NERIA_COLOR_FOOTER_TEXT';
    const KEY_SECTION_PADDING   = 'NERIA_SECTION_PADDING';
    const KEY_BLOCK_SPACING     = 'NERIA_BLOCK_SPACING';
    const KEY_SEPARATOR_STYLE   = 'NERIA_SEPARATOR_STYLE';
    const KEY_CARD_SHADOW       = 'NERIA_CARD_SHADOW';
    const KEY_DESIGN_WIZARD_SEEN = 'NERIA_DESIGN_WIZARD_SEEN';

    // ── Typographie corps ─────────────────────────────────────────
    const KEY_FONT_SIZE         = 'NERIA_FONT_SIZE';
    const KEY_LINE_HEIGHT       = 'NERIA_LINE_HEIGHT';
    const KEY_HEADING_WEIGHT    = 'NERIA_HEADING_WEIGHT';
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
    const KEY_AUTO_LANG         = 'NERIA_AUTO_LANG';
    const KEY_VOUCHER_VALIDITY  = 'NERIA_VOUCHER_VALIDITY';
    const KEY_LOG_INTERNAL      = 'NERIA_LOG_INTERNAL';
    const KEY_BIRTHDAY_VOUCHER_AMOUNT  = 'NERIA_BIRTHDAY_VOUCHER_AMOUNT';
    const KEY_BIRTHDAY_VOUCHER_PERCENT = 'NERIA_BIRTHDAY_VOUCHER_PERCENT';
    const KEY_MILESTONE_VOUCHER_ENABLED = 'NERIA_MILESTONE_VOUCHER_ENABLED';
    const KEY_MILESTONE_VOUCHER_AMOUNT  = 'NERIA_MILESTONE_VOUCHER_AMOUNT';
    const KEY_MILESTONE_VOUCHER_PERCENT = 'NERIA_MILESTONE_VOUCHER_PERCENT';
    const KEY_VOUCHER_FIXED_CAP = 'NERIA_VOUCHER_FIXED_CAP';
    const KEY_LOYALTY_CROSS_SHOP_ENABLED = 'NERIA_LOYALTY_CROSS_SHOP_ENABLED';

    // ── Centre de contrôle (visibilité menu BO) ────────────────────
    const KEY_MENU_HIDDEN_ITEMS = 'NERIA_MENU_HIDDEN_ITEMS';

    // ── Mode Silence (anti-doublon) ───────────────────────────────
    const KEY_COOLDOWN_ENABLED      = 'NERIA_COOLDOWN_ENABLED';
    const KEY_COOLDOWN_MINUTES      = 'NERIA_COOLDOWN_MINUTES';
    const KEY_FIRSTNAME_FALLBACKS         = 'NERIA_FIRSTNAME_FALLBACKS';
    const KEY_FIRSTNAME_FALLBACK_ENABLED  = 'NERIA_FIRSTNAME_FALLBACK_ENABLED';
    const KEY_TIME_GREETINGS              = 'NERIA_TIME_GREETINGS';
    const KEY_TIME_GREETING_ENABLED       = 'NERIA_TIME_GREETING_ENABLED';
    const KEY_TARGET_COUNTRIES            = 'NERIA_TARGET_COUNTRIES';
    const KEY_MULTI_SENDER_ENABLED        = 'NERIA_MULTI_SENDER_ENABLED';
    const KEY_SIGNATURE_ENABLED           = 'NERIA_SIGNATURE_ENABLED';

    // ── Empreinte carbone ─────────────────────────────────────────
    const KEY_CARBON_ENABLED    = 'NERIA_CARBON_ENABLED';
    const KEY_CARBON_LINK       = 'NERIA_CARBON_LINK';

    // ── Multi-expéditeur par langue ───────────────────────────────
    const KEY_SENDERS_JSON      = 'NERIA_SENDERS_JSON';

    // ── Traduction automatique ─────────────────────────────────────
    const KEY_DEEPL_KEY         = 'NERIA_DEEPL_KEY';

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
        self::KEY_FONT_HEADING        => 'Cormorant Garamond',
        self::KEY_BTN_RADIUS          => 2,
        self::KEY_BTN_COLOR           => '#2b2520',
        self::KEY_COLOR_HEADER_BG     => '#ffffff',
        self::KEY_COLOR_FOOTER_BG     => '#ffffff',
        self::KEY_COLOR_FOOTER_TEXT   => '#6b6459',
        self::KEY_SECTION_PADDING     => 40,
        self::KEY_BLOCK_SPACING       => 48,
        self::KEY_SEPARATOR_STYLE     => 'line',
        self::KEY_CARD_SHADOW         => 'soft',
        self::KEY_DESIGN_WIZARD_SEEN  => 0,
        self::KEY_FONT_SIZE           => 14,
        self::KEY_LINE_HEIGHT         => 1.8,
        self::KEY_HEADING_WEIGHT      => 600,
        // Noms courts correspondant aux clés de FontManager::FONT_CATALOG —
        // FontManager résout le css_family complet (avec fallback) à partir
        // de ce nom. Ne PAS stocker de stack CSS complet ici : le formulaire
        // de l'onglet Typographie enregistre lui aussi un nom court (valeur
        // des cartes radio), donc les deux doivent utiliser le même format
        // pour que l'état "sélectionné" s'affiche correctement.
        self::KEY_FONT_LATIN          => 'Cormorant Garamond',
        self::KEY_FONT_ARABIC         => 'Noto Naskh Arabic',
        self::KEY_FONT_JAPANESE       => 'Noto Serif JP',
        self::KEY_FONT_KOREAN         => 'Noto Serif KR',
        self::KEY_FONT_ZH_SIMPLIFIED  => 'Noto Serif SC',
        self::KEY_FONT_ZH_TRADITIONAL => 'Noto Serif TC',
        self::KEY_FONT_CYRILLIC       => 'PT Serif',
        self::KEY_SOCIAL_INSTAGRAM    => '',
        self::KEY_SOCIAL_PINTEREST    => '',
        self::KEY_SOCIAL_FACEBOOK     => '',
        self::KEY_SOCIAL_TWITTER      => '',
        self::KEY_SOCIAL_YOUTUBE      => '',
        self::KEY_SOCIAL_TIKTOK       => '',
        self::KEY_STATS_ENABLED       => 1,
        self::KEY_ABTEST_ENABLED      => 0,
        self::KEY_ACTIVE              => 1,
        self::KEY_AUTO_LANG           => 1,
        self::KEY_VOUCHER_VALIDITY    => 30,
        self::KEY_LOG_INTERNAL        => 0,
        self::KEY_BIRTHDAY_VOUCHER_AMOUNT  => 10,
        self::KEY_BIRTHDAY_VOUCHER_PERCENT => 1,
        self::KEY_MILESTONE_VOUCHER_ENABLED => 0,
        self::KEY_MILESTONE_VOUCHER_AMOUNT  => 10,
        self::KEY_VOUCHER_FIXED_CAP  => 10000,
        self::KEY_MILESTONE_VOUCHER_PERCENT => 1,
        self::KEY_LOYALTY_CROSS_SHOP_ENABLED => 1,
        self::KEY_MENU_HIDDEN_ITEMS  => '[]',
        self::KEY_COOLDOWN_ENABLED    => 0,
        self::KEY_COOLDOWN_MINUTES    => 10,
        self::KEY_CARBON_ENABLED      => 0,
        self::KEY_CARBON_LINK         => '',
        self::KEY_SENDERS_JSON        => '',
        // Ces 4 toggles étaient absents de DEFAULTS alors que leurs getters
        // (isTimeGreetingEnabled() etc.) lisent bien un défaut applicatif de
        // 1 — sans cette entrée, resetToDefaults() (bouton "Réinitialiser"
        // du BO) ne les réécrivait jamais, et deleteAll() laissait leur
        // ligne ps_configuration orpheline après suppression de la config.
        self::KEY_TIME_GREETING_ENABLED     => 1,
        self::KEY_FIRSTNAME_FALLBACK_ENABLED => 1,
        self::KEY_MULTI_SENDER_ENABLED      => 1,
        self::KEY_SIGNATURE_ENABLED         => 1,
    ];

    // Polices disponibles pour le sélecteur back-office (corps de texte)
    const FONT_OPTIONS_LATIN = [
        'Cormorant Garamond, Georgia, Times New Roman, serif' => 'Cormorant Garamond',
        'EB Garamond, Georgia, serif'                         => 'EB Garamond',
        'Playfair Display, Georgia, serif'                    => 'Playfair Display',
        'Libre Baskerville, Georgia, serif'                   => 'Libre Baskerville',
        'Georgia, Times New Roman, serif'                     => 'Georgia (système)',
    ];

    // Polices de titres disponibles — Google Fonts premium pour marques de luxe
    const HEADING_FONT_OPTIONS = [
        'Cormorant Garamond' => [
            'label'    => 'Cormorant Garamond — Élégance classique',
            'family'   => "'Cormorant Garamond', Georgia, 'Times New Roman', serif",
            'gfont'    => 'Cormorant+Garamond:ital,wght@0,400;0,600;1,400',
            'category' => 'serif',
        ],
        'Playfair Display' => [
            'label'    => 'Playfair Display — Éditorial luxe',
            'family'   => "'Playfair Display', Georgia, 'Times New Roman', serif",
            'gfont'    => 'Playfair+Display:ital,wght@0,400;0,700;1,400',
            'category' => 'serif',
        ],
        'EB Garamond' => [
            'label'    => 'EB Garamond — Intemporel lettres',
            'family'   => "'EB Garamond', Georgia, serif",
            'gfont'    => 'EB+Garamond:ital,wght@0,400;0,600;1,400',
            'category' => 'serif',
        ],
        'Lora' => [
            'label'    => 'Lora — Chaleur contemporaine',
            'family'   => "'Lora', Georgia, serif",
            'gfont'    => 'Lora:ital,wght@0,400;0,600;1,400',
            'category' => 'serif',
        ],
        'Libre Baskerville' => [
            'label'    => 'Libre Baskerville — Sobre et lisible',
            'family'   => "'Libre Baskerville', Georgia, serif",
            'gfont'    => 'Libre+Baskerville:ital,wght@0,400;0,700;1,400',
            'category' => 'serif',
        ],
        'Cinzel' => [
            'label'    => 'Cinzel — Prestige romain',
            'family'   => "'Cinzel', Georgia, serif",
            'gfont'    => 'Cinzel:wght@400;600',
            'category' => 'serif',
        ],
        'Josefin Sans' => [
            'label'    => 'Josefin Sans — Minimalisme chic',
            'family'   => "'Josefin Sans', Arial, sans-serif",
            'gfont'    => 'Josefin+Sans:ital,wght@0,300;0,400;1,300',
            'category' => 'sans-serif',
        ],
        'Raleway' => [
            'label'    => 'Raleway — Sophistiqué moderne',
            'family'   => "'Raleway', Arial, sans-serif",
            'gfont'    => 'Raleway:ital,wght@0,300;0,400;1,300',
            'category' => 'sans-serif',
        ],
    ];

    /**
     * Styles rapides ("One-Click Apply") — pré-remplissent le formulaire
     * Design (couleurs, police de titre, bouton, espacement, séparateur,
     * ombre) sans jamais sauvegarder automatiquement : le marchand garde
     * la main pour ajuster puis valider, exactement comme un reset de
     * section. Volontairement limité au visuel — aucun texte/traduction
     * n'est modifié par un preset (cf. Empreinte vocale + variables
     * personnalisées, qui restent le seul canal de personnalisation du
     * texte).
     */
    const DESIGN_PRESETS = [
        'haute_joaillerie' => [
            'label'   => 'Haute Joaillerie',
            'tagline' => 'Élégance extrême, doré discret, espacement royal',
            'values'  => [
                'color_background'  => '#faf8f4',
                'color_container'   => '#ffffff',
                'color_accent'      => '#8a6d3b',
                'color_text'        => '#2b2520',
                'font_heading'      => 'Cinzel',
                'btn_color'         => '#2b2520',
                'btn_radius'        => '0',
                'color_header_bg'   => '#2b2520',
                'color_footer_bg'   => '#2b2520',
                'color_footer_text' => '#c9a876',
                'section_padding'   => '56',
                'block_spacing'     => '56',
                'separator_style'   => 'double',
                'card_shadow'       => 'medium',
            ],
        ],
        'minimal_luxe' => [
            'label'   => 'Minimal Luxe',
            'tagline' => 'Sobriété chic, beaucoup d\'air, typographie fine',
            'values'  => [
                'color_background'  => '#ffffff',
                'color_container'   => '#ffffff',
                'color_accent'      => '#2c2c2c',
                'color_text'        => '#2c2c2c',
                'font_heading'      => 'Josefin Sans',
                'btn_color'         => '#2c2c2c',
                'btn_radius'        => '24',
                'color_header_bg'   => '#ffffff',
                'color_footer_bg'   => '#ffffff',
                'color_footer_text' => '#9a9a9a',
                'section_padding'   => '64',
                'block_spacing'     => '64',
                'separator_style'   => 'none',
                'card_shadow'       => 'none',
            ],
        ],
        'artisanal_authentique' => [
            'label'   => 'Artisanal Authentique',
            'tagline' => 'Chaleureux, matières naturelles, ton humain',
            'values'  => [
                'color_background'  => '#f6f0e8',
                'color_container'   => '#fffdfa',
                'color_accent'      => '#a8663f',
                'color_text'        => '#3d2e22',
                'font_heading'      => 'Lora',
                'btn_color'         => '#a8663f',
                'btn_radius'        => '6',
                'color_header_bg'   => '#fffdfa',
                'color_footer_bg'   => '#efe4d4',
                'color_footer_text' => '#8a7a63',
                'section_padding'   => '44',
                'block_spacing'     => '48',
                'separator_style'   => 'dotted',
                'card_shadow'       => 'soft',
            ],
        ],
        'parisien_chic' => [
            'label'   => 'Parisien Chic',
            'tagline' => 'Élégance française classique, sophistiquée et légère',
            'values'  => [
                'color_background'  => '#faf6f5',
                'color_container'   => '#ffffff',
                'color_accent'      => '#b5828c',
                'color_text'        => '#362a2c',
                'font_heading'      => 'Playfair Display',
                'btn_color'         => '#362a2c',
                'btn_radius'        => '2',
                'color_header_bg'   => '#ffffff',
                'color_footer_bg'   => '#ffffff',
                'color_footer_text' => '#a98f92',
                'section_padding'   => '44',
                'block_spacing'     => '48',
                'separator_style'   => 'line',
                'card_shadow'       => 'soft',
            ],
        ],
        'vintage_heritage' => [
            'label'   => 'Vintage Heritage',
            'tagline' => 'Charme intemporel, touches rétro nobles',
            'values'  => [
                'color_background'  => '#f3ece3',
                'color_container'   => '#fffdf8',
                'color_accent'      => '#7a3b3b',
                'color_text'        => '#362420',
                'font_heading'      => 'Libre Baskerville',
                'btn_color'         => '#7a3b3b',
                'btn_radius'        => '2',
                'color_header_bg'   => '#fffdf8',
                'color_footer_bg'   => '#ede2d3',
                'color_footer_text' => '#8a7360',
                'section_padding'   => '44',
                'block_spacing'     => '48',
                'separator_style'   => 'double',
                'card_shadow'       => 'soft',
            ],
        ],
        'modern_opulence' => [
            'label'   => 'Modern Opulence',
            'tagline' => 'Luxe audacieux, contrastes forts, présence affirmée',
            'values'  => [
                'color_background'  => '#f5f5f3',
                'color_container'   => '#ffffff',
                'color_accent'      => '#2f5233',
                'color_text'        => '#1c1c1c',
                'font_heading'      => 'EB Garamond',
                'btn_color'         => '#1c1c1c',
                'btn_radius'        => '0',
                'color_header_bg'   => '#ffffff',
                'color_footer_bg'   => '#1c1c1c',
                'color_footer_text' => '#c9c9c9',
                'section_padding'   => '36',
                'block_spacing'     => '40',
                'separator_style'   => 'line',
                'card_shadow'       => 'strong',
            ],
        ],
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

    /** @var \WatchdogManager|null Instance paresseuse du watchdog */
    private ?\WatchdogManager $watchdog = null;

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

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
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
        // Le cache mémoire ne conserve QUE la valeur brute lue en base, pas
        // le résultat final après application de $default — sinon le premier
        // appel à get($key, $defaultA) fige un $default qu'un appel ultérieur
        // get($key, $defaultB) sur la même clé recevrait à tort (le cache
        // renverrait la résolution figée par le premier appelant, ignorant
        // $defaultB). Latent aujourd'hui (aucun appelant actuel ne varie le
        // default d'une même clé dans une même requête), mais un piège pour
        // tout futur appel qui le ferait.
        if (array_key_exists($key, $this->cache)) {
            $value = $this->cache[$key];
        } else {
            $value = \Configuration::get($key);
            $this->cache[$key] = $value;
        }

        // Si vide, utilise le default fourni ou celui des DEFAULTS
        if ($value === false || $value === '') {
            $value = $default ?? self::DEFAULTS[$key] ?? '';
        }

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
            'color_background'  => $this->get(self::KEY_COLOR_BACKGROUND),
            'color_container'   => $this->get(self::KEY_COLOR_CONTAINER),
            'color_accent'      => $this->get(self::KEY_COLOR_ACCENT),
            'color_text'        => $this->get(self::KEY_COLOR_TEXT),
            'dark_mode'         => (bool) $this->get(self::KEY_DARK_MODE),
            'container_width'   => (int) $this->get(self::KEY_CONTAINER_WIDTH),
            'logo_width'        => (int) $this->get(self::KEY_LOGO_WIDTH),
            'logo_path'         => $this->get(self::KEY_LOGO_PATH),
            // Design avancé
            'font_heading'      => $this->get(self::KEY_FONT_HEADING),
            'btn_radius'        => (int) $this->get(self::KEY_BTN_RADIUS),
            'btn_color'         => $this->get(self::KEY_BTN_COLOR),
            'color_header_bg'   => $this->get(self::KEY_COLOR_HEADER_BG),
            'color_footer_bg'   => $this->get(self::KEY_COLOR_FOOTER_BG),
            'color_footer_text' => $this->get(self::KEY_COLOR_FOOTER_TEXT),
            'section_padding'   => (int) $this->get(self::KEY_SECTION_PADDING),
            'block_spacing'     => (int) $this->get(self::KEY_BLOCK_SPACING),
            'separator_style'   => $this->get(self::KEY_SEPARATOR_STYLE),
            'card_shadow'       => $this->get(self::KEY_CARD_SHADOW),
            // Typographie corps
            'font_size'         => (int)   $this->get(self::KEY_FONT_SIZE),
            'line_height'       => (float) $this->get(self::KEY_LINE_HEIGHT),
            'heading_weight'    => (int)   $this->get(self::KEY_HEADING_WEIGHT),
        ];
    }

    public function getHeadingFontFamily(string $font): string
    {
        return self::HEADING_FONT_OPTIONS[$font]['family']
            ?? "'Cormorant Garamond', Georgia, 'Times New Roman', serif";
    }

    public function getHeadingFontLink(string $font): string
    {
        $gfont = self::HEADING_FONT_OPTIONS[$font]['gfont'] ?? 'Cormorant+Garamond:ital,wght@0,400;0,600;1,400';
        return '<link href="https://fonts.googleapis.com/css2?family=' . $gfont . '&display=swap" rel="stylesheet">';
    }

    public static function getSeparatorCss(string $style): string
    {
        switch ($style) {
            case 'none':   return 'display:none; height:0; overflow:hidden;';
            case 'dotted': return 'border:0; border-top:1px dotted #e3d7c7; height:0; margin:20px 40px;';
            case 'double': return 'border:0; border-top:3px double #e3d7c7; height:0; margin:20px 40px;';
            default:       return 'border:0; height:1px; background-color:#e3d7c7; margin:20px 40px;';
        }
    }

    public static function getCardShadowCss(string $shadow): string
    {
        switch ($shadow) {
            case 'none':   return 'none';
            case 'medium': return '0 8px 24px rgba(0,0,0,0.14)';
            case 'strong': return '0 4px 16px rgba(0,0,0,0.30)';
            default:       return '0 20px 40px rgba(0,0,0,0.06)'; // soft
        }
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

    public function isTimeGreetingEnabled(): bool
    {
        return (bool) $this->get(self::KEY_TIME_GREETING_ENABLED, 1);
    }

    public function setTimeGreetingEnabled(bool $enabled): bool
    {
        return $this->set(self::KEY_TIME_GREETING_ENABLED, (int) $enabled);
    }

    public function isFirstnameFallbackEnabled(): bool
    {
        return (bool) $this->get(self::KEY_FIRSTNAME_FALLBACK_ENABLED, 1);
    }

    public function setFirstnameFallbackEnabled(bool $enabled): bool
    {
        return $this->set(self::KEY_FIRSTNAME_FALLBACK_ENABLED, (int) $enabled);
    }

    public function isMultiSenderEnabled(): bool
    {
        return (bool) $this->get(self::KEY_MULTI_SENDER_ENABLED, 1);
    }

    public function setMultiSenderEnabled(bool $enabled): bool
    {
        return $this->set(self::KEY_MULTI_SENDER_ENABLED, (int) $enabled);
    }

    public function isSignatureEnabled(): bool
    {
        return (bool) $this->get(self::KEY_SIGNATURE_ENABLED, 1);
    }

    public function setSignatureEnabled(bool $enabled): bool
    {
        return $this->set(self::KEY_SIGNATURE_ENABLED, (int) $enabled);
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

    /**
     * Indique si la détection automatique de la langue client est active
     *
     * @return bool
     */
    public function isAutoLangEnabled(): bool
    {
        return (bool) $this->get(self::KEY_AUTO_LANG, 1);
    }

    /**
     * Indique si les emails internes (destinés au marchand : alertes de log,
     * notifications administrateur…) doivent être journalisés par le watchdog.
     * Désactivé par défaut pour garder le journal centré sur les emails clients.
     *
     * @return bool
     */
    public function isInternalLogEnabled(): bool
    {
        return (bool) $this->get(self::KEY_LOG_INTERNAL, 0);
    }

    /**
     * Durée de validité des bons de réduction, en jours (variable {validity_days}).
     *
     * @return int
     */
    public function getVoucherValidity(): int
    {
        return (int) $this->get(self::KEY_VOUCHER_VALIDITY, 30);
    }

    /**
     * Montant du bon de réduction anniversaire (variable {voucher_code}),
     * en pourcentage ou en montant fixe selon isBirthdayVoucherPercent().
     *
     * @return float
     */
    public function getBirthdayVoucherAmount(): float
    {
        return (float) $this->get(self::KEY_BIRTHDAY_VOUCHER_AMOUNT, 10);
    }

    /**
     * Indique si le montant du bon anniversaire est un pourcentage (true)
     * ou un montant fixe dans la devise de la boutique (false).
     *
     * @return bool
     */
    public function isBirthdayVoucherPercent(): bool
    {
        return (bool) $this->get(self::KEY_BIRTHDAY_VOUCHER_PERCENT, 1);
    }

    /**
     * Plafond de sécurité (devise boutique) appliqué aux bons de réduction en
     * mode montant fixe (anniversaire, paliers, fidélité) — réglable par le
     * marchand pour éviter qu'une faute de frappe ("1000" au lieu de "10")
     * ne crée un bon d'un montant disproportionné, auto-envoyé aux clients.
     *
     * @return float
     */
    public function getVoucherFixedCap(): float
    {
        return (float) $this->get(self::KEY_VOUCHER_FIXED_CAP, 10000);
    }

    /**
     * Indique si les points/paliers de fidélité se cumulent sur TOUTES les
     * boutiques du marchand (activé par défaut — comportement historique du
     * module) ou séparément par boutique. Réglage demandé explicitement
     * par l'utilisateur le 2026-07-20 après avoir découvert que
     * getCustomerPoints() sommait déjà globalement sans distinction de
     * boutique, contrairement au reste du module (désabonnement,
     * préférences, liste d'attente...) déjà cloisonné par boutique.
     */
    public function isLoyaltyCrossShopEnabled(): bool
    {
        return (bool) $this->get(self::KEY_LOYALTY_CROSS_SHOP_ENABLED, 1);
    }

    // ================================================================
    // CENTRE DE CONTRÔLE — visibilité des features dans le menu BO
    // ================================================================
    //
    // Chaque entrée ci-dessous correspond à une feature qui a déjà son
    // propre réglage actif/inactif (NERIA_X_ENABLED) ailleurs dans le
    // module — le centre de contrôle ne duplique jamais cette logique,
    // il ajoute uniquement un contrôle d'AFFICHAGE du lien de menu
    // correspondant. Masquer une feature ici n'a donc jamais d'effet sur
    // son fonctionnement réel (crons, emails) : seul son lien disparaît
    // du menu BO, exactement comme masquer une appli sur un écran
    // d'accueil iPhone n'arrête pas l'appli elle-même.
    //
    // 'scope' vaut 'tab' (onglet principal du menu) ou 'stats_section'
    // (ancre à l'intérieur de la page Stats). 'label_key' réutilise les
    // clés de traduction nav.* déjà existantes — aucune nouvelle clé de
    // libellé n'est nécessaire.
    const CONTROL_CENTER_REGISTRY = [
        ['key' => 'abtest',                   'scope' => 'tab',           'tab' => 'abtest',       'enabled_key' => self::KEY_ABTEST_ENABLED,        'label_key' => 'nav.abtest'],
        ['key' => 'bounces',                  'scope' => 'tab',           'tab' => 'bounces',      'enabled_key' => 'NERIA_BOUNCE_ENABLED',          'label_key' => 'nav.bounces'],
        ['key' => 'certificates',             'scope' => 'tab',           'tab' => 'certificates', 'enabled_key' => 'NERIA_CERT_ENABLED',            'label_key' => 'nav.certificates', 'default_if_unset' => true],
        ['key' => 'checkout_abandonment',     'scope' => 'stats_section', 'anchor' => 'neria-checkout-abandonment-section',    'enabled_key' => 'NERIA_CHECKOUT_ABANDONMENT_ENABLED',   'label_key' => 'nav.sub_checkout_abandonment'],
        ['key' => 'relationship_anniversary', 'scope' => 'stats_section', 'anchor' => 'neria-relationship-anniversary-section', 'enabled_key' => 'NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED', 'label_key' => 'nav.sub_relationship_anniversary'],
        ['key' => 'upsell',                   'scope' => 'stats_section', 'anchor' => 'neria-upsell-section',                   'enabled_key' => 'NERIA_UPSELL_ENABLED',                 'label_key' => 'nav.sub_upsell', 'default_if_unset' => true],
        ['key' => 'propensity',               'scope' => 'stats_section', 'anchor' => 'neria-propensity-section',               'enabled_key' => 'NERIA_PROPENSITY_ENABLED',             'label_key' => 'nav.sub_propensity'],
        ['key' => 'purchase_window',          'scope' => 'stats_section', 'anchor' => 'neria-purchase-window-section',          'enabled_key' => 'NERIA_PURCHASE_WINDOW_ENABLED',        'label_key' => 'nav.sub_purchase_window'],
        ['key' => 'lifespan',                 'scope' => 'stats_section', 'anchor' => 'neria-lifespan-section',                 'enabled_key' => 'NERIA_LIFESPAN_ENABLED',               'label_key' => 'nav.sub_lifespan'],
        ['key' => 'reconciliation',           'scope' => 'stats_section', 'anchor' => 'neria-reconciliation-section',           'enabled_key' => 'NERIA_REFUND_RECONCILIATION_ENABLED',  'label_key' => 'nav.sub_reconciliation'],
        ['key' => 'quote',                    'scope' => 'stats_section', 'anchor' => 'neria-quote-section',                    'enabled_key' => 'NERIA_QUOTE_REMINDERS_ENABLED',        'label_key' => 'nav.sub_quote'],
        ['key' => 'collection',               'scope' => 'stats_section', 'anchor' => 'neria-collection-section',               'enabled_key' => 'NERIA_COLLECTION_COMPLETION_ENABLED',  'label_key' => 'nav.sub_collection'],
        ['key' => 'look',                     'scope' => 'stats_section', 'anchor' => 'neria-look-section',                     'enabled_key' => 'NERIA_LOOK_COMPLETION_ENABLED',        'label_key' => 'nav.sub_look'],
        ['key' => 'waitlist',                 'scope' => 'stats_section', 'anchor' => 'neria-waitlist-section',                 'enabled_key' => 'NERIA_WAITLIST_ENABLED',               'label_key' => 'nav.sub_waitlist'],
        ['key' => 'ghost_cart',               'scope' => 'stats_section', 'anchor' => 'neria-ghost-cart-section',               'enabled_key' => 'NERIA_GHOST_CART_ENABLED',             'label_key' => 'nav.sub_ghost_cart'],

        // ── Stats : sections sans réglage ENABLED dédié — 'enabled_key' à
        // null signifie "toujours active" (pas de concept marche/arrêt),
        // le centre de contrôle affiche alors la pastille Actif d'office.
        ['key' => 'monthly_comparison',   'scope' => 'stats_section', 'anchor' => 'neria-monthly-comparison',        'enabled_key' => null,                        'label_key' => 'nav.sub_monthly_comparison'],
        ['key' => 'health_kpi',           'scope' => 'stats_section', 'anchor' => 'neria-health-kpi-banner',         'enabled_key' => null,                        'label_key' => 'nav.sub_health_kpi'],
        // NERIA_ATTRIBUTION_ENABLED est une clé fantôme héritée : l'attribution
        // de revenus (last-click 24h) est en réalité toujours active, sans
        // interrupteur marchand — le cookie neria_ref est posé sans condition
        // (cf. HealthCheckManager::checkAttributionCoverage(), même constat
        // déjà fait et corrigé côté Watchdog pour ce même flag mort).
        ['key' => 'revenue_attribution',  'scope' => 'stats_section', 'anchor' => 'neria-revenue-attribution',       'enabled_key' => null, 'label_key' => 'nav.sub_revenue_attribution'],
        ['key' => 'engagement',           'scope' => 'stats_section', 'anchor' => 'neria-engagement-chart-section',  'enabled_key' => null,                        'label_key' => 'nav.sub_engagement'],
        ['key' => 'heatmap',              'scope' => 'stats_section', 'anchor' => 'neria-heatmap-section',           'enabled_key' => null,                        'label_key' => 'nav.sub_heatmap'],
        ['key' => 'domain_rep',           'scope' => 'stats_section', 'anchor' => 'neria-domain-rep',                'enabled_key' => null,                        'label_key' => 'nav.sub_domain_rep'],
        ['key' => 'pagespeed',            'scope' => 'stats_section', 'anchor' => 'neria-visibility-section',        'enabled_key' => null,                        'label_key' => 'nav.sub_visibility'],
        ['key' => 'search_console',       'scope' => 'stats_section', 'anchor' => 'neria-search-console-section',    'enabled_key' => null,                        'label_key' => 'nav.sub_search_console'],
        ['key' => 'seo_api',              'scope' => 'stats_section', 'anchor' => 'neria-seo-api-section',           'enabled_key' => null,                        'label_key' => 'nav.sub_seo_api'],
        ['key' => 'postmaster',           'scope' => 'stats_section', 'anchor' => 'neria-postmaster-tools',          'enabled_key' => null,                        'label_key' => 'nav.sub_postmaster'],
        ['key' => 'snds',                 'scope' => 'stats_section', 'anchor' => 'neria-snds-section',              'enabled_key' => null,                        'label_key' => 'nav.sub_snds'],
        ['key' => 'score_panel',          'scope' => 'stats_section', 'anchor' => 'neria-score-panel',               'enabled_key' => null,                        'label_key' => 'nav.sub_score'],
        ['key' => 'golden_hour',          'scope' => 'stats_section', 'anchor' => 'neria-golden-hour-section',       'enabled_key' => null,                        'label_key' => 'nav.sub_golden_hour'],

        // ── Onglet Accueil (configure.tpl) : sections du sous-menu ──────
        ['key' => 'auto_lang',            'scope' => 'configure_section', 'anchor' => 'neria-cfg-autolang',           'enabled_key' => self::KEY_AUTO_LANG,               'label_key' => 'nav.sub_autolang'],
        // 'default_if_unset' : ces 4 réglages sont actifs PAR DÉFAUT (chaque
        // getter ConfigManager::isXEnabled() passe un défaut=1), mais ne sont
        // jamais semés dans setDefaultConfiguration() — une install neuve
        // n'a donc aucune ligne ps_configuration pour ces clés tant que le
        // marchand n'a jamais touché le bouton. Sans ce fallback, une lecture
        // brute Configuration::getGlobalValue() renvoie false et affiche
        // à tort "Inactif" pour une feature qui fonctionne bien en Actif
        // partout ailleurs dans le module.
        ['key' => 'time_greeting',        'scope' => 'configure_section', 'anchor' => 'neria-cfg-time-greetings',     'enabled_key' => self::KEY_TIME_GREETING_ENABLED,   'default_if_unset' => true, 'label_key' => 'nav.sub_time_greetings'],
        ['key' => 'firstname_fallback',   'scope' => 'configure_section', 'anchor' => 'neria-cfg-firstname-fallbacks','enabled_key' => self::KEY_FIRSTNAME_FALLBACK_ENABLED, 'default_if_unset' => true, 'label_key' => 'nav.sub_firstname_fallbacks'],
        ['key' => 'vouchers',             'scope' => 'configure_section', 'anchor' => 'neria-cfg-vouchers',           'enabled_key' => self::KEY_MILESTONE_VOUCHER_ENABLED, 'label_key' => 'nav.sub_vouchers'],
        ['key' => 'cooldown',             'scope' => 'configure_section', 'anchor' => 'neria-cfg-cooldown',           'enabled_key' => self::KEY_COOLDOWN_ENABLED,        'label_key' => 'nav.sub_cooldown'],
        ['key' => 'silent_witness',       'scope' => 'configure_section', 'anchor' => 'neria-cfg-archive',            'enabled_key' => null,                              'label_key' => 'nav.sub_silent_witness'],
        ['key' => 'carbon',               'scope' => 'configure_section', 'anchor' => 'neria-cfg-carbon',             'enabled_key' => self::KEY_CARBON_ENABLED,          'label_key' => 'nav.sub_carbon'],
        ['key' => 'multi_sender',         'scope' => 'configure_section', 'anchor' => 'neria-cfg-senders',            'enabled_key' => self::KEY_MULTI_SENDER_ENABLED,    'default_if_unset' => true, 'label_key' => 'nav.sub_senders'],
        ['key' => 'blacklist',            'scope' => 'configure_section', 'anchor' => 'neria-cfg-blacklist',          'enabled_key' => null,                              'label_key' => 'nav.sub_blacklist'],
        // NERIA_REPORT_ENABLED est également auto-réparé au premier appel de
        // getReportEnabledConfig() (qui l'initialise à 1 si absent) — même
        // besoin de fallback ici pour rester correct avant ce premier appel.
        ['key' => 'monthly_report',       'scope' => 'configure_section', 'anchor' => 'neria-cfg-report',             'enabled_key' => 'NERIA_REPORT_ENABLED',            'default_if_unset' => true, 'label_key' => 'nav.sub_report'],
        ['key' => 'upcoming_events',      'scope' => 'configure_section', 'anchor' => 'neria-cfg-upcoming',           'enabled_key' => null,                              'label_key' => 'nav.sub_upcoming'],
        ['key' => 'custom_vars',          'scope' => 'configure_section', 'anchor' => 'neria-cfg-customvars',         'enabled_key' => null,                              'label_key' => 'nav.sub_customvars'],
        ['key' => 'signature',            'scope' => 'configure_section', 'anchor' => 'neria-cfg-signature',          'enabled_key' => self::KEY_SIGNATURE_ENABLED,       'default_if_unset' => true, 'label_key' => 'nav.sub_signature'],
        ['key' => 'preferences',          'scope' => 'configure_section', 'anchor' => 'neria-cfg-preferences',        'enabled_key' => null,                              'label_key' => 'nav.sub_preferences'],
        ['key' => 'loyalty',              'scope' => 'configure_section', 'anchor' => 'neria-loyalty-section',        'enabled_key' => 'NERIA_LOYALTY_ENABLED',           'label_key' => 'nav.sub_loyalty', 'default_if_unset' => true],
    ];

    /** @return array<int,string> Clés des features actuellement masquées du menu BO. */
    public function getHiddenMenuItems(): array
    {
        $json = (string) $this->get(self::KEY_MENU_HIDDEN_ITEMS, '[]');
        $decoded = json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
    }

    public function isMenuItemVisible(string $key): bool
    {
        return !in_array($key, $this->getHiddenMenuItems(), true);
    }

    /**
     * Bascule la visibilité d'une feature dans le menu BO (n'affecte jamais
     * son état actif/inactif réel, cf. commentaire du registre ci-dessus).
     * Ignore silencieusement toute clé absente du registre — appelant
     * responsable de valider contre CONTROL_CENTER_REGISTRY en amont.
     */
    public function toggleMenuItemVisibility(string $key): void
    {
        $hidden = $this->getHiddenMenuItems();
        if (in_array($key, $hidden, true)) {
            $hidden = array_values(array_diff($hidden, [$key]));
        } else {
            $hidden[] = $key;
        }
        $encoded = json_encode(array_values($hidden));
        \Configuration::updateGlobalValue(self::KEY_MENU_HIDDEN_ITEMS, $encoded);
        // Invalide le cache mémoire local : sans ça, un appel à
        // isMenuItemVisible()/getHiddenMenuItems() sur cette même instance
        // juste après le toggle renverrait encore l'ancienne valeur.
        $this->cache[self::KEY_MENU_HIDDEN_ITEMS] = $encoded;
    }

    /**
     * Affiche ou masque TOUTES les features du menu BO en un seul appel
     * (bouton "Tout afficher"/"Tout masquer" du centre de contrôle).
     * $visible = true  → NERIA_MENU_HIDDEN_ITEMS vidé (tout affiché).
     * $visible = false → NERIA_MENU_HIDDEN_ITEMS rempli avec la totalité
     * des clés du registre (tout masqué). N'affecte jamais l'état
     * actif/inactif réel des features, uniquement l'affichage du menu.
     */
    public function setAllMenuItemsVisibility(bool $visible): void
    {
        $hidden  = $visible ? [] : array_column(self::CONTROL_CENTER_REGISTRY, 'key');
        $encoded = json_encode(array_values($hidden));
        \Configuration::updateGlobalValue(self::KEY_MENU_HIDDEN_ITEMS, $encoded);
        $this->cache[self::KEY_MENU_HIDDEN_ITEMS] = $encoded;
    }

    /**
     * Indique si le marchand a activé le bon de réduction sur les paliers
     * de commandes (milestone_order). Désactivé par défaut : sans ce toggle,
     * milestone_order reste un email de pure reconnaissance sans bon promis.
     */
    public function isMilestoneVoucherEnabled(): bool
    {
        return (bool) $this->get(self::KEY_MILESTONE_VOUCHER_ENABLED, 0);
    }

    /**
     * Montant du bon de réduction par palier de commandes (variable
     * {voucher_code} de milestone_order), en pourcentage ou en montant fixe
     * selon isMilestoneVoucherPercent().
     */
    public function getMilestoneVoucherAmount(): float
    {
        return (float) $this->get(self::KEY_MILESTONE_VOUCHER_AMOUNT, 10);
    }

    /** Indique si le montant du bon palier est un pourcentage (true) ou un montant fixe (false). */
    public function isMilestoneVoucherPercent(): bool
    {
        return (bool) $this->get(self::KEY_MILESTONE_VOUCHER_PERCENT, 1);
    }

    /** Retourne les fallbacks firstname par langue (tableau ['fr'=>'Cher Invité', ...]) */
    public function getFirstnameFallbacks(): array
    {
        $defaults = [
            'fr' => 'Cher Invité',      'en' => 'Dear Guest',       'de' => 'Lieber Gast',
            'it' => 'Caro Ospite',      'es' => 'Estimado Cliente',  'pt' => 'Caro Convidado',
            'br' => 'Prezado Cliente',  'gb' => 'Dear Guest',        'ar' => 'عزيزي الضيف',       'ja' => 'お客様',
            'ko' => '소중한 고객님',      'zh' => '尊贵的顾客',          'tw' => '尊貴的顧客',
            'ru' => 'Уважаемый гость',  'tr' => 'Sayın Misafirimiz', 'sv' => 'Kära Gäst',
            'no' => 'Kjære Gjest',      'da' => 'Kære Gæst',         'nl' => 'Beste Gast',
        ];
        $saved = $this->get(self::KEY_FIRSTNAME_FALLBACKS);
        if (!$saved) {
            return $defaults;
        }
        $decoded = json_decode($saved, true);
        return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
    }

    public function saveFirstnameFallbacks(array $fallbacks): bool
    {
        return $this->set(self::KEY_FIRSTNAME_FALLBACKS, json_encode($fallbacks, JSON_UNESCAPED_UNICODE));
    }

    /** Retourne les salutations horaires par langue × créneau (morning/afternoon/evening/night) */
    public function getTimeGreetings(): array
    {
        $defaults = [
            'fr' => ['morning' => 'Bonjour',          'afternoon' => 'Bonjour',         'evening' => 'Bonsoir',         'night' => 'Bonjour'],
            'en' => ['morning' => 'Good morning',     'afternoon' => 'Good afternoon',  'evening' => 'Good evening',    'night' => 'Hello'],
            'de' => ['morning' => 'Guten Morgen',     'afternoon' => 'Guten Tag',       'evening' => 'Guten Abend',     'night' => 'Hallo'],
            'it' => ['morning' => 'Buongiorno',       'afternoon' => 'Buon pomeriggio', 'evening' => 'Buonasera',       'night' => 'Salve'],
            'es' => ['morning' => 'Buenos días',      'afternoon' => 'Buenas tardes',   'evening' => 'Buenas noches',   'night' => 'Hola'],
            'pt' => ['morning' => 'Bom dia',          'afternoon' => 'Boa tarde',       'evening' => 'Boa noite',       'night' => 'Olá'],
            'br' => ['morning' => 'Bom dia',          'afternoon' => 'Boa tarde',       'evening' => 'Boa noite',       'night' => 'Olá'],
            'gb' => ['morning' => 'Good morning',     'afternoon' => 'Good afternoon',  'evening' => 'Good evening',    'night' => 'Hello'],
            'ar' => ['morning' => 'صباح الخير',       'afternoon' => 'مساء الخير',      'evening' => 'مساء النور',      'night' => 'أهلاً'],
            'ja' => ['morning' => 'おはようございます', 'afternoon' => 'こんにちは',       'evening' => 'こんばんは',       'night' => 'こんにちは'],
            'ko' => ['morning' => '좋은 아침이에요',    'afternoon' => '안녕하세요',       'evening' => '안녕하세요',       'night' => '안녕하세요'],
            'zh' => ['morning' => '早上好',            'afternoon' => '下午好',          'evening' => '晚上好',           'night' => '您好'],
            'tw' => ['morning' => '早安',              'afternoon' => '午安',            'evening' => '晚安',             'night' => '您好'],
            'ru' => ['morning' => 'Доброе утро',      'afternoon' => 'Добрый день',     'evening' => 'Добрый вечер',    'night' => 'Здравствуйте'],
            'tr' => ['morning' => 'Günaydın',         'afternoon' => 'İyi günler',      'evening' => 'İyi akşamlar',    'night' => 'Merhaba'],
            'sv' => ['morning' => 'God morgon',       'afternoon' => 'God dag',         'evening' => 'God kväll',       'night' => 'Hej'],
            'no' => ['morning' => 'God morgen',       'afternoon' => 'God dag',         'evening' => 'God kveld',       'night' => 'Hei'],
            'da' => ['morning' => 'God morgen',       'afternoon' => 'God dag',         'evening' => 'God aften',       'night' => 'Hej'],
            'nl' => ['morning' => 'Goedemorgen',      'afternoon' => 'Goedemiddag',     'evening' => 'Goedenavond',     'night' => 'Hallo'],
        ];
        $saved = $this->get(self::KEY_TIME_GREETINGS);
        if (!$saved) {
            return $defaults;
        }
        $decoded = json_decode($saved, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        foreach ($defaults as $lang => $slots) {
            $decoded[$lang] = array_merge($slots, $decoded[$lang] ?? []);
        }
        return $decoded;
    }

    public function saveTimeGreetings(array $greetings): bool
    {
        return $this->set(self::KEY_TIME_GREETINGS, json_encode($greetings, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Réinitialise les salutations aux valeurs par défaut.
     * Sans argument → réinitialise toutes les langues.
     * Avec $lang → réinitialise uniquement cette langue.
     */
    public function resetTimeGreetings(?string $lang = null): bool
    {
        if ($lang === null) {
            return (bool) \Configuration::deleteByName(self::KEY_TIME_GREETINGS);
        }
        $saved = $this->get(self::KEY_TIME_GREETINGS);
        $data  = ($saved && is_array($arr = json_decode($saved, true))) ? $arr : [];
        unset($data[$lang]);
        return $this->set(self::KEY_TIME_GREETINGS, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** Retourne les pays cibles activés (ISO 2 lettres). Vide = tous activés. */
    public function getTargetCountries(): array
    {
        $saved = $this->get(self::KEY_TARGET_COUNTRIES);
        if (!$saved) return [];
        $decoded = json_decode($saved, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveTargetCountries(array $isoCodes): bool
    {
        return $this->set(self::KEY_TARGET_COUNTRIES, json_encode(array_values($isoCodes)));
    }

    /** Liste exhaustive des pays du monde (ISO 3166-1 alpha-2 → nom FR) */
    public static function getAllCountries(): array
    {
        return [
            'AF'=>'Afghanistan','ZA'=>'Afrique du Sud','AL'=>'Albanie','DZ'=>'Algérie',
            'DE'=>'Allemagne','AD'=>'Andorre','AO'=>'Angola','AG'=>'Antigua-et-Barbuda',
            'SA'=>'Arabie saoudite','AR'=>'Argentine','AM'=>'Arménie','AU'=>'Australie',
            'AT'=>'Autriche','AZ'=>'Azerbaïdjan','BS'=>'Bahamas','BH'=>'Bahreïn',
            'BD'=>'Bangladesh','BB'=>'Barbade','BE'=>'Belgique','BZ'=>'Belize',
            'BJ'=>'Bénin','BT'=>'Bhoutan','BY'=>'Biélorussie','BO'=>'Bolivie',
            'BA'=>'Bosnie-Herzégovine','BW'=>'Botswana','BR'=>'Brésil','BN'=>'Brunéi',
            'BG'=>'Bulgarie','BF'=>'Burkina Faso','BI'=>'Burundi','CV'=>'Cap-Vert',
            'KH'=>'Cambodge','CM'=>'Cameroun','CA'=>'Canada','QA'=>'Qatar',
            'CF'=>'Rép. centrafricaine','CL'=>'Chili','CN'=>'Chine','CY'=>'Chypre',
            'CO'=>'Colombie','KM'=>'Comores','CG'=>'Congo','CD'=>'Congo (RDC)',
            'KR'=>'Corée du Sud','KP'=>'Corée du Nord','CR'=>'Costa Rica',
            'HR'=>'Croatie','CU'=>'Cuba','CZ'=>'Tchéquie','DK'=>'Danemark',
            'DJ'=>'Djibouti','DM'=>'Dominique','EG'=>'Égypte','AE'=>'Émirats arabes unis',
            'EC'=>'Équateur','ER'=>'Érythrée','ES'=>'Espagne','EE'=>'Estonie',
            'SZ'=>'Eswatini','ET'=>'Éthiopie','FJ'=>'Fidji','FI'=>'Finlande',
            'FR'=>'France','GA'=>'Gabon','GM'=>'Gambie','GE'=>'Géorgie',
            'GH'=>'Ghana','GR'=>'Grèce','GD'=>'Grenade','GT'=>'Guatemala',
            'GN'=>'Guinée','GQ'=>'Guinée équatoriale','GW'=>'Guinée-Bissau',
            'GY'=>'Guyana','HT'=>'Haïti','HN'=>'Honduras','HU'=>'Hongrie',
            'IN'=>'Inde','ID'=>'Indonésie','IQ'=>'Irak','IR'=>'Iran',
            'IE'=>'Irlande','IS'=>'Islande','IL'=>'Israël','IT'=>'Italie',
            'JM'=>'Jamaïque','JP'=>'Japon','JO'=>'Jordanie','KZ'=>'Kazakhstan',
            'KE'=>'Kenya','KI'=>'Kiribati','KW'=>'Koweït','KG'=>'Kirghizistan',
            'LA'=>'Laos','LS'=>'Lesotho','LV'=>'Lettonie','LB'=>'Liban',
            'LR'=>'Libéria','LY'=>'Libye','LI'=>'Liechtenstein','LT'=>'Lituanie',
            'LU'=>'Luxembourg','MK'=>'Macédoine du Nord','MG'=>'Madagascar',
            'MY'=>'Malaisie','MW'=>'Malawi','MV'=>'Maldives','ML'=>'Mali',
            'MT'=>'Malte','MA'=>'Maroc','MH'=>'Îles Marshall','MU'=>'Maurice',
            'MR'=>'Mauritanie','MX'=>'Mexique','FM'=>'Micronésie','MD'=>'Moldavie',
            'MC'=>'Monaco','MN'=>'Mongolie','ME'=>'Monténégro','MZ'=>'Mozambique',
            'MM'=>'Myanmar','NA'=>'Namibie','NR'=>'Nauru','NP'=>'Népal',
            'NI'=>'Nicaragua','NE'=>'Niger','NG'=>'Nigéria','NO'=>'Norvège',
            'NZ'=>'Nouvelle-Zélande','NL'=>'Pays-Bas','OM'=>'Oman','UG'=>'Ouganda',
            'UZ'=>'Ouzbékistan','PK'=>'Pakistan','PW'=>'Palaos','PS'=>'Palestine',
            'PA'=>'Panama','PG'=>'Papouasie-Nouvelle-Guinée','PY'=>'Paraguay',
            'PE'=>'Pérou','PH'=>'Philippines','PL'=>'Pologne','PT'=>'Portugal',
            'RO'=>'Roumanie','GB'=>'Royaume-Uni','RU'=>'Russie','RW'=>'Rwanda',
            'KN'=>'Saint-Christophe-et-Niévès','SM'=>'Saint-Marin',
            'VC'=>'Saint-Vincent-et-les-Grenadines','LC'=>'Sainte-Lucie',
            'SB'=>'Îles Salomon','WS'=>'Samoa','ST'=>'São Tomé-et-Príncipe',
            'SN'=>'Sénégal','RS'=>'Serbie','SC'=>'Seychelles','SL'=>'Sierra Leone',
            'SG'=>'Singapour','SK'=>'Slovaquie','SI'=>'Slovénie','SO'=>'Somalie',
            'SD'=>'Soudan','SS'=>'Soudan du Sud','LK'=>'Sri Lanka','SE'=>'Suède',
            'CH'=>'Suisse','SR'=>'Suriname','SY'=>'Syrie','TJ'=>'Tadjikistan',
            'TW'=>'Taïwan','TZ'=>'Tanzanie','TD'=>'Tchad','TH'=>'Thaïlande',
            'TL'=>'Timor oriental','TG'=>'Togo','TO'=>'Tonga',
            'TT'=>'Trinité-et-Tobago','TN'=>'Tunisie','TM'=>'Turkménistan',
            'TR'=>'Turquie','TV'=>'Tuvalu','UA'=>'Ukraine','UY'=>'Uruguay',
            'VU'=>'Vanuatu','VE'=>'Venezuela','VN'=>'Viêt Nam',
            'YE'=>'Yémen','ZM'=>'Zambie','ZW'=>'Zimbabwe',
            'DO'=>'Rép. dominicaine','SV'=>'Salvador','US'=>'États-Unis',
        ];
    }

    public function isCooldownEnabled(): bool
    {
        return (bool) $this->get(self::KEY_COOLDOWN_ENABLED, 0);
    }

    public function setCooldownEnabled(bool $enabled): bool
    {
        return $this->set(self::KEY_COOLDOWN_ENABLED, (int) $enabled);
    }

    public function getCooldownMinutes(): int
    {
        return max(1, (int) $this->get(self::KEY_COOLDOWN_MINUTES, 10));
    }

    public function isCarbonEnabled(): bool
    {
        return (bool) $this->get(self::KEY_CARBON_ENABLED, 0);
    }

    public function getCarbonLink(): string
    {
        return (string) $this->get(self::KEY_CARBON_LINK, '');
    }

    /**
     * Retourne tous les expéditeurs par langue.
     * Structure : ['fr' => ['name' => '...', 'email' => '...'], ...]
     */
    public function getAllSenders(): array
    {
        $json = $this->get(self::KEY_SENDERS_JSON, '');
        if ($json === '' || $json === false) {
            return [];
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Retourne le nom et l'email expéditeur pour une langue donnée.
     * Retourne un tableau vide si aucun expéditeur n'est configuré pour cette langue.
     */
    public function getSenderForLang(string $lang): array
    {
        $all = $this->getAllSenders();
        $entry = $all[$lang] ?? [];
        if (empty($entry['name']) && empty($entry['email'])) {
            return [];
        }
        return $entry;
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
            self::KEY_COLOR_BACKGROUND  => 'color_background',
            self::KEY_COLOR_CONTAINER   => 'color_container',
            self::KEY_COLOR_ACCENT      => 'color_accent',
            self::KEY_COLOR_TEXT        => 'color_text',
            self::KEY_BTN_COLOR         => 'btn_color',
            self::KEY_COLOR_HEADER_BG   => 'color_header_bg',
            self::KEY_COLOR_FOOTER_BG   => 'color_footer_bg',
            self::KEY_COLOR_FOOTER_TEXT => 'color_footer_text',
        ];

        foreach ($colorKeys as $key => $postKey) {
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

        // Police de titres
        if (!empty($data['font_heading']) && isset(self::HEADING_FONT_OPTIONS[$data['font_heading']])) {
            $success = $success && $this->set(self::KEY_FONT_HEADING, $data['font_heading']);
        }

        // Border-radius bouton — valeurs autorisées : 0, 2, 6, 24
        if (isset($data['btn_radius'])) {
            $radius = (int) $data['btn_radius'];
            if (in_array($radius, [0, 2, 6, 24], true)) {
                $success = $success && $this->set(self::KEY_BTN_RADIUS, $radius);
            }
        }

        // Espacement interne des sections — entre 20 et 64px
        if (isset($data['section_padding'])) {
            $pad = (int) $data['section_padding'];
            $pad = max(16, min(64, $pad));
            $success = $success && $this->set(self::KEY_SECTION_PADDING, $pad);
        }

        // Espacement entre les blocs — entre 16 et 80px
        if (isset($data['block_spacing'])) {
            $sp = (int) $data['block_spacing'];
            $sp = max(16, min(80, $sp));
            $success = $success && $this->set(self::KEY_BLOCK_SPACING, $sp);
        }

        // Style du séparateur
        if (!empty($data['separator_style']) && in_array($data['separator_style'], ['none', 'line', 'dotted', 'double'], true)) {
            $success = $success && $this->set(self::KEY_SEPARATOR_STYLE, $data['separator_style']);
        }

        // Ombre de la carte email
        if (!empty($data['card_shadow']) && in_array($data['card_shadow'], ['none', 'soft', 'medium', 'strong'], true)) {
            $success = $success && $this->set(self::KEY_CARD_SHADOW, $data['card_shadow']);
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
        // Les clés POST correspondent aux scripts retournés par
        // FontManager::getAllScripts() ('chinese_simplified'/'chinese_traditional',
        // pas 'zh_simplified'/'zh_traditional') — typography.tpl construit
        // name="font_{$script}" à partir de ces mêmes clés.
        $fontKeys = [
            'font_latin'               => self::KEY_FONT_LATIN,
            'font_arabic'              => self::KEY_FONT_ARABIC,
            'font_japanese'            => self::KEY_FONT_JAPANESE,
            'font_korean'              => self::KEY_FONT_KOREAN,
            'font_chinese_simplified'  => self::KEY_FONT_ZH_SIMPLIFIED,
            'font_chinese_traditional' => self::KEY_FONT_ZH_TRADITIONAL,
            'font_cyrillic'            => self::KEY_FONT_CYRILLIC,
        ];

        foreach ($fontKeys as $postKey => $configKey) {
            if (!empty($data[$postKey])) {
                $success = $success && $this->set(
                    $configKey,
                    \Tools::safeOutput($data[$postKey])
                );
            }
        }

        // Taille de police corps — 12 à 16px
        if (isset($data['font_size'])) {
            $fs = (int) $data['font_size'];
            $fs = max(12, min(16, $fs));
            $success = $success && $this->set(self::KEY_FONT_SIZE, $fs);
        }

        // Interligne — 1.4 à 2.0
        if (isset($data['line_height'])) {
            $lh = round((float) $data['line_height'], 1);
            $lh = max(1.4, min(2.0, $lh));
            $success = $success && $this->set(self::KEY_LINE_HEIGHT, $lh);
        }

        // Poids titres — 400 / 600 / 700
        if (isset($data['heading_weight'])) {
            $hw = (int) $data['heading_weight'];
            if (in_array($hw, [400, 600, 700], true)) {
                $success = $success && $this->set(self::KEY_HEADING_WEIGHT, $hw);
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
     * Liste, parmi les variables personnalisées RÉELLEMENT utilisées par un
     * template donné (translations.json), celles qui n'ont ni valeur
     * persistée (Configurer → Variables personnalisées) ni valeur fournie
     * pour cet envoi précis via $overrideKeys — utilisé à la fois par
     * ManualSendManager (garde-fou avant envoi) et HealthCheckManager
     * (contrôle Watchdog #67, vision globale tous templates).
     *
     * @param string $template     Nom du template (ex. 'return_slip')
     * @param array  $overrideKeys Clés déjà normalisées (minuscules, [a-z0-9_])
     *                             fournies pour CET envoi (contentVars du BO)
     * @return string[] Clés manquantes (ex. ['return_deadline_days'])
     */
    public function findMissingCustomVarsForTemplate(string $template, array $overrideKeys = []): array
    {
        $jsonPath = _PS_MODULE_DIR_ . 'neria/data/translations.json';
        if (!is_file($jsonPath)) {
            return [];
        }

        $dict  = json_decode((string) file_get_contents($jsonPath), true);
        $block = is_array($dict) ? ($dict[$template] ?? null) : null;
        if (!is_array($block)) {
            return [];
        }

        $usedKeys = [];
        foreach ($block as $vals) {
            if (!is_array($vals)) {
                continue;
            }
            foreach ($vals as $val) {
                if (!is_string($val)) {
                    continue;
                }
                foreach (self::CUSTOM_VARIABLE_KEYS as $key) {
                    if (isset($usedKeys[$key])) {
                        continue;
                    }
                    if (strpos($val, '{' . $key . '}') !== false
                        || strpos($val, '{' . $key . '_html}') !== false
                        || strpos($val, '{' . $key . '_txt}') !== false
                    ) {
                        $usedKeys[$key] = true;
                    }
                }
            }
        }

        if (empty($usedKeys)) {
            return [];
        }

        $filled = [];
        foreach ($this->getCustomVariables() as $row) {
            if (trim((string) ($row['variable_value'] ?? '')) !== '') {
                $filled[$row['variable_key']] = true;
            }
        }

        $missing = [];
        foreach (array_keys($usedKeys) as $key) {
            if (in_array($key, $overrideKeys, true) || isset($filled[$key])) {
                continue;
            }
            $missing[] = $key;
        }

        return $missing;
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
        $success = true;

        foreach (self::CUSTOM_VARIABLE_KEYS as $varKey) {
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
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.logo_upload_rejected_mime', ['mime' => $mime]),
                '', 'ConfigManager'
            );
            return false;
        }

        // Taille max : 2 Mo
        if ($file['size'] > 2 * 1024 * 1024) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.logo_upload_rejected_size', ['size' => round($file['size'] / 1024)]),
                '', 'ConfigManager'
            );
            return false;
        }

        // Destination — l'extension est dérivée du MIME type validé
        // ci-dessus, JAMAIS du nom de fichier envoyé par le client :
        // un nom "logo.php" avec un contenu image valide (polyglotte
        // GIF/PNG contenant du code PHP) contournerait sinon le
        // contrôle MIME et permettrait un dépôt de webshell exécutable
        // dans data/signatures/ (dossier accessible publiquement).
        $extByMime = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        $uploadDir  = $this->module->getModulePath('data/signatures');
        $ext        = $extByMime[$mime];
        $filename   = 'logo_' . $this->idShop . '.' . $ext;
        $dest       = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.logo_upload_move_failed', ['dest' => $dest]),
                '', 'ConfigManager'
            );
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
     * Réinitialise UNIQUEMENT les champs de l'onglet Design (couleurs,
     * police de titre, bouton, espacement, séparateur, ombre) aux valeurs
     * neutres livrées avec le module. Volontairement plus restreint que
     * resetToDefaults() : ne touche ni au logo du marchand (KEY_LOGO_PATH),
     * ni aux réglages d'autres onglets (réseaux sociaux, A/B testing,
     * multi-expéditeur, typographie par script...).
     *
     * Réinitialise aussi KEY_DESIGN_WIZARD_SEEN : un retour au design
     * usine remet le marchand devant une ardoise vierge, donc l'assistant
     * de démarrage (bandeau "Nouveau sur Neria ?") redevient pertinent et
     * doit se réafficher.
     *
     * @return bool
     */
    public function resetDesignConfig(): bool
    {
        $designKeys = [
            self::KEY_COLOR_BACKGROUND,
            self::KEY_COLOR_CONTAINER,
            self::KEY_COLOR_ACCENT,
            self::KEY_COLOR_TEXT,
            self::KEY_BTN_COLOR,
            self::KEY_COLOR_HEADER_BG,
            self::KEY_COLOR_FOOTER_BG,
            self::KEY_COLOR_FOOTER_TEXT,
            self::KEY_DARK_MODE,
            self::KEY_CONTAINER_WIDTH,
            self::KEY_LOGO_WIDTH,
            self::KEY_FONT_HEADING,
            self::KEY_BTN_RADIUS,
            self::KEY_SECTION_PADDING,
            self::KEY_BLOCK_SPACING,
            self::KEY_SEPARATOR_STYLE,
            self::KEY_CARD_SHADOW,
            self::KEY_DESIGN_WIZARD_SEEN,
        ];

        $success = true;
        foreach ($designKeys as $key) {
            $success = $success && $this->set($key, self::DEFAULTS[$key]);
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
        // La signature active est stockée dans ps_neria_signature (is_active=1),
        // pas dans ps_configuration — c'est aussi ce que lit EmailRenderer::
        // resolveSignature() pour l'injection dans les emails. Les anciennes
        // clés NERIA_SIGNATURE_* n'étaient jamais écrites nulle part.
        $table = _DB_PREFIX_ . 'neria_signature';
        $row   = $this->db->getRow(
            "SELECT `signer_name`, `signer_title`, `font_style`, `color`, `image_path`
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop} AND `is_active` = 1"
        );

        if (!$row) {
            return [
                'style'         => 'great_vibes',
                'founder_name'  => '',
                'founder_title' => '',
                'color'         => $this->get(self::KEY_COLOR_ACCENT),
                'url'           => '',
            ];
        }

        return [
            'style'         => $row['font_style'],
            'founder_name'  => $row['signer_name'],
            'founder_title' => $row['signer_title'],
            'color'         => $row['color'],
            'url'           => !empty($row['image_path']) ? $this->module->getModuleUrl($row['image_path']) : '',
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