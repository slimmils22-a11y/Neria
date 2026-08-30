<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — NeriaTools
 *
 * Boite a outils du module — fonctions utilitaires transversales.
 * Utilisees par toutes les autres classes src/ sans dependance circulaire.
 *
 * Responsabilites :
 * — Nettoyage et validation des donnees
 * — Formatage des textes et des nombres
 * — Helpers pour les templates email
 * — Diagnostic et sante du module
 * — Utilitaires de securite
 * — Helpers pour le back-office
 *
 * Toutes les methodes sont statiques — pas d'instanciation requise.
 * Appel direct : NeriaTools::sanitizeHtml($str)
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaTools
{
    // ============================================================
    // NETTOYAGE ET VALIDATION
    // ============================================================

    /**
     * Nettoie un texte pour injection dans un email HTML
     * Conserve les balises HTML autorisees (gras, italique, liens)
     * Supprime tout le reste pour eviter les injections
     *
     * @param string $text Texte brut potentiellement dangereux
     * @return string Texte nettoye
     */
    public static function sanitizeHtml(string $text): string
    {
        $allowed = '<b><strong><i><em><u><br><p><span><a>';
        $text = strip_tags($text, $allowed);

        // strip_tags() ne retire QUE les balises non autorisées, jamais les
        // attributs des balises conservées — un <a href="javascript:..."> ou
        // <span onmouseover="..."> passait intégralement à travers malgré le
        // nom de la fonction. On retire tout attribut event handler (on*=)
        // sur toutes les balises, et on ne garde sur <a> que href pointant
        // vers un schéma sûr (http(s)/mailto) ; tout autre attribut/schéma
        // est neutralisé.
        $text = (string) preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $text);
        $text = (string) preg_replace_callback(
            '/<a\b[^>]*>/i',
            static function (array $m): string {
                if (preg_match('/href\s*=\s*(["\'])((?:https?:|mailto:)[^"\']*)\1/i', $m[0], $href)) {
                    // Round 255 : $href[2] était réinjecté SANS échappement.
                    // La classe de caractères [^"\'] du regex ci-dessus exclut
                    // déjà tout guillemet de la valeur capturée (donc pas de
                    // sortie d'attribut possible par ce biais), et tout
                    // attribut hors href (ex: onmouseover=) est de toute façon
                    // éliminé par la reconstruction minimale ci-dessous -- ce
                    // n'est PAS un contournement d'attribut exploitable tel
                    // quel. En revanche, une URL contenant un '&' (fréquent en
                    // query string, ex: "?a=1&b=2") produisait un attribut
                    // HTML invalide (un '&' brut hors entité n'est pas
                    // strictement conforme dans un attribut HTML) -- durci en
                    // défense en profondeur avec htmlspecialchars(), y compris
                    // si la logique de reconstruction ci-dessus change un jour.
                    return '<a href="' . htmlspecialchars($href[2], ENT_QUOTES, 'UTF-8') . '">';
                }
                return '<a>';
            },
            $text
        );

        return $text;
    }

    /**
     * Nettoie un texte pour injection dans un email TXT
     * Supprime toutes les balises HTML
     *
     * @param string $text Texte HTML
     * @return string Texte brut sans HTML
     */
    public static function sanitizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }

    /**
     * Valide un code langue Neria
     *
     * @param string $lang Code langue a valider
     * @return bool
     */
    public static function isValidLang(string $lang): bool
    {
        return in_array($lang, [
            'fr','en','de','it','es','pt','br','gb',
            'ar','ja','ko','zh','tw',
            'ru','tr','sv','no','da','nl',
        ], true);
    }

    /**
     * Valide un nom de template email
     * Uniquement lettres minuscules, chiffres et underscores
     *
     * @param string $template Nom du template
     * @return bool
     */
    public static function isValidTemplate(string $template): bool
    {
        return (bool) preg_match('/^[a-z0-9_]{1,100}$/', $template);
    }

    /**
     * Valide une cle de traduction
     *
     * @param string $key Cle a valider
     * @return bool
     */
    public static function isValidTranslationKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z0-9_]{1,150}$/', $key);
    }

    /**
     * Valide et nettoie une couleur hexadecimale
     * Accepte #fff et #ffffff — retourne #000000 si invalide
     *
     * @param string $color  Couleur brute
     * @param string $default Valeur par defaut si invalide
     * @return string Couleur normalisee en 6 caracteres
     */
    public static function sanitizeColor(
        string $color,
        string $default = '#000000'
    ): string {
        $color = trim($color);

        if (substr($color, 0, 1) !== '#') {
            $color = '#' . $color;
        }

        // Format court #abc → #aabbcc
        if (preg_match('/^#([0-9a-fA-F]{3})$/', $color, $m)) {
            $color = '#'
                . str_repeat($m[1][0], 2)
                . str_repeat($m[1][1], 2)
                . str_repeat($m[1][2], 2);
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $default;
        }

        return strtolower($color);
    }

    /**
     * Valide et nettoie une URL
     *
     * @param string $url URL brute
     * @return string URL nettoyee ou chaine vide si invalide
     */
    public static function sanitizeUrl(string $url): string
    {
        $url = trim($url);

        if (empty($url)) {
            return '';
        }

        // Ajoute https:// si manquant
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        $filtered = filter_var($url, FILTER_VALIDATE_URL);
        return $filtered ?: '';
    }

    // ============================================================
    // FORMATAGE DES TEXTES
    // ============================================================

    /**
     * Tronque un texte a une longueur donnee en preservant les mots
     *
     * @param string $text   Texte a tronquer
     * @param int    $length Longueur maximale
     * @param string $suffix Suffixe (defaut: '...')
     * @return string
     */
    public static function truncate(
        string $text,
        int    $length = 100,
        string $suffix = '...'
    ): string {
        $text = strip_tags($text);

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        // max(0, ...) : si $length est inférieur ou égal à la longueur du
        // suffixe, $length - mb_strlen($suffix) devient négatif — mb_substr()
        // avec une longueur négative ne tronque pas "à 0 caractère moins N"
        // comme on pourrait le croire, mais retire les N DERNIERS caractères
        // du texte, produisant l'effet inverse de celui recherché (texte
        // quasiment pas tronqué au lieu d'être coupé très court).
        $truncated = mb_substr($text, 0, max(0, $length - mb_strlen($suffix)));
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . $suffix;
    }

    /**
     * Convertit les sauts de ligne en balises <br>
     * Utilisé pour les champs texte multiligne dans les emails
     *
     * @param string $text Texte avec sauts de ligne
     * @return string Texte avec <br>
     */
    public static function nl2brSafe(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Formate une date pour l'affichage dans un email
     * Adapte le format selon la langue
     *
     * @param string $date     Date au format Y-m-d, chaîne relative (ex: 'now', '+7 days') ou timestamp
     * @param string $lang     Langue cible
     * @param bool   $withTime Ajoute l'heure (H:i, 24h) après la date localisée
     * @return string Date formatee
     */
    public static function formatDate(string $date, string $lang = 'fr', bool $withTime = false): string
    {
        $ts = is_numeric($date) ? (int) $date : strtotime($date);

        // Round 173 : `!$ts` était vrai pour un timestamp epoch valide (0 =
        // 1970-01-01T00:00:00Z), confondant une date réelle avec un échec
        // de parsing — distingue désormais explicitement l'échec de
        // strtotime() (false) d'un timestamp 0 légitime.
        if ($ts === false) {
            return $date;
        }

        $formats = [
            'fr' => 'd/m/Y',
            'en' => 'm/d/Y',
            'de' => 'd.m.Y',
            'it' => 'd/m/Y',
            'es' => 'd/m/Y',
            'pt' => 'd/m/Y',
            'br' => 'd/m/Y',
            'gb' => 'd/m/Y',
            'ar' => 'd/m/Y',
            'ja' => 'Y年m月d日',
            'ko' => 'Y년 m월 d일',
            'zh' => 'Y年m月d日',
            'tw' => 'Y年m月d日',
            'ru' => 'd.m.Y',
            'tr' => 'd.m.Y',
            'sv' => 'Y-m-d',
            'no' => 'd.m.Y',
            'da' => 'd.m.Y',
            'nl' => 'd-m-Y',
        ];

        $format = $formats[$lang] ?? 'd/m/Y';
        if ($withTime) {
            $format .= ' H:i';
        }
        return date($format, $ts);
    }

    // ============================================================
    // HELPERS EMAILS
    // ============================================================

    /**
     * Convertit un template HTML en version texte brut
     * Utilisé pour generer les .txt depuis les .html
     *
     * @param string $html Contenu HTML
     * @return string Texte brut
     */
    public static function htmlToText(string $html): string
    {
        // Remplace les balises de structure par des sauts de ligne
        $text = preg_replace(
            ['/<br\s*\/?>/i', '/<\/p>/i', '/<\/div>/i', '/<\/tr>/i', '/<\/h[1-6]>/i'],
            ["\n", "\n\n", "\n", "\n", "\n\n"],
            $html
        );

        // Remplace les liens par leur texte + URL
        $text = preg_replace(
            '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i',
            '$2 ($1)',
            $text
        );

        // Supprime toutes les balises restantes
        $text = strip_tags($text);

        // Decode les entites HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Nettoie les espaces multiples et lignes vides excessives
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Genere un sujet d'email localise
     * Utilise le nom de la boutique comme prefixe
     *
     * @param string $subject  Sujet de base
     * @param string $shopName Nom de la boutique
     * @return string Sujet formate
     */
    public static function buildSubject(
        string $subject,
        string $shopName = ''
    ): string {
        if (empty($shopName)) {
            $shopName = \Configuration::get('PS_SHOP_NAME');
        }

        return empty($subject)
            ? $shopName
            : $shopName . ' — ' . $subject;
    }

    /**
     * Verifie si un email est valide
     *
     * @param string $email Adresse email
     * @return bool
     */
    public static function isValidEmail(string $email): bool
    {
        return (bool) filter_var(trim($email), FILTER_VALIDATE_EMAIL);
    }

    // ============================================================
    // DIAGNOSTIC ET SANTE DU MODULE
    // ============================================================

    /**
     * Retourne un rapport complet de l'etat du module
     * Affiche dans l'onglet Aide → section Diagnostic
     *
     * @param Neria $module Instance du module
     * @return array
     */
    public static function getDiagnosticReport(Neria $module): array
    {
        $report = [];

        // ── PHP ───────────────────────────────────────────────────
        $report['php'] = [
            'version'        => PHP_VERSION,
            'version_ok'     => version_compare(PHP_VERSION, '8.0.0', '>='),
            'gd_available'   => extension_loaded('gd'),
            'mbstring'       => extension_loaded('mbstring'),
            'json'           => extension_loaded('json'),
            'openssl'        => extension_loaded('openssl'),
        ];

        // ── PrestaShop ────────────────────────────────────────────
        $report['prestashop'] = [
            'version'        => _PS_VERSION_,
            'version_ok'     => version_compare(_PS_VERSION_, '8.0.0', '>='),
            'module_active'  => (bool) \Configuration::get('NERIA_ACTIVE'),
        ];

        // ── Base de donnees ───────────────────────────────────────
        $db     = \Db::getInstance();
        $tables = [
            'neria_translation',
            'neria_config',
            'neria_custom_variable',
            'neria_signature',
            'neria_abtest',
            'neria_abtest_translation',
            'neria_stat',
            'neria_calendar_event',
        ];

        $report['database'] = [];
        foreach ($tables as $table) {
            $fullTable = _DB_PREFIX_ . $table;
            // Round 216 : $use_cache=false sur les 2 lectures — sans lui,
            // le rapport de diagnostic pourrait afficher "table absente"/
            // "0 ligne" pour une table réellement présente et non vide,
            // trompeur pour le marchand ou le support consultant ce
            // diagnostic.
            $exists    = (bool) $db->getValue(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '" . pSQL($fullTable) . "'",
                false
            );

            $count = 0;
            if ($exists) {
                $count = (int) $db->getValue(
                    "SELECT COUNT(*) FROM `{$fullTable}`",
                    false
                );
            }

            $report['database'][$table] = [
                'exists' => $exists,
                'rows'   => $count,
            ];
        }

        // ── Traductions ───────────────────────────────────────────
        $translationCount = (int) $db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_translation`"
        );

        $report['translations'] = [
            'total_rows'     => $translationCount,
            'expected_min'   => 5000,
            'ok'             => $translationCount >= 5000,
        ];

        // ── Fichiers ──────────────────────────────────────────────
        $modulePath = $module->getModulePath();

        $files = [
            'translations.json' => 'data/translations.json',
            'calendar.json'     => 'data/calendar.json',
            'install.sql'       => 'sql/install.sql',
            'uninstall.sql'     => 'sql/uninstall.sql',
        ];

        $report['files'] = [];
        foreach ($files as $label => $relativePath) {
            $fullPath = $modulePath . '/' . $relativePath;
            $report['files'][$label] = [
                'path'   => $relativePath,
                'exists' => file_exists($fullPath),
                'size'   => file_exists($fullPath)
                    ? self::formatFileSize(filesize($fullPath))
                    : 0,
            ];
        }

        // ── Polices TTF ───────────────────────────────────────────
        $fontFiles = [
            'GreatVibes-Regular.ttf',
            'DancingScript-Regular.ttf',
            'Sacramento-Regular.ttf',
            'PinyonScript-Regular.ttf',
            'Pacifico-Regular.ttf',
        ];

        $report['fonts'] = [];
        foreach ($fontFiles as $font) {
            $path = $modulePath . '/data/fonts/' . $font;
            $report['fonts'][$font] = file_exists($path);
        }

        // ── Hooks ─────────────────────────────────────────────────
        $hooks = Neria::HOOKS;
        $report['hooks'] = [];
        foreach ($hooks as $hook) {
            $report['hooks'][$hook] = (bool) \Hook::getIdByName($hook)
                && $module->isRegisteredInHook($hook);
        }

        // ── Permissions dossiers ──────────────────────────────────
        $dirs = [
            'data/'            => $modulePath . '/data',
            'data/fonts/'      => $modulePath . '/data/fonts',
            'data/signatures/' => $modulePath . '/data/signatures',
        ];

        $report['permissions'] = [];
        foreach ($dirs as $label => $path) {
            $report['permissions'][$label] = [
                'exists'   => is_dir($path),
                'writable' => is_writable($path),
            ];
        }

        // ── Score global ──────────────────────────────────────────
        $report['score'] = self::computeDiagnosticScore($report);

        return $report;
    }

    /**
     * Calcule un score de sante du module sur 100
     *
     * @param array $report Rapport de diagnostic
     * @return array ['score' => int, 'status' => string]
     */
    private static function computeDiagnosticScore(array $report): array
    {
        $points = 0;
        $max    = 0;

        // PHP OK (+20)
        $max += 20;
        if ($report['php']['version_ok'])   $points += 10;
        if ($report['php']['gd_available']) $points += 5;
        if ($report['php']['mbstring'])     $points += 5;

        // PS OK (+10)
        $max += 10;
        if ($report['prestashop']['version_ok'])   $points += 5;
        if ($report['prestashop']['module_active']) $points += 5;

        // Tables BDD (+20)
        $max += 20;
        $dbCount = count($report['database']);
        if ($dbCount > 0) {
            foreach ($report['database'] as $data) {
                if ($data['exists']) {
                    // Pas d'arrondi par item : arrondir ici gonfle le score des
                    // categories partiellement completes (ex: round(20/8)=3,
                    // 8*3=24 > 20 si les 8 tables existent, et surestime aussi
                    // les cas partiels comme 4/8 -> 12 pts au lieu de 10).
                    $points += 20 / $dbCount;
                }
            }
        }

        // Traductions (+20)
        $max += 20;
        if ($report['translations']['ok']) $points += 20;

        // Fichiers (+15)
        $max += 15;
        $filesCount = count($report['files']);
        if ($filesCount > 0) {
            foreach ($report['files'] as $file) {
                if ($file['exists']) {
                    $points += 15 / $filesCount;
                }
            }
        }

        // Hooks (+15)
        $max += 15;
        $hooksCount = count($report['hooks']);
        if ($hooksCount > 0) {
            foreach ($report['hooks'] as $registered) {
                if ($registered) {
                    $points += 15 / $hooksCount;
                }
            }
        }

        $score = $max > 0 ? min(100, (int) round(($points / $max) * 100)) : 0;

        $status = match (true) {
            $score >= 90 => 'excellent',
            $score >= 70 => 'good',
            $score >= 50 => 'warning',
            default      => 'critical',
        };

        return ['score' => $score, 'status' => $status];
    }

    // ============================================================
    // HELPERS BACK-OFFICE
    // ============================================================

    /**
     * Retourne les noms lisibles des langues Neria
     * Utilise dans tous les selecteurs du back-office
     *
     * @return array ['fr' => 'Français', 'en' => 'English', ...]
     */
    public static function getLangLabels(): array
    {
        return [
            'fr' => 'Français',
            'en' => 'English (US)',
            'de' => 'Deutsch',
            'it' => 'Italiano',
            'es' => 'Español',
            'pt' => 'Português (PT)',
            'br' => 'Português (BR)',
            'gb' => 'English (GB)',
            'ar' => 'العربية',
            'ja' => '日本語',
            'ko' => '한국어',
            'zh' => '中文 (简体)',
            'tw' => '中文 (繁體)',
            'ru' => 'Русский',
            'tr' => 'Türkçe',
            'sv' => 'Svenska',
            'no' => 'Norsk',
            'da' => 'Dansk',
            'nl' => 'Nederlands',
        ];
    }

    /**
     * Retourne les noms lisibles des templates email
     * Utilise dans les selecteurs du back-office
     *
     * @return array ['order_conf' => 'Confirmation de commande', ...]
     */
    public static function getTemplateLabels(): array
    {
        return [
            'abandoned_cart_1'               => 'Panier abandonné — Relance 1',
            'abandoned_cart_2'               => 'Panier abandonné — Relance 2',
            'abandoned_cart_3'               => 'Panier abandonné — Relance 3',
            'account'                        => 'Création de compte',
            'alteration_update'              => 'Mise à jour retouche',
            'artisan_message'                => 'Message artisan',
            'backoffice_order'               => 'Nouvelle commande (admin)',
            'bankwire'                       => 'Virement bancaire',
            'bespoke_ready'                  => 'Sur-mesure prêt',
            'birthday'                       => 'Anniversaire client',
            'black_friday'                   => 'Black Friday',
            'care_certificate'               => 'Certificat d\'entretien',
            'certificate_email'              => 'Certificat d\'authenticité (envoi)',
            'certificate_provenance'         => 'Certificat de provenance',
            'checkout_abandonment'           => 'Abandon de caisse',
            'cheque'                         => 'Paiement par chèque',
            'christmas'                      => 'Email de Noël',
            'collection_completion'          => 'Complétion de collection',
            'complete_your_look'             => 'Complétez votre look',
            'concierge_followup'             => 'Suivi conciergerie',
            'contact'                        => 'Copie message de contact',
            'contact_form'                   => 'Formulaire de contact',
            'corporate_order_confirm'        => 'Confirmation commande entreprise',
            'craftsmanship_update'           => 'Mise à jour artisanale',
            'credit_slip'                    => 'Avoir',
            'customer_qty'                   => 'Quantité client',
            'customs_alert'                  => 'Alerte douane',
            'delivered'                      => 'Commande livrée',
            'delivery_attempt_failed'        => 'Tentative de livraison échouée',
            'diwali'                         => 'Email Diwali',
            'download_product'               => 'Produit téléchargeable',
            'early_access'                   => 'Accès anticipé',
            'eid'                            => 'Email Eid',
            'employee_password'              => 'Mot de passe employé (admin)',
            'end_of_year_gift'               => 'Cadeau fin d\'année',
            'exclusive_preview'              => 'Avant-première exclusive',
            'extended_warranty'              => 'Garantie étendue',
            'fathers_day'                    => 'Fête des pères',
            'first_anniversary'              => 'Premier anniversaire client',
            'relationship_anniversary'       => 'Anniversaire de la relation client',
            'forward_msg'                    => 'Message transféré (admin)',
            'ghost_cart'                     => 'Panier fantôme',
            'gift_guarantee'                 => 'Garantie cadeau',
            'gift_ideas'                     => 'Idées cadeaux',
            'gift_message_confirm'           => 'Confirmation message cadeau',
            'grandparents_day'               => 'Fête des grands-parents',
            'guest_to_customer'              => 'Invité → Client',
            'halloween'                      => 'Email Halloween',
            'hanukkah'                       => 'Email Hanoukka',
            'import'                         => 'Import (admin)',
            'in_transit'                     => 'En transit',
            'log_alert'                      => 'Alerte log (admin)',
            'loyalty_recap'                  => 'Récap fidélité mensuel',
            'loyalty_reward_expiry'          => 'Expiration récompense fidélité',
            'loyalty_tier_upgrade'           => 'Upgrade niveau fidélité',
            'lunar_new_year'                 => 'Nouvel An lunaire',
            'milestone_order'                => 'Commande anniversaire',
            'mothers_day'                    => 'Fête des mères',
            'neria_fallback'                 => 'Email de secours (fallback)',
            'new_order'                      => 'Nouvelle commande (admin)',
            'new_year'                       => 'Nouvel An',
            'newsletter'                     => 'Newsletter',
            'newsletter_conf'                => 'Confirmation newsletter',
            'newsletter_verif'               => 'Vérification newsletter',
            'newsletter_voucher'             => 'Bon newsletter',
            'order_canceled'                 => 'Annulation de commande',
            'order_changed'                  => 'Modification de commande',
            'order_conf'                     => 'Confirmation de commande',
            'order_customer_comment'         => 'Commentaire client',
            'order_merchant_comment'         => 'Commentaire marchand',
            'order_on_hold'                  => 'Commande en attente',
            'order_partial_shipped'          => 'Expédition partielle',
            'order_return_state'             => 'État du retour',
            'order_shipped_delay'            => 'Retard d\'expédition',
            'outofstock'                     => 'Rupture de stock',
            'packaging_choice'               => 'Choix d\'emballage',
            'password'                       => 'Nouveau mot de passe',
            'password_query'                 => 'Réinitialisation mot de passe',
            'payment'                        => 'Paiement accepté',
            'payment_error'                  => 'Erreur de paiement',
            'personal_shopper_intro'         => 'Introduction personal shopper',
            'post_purchase_care'             => 'Entretien post-achat',
            'post_purchase_review'           => 'Demande d\'avis',
            'preparation'                    => 'Commande en préparation',
            'private_invitation'             => 'Invitation privée',
            'private_sale'                   => 'Vente privée',
            'product_recall'                 => 'Rappel produit',
            'productcoverage'                => 'Couverture produit (admin)',
            'productoutofstock'              => 'Rupture de stock (admin)',
            'quote_expiry_48h'               => 'Relance devis — J-48h',
            'quote_expiry_day'               => 'Relance devis — jour J',
            'quote_extension_offer'          => 'Offre de prolongation de devis',
            'ramadan'                        => 'Email Ramadan',
            'refund'                         => 'Remboursement',
            'refund_reconciliation_1'        => 'Réconciliation post-remboursement — J+1',
            'refund_reconciliation_2'        => 'Réconciliation post-remboursement — J+3',
            'refund_reconciliation_3'        => 'Réconciliation post-remboursement — J+7',
            'product_lifespan_reminder'      => 'Rappel fin de vie produit',
            'refund_processed'               => 'Remboursement traité',
            'reorder_reminder'               => 'Rappel de réachat',
            'repair_completed'               => 'Réparation terminée',
            'repair_request_confirm'         => 'Confirmation demande de réparation',
            'reply_msg'                      => 'Réponse message',
            'return_received'                => 'Retour reçu',
            'return_slip'                    => 'Bon de retour',
            'shipped'                        => 'Expédition',
            'tax_refund_eligible'            => 'Éligible remboursement taxes',
            'test'                           => 'Email de test',
            'unboxing_guide'                 => 'Guide de déballage',
            'valentine'                      => 'Saint-Valentin',
            'vip'                            => 'Programme VIP',
            'voucher'                        => 'Bon de réduction',
            'voucher_new'                    => 'Nouveau bon de réduction',
            'waitlist_available'             => 'Produit disponible (liste d\'attente)',
            'white_glove_apology'            => 'Excuse White Glove',
            'win_back'                       => 'Reconquête client',
            'wishlist_reminder'              => 'Rappel liste de souhaits',
        ];
    }

    /**
     * Retourne le label lisible d'un template
     *
     * @param string $template Cle du template
     * @return string Label lisible
     */
    public static function getTemplateLabel(string $template): string
    {
        $labels = self::getTemplateLabels();
        return $labels[$template] ?? ucwords(str_replace('_', ' ', $template));
    }

    /**
     * Retourne les drapeaux emoji par langue
     * Utilises dans les selecteurs du back-office
     *
     * @return array ['fr' => '🇫🇷', ...]
     */
    public static function getLangFlags(): array
    {
        return [
            'fr' => '🇫🇷',
            'en' => '🇺🇸',
            'de' => '🇩🇪',
            'it' => '🇮🇹',
            'es' => '🇪🇸',
            'pt' => '🇵🇹',
            'br' => '🇧🇷',
            'gb' => '🇬🇧',
            'ar' => '🇸🇦',
            'ja' => '🇯🇵',
            'ko' => '🇰🇷',
            'zh' => '🇨🇳',
            'tw' => '🇹🇼',
            'ru' => '🇷🇺',
            'tr' => '🇹🇷',
            'sv' => '🇸🇪',
            'no' => '🇳🇴',
            'da' => '🇩🇰',
            'nl' => '🇳🇱',
        ];
    }

    // ============================================================
    // UTILITAIRES DIVERS
    // ============================================================

    /**
     * Formate une taille de fichier en human-readable
     *
     * @param int $bytes Taille en octets
     * @return string Ex: '823 Ko', '1.2 Mo'
     */
    public static function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' o';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' Ko';
        }

        return round($bytes / (1024 * 1024), 1) . ' Mo';
    }

    /**
     * Genere un identifiant unique pour une session Neria
     * Utilise pour les tokens temporaires et les nonces
     *
     * @param int $length Longueur du token (defaut: 32)
     * @return string Token hexadecimal
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Clé de signature stable pour les liens de clic trackés (HMAC).
     *
     * Réutilise NERIA_ENCRYPTION_KEY si disponible (générée par CryptoManager,
     * 32 octets aléatoires), sinon retombe sur _COOKIE_KEY_/_NEW_COOKIE_KEY_
     * (toujours présentes dans une install PrestaShop) pour ne jamais
     * retourner de clé vide.
     *
     * Round 155 : l'ancien dernier repli était la chaîne littérale
     * 'neria-fallback-static-key', visible dans le code source du module —
     * quiconque connaît ce code (module open, décompilé, ancien commit
     * public) pouvait forger des signatures valides et casser entièrement
     * la protection anti-open-redirect que signTrackingUrl()/
     * verifyTrackingUrl() sont censées fournir. Remplacé par une clé
     * aléatoire générée et persistée une seule fois (auto-réparation,
     * jamais prévisible), au lieu d'un secret connu à l'avance.
     */
    private static function trackingSignKey(): string
    {
        $hex = (string) \Configuration::get('NERIA_ENCRYPTION_KEY');
        if (strlen($hex) === 64 && ($bin = @hex2bin($hex)) !== false) {
            return $bin;
        }

        if (defined('_NEW_COOKIE_KEY_') && _NEW_COOKIE_KEY_ !== '') {
            return _NEW_COOKIE_KEY_;
        }
        if (defined('_COOKIE_KEY_') && _COOKIE_KEY_ !== '') {
            return _COOKIE_KEY_;
        }

        $fallbackHex = (string) \Configuration::get('NERIA_TRACKING_FALLBACK_KEY');
        if (strlen($fallbackHex) === 64 && ($bin = @hex2bin($fallbackHex)) !== false) {
            return $bin;
        }

        $newBin = random_bytes(32);
        \Configuration::updateValue('NERIA_TRACKING_FALLBACK_KEY', bin2hex($newBin));

        return $newBin;
    }

    /**
     * Signe (HMAC-SHA256) le couple token+URL d'un lien de clic tracké.
     *
     * Empêche un open redirect : sans cette signature, n'importe quel token
     * de tracking valide (par ex. reçu légitimement par l'attaquant lui-même
     * dans un email qui lui est destiné) pourrait être combiné à n'importe
     * quelle URL externe pour forger un lien de phishing hébergé sur le
     * domaine de confiance de la boutique. La signature lie le token à
     * l'URL précise qui a été effectivement injectée dans CET email.
     */
    public static function signTrackingUrl(string $token, string $url): string
    {
        return hash_hmac('sha256', $token . '|' . $url, self::trackingSignKey());
    }

    /**
     * Vérifie la signature d'un lien de clic tracké (comparaison à temps
     * constant).
     */
    public static function verifyTrackingUrl(string $token, string $url, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals(self::signTrackingUrl($token, $url), $signature);
    }

    /**
     * Retourne le temps ecoule depuis une date en format lisible
     * Ex: "il y a 3 jours", "il y a 2 heures"
     *
     * @param string $date Date au format Y-m-d H:i:s
     * @param string $lang Langue pour le formatage
     * @return string
     */
    public static function timeAgo(string $date, string $lang = 'fr'): string
    {
        // Round 205 : strtotime() renvoie false (pas 0) pour une date non
        // parsable — time() - false caste false en 0, donnant un $diff
        // énorme (≈ time() courant) qui retombait silencieusement dans la
        // branche "il y a N mois" avec un N absurde, au lieu de signaler
        // l'échec. Même piège déjà corrigé pour formatDate() (round 173,
        // voir son commentaire ci-dessus) mais jamais porté ici.
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        $diff = time() - $ts;

        $labels = [
            'fr' => [
                'just_now' => 'à l\'instant',
                'minutes'  => 'il y a %d minute(s)',
                'hours'    => 'il y a %d heure(s)',
                'days'     => 'il y a %d jour(s)',
                'months'   => 'il y a %d mois',
            ],
            'en' => [
                'just_now' => 'just now',
                'minutes'  => '%d minute(s) ago',
                'hours'    => '%d hour(s) ago',
                'days'     => '%d day(s) ago',
                'months'   => '%d month(s) ago',
            ],
        ];

        $l = $labels[$lang] ?? $labels['fr'];

        // Round 173 : une $date future (ex. envoi programmé) rendait $diff
        // négatif, donc toujours < 60 → affichait à tort "à l'instant" au
        // lieu de refléter un délai à venir. Traite toute date future comme
        // "à l'instant" explicitement plutôt que par un artefact du calcul.
        if ($diff <= 0) {
            return $l['just_now'];
        }

        if ($diff < 60) {
            return $l['just_now'];
        }

        if ($diff < 3600) {
            return sprintf($l['minutes'], (int) ($diff / 60));
        }

        if ($diff < 86400) {
            return sprintf($l['hours'], (int) ($diff / 3600));
        }

        if ($diff < 2592000) {
            return sprintf($l['days'], (int) ($diff / 86400));
        }

        return sprintf($l['months'], (int) ($diff / 2592000));
    }

    /**
     * Verifie si le module tourne en environnement de developpement
     * Utilise pour afficher des informations de debug supplementaires
     *
     * @return bool
     */
    public static function isDev(): bool
    {
        return defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ === true;
    }

    /**
     * Retourne la version courante du module
     *
     * @return string
     */
    public static function getVersion(): string
    {
        return Neria::VERSION;
    }

    /**
     * Formate un montant avec le symbole/format de la devise donnée —
     * remplacement compatible PS8/PS9 de \Tools::displayPrice().
     *
     * \Tools::displayPrice() a été entièrement retirée du cœur sur
     * PrestaShop 9 (confirmé par method_exists() sur une vraie installation
     * PS9, indépendamment du contexte CLI/web — ce n'est pas un problème de
     * conteneur Symfony absent comme pour d'autres méthodes ce soir, la
     * méthode n'existe simplement plus). Tout appel direct à
     * \Tools::displayPrice() dans Neria échoue silencieusement sur PS9
     * (catché par les try/catch "best-effort" des managers), empêchant
     * l'envoi de l'email concerné sans aucune erreur visible au marchand.
     *
     * L'alternative PS9 recommandée (Context::getCurrentLocale()) nécessite
     * elle-même le conteneur Symfony complet et retourne null en CLI/cron —
     * donc pas fiable comme unique solution. Cette méthode utilise
     * NumberFormatter (extension intl, présente sur les deux environnements
     * testés ce soir) avec la locale de la langue courante, une solution
     * qui fonctionne de façon identique en PS8, PS9, web et CLI.
     *
     * @param float    $amount   Montant à formater
     * @param Currency $currency Devise cible
     * @return string            Montant formaté (ex: "35,90 $", "29.99 €")
     */
    /**
     * $idLang optionnel : la locale de formatage (position du symbole,
     * séparateur décimal) suit sinon Context::getContext()->language, qui
     * en contexte cron (BehavioralCronManager, WaitlistManager,
     * CollectionManager, LookCompletionManager...) est celle de la dernière
     * requête web/admin ayant tourné dans ce process — PAS forcément celle
     * du client destinataire de l'email. Passer explicitement l'id_lang du
     * destinataire pour un prix correctement localisé même en cron.
     */
    /**
     * Formate un prix via l'extension intl (NumberFormatter) — chemin de
     * repli utilisé par displayPrice() UNIQUEMENT quand Tools::displayPrice()
     * n'existe plus côté cœur PS. Extraite en méthode séparée pour rester
     * testable en isolation par un test de régression : sur toute version PS
     * actuelle (8, 9), Tools::displayPrice() existe encore et court-circuite
     * displayPrice() avant d'atteindre ce code — sans cette extraction, la
     * table de correspondance iso_code→locale ICU ci-dessous ne serait
     * vérifiable que sur une hypothétique future version de PS qui aurait
     * supprimé Tools::displayPrice().
     *
     * @return string|null null si NumberFormatter échoue (repli manuel appelant)
     */
    public static function formatPriceWithIntl(float $amount, \Currency $currency, ?\Language $lang): ?string
    {
        $localeIso = 'en-US';
        // iso_code interne PS ne correspond pas toujours à un identifiant de
        // locale ICU valide (ex: 'gb', 'br', 'tw' ne sont pas des codes ISO
        // 639 — les vrais identifiants ICU attendus sont 'en-GB', 'pt-BR',
        // 'zh-TW'). Sans cette table, NumberFormatter('gb', ...) construisait
        // une locale ICU non standard et retombait sur des règles de repli
        // proches de en-US, produisant un prix mal formaté (position du
        // symbole, séparateur) pour ces langues — silencieux, pas de crash.
        static $isoToIcu = [
            'gb' => 'en-GB',
            'br' => 'pt-BR',
            'tw' => 'zh-TW',
        ];
        try {
            if ($lang && !empty($lang->locale)) {
                $localeIso = str_replace('_', '-', $lang->locale);
            } elseif ($lang && !empty($lang->iso_code)) {
                $isoLower  = strtolower($lang->iso_code);
                $localeIso = $isoToIcu[$isoLower] ?? $lang->iso_code;
            }
        } catch (\Throwable $e) {
            // Repli sur en-US si le contexte langue n'est pas disponible.
        }

        $formatter = new \NumberFormatter($localeIso, \NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($amount, $currency->iso_code);
        return $formatted !== false ? $formatted : null;
    }

    public static function displayPrice(float $amount, \Currency $currency, ?int $idLang = null): string
    {
        $context = \Context::getContext();

        // Résout la langue EXPLICITEMENT demandée (destinataire de l'email),
        // distincte de $context->language (contexte cron/BO du process qui
        // déclenche l'envoi).
        $targetLang = null;
        if ($idLang !== null && \Validate::isUnsignedId($idLang)) {
            $lang = new \Language($idLang);
            // Round 173 : $context->language peut être null (cron/CLI sans
            // contexte complet — précisément le cas visé par ce paramètre
            // $idLang explicite), et accéder à ->id dessus émettrait un
            // warning PHP 8 "Attempt to read property on null" à chaque
            // appel malgré le `??` qui ne protège que le résultat, pas
            // l'accès lui-même.
            $contextLangId = $context->language !== null ? (int) $context->language->id : 0;
            if (\Validate::isLoadedObject($lang) && (int) $lang->id !== $contextLangId) {
                $targetLang = $lang;
            }
        }

        // Quand une langue explicite diffère du contexte, NE PAS passer par
        // \Tools::displayPrice() natif malgré method_exists() : il délègue à
        // Tools::getContextLocale(), qui retourne $context->getCurrentLocale()
        // — un objet Locale calculé UNE SEULE FOIS par Controller::init() (ou
        // jamais en CLI/cron) et jamais recalculé quand du code réaffecte
        // $context->language en cours de script. Réaffecter $context->language
        // ici serait donc un no-op silencieux : le prix resterait formaté
        // selon la locale figée du process (langue du cron/BO), pas celle du
        // destinataire — MonthlyReportManager/CollectionManager/
        // LookCompletionManager appellent tous displayPrice(..., $idLang) en
        // croyant obtenir un prix dans la langue du CLIENT. formatPriceWithIntl()
        // ci-dessous prend un objet \Language directement, indépendamment de
        // $context, et formate donc réellement dans la bonne langue.
        if ($targetLang !== null) {
            if (class_exists('NumberFormatter')) {
                $formatted = self::formatPriceWithIntl($amount, $currency, $targetLang);
                if ($formatted !== null) {
                    return $formatted;
                }
            }
            $sign = $currency->sign ?: $currency->iso_code;
            return number_format($amount, 2, ',', ' ') . ' ' . $sign;
        }

        // PS8 (et versions antérieures) : délègue à l'implémentation native,
        // comportement strictement identique à l'existant, zéro risque de
        // régression sur les environnements où la méthode existe encore —
        // uniquement quand aucune langue explicite n'est demandée (le prix
        // du contexte courant est bien celui attendu dans ce cas).
        if (method_exists('Tools', 'displayPrice')) {
            return \Tools::displayPrice($amount, $currency);
        }

        if (class_exists('NumberFormatter')) {
            $formatted = self::formatPriceWithIntl($amount, $currency, $context->language ?? null);
            if ($formatted !== null) {
                return $formatted;
            }
        }

        // Dernier repli, sans extension intl : formatage manuel simple mais
        // jamais faux (mieux qu'un montant absent de l'email).
        $sign = $currency->sign ?: $currency->iso_code;
        return number_format($amount, 2, ',', ' ') . ' ' . $sign;
    }

    /**
     * Dernier repli pour formater un nombre décimal (ex: un pourcentage) sans
     * extension intl disponible ni NumberFormatter fonctionnel — mêmes
     * conditions/justification que le repli sans-intl de displayPrice()
     * ci-dessus (mieux qu'une valeur absente de l'email). Centralisé ici
     * pour que ce soit le SEUL endroit du module autorisé à coder en dur un
     * séparateur décimal (cf. HealthCheckManager::checkHardcodedDecimalFormat,
     * qui exclut ce fichier pour cette raison précise) : tout autre appelant
     * doit passer par cette méthode plutôt que dupliquer number_format(...,',',...).
     *
     * @param float $value
     * @return string Ex: "12,5" ou "10"
     */
    public static function formatDecimalFallback(float $value): string
    {
        return (fmod($value, 1.0) === 0.0)
            ? (string) (int) $value
            : rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}