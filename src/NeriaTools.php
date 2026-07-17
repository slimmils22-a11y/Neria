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
        return strip_tags($text, $allowed);
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

        $truncated = mb_substr($text, 0, $length - mb_strlen($suffix));
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
     * Formate un montant pour l'affichage dans un email
     * Respecte le separateur decimal et le symbole monnaie
     *
     * @param float  $amount   Montant
     * @param string $currency Symbole monnaie (ex: '€', '$')
     * @param string $lang     Langue pour le formatage
     * @return string Montant formate (ex: '189,00 €')
     */
    public static function formatPrice(
        float  $amount,
        string $currency = '€',
        string $lang     = 'fr'
    ): string {
        // Separateur decimal selon la langue
        $decimalSep = in_array($lang, ['en','ja','ko','zh','tw'], true)
            ? '.'
            : ',';

        $thousandSep = in_array($lang, ['en','ja','ko','zh','tw'], true)
            ? ','
            : ' ';

        $formatted = number_format($amount, 2, $decimalSep, $thousandSep);

        // Placement du symbole selon la langue
        $symbolAfter = in_array($lang, ['fr','de','it','es','pt','br','nl'], true);

        return $symbolAfter
            ? $formatted . ' ' . $currency
            : $currency . $formatted;
    }

    /**
     * Formate une date pour l'affichage dans un email
     * Adapte le format selon la langue
     *
     * @param string $date Date au format Y-m-d ou timestamp
     * @param string $lang Langue cible
     * @return string Date formatee
     */
    public static function formatDate(string $date, string $lang = 'fr'): string
    {
        $ts = is_numeric($date) ? (int) $date : strtotime($date);

        if (!$ts) {
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
            $exists    = (bool) $db->getValue(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '" . pSQL($fullTable) . "'"
            );

            $count = 0;
            if ($exists) {
                $count = (int) $db->getValue(
                    "SELECT COUNT(*) FROM `{$fullTable}`"
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
            'gift_guarantee'                 => 'Garantie cadeau',
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
     * Retourne le temps ecoule depuis une date en format lisible
     * Ex: "il y a 3 jours", "il y a 2 heures"
     *
     * @param string $date Date au format Y-m-d H:i:s
     * @param string $lang Langue pour le formatage
     * @return string
     */
    public static function timeAgo(string $date, string $lang = 'fr'): string
    {
        $diff = time() - strtotime($date);

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
}