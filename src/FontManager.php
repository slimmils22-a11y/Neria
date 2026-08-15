<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — FontManager
 *
 * Gestionnaire des polices premium par langue.
 * Responsabilités :
 * — Catalogue des polices disponibles par famille d'écriture
 * — Génération des balises @font-face et liens Google Fonts
 * — Résolution de la police correcte selon la langue du destinataire
 * — Génération du bloc CSS inline pour les clients email
 * — Détection des polices système de secours (fallback stack)
 *
 * Pourquoi les polices sont critiques dans les emails :
 * Les clients email (Outlook, Apple Mail, Gmail) ont des règles
 * strictes sur les polices. Outlook n'accepte que les polices
 * système. Apple Mail et Gmail acceptent Google Fonts via @import.
 * Le fallback stack garantit un rendu élégant partout.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FontManager
{
    // ============================================================
    // CATALOGUE DES POLICES
    // Chaque police a : url Google Fonts, fallback stack, et les
    // langues qu'elle couvre
    // ============================================================

    const FONT_CATALOG = [

        // ── Polices latines ───────────────────────────────────────

        'Cormorant Garamond' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&display=swap',
            'css_family'  => "'Cormorant Garamond', Georgia, 'Times New Roman', serif",
            'fallback'    => "Georgia, 'Times New Roman', serif",
            'script'      => 'latin',
            'languages'   => ['fr','en','de','it','es','pt','br','gb','tr','sv','no','da','nl'],
            'description' => 'Élégante et raffinée — idéale pour le luxe',
        ],
        'EB Garamond' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap',
            'css_family'  => "'EB Garamond', 'Cormorant Garamond', Georgia, serif",
            'fallback'    => "Georgia, 'Times New Roman', serif",
            'script'      => 'latin',
            'languages'   => ['fr','en','de','it','es','pt','br','gb','tr','sv','no','da','nl'],
            'description' => 'Garamond classique — plus rond que Cormorant',
        ],
        'Playfair Display' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400&display=swap',
            'css_family'  => "'Playfair Display', Georgia, 'Times New Roman', serif",
            'fallback'    => "Georgia, 'Times New Roman', serif",
            'script'      => 'latin',
            'languages'   => ['fr','en','de','it','es','pt','br','gb','tr','sv','no','da','nl'],
            'description' => 'Majestueuse et contrastée',
        ],
        'Libre Baskerville' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&display=swap',
            'css_family'  => "'Libre Baskerville', Georgia, serif",
            'fallback'    => "Georgia, 'Times New Roman', serif",
            'script'      => 'latin',
            'languages'   => ['fr','en','de','it','es','pt','br','gb','tr','sv','no','da','nl'],
            'description' => 'Sobre et lisible',
        ],

        // ── Polices arabes ────────────────────────────────────────

        'Noto Naskh Arabic' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;500;600&display=swap',
            'css_family'  => "'Noto Naskh Arabic', 'Traditional Arabic', 'Arial Unicode MS', serif",
            'fallback'    => "'Traditional Arabic', 'Arial Unicode MS', serif",
            'script'      => 'arabic',
            'languages'   => ['ar'],
            'description' => 'Police arabe Naskh — élégante et lisible',
        ],
        'Scheherazade New' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Scheherazade+New:wght@400;500;600&display=swap',
            'css_family'  => "'Scheherazade New', 'Noto Naskh Arabic', 'Traditional Arabic', serif",
            'fallback'    => "'Traditional Arabic', serif",
            'script'      => 'arabic',
            'languages'   => ['ar'],
            'description' => 'Style calligraphique arabe classique',
        ],

        // ── Polices japonaises ────────────────────────────────────

        'Noto Serif JP' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Noto+Serif+JP:wght@300;400;500&display=swap',
            'css_family'  => "'Noto Serif JP', 'Hiragino Mincho Pro', 'Yu Mincho', serif",
            'fallback'    => "'Hiragino Mincho Pro', 'Yu Mincho', 'MS Mincho', serif",
            'script'      => 'japanese',
            'languages'   => ['ja'],
            'description' => 'Mincho japonaise — raffinée et lisible',
        ],
        'Shippori Mincho' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Shippori+Mincho:wght@400;500&display=swap',
            'css_family'  => "'Shippori Mincho', 'Noto Serif JP', 'Hiragino Mincho Pro', serif",
            'fallback'    => "'Hiragino Mincho Pro', 'Yu Mincho', serif",
            'script'      => 'japanese',
            'languages'   => ['ja'],
            'description' => 'Mincho japonaise traditionnelle premium',
        ],

        // ── Polices coréennes ─────────────────────────────────────

        'Noto Serif KR' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Noto+Serif+KR:wght@300;400;500&display=swap',
            'css_family'  => "'Noto Serif KR', Batang, 'Malgun Gothic', serif",
            'fallback'    => "Batang, 'Malgun Gothic', serif",
            'script'      => 'korean',
            'languages'   => ['ko'],
            'description' => 'Serif coréenne — formelle et élégante',
        ],
        'Nanum Myeongjo' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Nanum+Myeongjo:wght@400;700;800&display=swap',
            'css_family'  => "'Nanum Myeongjo', Batang, 'Malgun Gothic', serif",
            'fallback'    => "Batang, 'Malgun Gothic', serif",
            'script'      => 'korean',
            'languages'   => ['ko'],
            'description' => 'Serif coréenne traditionnelle — très lisible',
        ],

        // ── Polices chinoises simplifiées ─────────────────────────

        'Noto Serif SC' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Noto+Serif+SC:wght@300;400;500&display=swap',
            'css_family'  => "'Noto Serif SC', SimSun, 'Songti SC', serif",
            'fallback'    => "SimSun, 'Songti SC', serif",
            'script'      => 'chinese_simplified',
            'languages'   => ['zh'],
            'description' => 'Serif chinois simplifié',
        ],
        'ZCOOL XiaoWei' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=ZCOOL+XiaoWei&display=swap',
            'css_family'  => "'ZCOOL XiaoWei', SimSun, 'Songti SC', serif",
            'fallback'    => "SimSun, 'Songti SC', serif",
            'script'      => 'chinese_simplified',
            'languages'   => ['zh'],
            'description' => 'Serif moderne et épurée',
        ],

        // ── Polices chinoises traditionnelles ─────────────────────

        'Noto Serif TC' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@300;400;500&display=swap',
            'css_family'  => "'Noto Serif TC', PMingLiU, 'Apple LiSung', serif",
            'fallback'    => "PMingLiU, 'Apple LiSung', serif",
            'script'      => 'chinese_traditional',
            'languages'   => ['tw'],
            'description' => 'Serif chinois traditionnel',
        ],
        'Noto Serif HK' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Noto+Serif+HK:wght@300;400;500&display=swap',
            'css_family'  => "'Noto Serif HK', PMingLiU, 'Apple LiSung', serif",
            'fallback'    => "PMingLiU, 'Apple LiSung', serif",
            'script'      => 'chinese_traditional',
            'languages'   => ['tw'],
            'description' => 'Serif style Hong Kong — variante raffinée',
        ],

        // ── Polices cyrilliques ────────────────────────────────────

        'PT Serif' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=PT+Serif:ital,wght@0,400;0,700;1,400&display=swap',
            'css_family'  => "'PT Serif', Georgia, 'Times New Roman', serif",
            'fallback'    => "Georgia, 'Times New Roman', serif",
            'script'      => 'cyrillic',
            'languages'   => ['ru'],
            'description' => 'Serif russe classique — lisible et élégante',
        ],
        'Noto Serif' => [
            'google_url'  => 'https://fonts.googleapis.com/css2?family=Noto+Serif:ital,wght@0,400;0,600;1,400&display=swap',
            'css_family'  => "'Noto Serif', 'PT Serif', Georgia, serif",
            'fallback'    => "Georgia, 'Times New Roman', serif",
            'script'      => 'cyrillic',
            'languages'   => ['ru'],
            'description' => 'Universelle et sobre — couverture cyrillique complète',
        ],
    ];

    // ============================================================
    // POLICE PAR DÉFAUT PAR LANGUE
    // ============================================================

    const DEFAULT_FONT_BY_LANG = [
        'fr'  => 'Cormorant Garamond',
        'en'  => 'Cormorant Garamond',
        'de'  => 'Cormorant Garamond',
        'it'  => 'Cormorant Garamond',
        'es'  => 'Cormorant Garamond',
        'pt'  => 'Cormorant Garamond',
        'br'  => 'Cormorant Garamond',
        'gb'  => 'Cormorant Garamond',
        'tr'  => 'Cormorant Garamond',
        'sv'  => 'Cormorant Garamond',
        'no'  => 'Cormorant Garamond',
        'da'  => 'Cormorant Garamond',
        'nl'  => 'Cormorant Garamond',
        'ru'  => 'PT Serif',
        'ar'  => 'Noto Naskh Arabic',
        'ja'  => 'Noto Serif JP',
        'ko'  => 'Noto Serif KR',
        'zh'  => 'Noto Serif SC',
        'tw'  => 'Noto Serif TC',
    ];

    // ============================================================
    // PROPRIÉTÉS
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var ConfigManager Gestionnaire de configuration */
    private ConfigManager $config;

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->config = new ConfigManager($module);
    }

    // ============================================================
    // RÉSOLUTION DE LA POLICE
    // ============================================================

    /**
     * Retourne le nom de la police configurée pour une langue
     * Prend en compte la personnalisation du marchand
     *
     * @param string $lang Code langue Neria (ex: ja, ar, fr)
     * @return string Nom de la police (ex: 'Noto Serif JP')
     */
    public function getFontNameForLang(string $lang): string
    {
        // Police configurée par le marchand
        $configuredFamily = $this->config->getFontForLang($lang);

        // Cherche le nom court à partir de la css_family configurée.
        // Round 174 : un strpos() non ancré est ambigu entre un nom de
        // police et ses variantes régionales qui le préfixent (ex. 'Noto
        // Serif' est une sous-chaîne littérale de 'Noto Serif JP'/'KR'/
        // 'SC'/'TC'/'HK') — l'ordre de déclaration de FONT_CATALOG
        // déterminait alors silencieusement lequel gagnait, un piège de
        // maintenance si cet ordre change un jour. Tri par longueur de nom
        // DÉCROISSANTE avant la recherche : le nom le plus spécifique
        // (le plus long) est toujours testé en premier, indépendamment de
        // l'ordre de déclaration du catalogue.
        $namesByLengthDesc = array_keys(self::FONT_CATALOG);
        usort($namesByLengthDesc, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($namesByLengthDesc as $name) {
            if (strpos($configuredFamily, $name) !== false) {
                return $name;
            }
        }

        // Fallback : police par défaut de la langue
        return self::DEFAULT_FONT_BY_LANG[$lang] ?? 'Cormorant Garamond';
    }

    /**
     * Retourne la css_family complète pour une langue
     * C'est la valeur injectée dans le CSS des emails
     *
     * @param string $lang Code langue
     * @return string CSS font-family complet avec fallbacks
     */
    public function getCssFamilyForLang(string $lang): string
    {
        $fontName = $this->getFontNameForLang($lang);
        return self::FONT_CATALOG[$fontName]['css_family']
            ?? "Georgia, 'Times New Roman', serif";
    }

    /**
     * Retourne le stack de fallback uniquement (sans la police principale)
     * Utilisé dans le CSS Outlook (ne supporte pas les web fonts)
     *
     * @param string $lang Code langue
     * @return string CSS fallback font-family
     */
    public function getFallbackForLang(string $lang): string
    {
        $fontName = $this->getFontNameForLang($lang);
        return self::FONT_CATALOG[$fontName]['fallback']
            ?? "Georgia, 'Times New Roman', serif";
    }

    // ============================================================
    // GÉNÉRATION CSS
    // ============================================================

    /**
     * Génère le bloc CSS complet à injecter dans le <head> de l'email
     * Inclut :
     * — @import Google Fonts (pour Gmail, Apple Mail, etc.)
     * — Déclaration font-family sur body et éléments principaux
     * — Bloc conditionnel Outlook (MSO) avec police système
     *
     * @param string $lang Code langue du destinataire
     * @return string Bloc CSS complet
     */
    public function generateFontCss(string $lang): string
    {
        $fontName   = $this->getFontNameForLang($lang);
        $fontData   = self::FONT_CATALOG[$fontName] ?? null;

        if (!$fontData) {
            return '';
        }

        $cssFamily  = $fontData['css_family'];
        $fallback   = $fontData['fallback'];
        $googleUrl  = $fontData['google_url'];
        // sanitizeColor() en défense en profondeur : ConfigManager::
        // saveDesignConfig() valide déjà ce format à l'écriture (non
        // exploitable aujourd'hui via l'admin), mais sans ce filtre ici,
        // toute valeur qui atteindrait cette clé de config par un autre
        // chemin (import direct, script d'upgrade, accès DB) serait
        // injectée telle quelle dans du CSS sans second contrôle — même
        // garde-fou que sig_color ailleurs dans le module.
        $accentColor = NeriaTools::sanitizeColor(
            (string) $this->config->get(ConfigManager::KEY_COLOR_ACCENT),
            '#b38b59'
        );

        // Bloc CSS avec import Google Fonts + fallback Outlook
        $css = <<<CSS
        /* NERIA — Font Import */
        @import url('{$googleUrl}');

        /* Application de la police */
        body, table, td, p, a, li, blockquote {
            font-family: {$cssFamily} !important;
        }

        /* Fallback Outlook (MSO) — utilise uniquement les polices système */
        <!--[if mso]>
        <style type="text/css">
        body, table, td, p, a, li, blockquote {
            font-family: {$fallback} !important;
        }
        </style>
        <![endif]-->

        /* Liens avec couleur accent */
        a {
            color: {$accentColor};
        }
        CSS;

        return $css;
    }

    /**
     * Génère la balise <link> Google Fonts pour le <head> de l'email
     * Alternative à @import — meilleure compatibilité selon les clients
     *
     * @param string $lang Code langue
     * @return string Balise HTML <link>
     */
    public function generateGoogleFontsLink(string $lang): string
    {
        $fontName = $this->getFontNameForLang($lang);
        $fontData = self::FONT_CATALOG[$fontName] ?? null;

        if (!$fontData) {
            return '';
        }

        return sprintf(
            '<link href="%s" rel="stylesheet" type="text/css">',
            htmlspecialchars($fontData['google_url'], ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Génère les variables CSS Neria pour le layout.html
     * Injectées en tant que CSS custom properties dans le <head>
     *
     * @param string $lang Code langue
     * @return string Bloc <style> avec variables CSS
     */
    public function generateCssVariables(string $lang): string
    {
        $design    = $this->config->getDesignConfig();
        $cssFamily = $this->getCssFamilyForLang($lang);
        $fallback  = $this->getFallbackForLang($lang);

        return sprintf(
            '<style type="text/css">
                :root {
                    --neria-font-family:      %s;
                    --neria-font-fallback:    %s;
                    --neria-color-bg:         %s;
                    --neria-color-container:  %s;
                    --neria-color-accent:     %s;
                    --neria-color-text:       %s;
                    --neria-container-width:  %dpx;
                }
            </style>',
            $cssFamily,
            $fallback,
            // Défense en profondeur (comme generateFontCss() ci-dessus pour
            // accentColor) : ces 4 couleurs proviennent de la config design
            // déjà validée à l'écriture par ConfigManager::saveDesignConfig(),
            // mais aucun filtre n'existait ici en lecture — incohérence avec
            // le traitement voisin dans ce même fichier.
            //
            // Round 159 : le 2e argument (repli) était omis ici,
            // contrairement à generateFontCss() qui passe explicitement le
            // vrai défaut de marque (#b38b59) — sanitizeColor() retombe
            // alors sur son défaut interne générique #000000. Si une
            // couleur corrompue atteignait la config hors admin (import,
            // script d'upgrade, accès DB direct — exactement le scénario
            // que ce garde-fou vise), les 4 couleurs retombaient TOUTES sur
            // le noir au lieu des vraies valeurs de marque
            // (ConfigManager::DEFAULTS) : fond noir + texte noir rendait le
            // contenu de l'email invisible, au lieu de conserver
            // l'esthétique définie par les vrais défauts.
            \NeriaTools::sanitizeColor((string) $design['color_background'], \ConfigManager::DEFAULTS[\ConfigManager::KEY_COLOR_BACKGROUND]),
            \NeriaTools::sanitizeColor((string) $design['color_container'], \ConfigManager::DEFAULTS[\ConfigManager::KEY_COLOR_CONTAINER]),
            \NeriaTools::sanitizeColor((string) $design['color_accent'], \ConfigManager::DEFAULTS[\ConfigManager::KEY_COLOR_ACCENT]),
            \NeriaTools::sanitizeColor((string) $design['color_text'], \ConfigManager::DEFAULTS[\ConfigManager::KEY_COLOR_TEXT]),
            $design['container_width']
        );
    }

    // ============================================================
    // CATALOGUE POUR LE BACK-OFFICE
    // ============================================================

    /**
     * Retourne les polices disponibles pour un script donné
     * Utilisé par typography.tpl pour construire les sélecteurs
     *
     * @param string $script Famille d'écriture (latin, arabic, japanese, etc.)
     * @return array ['Nom Police' => ['css_family' => ..., 'description' => ...]]
     */
    public function getFontsForScript(string $script): array
    {
        $fonts = [];

        foreach (self::FONT_CATALOG as $name => $data) {
            if ($data['script'] === $script) {
                $fonts[$name] = [
                    'css_family'  => $data['css_family'],
                    'google_url'  => $data['google_url'],
                    'description' => $data['description'],
                ];
            }
        }

        return $fonts;
    }

    /**
     * Retourne tous les scripts disponibles avec leurs langues
     * Utilisé par typography.tpl pour organiser les onglets
     *
     * @return array
     */
    public function getAllScripts(): array
    {
        return [
            'latin'              => [
                'label'     => 'Latin',
                'languages' => ['fr','en','de','it','es','pt','br','gb','tr','sv','no','da','nl'],
            ],
            'arabic'             => [
                'label'     => 'Arabe',
                'languages' => ['ar'],
            ],
            'japanese'           => [
                'label'     => 'Japonais',
                'languages' => ['ja'],
            ],
            'korean'             => [
                'label'     => 'Coréen',
                'languages' => ['ko'],
            ],
            'chinese_simplified' => [
                'label'     => 'Chinois simplifié',
                'languages' => ['zh'],
            ],
            'chinese_traditional' => [
                'label'     => 'Chinois traditionnel',
                'languages' => ['tw'],
            ],
            'cyrillic'           => [
                'label'     => 'Cyrillique',
                'languages' => ['ru'],
            ],
        ];
    }

    /**
     * Retourne toutes les polices disponibles pour le back-office
     * Format plat — utilisé pour l'aperçu en temps réel
     *
     * @return array
     */
    public function getAllFonts(): array
    {
        return self::FONT_CATALOG;
    }

    /**
     * Retourne la police par défaut d'une langue
     *
     * @param string $lang Code langue
     * @return string Nom de la police par défaut
     */
    public function getDefaultFontForLang(string $lang): string
    {
        return self::DEFAULT_FONT_BY_LANG[$lang] ?? 'Cormorant Garamond';
    }
}