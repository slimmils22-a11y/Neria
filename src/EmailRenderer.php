<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA â€” EmailRenderer
 *
 * Orchestrateur central du rendu des emails Neria.
 * Intercepte chaque email envoyÃ© par PrestaShop via le hook
 * actionEmailSendBefore et :
 *
 * 1. Identifie le template et la langue du destinataire
 * 2. Enregistre la fonction Smarty {neria_trad key='...'}
 * 3. Injecte les variables de design (couleurs, polices, RTL)
 * 4. SÃ©lectionne la variante A/B si un test est actif
 * 5. Injecte le pixel de tracking pour les statistiques
 * 6. Injecte les liens rÃ©seaux sociaux
 * 7. Injecte la signature manuscrite si configurÃ©e
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EmailRenderer
{
    // ============================================================
    // CONSTANTES
    // ============================================================

    /**
     * Templates qui NE doivent PAS être traités par Neria
     */
    const EXCLUDED_TEMPLATES = [];

    /**
     * Alias : template PS natif → template Neria équivalent.
     * À utiliser uniquement quand le module tiers utilise un nom de template
     * différent de celui du fichier core Neria correspondant.
     * Exemple : 'native_name' => 'neria_template_name'
     */
    const TEMPLATE_ALIASES = [];

    // ============================================================
    // PROPRIÃ‰TÃ‰S
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var TranslationEngine Moteur de traduction */
    private TranslationEngine $engine;

    /** @var ConfigManager Gestionnaire de configuration */
    private ConfigManager $config;

    /** @var \Context Contexte PrestaShop courant */
    private \Context $context;

    /** @var WatchdogManager|null Instance paresseuse du watchdog */
    private ?WatchdogManager $watchdog = null;

    /** @var FontManager|null Instance paresseuse du gestionnaire de polices */
    private ?FontManager $fonts = null;

    /**
     * Garde anti-récursion pour l'email de secours.
     * Statique car le hook crée une nouvelle instance EmailRenderer à chaque
     * appel : l'envoi du fallback rappelle Mail::Send → ce même hook. Sans ce
     * drapeau partagé, un fallback en échec relancerait un fallback à l'infini.
     *
     * @var bool
     */
    private static bool $inFallback = false;

    /** @var bool L'email en cours de traitement est-il interne (destiné au marchand) ? */
    private bool $currentInternal = false;

    /** @var bool|null Cache du réglage « journaliser les emails internes » */
    private ?bool $logInternalCache = null;

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    public function __construct(Neria $module)
    {
        $this->module  = $module;
        $this->engine  = new TranslationEngine($module);
        $this->config  = new ConfigManager($module);
        $this->context = \Context::getContext();
    }

    // ============================================================
    // POINT D'ENTRÃ‰E PRINCIPAL
    // ============================================================

    /**
     * Traite les paramÃ¨tres d'un email avant son envoi
     * AppelÃ© depuis neria.php â†’ hookActionEmailSendBefore()
     *
     * @param array $params ParamÃ¨tres passÃ©s par PrestaShop :
     *   $params['template']     : nom du template (ex: order_conf)
     *   $params['idLang']       : id langue PrestaShop
     *   $params['templateVars'] : variables Smarty du template
     *   $params['subject']      : sujet de l'email
     *   $params['to']           : adresse email destinataire
     *   $params['toName']       : nom du destinataire
     */
    private function watchdog(): WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    private function fonts(): FontManager
    {
        if ($this->fonts === null) {
            $this->fonts = new FontManager($this->module);
        }
        return $this->fonts;
    }

    /**
     * Balises <link> Google Fonts combinées (police de titre + police de corps).
     * Les deux polices sont choisies indépendamment (titre = un seul réglage
     * global, corps = un réglage par langue/écriture) et peuvent donc pointer
     * vers deux URLs Google Fonts différentes — les deux doivent être chargées.
     */
    private function googleFontLinks(string $lang, string $headingFont): string
    {
        $headingLink = $this->config->getHeadingFontLink($headingFont);
        $bodyLink    = $this->fonts()->generateGoogleFontsLink($lang);
        if ($bodyLink === '' || strpos($headingLink, $bodyLink) !== false) {
            return $headingLink;
        }
        return $headingLink . "\n  " . $bodyLink;
    }

    private function tw(string $key, array $vars = []): string
    {
        $str = class_exists('AdminTranslator') ? AdminTranslator::t($key) : $key;
        foreach ($vars as $k => $v) {
            $str = str_replace('{' . $k . '}', (string) $v, $str);
        }
        return $str;
    }

    /**
     * Journalise un événement « doux » (info / warning) lié au rendu d'un
     * email. Les emails internes (destinés au marchand) ne sont PAS journalisés
     * à ce niveau, sauf si le marchand l'a explicitement activé (réglage
     * « Journaliser les emails internes »), pour garder le journal centré sur
     * les emails clients. Les erreurs et critiques passent toujours par
     * watchdog()->error()/critical() directement, quel que soit le réglage.
     *
     * @param string $level    'info' ou 'warning'
     * @param string $message
     * @param string $template
     * @param array  $context
     */
    private function softLog(string $level, string $message, string $template, array $context = []): void
    {
        if ($this->currentInternal && !$this->internalLogEnabled()) {
            return;
        }
        $this->watchdog()->{$level}($message, $template, 'EmailRenderer', $context);
    }

    /**
     * Réglage « journaliser les emails internes » (mis en cache pour la requête).
     *
     * @return bool
     */
    private function internalLogEnabled(): bool
    {
        if ($this->logInternalCache === null) {
            $this->logInternalCache = $this->config->isInternalLogEnabled();
        }
        return $this->logInternalCache;
    }

    /**
     * Détermine si un email est « interne » : destiné au marchand plutôt qu'au
     * client (alertes de log, notifications administrateur, email de test…).
     * Heuristique robuste : le destinataire est l'email de la boutique ou
     * celui d'un employé.
     *
     * @param array $params Paramètres de l'email
     * @return bool
     */
    private function isInternalEmail(array $params): bool
    {
        $to = $params['to'] ?? '';
        if (is_array($to)) {
            $to = reset($to);
        }
        $to = strtolower(trim((string) $to));
        if ($to === '') {
            return false;
        }

        $shopEmail = strtolower((string) \Configuration::get('PS_SHOP_EMAIL'));
        if ($shopEmail !== '' && $to === $shopEmail) {
            return true;
        }

        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'employee` WHERE `email` = \'' . pSQL($to) . '\''
        ) > 0;
    }

    public function processEmailParams(array &$params): bool
    {
        // â”€â”€ VÃ©rifie que le module est actif â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if (!$this->config->isActive()) {
            return true;
        }

        // Pendant l'envoi de l'email de secours, ne pas re-traiter : le fichier
        // est déjà compilé et le templatePath déjà fourni à Mail::Send.
        if (self::$inFallback) {
            return true;
        }

        // â”€â”€ RÃ©cupÃ¨re et valide le template â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $template = $this->resolveTemplate($params['template'] ?? '');

        if (!$template || $this->isExcluded($template, $params)) {
            return true;
        }

        // ── Rendu protégé : la moindre erreur déclenche l'email de secours,
        // plutôt que de laisser PrestaShop envoyer un email natif brut ───────
        try {
            $this->applyNeriaRendering($params, $template);
            return true;
        } catch (\Throwable $e) {
            return $this->handleRenderFailure($params, $template, $e);
        }
    }

    /**
     * Applique tout le traitement Neria à un email (langue, sujet, design,
     * variantes, variables, compilation). Isolé dans sa propre méthode pour
     * pouvoir l'envelopper dans un try/catch : toute erreur de rendu bascule
     * sur l'email de secours sans jamais remonter jusqu'à PrestaShop.
     *
     * @param array  $params   Paramètres de l'email (par référence)
     * @param string $template Nom de template déjà résolu
     */
    private function applyNeriaRendering(array &$params, string $template): void
    {
        // Email interne (destiné au marchand) ? Conditionne la journalisation
        // « douce » (info/warning) selon le réglage du marchand.
        $this->currentInternal = $this->isInternalEmail($params);

        // Template absent de tous les emplacements où PrestaShop le chercherait
        // (et non couvert par Neria) → l'envoi natif échouerait dans le vide.
        // On lève pour basculer sur l'email de secours.
        if (!file_exists($this->module->getModulePath('mails/themes/neria_global/core/' . $template . '.html'))
            && $this->templateMissingEverywhere($template, $params)) {
            throw new \RuntimeException(WatchdogManager::i18nMsg('watchdog.core_missing', ['template' => $template]));
        }


        // â”€â”€ RÃ©sout la langue â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $lang = $this->resolveEmailLang($params);

        // Expéditeur spécifique à la langue (multi-sender)
        if ($this->config->isMultiSenderEnabled()) {
            $sender = $this->config->getSenderForLang($lang);
            if (!empty($sender['name'])) {
                $params['fromName'] = $sender['name'];
            }
            if (!empty($sender['email']) && \Validate::isEmail($sender['email'])) {
                $params['from'] = $sender['email'];
            }
        }

        // â”€â”€ Sujet â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Si aucun sujet n'est fourni (ex. envoi manuel), on utilise le titre
        // principal du template (clé greeting_main) traduit dans la langue
        // détectée — réutilise les traductions existantes (19 langues).
        if (trim((string) ($params['subject'] ?? '')) === '') {
            $headline = $this->engine->get($template, 'greeting_main', $lang);
            if ($headline !== '') {
                $params['subject'] = trim(strip_tags($headline));
            } else {
                $this->softLog(
                    'warning',
                    WatchdogManager::i18nMsg('watchdog.subject_empty'),
                    $template,
                    ['lang' => $lang]
                );
            }
        }

        // â”€â”€ SÃ©lectionne la variante A/B si nÃ©cessaire â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $variant = $this->resolveABVariant($template, $params);

        // â”€â”€ Enregistre {neria_trad} dans Smarty â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->registerSmartyFunction($template, $lang, $variant);

        // â”€â”€ Injecte les liens rÃ©seaux sociaux â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Reformate le tableau produits PrestaShop
        $this->reformatProductsHtml($params['templateVars']);

        $this->injectSocialVars($params['templateVars']);

        // â”€â”€ Injecte la signature â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($this->config->isSignatureEnabled()) {
            $this->injectSignatureVars($params['templateVars']);
        }

        // Injecte les variables personnalisées du marchand ({return_address}, etc.)
        $this->injectCustomVars($params['templateVars']);

        // Fallback prénom : si {firstname} absent/vide, substitue par le mot élégant défini par le marchand
        if ($this->config->isFirstnameFallbackEnabled()) {
            $this->injectFirstnameFallback($params['templateVars'], $lang);
        }

        // Salutation horaire : {time_greeting} selon l'heure locale du client
        if ($this->config->isTimeGreetingEnabled()) {
            $this->injectTimeGreeting($params['templateVars'], $lang);
        }

        // Injecte {email} depuis le destinataire si absent (ex: newsletter_conf → subscription_confirmation)
        if (empty($params['templateVars']['{email}'])) {
            $to = $params['to'] ?? '';
            $params['templateVars']['{email}'] = is_array($to) ? (string) reset($to) : (string) $to;
        }

        // Message personnalisé optionnel (envoi manuel) — versions HTML et TXT
        $this->injectCustomMessage($params['templateVars']);

        // {subject} — utilisé dans le <title> de layout.html ; jamais injecté
        // en dehors de l'aperçu/fallback jusqu'ici, laissant un "{subject}"
        // brut dans le <title> de tous les envois réels (bug trouvé le
        // 2026-07-13 via un rapport de test externe, cf. mémoire).
        if (is_array($params['templateVars'])) {
            $params['templateVars']['{subject}'] = (string) ($params['subject'] ?? '');
        }

        // Durée de validité des bons (variable {validity_days}, réglage marchand)
        if (is_array($params['templateVars'])) {
            $params['templateVars']['{validity_days}'] = (string) $this->config->getVoucherValidity();

            // Lien de désabonnement signé (pied de page) — cohérent avec
            // l'en-tête List-Unsubscribe ajouté par le module avant l'envoi.
            $unsubTo = $params['to'] ?? '';
            if (is_array($unsubTo)) {
                $unsubTo = reset($unsubTo);
            }
            $params['templateVars']['{unsubscribe_url}'] = $this->module->getUnsubscribeUrl((string) $unsubTo, $lang);

            // Lien du centre de préférences (pied de page du layout global) —
            // même destinataire, résolu au client si connu (cf. resolveCustomerId).
            if (class_exists('PreferencesManager') && (string) $unsubTo !== '') {
                $pm = new PreferencesManager($this->module);
                $params['templateVars']['{preferences_url}'] = $pm->getPreferencesUrl(
                    (string) $unsubTo,
                    $this->resolveCustomerId($params),
                    $lang
                );
            }
        }

        // newsletter_voucher : ps_emailsubscription passe le CODE du bon dans
        // {discount}. On remet le code dans {voucher_code} (ligne « Code : … »)
        // et on calcule le vrai taux/montant du bon pour {discount} (intro).
        if ($template === 'newsletter_voucher') {
            $this->fixNewsletterVoucherVars($params['templateVars']);
        }

        // â”€â”€ GÃ©nÃ¨re les variantes texte des variables HTML (pour le .txt)
        $this->injectTextVariants($params['templateVars']);

        // â”€â”€ Injecte le pixel de tracking â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($this->config->isStatsEnabled()) {
            $this->injectTrackingPixel($template, $lang, $params);
        }

        // Compiler template Neria et changer templatePath.
        // Le contenu est compilé dans la langue DÉTECTÉE ($lang), mais doit
        // être écrit dans le dossier ISO que Mail::send va réellement lire —
        // celui de $idLang (cf. Mail::send → getIsoById((int)$idLang)). Sinon
        // PrestaShop sert un autre fichier que la langue détectée et l'email
        // part dans la mauvaise langue.
        $outIso = \Language::getIsoById((int) ($params['idLang'] ?? 0)) ?: $lang;
        // silentIfCoreMissing=true : ce template peut être hors périmètre Neria
        // (module tiers) — cf. docblock de compileNeriaTemplate().
        $compiledPath = $this->compileNeriaTemplate($template, $lang, $outIso, $params['templateVars'] ?? [], false, true);
        if ($compiledPath !== null) {
            // ── Wrapping des liens pour le tracking de clics ─────────────
            if ($this->config->isStatsEnabled() && !empty($params['neria_token'])) {
                $this->wrapLinksInFile($compiledPath, (string) $params['neria_token'], (int) ($params['idLang'] ?? 0));
            }

            if (isset($params['templatePath'])) {
                // PS détecte 'modules/neria/' dans le chemin et cherche dans ce dossier
                $params['templatePath'] = _PS_MODULE_DIR_ . 'neria/mails/';
            }
            // Si un alias a changé le nom du template, synchroniser $params['template']
            // pour que PS cherche le bon fichier compilé dans templatePath.
            if ($params['template'] !== $template) {
                $params['template'] = $template;
            }
        } elseif (file_exists(
            $this->module->getModulePath('mails/themes/neria_global/core/' . $template . '.html')
        )) {
            // Le template Neria existe mais n'a pas pu être compilé (fichier
            // corrompu, bloc neria_content manquant) : on lève pour basculer
            // sur l'email de secours. Un template hors périmètre Neria (pas de
            // fichier core) est au contraire laissé tel quel à PrestaShop.
            throw new \RuntimeException(WatchdogManager::i18nMsg('watchdog.block_missing', ['template' => $template]));
        }

        // â”€â”€ Log â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->module->log(
            sprintf(
                'EmailRenderer: rendu [%s][%s]%s',
                $template,
                $lang,
                $variant ? ' variante ' . $variant : ''
            ),
            1
        );

        // INFO de succès uniquement si Neria a réellement habillé l'email
        // (compilation effective) — pas pour les emails laissés tels quels à
        // PrestaShop. softLog respecte aussi le réglage « emails internes ».
        if ($compiledPath !== null) {
            // Rendu réussi → réinitialiser le compteur d'échecs consécutifs
            \Configuration::updateValue(HealthCheckManager::CFG_CONSECUTIVE_FAILURES, 0);

            $msg = $variant
                ? WatchdogManager::i18nMsg('watchdog.render_success_variant', ['variant' => $variant])
                : WatchdogManager::i18nMsg('watchdog.render_success');
            $this->softLog('info', $msg, $template);
        }
    }

    // ============================================================
    // EMAIL DE SECOURS (FALLBACK)
    // ============================================================

    /**
     * Détermine si un template email est introuvable dans TOUS les emplacements
     * où PrestaShop irait le chercher (thèmes, racine, dossier mails du module
     * concerné, mails compilés Neria) — auquel cas l'envoi natif échouerait.
     * Réplique la résolution de Mail::getTemplateBasePath (PS 8) et reste
     * PRUDENTE : en cas de doute, renvoie false (ne jamais annuler un email
     * légitime sur une fausse alerte).
     *
     * @param string $template Nom du template
     * @param array  $params   Paramètres de l'email (idLang + templatePath)
     * @return bool true uniquement si le template n'existe nulle part
     */
    private function templateMissingEverywhere(string $template, array $params): bool
    {
        try {
            // ISO à tester : langue de l'email + 'en' (comme PrestaShop)
            $idLang   = (int) ($params['idLang'] ?? 0);
            $iso      = $idLang > 0 ? \Language::getIsoById($idLang) : '';
            $isoArray = [];
            if ($iso) {
                $isoArray[] = strtolower($iso);
            }
            if (!in_array('en', $isoArray, true)) {
                $isoArray[] = 'en';
            }

            // Nom du module depuis le templatePath fourni (comme PrestaShop)
            $moduleName = '';
            $tp = isset($params['templatePath'])
                ? str_replace(DIRECTORY_SEPARATOR, '/', (string) $params['templatePath'])
                : '';
            if ($tp !== '' && preg_match('#modules/([a-z0-9_-]+)/#ui', $tp, $res)) {
                $moduleName = $res[1];
            }

            // Chemins de base (cf. Mail::getTemplateBasePath)
            $basePaths = [];
            $theme = $this->context->shop->theme ?? null;
            if ($theme && method_exists($theme, 'getName')) {
                $basePaths[] = _PS_ROOT_DIR_ . '/themes/' . $theme->getName() . '/';
                $parent = method_exists($theme, 'get') ? (string) $theme->get('parent') : '';
                if ($parent !== '') {
                    $basePaths[] = _PS_ROOT_DIR_ . '/themes/' . $parent . '/';
                }
            }
            $basePaths[] = _PS_ROOT_DIR_;

            $rel = $moduleName !== '' ? '/modules/' . $moduleName . '/mails/' : '/mails/';

            foreach ($isoArray as $isoCode) {
                $isoTemplate = $isoCode . '/' . $template;
                foreach ($basePaths as $base) {
                    $path = $base . $rel . $isoTemplate;
                    if (file_exists($path . '.txt') || file_exists($path . '.html')) {
                        return false;
                    }
                }
                // Dossier des emails compilés par Neria
                $neria = _PS_MODULE_DIR_ . 'neria/mails/' . $isoTemplate;
                if (file_exists($neria . '.txt') || file_exists($neria . '.html')) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            // Prudence absolue : à la moindre incertitude, ne pas considérer le
            // template comme manquant (ne jamais annuler un email légitime).
            return false;
        }
    }

    /**
     * Gère un échec de rendu : journalise et, si le template fait partie de
     * ceux que Neria habille, envoie l'email de secours élégant à la place de
     * l'email natif brut de PrestaShop.
     *
     * @param array      $params   Paramètres de l'email (par référence)
     * @param string     $template Template en échec
     * @param \Throwable $e        Erreur survenue pendant le rendu
     * @return bool false = annuler l'envoi natif (secours envoyé) ;
     *              true  = laisser PrestaShop poursuivre son envoi natif
     */
    /**
     * Extrait un conseil actionnable depuis un message d'erreur Smarty.
     */
    private function extractSmartyHint(string $cause, string $template): string
    {
        $lower = strtolower($cause);
        $prevLang = AdminTranslator::currentLang();
        AdminTranslator::setLang(WatchdogManager::shopLang());

        // Variable Smarty manquante : "Undefined variable: foo" ou "Undefined index: foo"
        if (preg_match('/undefined (?:variable|index)[:\s]+[\'"]?(\w+)/i', $cause, $m)) {
            $hint = AdminTranslator::tVars('hint.smarty_missing_var', ['var' => '{' . $m[1] . '}', 'template' => $template]);
            AdminTranslator::setLang($prevLang);
            return $hint;
        }

        // Fichier template introuvable
        if (strpos($lower, 'no such file') !== false || strpos($lower, 'unable to load') !== false) {
            $hint = AdminTranslator::tVars('hint.smarty_file_missing', ['template' => $template]);
            AdminTranslator::setLang($prevLang);
            return $hint;
        }

        // Erreur de permissions
        if (strpos($lower, 'permission denied') !== false || strpos($lower, 'failed to open stream') !== false) {
            $hint = AdminTranslator::t('hint.smarty_permission');
            AdminTranslator::setLang($prevLang);
            return $hint;
        }

        // Dépassement de mémoire
        if (strpos($lower, 'allowed memory size') !== false || strpos($lower, 'out of memory') !== false) {
            $hint = AdminTranslator::t('hint.smarty_memory');
            AdminTranslator::setLang($prevLang);
            return $hint;
        }

        AdminTranslator::setLang($prevLang);

        return '';
    }

    private function handleRenderFailure(array &$params, string $template, \Throwable $e): bool
    {
        $cause = $e->getMessage();
        $this->module->log('Echec du rendu Neria [' . $template . '] : ' . $cause, 3);

        // Incrémenter le compteur d'échecs consécutifs
        $fails = (int) \Configuration::get(HealthCheckManager::CFG_CONSECUTIVE_FAILURES);
        \Configuration::updateValue(HealthCheckManager::CFG_CONSECUTIVE_FAILURES, $fails + 1);

        if (str_starts_with($cause, '::i18n::')) {
            $wdMsg = $cause;
        } else {
            // Détecter les erreurs Smarty avec variable manquante
            $actionable = $this->extractSmartyHint($cause, $template);
            $enriched   = $cause . ($actionable ? ' → ' . $actionable : '');
            $wdMsg      = WatchdogManager::i18nMsg('watchdog.render_unexpected', ['cause' => $enriched]);
        }
        $this->watchdog()->error($wdMsg, $template, 'EmailRenderer');

        // Ne détourner que les emails que Neria habille réellement (un fichier
        // core/<template>.html existe). Un template tiers/natif inconnu est
        // laissé à PrestaShop — on ne le remplace pas par un message générique.
        // Et jamais de secours pendant l'envoi d'un secours (anti-récursion).
        // Éligible au secours si : (a) Neria habille ce template (core présent),
        // ou (b) le template est introuvable partout (PS échouerait de toute
        // façon). Un template tiers/natif existant n'est jamais détourné.
        $eligible = file_exists(
            $this->module->getModulePath('mails/themes/neria_global/core/' . $template . '.html')
        ) || $this->templateMissingEverywhere($template, $params);

        if ($eligible && !self::$inFallback && $this->sendFallbackEmail($params, $e)) {
            return false;
        }

        return true;
    }

    /**
     * Envoie l'email de secours Neria (template neria_fallback) — générique
     * mais élégant, avec l'identité visuelle Neria. Garantit qu'aucun email
     * natif brut ne parte quand le rendu normal échoue.
     *
     * Ne lève JAMAIS d'exception : si le secours lui-même échoue, on
     * journalise et on renvoie false (PrestaShop reprend la main).
     *
     * @param array      $params Paramètres de l'email d'origine
     * @param \Throwable $cause  Erreur ayant déclenché le secours (pour le log)
     * @return bool true si l'email de secours a bien été envoyé
     */
    private function sendFallbackEmail(array $params, \Throwable $cause): bool
    {
        try {
            // ── Destinataire ────────────────────────────────────────────
            $to = $params['to'] ?? '';
            if (is_array($to)) {
                $to = reset($to);
            }
            $to = trim((string) $to);
            if ($to === '' || !\Validate::isEmail($to)) {
                $this->watchdog()->critical(
                    WatchdogManager::i18nMsg('watchdog.fallback_no_to'),
                    'neria_fallback',
                    'EmailRenderer'
                );
                return false;
            }
            $toName = (string) ($params['toName'] ?? '');

            // ── Langue : même résolution que le flux normal ─────────────
            $lang   = $this->resolveEmailLang($params);
            $idLang = (int) ($params['idLang'] ?? 0);
            if ($idLang <= 0) {
                $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
            }
            $outIso = \Language::getIsoById($idLang) ?: $lang;

            // ── Sujet (clé fallback_subject), repli sur le nom de boutique
            $subject = trim(strip_tags(
                $this->engine->get('neria_fallback', 'fallback_subject', $lang)
            ));
            if ($subject === '') {
                $subject = (string) \Configuration::get('PS_SHOP_NAME');
            }

            // ── Variables minimales attendues par le layout ─────────────
            // Construites AVANT la compilation (voir plus bas) : le fichier
            // .html/.txt écrit sur disque est ce que Mail::Send() lit et
            // envoie tel quel — passer des variables vides ici puis
            // espérer une résolution ultérieure via Swift ne fonctionne
            // pas, les placeholders {xxx} non résolus sont déjà retirés
            // (filet de sécurité) au moment de l'écriture du fichier.
            $templateVars = [
                '{shop_name}'          => (string) \Configuration::get('PS_SHOP_NAME'),
                '{shop_url}'           => $this->context->link->getBaseLink(),
                '{history_url}'        => $this->context->link->getPageLink('history', true, $idLang),
                '{guest_tracking_url}' => $this->context->link->getPageLink('guest-tracking', true, $idLang),
                '{custom_message}'     => '',
                '{custom_message_txt}' => '',
                '{subject}'            => $subject,
                '{unsubscribe_url}'    => $this->module->getUnsubscribeUrl($to, $lang),
                // {preferences_url} manquait ici — layout.html (partagé avec
                // le flux normal) rend alors un lien "Gérer mes préférences"
                // cassé (href="") dans CHAQUE email de secours, exactement
                // le même défaut que celui trouvé et corrigé le 2026-07-20
                // sur ensureInternalTemplateCompiled() (log_alert), ici plus
                // gênant encore : l'email de secours part déjà dans une
                // situation dégradée (échec du rendu normal).
                '{preferences_url}'    => class_exists('PreferencesManager')
                    ? (new \PreferencesManager($this->module))->getPreferencesUrl($to, $this->resolveCustomerId($params), $lang)
                    : '',
            ];

            // ── Compile le template de secours ──────────────────────────
            // Écrit les .html/.txt plats que Mail::send lira dans mails/<iso>/
            // — avec les VRAIES variables du destinataire (bug trouvé le
            // 2026-07-20 : cet appel se faisait avant sans aucune variable,
            // laissant le fichier compilé avec des placeholders déjà
            // retirés/vidés, jamais résolus par la suite malgré le
            // $templateVars passé à Mail::Send() plus bas).
            if ($this->compileNeriaTemplate('neria_fallback', $lang, $outIso, $templateVars) === null) {
                $this->watchdog()->critical(
                    WatchdogManager::i18nMsg('watchdog.fallback_no_template'),
                    'neria_fallback',
                    'EmailRenderer'
                );
                return false;
            }

            // ── Envoi (anti-récursion via le drapeau statique) ──────────
            self::$inFallback = true;
            try {
                $sent = \Mail::Send(
                    $idLang,
                    'neria_fallback',
                    $subject,
                    $templateVars,
                    $to,
                    $toName,
                    null,
                    null,
                    null,
                    null,
                    _PS_MODULE_DIR_ . 'neria/mails/'
                );
            } finally {
                self::$inFallback = false;
            }

            if ($sent) {
                $this->watchdog()->warning(
                    WatchdogManager::i18nMsg('watchdog.fallback_sent', [
                        'template' => $params['template'] ?? '?',
                        'cause'    => $cause->getMessage(),
                    ]),
                    (string) ($params['template'] ?? ''),
                    'EmailRenderer',
                    ['to' => $to, 'lang' => $lang]
                );
                return true;
            }

            $this->watchdog()->critical(
                WatchdogManager::i18nMsg('watchdog.fallback_send_failed'),
                'neria_fallback',
                'EmailRenderer'
            );
            return false;

        } catch (\Throwable $e) {
            // Le secours lui-même a échoué — ne JAMAIS laisser remonter.
            self::$inFallback = false;
            $this->module->log('Echec du fallback email : ' . $e->getMessage(), 3);
            $this->watchdog()->critical(
                WatchdogManager::i18nMsg('watchdog.fallback_exception', ['error' => $e->getMessage()]),
                'neria_fallback',
                'EmailRenderer'
            );
            return false;
        }
    }

    // ============================================================
    // SMARTY â€” Enregistrement de {neria_trad}
    // ============================================================

    /**
     * Enregistre la fonction Smarty {neria_trad key='...'} sur
     * l'instance Smarty du contexte PrestaShop.
     *
     * La closure capture $template, $lang et $variant pour que
     * chaque appel {neria_trad} sache quel bloc charger.
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     * @param string $variant  Variante A/B ('A', 'B' ou '')
     */
    private function registerSmartyFunction(
        string $template,
        string $lang,
        string $variant
    ): void {
        $engine  = $this->engine;
        $module  = $this->module;
        $smarty  = $this->context->smarty;

        // Ã‰vite d'enregistrer deux fois (cas de plusieurs emails
        // envoyÃ©s dans la mÃªme requÃªte)
        try {
            $smarty->unregisterPlugin('function', 'neria_trad');
        } catch (\Throwable $e) {
            // Plugin pas encore enregistrÃ© â€” normal au premier appel
        }

        $smarty->registerPlugin(
            'function',
            'neria_trad',
            function (array $p) use ($engine, $template, $lang, $variant, $module): string {
                if (empty($p['key'])) {
                    return '';
                }

                $key = $p['key'];

                // Variante B : cherche d'abord dans les textes A/B
                if ($variant === 'B') {
                    $abManager = new ABTestManager($module);
                    $abValue   = $abManager->getVariantBValue($template, $lang, $key);
                    if ($abValue !== null) {
                        return self::sanitizeTranslationHtml($abValue);
                    }
                }

                // Traduction standard
                return self::sanitizeTranslationHtml($engine->get($template, $key, $lang));
            }
        );
    }

    /**
     * Neutralise le HTML dangereux d'une valeur de traduction avant de
     * l'injecter dans un email.
     *
     * Les traductions livrées avec le module contiennent volontairement du
     * HTML (ex: `<a href="{tracking_url}" style="color:#b38b59;">`) pour la
     * mise en forme des liens — un htmlspecialchars() global casserait donc
     * tous les emails. Mais `translation_value` peut aussi être écrasée par
     * un import CSV de traduction (BO admin, neria.php action
     * import_translations_csv / import_variant_b_csv) : le fichier importé
     * est parsé et inséré tel quel (seul `pSQL($value, true)` est appliqué,
     * qui échappe pour SQL, pas pour HTML). Un CSV contenant
     * `<script>...</script>` ou `<img onerror=...>` dans une valeur de
     * traduction se retrouvait donc injecté brut dans TOUS les emails
     * utilisant cette clé — un stored XSS confirmé par test réel (voir
     * audit du 2026-07-18 : payload `<script>alert(...)</script><img
     * src=x onerror="alert(1)">` retrouvé tel quel dans le HTML compilé de
     * renderPreviewHtml('test','fr')).
     *
     * Seule la balise `<a href="..." style="...">` étant réellement
     * utilisée par les traductions légitimes (vérifié sur
     * data/translations.json : aucune autre balise HTML n'y apparaît),
     * la sanitisation se limite à :
     * — retirer entièrement <script>/<style>/<iframe>/<object>/<embed> et
     *   leur contenu ;
     * — ne garder que les balises <a>, <br>, <b>, <strong>, <em>, <i>,
     *   <u>, <span> (strip_tags) ;
     * — sur ces balises, ne garder que les attributs href/style/target/rel
     *   et rejeter tout attribut on*= résiduel, tout href autre que
     *   http(s)/mailto/variable Neria ({xxx_url}), et tout style contenant
     *   "expression(" ou "url(".
     *
     * @param string $value Valeur de traduction brute (déjà résolue)
     * @return string       Valeur sûre à injecter dans le HTML de l'email
     */
    private static function sanitizeTranslationHtml(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Supprime entièrement les balises dangereuses et leur contenu
        $value = preg_replace(
            '#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is',
            '',
            $value
        );
        // Au cas où une balise ouvrante orpheline (sans fermeture) subsiste
        $value = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*/?>#i', '', $value);

        // Ne garde que les balises réellement utilisées par les traductions
        $value = strip_tags($value, '<a><br><b><strong><em><i><u><span>');

        // Sur les balises restantes, ne garde que des attributs whitelistés
        // et neutralise tout gestionnaire d'événement (onclick, onerror...)
        // ou protocole dangereux (javascript:, data:, vbscript:) qui aurait
        // survécu dans un attribut autorisé.
        $value = preg_replace_callback(
            '#<([a-z]+)([^>]*)>#i',
            static function (array $m): string {
                $tag       = strtolower($m[1]);
                $attrsRaw  = $m[2];
                $safeAttrs = '';

                if (preg_match_all('/([a-z-]+)\s*=\s*"([^"]*)"/i', $attrsRaw, $am, PREG_SET_ORDER)) {
                    foreach ($am as $attr) {
                        $name  = strtolower($attr[1]);
                        $val   = $attr[2];

                        if (!in_array($name, ['href', 'style', 'target', 'rel'], true)) {
                            continue;
                        }
                        if ($name === 'href') {
                            // Autorise http(s)/mailto/variables Neria ({tracking_url}...),
                            // rejette javascript:/data:/vbscript: et tout schéma inconnu.
                            if (!preg_match('#^(https?://|mailto:|\{[a-z0-9_]+\})#i', $val)) {
                                continue;
                            }
                        }
                        if ($name === 'style' && preg_match('/expression\s*\(|url\s*\(/i', $val)) {
                            continue;
                        }
                        $safeAttrs .= ' ' . $name . '="' . htmlspecialchars($val, ENT_QUOTES) . '"';
                    }
                }

                return '<' . $tag . $safeAttrs . '>';
            },
            $value
        );

        return $value;
    }

    // ============================================================
    // VARIABLES DE DESIGN
    // ============================================================


    /**
     * Injecte les liens rÃ©seaux sociaux dans les templateVars
     * Seuls les liens renseignÃ©s sont injectÃ©s
     * Si vide â†’ variable Ã  chaÃ®ne vide (le template gÃ¨re l'affichage)
     *
     * @param array $templateVars Variables Smarty (passÃ© par rÃ©fÃ©rence)
     */
    private function injectSocialVars(array &$templateVars): void
    {
        $links = $this->config->getSocialLinks();

        $labels = [
            'instagram' => 'Instagram',
            'pinterest' => 'Pinterest',
            'facebook'  => 'Facebook',
            'twitter'   => 'Twitter',
            'youtube'   => 'YouTube',
            'tiktok'    => 'TikTok',
        ];

        // Badges circulaires monogramme (ton bronze de la marque) plutôt que
        // les logos officiels des réseaux — évite toute question de droits
        // sur les logos de marques tierces, cohérent avec l'identité visuelle
        // luxe du module. alt=$label conservé pour les clients email qui
        // bloquent les images par défaut (comportement courant).
        $iconBaseUrl = rtrim($this->context->link->getBaseLink(), '/')
            . '/modules/' . $this->module->name . '/views/img/social/';

        $html = '';
        foreach ($labels as $key => $label) {
            if (!empty($links[$key])) {
                $html .= '<a href="' . htmlspecialchars($links[$key], ENT_QUOTES, 'UTF-8') . '" target="_blank">'
                    . '<img src="' . $iconBaseUrl . $key . '.png" width="28" height="28" alt="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;vertical-align:middle;border:0;border-radius:50%;">'
                    . '</a>';
            }
        }

        $templateVars = array_merge($templateVars, [
            'neria_social_links' => $html,
            'neria_has_social'   => !empty($links),
        ]);
    }

    /**
     * Injecte la signature manuscrite dans les templateVars
     * Si aucune signature n'est configurÃ©e, injecte des chaÃ®nes vides
     *
     * @param array $templateVars Variables Smarty (passÃ© par rÃ©fÃ©rence)
     */
    private function injectSignatureVars(array &$templateVars): void
    {
        $signature = $this->resolveSignature();

        $templateVars = array_merge($templateVars, [
            'neria_signature_url'    => $signature['url'],
            'neria_signature_name'   => $signature['name'],
            'neria_signature_title'  => $signature['title'],
            'neria_has_signature'    => !empty($signature['url']),
        ]);
    }

    /**
     * Injecte les variables personnalisées du marchand dans les templateVars
     * pour qu'elles soient substituées directement dans les emails (.html et .txt).
     *
     * Pour chaque variable {cle}, génère aussi deux variantes :
     *   - {cle_html} : sauts de ligne convertis en <br> (rendu HTML)
     *   - {cle_txt}  : texte brut, entités décodées (rendu .txt)
     *
     * Ne remplace jamais une valeur déjà présente (respecte les fakeVars d'aperçu).
     *
     * @param array $templateVars Variables Smarty (passé par référence)
     */
    private function injectCustomVars(array &$templateVars): void
    {
        if (!is_array($templateVars)) {
            return;
        }

        $vars = $this->config->getCustomVariables();
        if (empty($vars)) {
            return;
        }

        foreach ($vars as $row) {
            $key = isset($row['variable_key']) ? trim((string) $row['variable_key']) : '';
            if ($key === '') {
                continue;
            }

            $value   = (string) ($row['variable_value'] ?? '');
            $rawKey  = '{' . $key . '}';
            $htmlKey = '{' . $key . '_html}';
            $txtKey  = '{' . $key . '_txt}';

            if (empty($templateVars[$rawKey])) {
                $templateVars[$rawKey] = $value;
            }
            if (empty($templateVars[$htmlKey])) {
                $templateVars[$htmlKey] = nl2br($value);
            }
            if (empty($templateVars[$txtKey])) {
                $templateVars[$txtKey] = trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
            }
        }
    }

    /**
     * Journalise une variable résiduelle non résolue (filet de sécurité,
     * cf. appelants). Escalade en `error` — donc alerte immédiate via
     * WatchdogManager::sendImmediateAlert, throttlée à 1/heure — si la
     * variable manquante est une VARIABLE PERSONNALISÉE du marchand
     * (ConfigManager::CUSTOM_VARIABLE_KEYS) : 100% dans son contrôle,
     * contrairement à un bug de code où seul le développeur peut agir
     * (reste en `warning`, visible seulement au tableau de bord/digest).
     *
     * Complète checkCustomVarsCompleteness (contrôle Watchdog #67,
     * réactif jusqu'à 24h) et le garde-fou de ManualSendManager (préventif
     * mais uniquement pour le clic manuel) : ce point-ci est le seul qui
     * couvre aussi les envois AUTOMATIQUES (crons, hooks commande), où
     * aucun blocage préventif n'est possible.
     */
    private function logResidualVars(string $template, array $residualKeys): void
    {
        if (!class_exists('WatchdogManager')) {
            return;
        }

        $isCustomVarIssue = false;
        if (class_exists('ConfigManager')) {
            foreach ($residualKeys as $residualKey) {
                $bare = preg_replace('/_(html|txt)$/', '', trim($residualKey, '{}'));
                if (in_array($bare, \ConfigManager::CUSTOM_VARIABLE_KEYS, true)) {
                    $isCustomVarIssue = true;
                    break;
                }
            }
        }

        $watchdog = new WatchdogManager($this->module);
        $message  = WatchdogManager::i18nMsg('watchdog.residual_vars_stripped', [
            'template' => $template,
            'vars'     => implode(', ', $residualKeys),
        ]);

        if ($isCustomVarIssue) {
            $watchdog->error($message, $template, 'EmailRenderer');
        } else {
            $watchdog->warning($message, $template, 'EmailRenderer');
        }
    }

    private function injectTimeGreeting(array &$templateVars, string $lang): void
    {
        try {
            $timezone = $this->resolveCustomerTimezone($templateVars);
            $hour     = (int) (new \DateTime('now', new \DateTimeZone($timezone)))->format('H');
            $slot     = $this->getTimeSlot($hour);
            $greetings = $this->config->getTimeGreetings();
            $greeting  = $greetings[$lang][$slot] ?? $greetings['en'][$slot] ?? '';
            $templateVars['{time_greeting}'] = $greeting;
            (new WatchdogManager($this->module))->info(
                WatchdogManager::i18nMsg('watchdog.time_greeting_injected', ['greeting' => $greeting, 'lang' => $lang, 'slot' => $slot, 'tz' => $timezone])
            );
        } catch (\Throwable $e) {
            $templateVars['{time_greeting}'] = '';
            (new WatchdogManager($this->module))->warning(
                WatchdogManager::i18nMsg('watchdog.time_greeting_tz_error', ['error' => $e->getMessage()])
            );
        }
    }

    private function resolveCustomerTimezone(array $templateVars): string
    {
        $countryIso = '';

        // Priorité 1 : via id_customer → adresse par défaut
        $idCustomer = (int) ($templateVars['{id_customer}'] ?? 0);
        if ($idCustomer > 0) {
            $addresses = (new \Customer($idCustomer))->getAddresses((int) \Configuration::get('PS_LANG_DEFAULT'));
            if (!empty($addresses)) {
                $countryIso = \Country::getIsoById((int) $addresses[0]['id_country']);
            }
        }

        // Priorité 2 : via id_address_delivery (emails de commande)
        if (!$countryIso) {
            $idAddress = (int) ($templateVars['{id_address_delivery}'] ?? 0);
            if ($idAddress > 0) {
                $address    = new \Address($idAddress);
                $countryIso = \Country::getIsoById((int) $address->id_country);
            }
        }

        // Priorité 3 : pays par défaut de la boutique
        if (!$countryIso) {
            $countryIso = \Country::getIsoById((int) \Configuration::get('PS_COUNTRY_DEFAULT'));
        }

        // Vérification pays cibles : avertir si le pays n'est pas dans la liste configurée
        $targetCountries = $this->config->getTargetCountries();
        if (!empty($targetCountries) && $countryIso && !in_array(strtoupper($countryIso), array_map('strtoupper', $targetCountries), true)) {
            (new WatchdogManager($this->module))->warning(
                WatchdogManager::i18nMsg('watchdog.time_greeting_country_fallback', ['country' => $countryIso])
            );
            return 'UTC';
        }

        return $this->countryIsoToTimezone((string) $countryIso);
    }

    private function countryIsoToTimezone(string $iso): string
    {
        if ($iso === '') {
            return 'UTC';
        }
        // Pays à fuseaux multiples : on retient la zone la plus peuplée
        $preferred = [
            'US' => 'America/New_York',   'RU' => 'Europe/Moscow',
            'AU' => 'Australia/Sydney',   'BR' => 'America/Sao_Paulo',
            'CN' => 'Asia/Shanghai',      'ID' => 'Asia/Jakarta',
            'MX' => 'America/Mexico_City','CA' => 'America/Toronto',
            'IN' => 'Asia/Kolkata',       'AR' => 'America/Argentina/Buenos_Aires',
        ];
        if (isset($preferred[strtoupper($iso)])) {
            return $preferred[strtoupper($iso)];
        }
        $zones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, strtoupper($iso));
        return $zones[0] ?? 'UTC';
    }

    private function getTimeSlot(int $hour): string
    {
        // Bornes alignées sur ChurnScoreManager::recomputeAll() (18h-23h pour
        // "evening", 23h-6h pour "night") — auparavant "evening" s'arrêtait
        // ici à 22h au lieu de 23h. Un client classé "evening" par
        // ChurnScoreManager (et ciblable comme tel via SegmentManager pour
        // une campagne BO) recevait une salutation "night" générique dans
        // ses emails s'il ouvrait entre 22h et 23h — même mot "evening"
        // utilisé par le marchand pour cibler, mais deux définitions
        // différentes de la tranche horaire selon la fonctionnalité.
        if ($hour >= 6 && $hour < 12) return 'morning';
        if ($hour >= 12 && $hour < 18) return 'afternoon';
        if ($hour >= 18 && $hour < 23) return 'evening';
        return 'night';
    }

    private function injectFirstnameFallback(array &$templateVars, string $lang): void
    {
        if (!is_array($templateVars)) {
            return;
        }
        $firstname = trim((string) ($templateVars['{firstname}'] ?? ''));
        if ($firstname !== '') {
            return;
        }
        try {
            $fallbacks = $this->config->getFirstnameFallbacks();
            $fallback  = $fallbacks[$lang] ?? $fallbacks['en'] ?? 'Dear Guest';
            $templateVars['{firstname}'] = $fallback;
            $template = trim((string) ($templateVars['{template_name}'] ?? ''));
            (new WatchdogManager($this->module))->info(
                $template
                    ? WatchdogManager::i18nMsg('watchdog.fallback_firstname_injected_tpl', ['fallback' => $fallback, 'template' => $template, 'lang' => $lang])
                    : WatchdogManager::i18nMsg('watchdog.fallback_firstname_injected_notpl', ['fallback' => $fallback, 'lang' => $lang])
            );
        } catch (\Throwable $e) {
            $templateVars['{firstname}'] = 'Dear Guest';
            (new WatchdogManager($this->module))->warning(
                WatchdogManager::i18nMsg('watchdog.fallback_firstname_error', ['error' => $e->getMessage()])
            );
        }
    }

    /**
     * Construit le message personnalisé optionnel (saisi par le marchand lors
     * d'un envoi manuel) en deux variantes : {custom_message} (bloc HTML) et
     * {custom_message_txt} (texte). Source : {custom_message_raw}.
     *
     * Toujours défini (vide par défaut) pour qu'aucun placeholder littéral ne
     * subsiste dans les emails standards (le slot existe dans layout.html et
     * dans le TXT compilé).
     *
     * @param array $templateVars Variables Smarty (passé par référence)
     */
    private function injectCustomMessage(array &$templateVars): void
    {
        if (!is_array($templateVars)) {
            return;
        }

        $raw = isset($templateVars['{custom_message_raw}'])
            ? trim((string) $templateVars['{custom_message_raw}'])
            : '';

        if ($raw === '') {
            $templateVars['{custom_message}']     = '';
            $templateVars['{custom_message_txt}'] = '';
            return;
        }

        $safe = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        $templateVars['{custom_message}'] =
            '<div class="neria-info-box" style="margin-top:28px; font-style:italic;">'
            . nl2br($safe)
            . '</div>';

        $templateVars['{custom_message_txt}'] =
            "\n--------------------------------\n" . $raw . "\n";
    }

    /**
     * Corrige les variables de newsletter_voucher.
     *
     * ps_emailsubscription envoie ce template avec {discount} = le CODE du bon
     * (réglage NW_VOUCHER_CODE), pas un taux. Or le template Neria attend
     * {voucher_code} (le code) et {discount} (le taux dans l'intro). On remet
     * donc le code dans {voucher_code}, et on calcule le vrai taux/montant du
     * cart rule pour {discount}. Aucune traduction à modifier.
     *
     * @param array $templateVars Variables Smarty (passé par référence)
     */
    private function fixNewsletterVoucherVars(array &$templateVars): void
    {
        if (!is_array($templateVars)) {
            return;
        }

        $code = isset($templateVars['{discount}']) ? trim((string) $templateVars['{discount}']) : '';
        if ($code === '') {
            return;
        }

        // Le code va sur la ligne « Code : {voucher_code} »
        if (empty($templateVars['{voucher_code}'])) {
            $templateVars['{voucher_code}'] = $code;
        }

        // {discount} (intro « offrant … de réduction ») = vrai taux/montant du bon
        $rate = $this->voucherRateFromCode($code);
        if ($rate === '') {
            // Aucun cart rule ne correspond à ce code : l'intro afficherait un
            // montant vide. On le signale (email visiblement défectueux).
            $this->watchdog()->warning(
                WatchdogManager::i18nMsg('watchdog.voucher_rate_missing'),
                'newsletter_voucher',
                'EmailRenderer',
                ['code' => $code]
            );
        }
        $templateVars['{discount}'] = $rate;
    }

    /**
     * Retourne le taux ("10 %") ou le montant ("15,00 €") d'un bon à partir de
     * son code, en chargeant le cart rule correspondant. '' si introuvable.
     *
     * @param string $code
     * @return string
     */
    private function voucherRateFromCode(string $code): string
    {
        $id = (int) \Db::getInstance()->getValue(
            'SELECT `id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule`
             WHERE `code` = \'' . pSQL($code) . '\''
        );
        if ($id <= 0) {
            return '';
        }

        $rule = new \CartRule($id);
        if (!\Validate::isLoadedObject($rule)) {
            return '';
        }

        if ((float) $rule->reduction_percent > 0) {
            $p = (float) $rule->reduction_percent;
            // Séparateur décimal selon la langue courante — auparavant codé
            // en dur avec une virgule française, affichant "12,5 %" même
            // dans un email en anglais/japonais/allemand (18 langues sur 19).
            if (class_exists('NumberFormatter')) {
                try {
                    $ctx       = \Context::getContext();
                    $localeIso = 'en-US';
                    $lang      = $ctx->language ?? null;
                    if ($lang && !empty($lang->locale)) {
                        $localeIso = str_replace('_', '-', $lang->locale);
                    } elseif ($lang && !empty($lang->iso_code)) {
                        $localeIso = $lang->iso_code;
                    }
                    $formatter = new \NumberFormatter($localeIso, \NumberFormatter::DECIMAL);
                    $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
                    $formatted = $formatter->format($p);
                    if ($formatted !== false) {
                        return $formatted . ' %';
                    }
                } catch (\Throwable $e) {
                    // Repli ci-dessous.
                }
            }
            return \NeriaTools::formatDecimalFallback($p) . ' %';
        }

        if ((float) $rule->reduction_amount > 0) {
            $ctx = \Context::getContext();
            try {
                return \Tools::getContextLocale($ctx)->formatPrice(
                    (float) $rule->reduction_amount,
                    $ctx->currency->iso_code
                );
            } catch (\Throwable $e) {
                // Repli sans "€" ni virgule codés en dur (faux hors zone euro/FR) —
                // NeriaTools::displayPrice utilise la devise réelle du contexte et
                // la locale de la langue courante (NumberFormatter), avec son
                // propre dernier repli minimal si l'extension intl est absente.
                return \NeriaTools::displayPrice((float) $rule->reduction_amount, $ctx->currency);
            }
        }

        return '';
    }

    /**
     * GÃ©nÃ¨re des variantes texte brut des variables HTML, pour que
     * la version .txt des emails n'affiche pas de balises HTML.
     *
     * PrestaShop substitue les mÃªmes templateVars dans le .html et
     * le .txt. Une variable comme {messages} (bloc HTML de conversation)
     * apparaÃ®t donc en balises brutes dans le .txt. On crÃ©e ici une
     * variante {messages_txt} nettoyÃ©e que le template .txt utilise.
     *
     * @param array $templateVars Variables Smarty (passÃ© par rÃ©fÃ©rence)
     */
    private function injectTextVariants(array &$templateVars): void
    {
        if (!is_array($templateVars)) {
            return;
        }

        // Variables HTML connues â†’ variante texte {xxx_txt}
        $htmlKeys = ['{messages}'];

        foreach ($htmlKeys as $key) {
            if (empty($templateVars[$key])) {
                continue;
            }

            $txtKey = preg_replace('/\}$/', '_txt}', $key);

            // Si la variante texte existe dÃ©jÃ , on la respecte
            if (isset($templateVars[$txtKey])) {
                continue;
            }

            $html = (string) $templateVars[$key];
            // Convertit les sauts de bloc en retours Ã  la ligne
            $text = preg_replace('#</p>|<br\s*/?>#i', "\n", $html);
            $text = NeriaTools::sanitizeText($text);
            // Compacte les lignes vides multiples
            $text = preg_replace("/\n{2,}/", "\n", $text);

            $templateVars[$txtKey] = trim($text);
        }
    }

    // ============================================================
    // TRACKING
    // ============================================================

    /**
     * GÃ©nÃ¨re un token de tracking unique et injecte le pixel HTML
     * dans les templateVars. Le pixel est une image 1Ã—1 invisible
     * qui dÃ©clenche un "open" quand l'email est ouvert.
     *
     * @param string $template    Nom du template
     * @param string $lang        Code langue
     * @param array  $params      ParamÃ¨tres email (passÃ© par rÃ©fÃ©rence)
     */
    private function injectTrackingPixel(
        string $template,
        string $lang,
        array &$params
    ): void {
        // GÃ©nÃ¨re un token unique par email
        // $params['to'] peut être un tableau (envoi multi-destinataires) :
        // on retient la première adresse pour le token (string attendue).
        $to = $params['to'] ?? '';
        if (is_array($to)) {
            $to = (string) (reset($to) ?: '');
        }
        $token = $this->generateTrackingToken(
            $template,
            $lang,
            (string) $to
        );

        // URL du pixel de tracking (module front controller)
        // id_lang explicite : sans lui, getModuleLink() utilise la langue du
        // CONTEXTE courant (admin/cron) plutôt que celle réelle de l'email —
        // même bug que {history_url} plus haut, trouvé sur le lien de clic
        // (ci-dessous) qui restait préfixé "/fr/" sur un envoi en anglais.
        $trackIdLang = !empty($params['idLang']) ? (int) $params['idLang'] : null;
        $trackingUrl = $this->context->link->getModuleLink(
            'neria',
            'track',
            ['t' => $token, 'e' => 'open'],
            true, // HTTPS forcÃ©
            $trackIdLang
        );

        // Pixel HTML 1Ã—1 invisible â€” compatible tous clients email
        $pixel = sprintf(
            '<img src="%s" width="1" height="1" '
            . 'style="display:block;width:1px;height:1px;border:0;" '
            . 'alt="" />',
            htmlspecialchars($trackingUrl, ENT_QUOTES, 'UTF-8')
        );

        // Injecte dans templateVars
        $params['templateVars']['neria_tracking_pixel'] = $pixel;
        $params['templateVars']['neria_tracking_token'] = $token;

        // Stocke le token pour que StatsManager puisse enregistrer l'envoi
        $params['neria_token']    = $token;
        $params['neria_template'] = $template;
        $params['neria_lang']     = $lang;
    }

    /**
     * Remplace les href HTTP(S) du fichier HTML compilé par des URLs de tracking.
     * Permet de compter les clics et d'identifier le visiteur pour l'attribution.
     * Liens ignorés : mailto, tel, #, javascript, déjà trackés, désabonnement.
     */
    private function wrapLinksInFile(string $filePath, string $token, int $idLang = 0): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }
        $html = file_get_contents($filePath);
        if ($html === false || $html === '') {
            return;
        }

        // id_lang explicite — même correctif que injectTrackingPixel() plus
        // haut : sans lui, ces liens de clic restent préfixés par la langue
        // du contexte admin/cron plutôt que celle réelle de l'email.
        $wrapIdLang = $idLang > 0 ? $idLang : null;

        // Matche uniquement les balises <a …> pour ne pas wrapper les <link>
        $wrapped = preg_replace_callback(
            '/<a(\s[^>]*)>/i',
            function ($m) use ($token, $wrapIdLang) {
                $attrs = preg_replace_callback(
                    '/\bhref=(["\'])(https?:\/\/[^"\'>\s]+)\1/i',
                    function ($am) use ($token, $wrapIdLang) {
                        $quote = $am[1];
                        $url   = $am[2];
                        if (
                            strpos($url, 'controller=track')    !== false ||
                            strpos($url, '/neria/track')        !== false ||
                            strpos($url, '/neria/unsubscribe')  !== false ||
                            strpos($url, 'neria_action=unsubscribe') !== false
                        ) {
                            return $am[0];
                        }
                        $trackUrl = $this->context->link->getModuleLink(
                            'neria',
                            'track',
                            [
                                't'   => $token,
                                'e'   => 'click',
                                'url' => $url,
                                // Signature HMAC token+URL : empêche de rejouer un token
                                // valide avec une URL de destination différente/arbitraire
                                // (open redirect) — cf. NeriaTools::signTrackingUrl().
                                's'   => NeriaTools::signTrackingUrl($token, $url),
                            ],
                            true,
                            $wrapIdLang
                        );
                        return 'href=' . $quote . $trackUrl . $quote;
                    },
                    $m[1]
                );
                return '<a' . $attrs . '>';
            },
            $html
        );

        if ($wrapped !== null && $wrapped !== $html) {
            file_put_contents($filePath, $wrapped);
        }
    }

    /**
     * GÃ©nÃ¨re un token SHA-256 unique pour un email
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     * @param string $to       Email destinataire
     * @return string Token hexadÃ©cimal de 64 caractÃ¨res
     */
    private function generateTrackingToken(
        string $template,
        string $lang,
        string $to
    ): string {
        return hash('sha256', implode('|', [
            $template,
            $lang,
            $to,
            microtime(true),
            random_int(100000, 999999),
        ]));
    }

    // ============================================================
    // A/B TESTING
    // ============================================================

    /**
     * DÃ©termine la variante A/B Ã  utiliser pour un email donnÃ©
     * Retourne 'A', 'B' ou '' si pas de test actif
     *
     * @param string $template Nom du template
     * @param array  $params   ParamÃ¨tres email
     * @return string
     */
    private function resolveABVariant(string $template, array $params): string
    {
        if (!$this->config->isAbtestEnabled()) {
            return '';
        }

        if (!class_exists('ABTestManager')) {
            return '';
        }

        $abManager = new ABTestManager($this->module);
        $toEmail   = is_array($params['to'] ?? null) ? (string) reset($params['to']) : (string) ($params['to'] ?? '');
        return $abManager->getVariantForEmail(
            $template,
            (int) ($params['idCustomer'] ?? 0),
            $toEmail
        );
    }

    // ============================================================
    // RÉSOLUTION DE LA LANGUE
    // ============================================================

    /**
     * Détermine la langue de l'email.
     *
     * Si la détection automatique (NERIA_AUTO_LANG) est activée, délègue
     * à TranslationEngine::resolveOptimalLang() qui tient compte du choix
     * explicite du client et du pays de livraison. Sinon, conserve le
     * comportement PrestaShop historique (langue du compte).
     *
     * @param array $params Paramètres de l'email
     * @return string Code langue Neria
     */
    private function resolveEmailLang(array $params): string
    {
        $idLang = (int) ($params['idLang'] ?? 0);

        if (!$this->config->isAutoLangEnabled()) {
            return $this->engine->langFromId($idLang);
        }

        // Pour les emails internes (marchand, employés), on utilise directement
        // idLang sans chercher la localisation client : l'adresse admin peut être
        // enregistrée comme client avec un pays français, ce qui forcerait
        // systématiquement le français sur les envois de test.
        if ($this->currentInternal) {
            // Priorité 1 : langue explicitement demandée via le picker test Neria
            // (fonctionne même si la langue n'est pas installée dans ps_lang)
            $testLang = (string) \Tools::getValue('neria_test_lang', '');
            if ($testLang !== '' && in_array($testLang, TranslationEngine::SUPPORTED_LANGS, true)) {
                return $testLang;
            }
            // Priorité 2 : id_lang transmis par PS (langue de l'employé en base)
            $lang = $this->engine->langFromId($idLang);
            return in_array($lang, TranslationEngine::SUPPORTED_LANGS, true)
                ? $lang
                : TranslationEngine::FALLBACK_LANG;
        }

        $idCustomer = $this->resolveCustomerId($params);
        $location   = $this->getCustomerLocation($idCustomer, $params);

        // Surveillance : sur une boutique mono-langue, c'est le pays du client
        // qui décide de la langue. Si aucune localisation n'a pu être trouvée,
        // on retombe sur la langue par défaut — l'email peut donc partir dans
        // une langue qui ne correspond pas au lecteur, sans erreur visible.
        if ($location['iso'] === '' && !$this->engine->isMultilingualShop()) {
            $this->softLog(
                'warning',
                WatchdogManager::i18nMsg('watchdog.lang_fallback'),
                $this->resolveTemplate($params['template'] ?? ''),
                [
                    'id_customer' => $idCustomer,
                    'id_order'    => (int) (($params['templateVars']['{id_order}'] ?? 0)),
                    'to'          => is_array($params['to'] ?? null) ? reset($params['to']) : ($params['to'] ?? ''),
                ]
            );
        }

        return $this->engine->resolveOptimalLang(
            $idLang,
            $location['iso'],
            $location['postcode']
        );
    }

    /**
     * Retrouve l'id_customer à partir de l'email destinataire.
     *
     * Le hook actionEmailSendBefore ne fournit pas d'id_customer ; on le
     * déduit de l'adresse email (champ 'to') pour pouvoir remonter au
     * pays de livraison.
     *
     * @param array $params Paramètres de l'email
     * @return int id_customer ou 0 si introuvable
     */
    private function resolveCustomerId(array $params): int
    {
        $to = $params['to'] ?? '';
        if (is_array($to)) {
            $to = reset($to);
        }
        $to = trim((string) $to);

        if ($to === '' || !\Validate::isEmail($to)) {
            return 0;
        }

        return (int) \Db::getInstance()->getValue(
            'SELECT `id_customer`
             FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `email` = \'' . pSQL($to) . '\'
               AND `deleted` = 0
             ORDER BY `id_customer` DESC'
        );
    }

    /**
     * Détermine la localisation de référence du client (pays + code postal)
     * pour le choix de la langue.
     *
     * Privilégie l'adresse de FACTURATION (le titulaire du compte qui lit
     * l'email), pas la livraison — celle-ci peut être un tiers (cadeau,
     * bureau, famille à l'étranger) et ne reflète pas la langue du lecteur.
     * Le code postal sert à départager les pays multilingues (BE, CH).
     *
     * 1. Email de commande ({id_order} présent dans templateVars) :
     *    adresse de facturation de CETTE commande (orders.id_address_invoice).
     * 2. Email hors commande (newsletter, compte…) : adresse principale du
     *    client (la plus récente) comme approximation.
     *
     * @param int   $idCustomer
     * @param array $params Paramètres de l'email (dont templateVars)
     * @return array{iso:string, postcode:string}
     */
    private function getCustomerLocation(int $idCustomer, array $params): array
    {
        // 1. Adresse de facturation de la commande liée à l'email
        $loc = $this->invoiceLocationFromOrder($params);
        if ($loc['iso'] !== '') {
            return $loc;
        }

        // 2. Repli : adresse principale du client (la plus récente)
        return $this->customerAddressLocation($idCustomer);
    }

    /**
     * Localisation (pays + code postal) de l'adresse de facturation de la
     * commande référencée par {id_order} dans les templateVars.
     *
     * @param array $params Paramètres de l'email
     * @return array{iso:string, postcode:string}
     */
    private function invoiceLocationFromOrder(array $params): array
    {
        $vars    = is_array($params['templateVars'] ?? null) ? $params['templateVars'] : [];
        $idOrder = (int) ($vars['{id_order}'] ?? 0);

        if ($idOrder <= 0) {
            return ['iso' => '', 'postcode' => ''];
        }

        $row = \Db::getInstance()->getRow(
            'SELECT co.`iso_code`, a.`postcode`
             FROM `' . _DB_PREFIX_ . 'orders` o
             INNER JOIN `' . _DB_PREFIX_ . 'address` a ON a.`id_address` = o.`id_address_invoice`
             INNER JOIN `' . _DB_PREFIX_ . 'country` co ON co.`id_country` = a.`id_country`
             WHERE o.`id_order` = ' . $idOrder
        );

        return $this->locationRow($row);
    }

    /**
     * Localisation (pays + code postal) de l'adresse non supprimée la plus
     * récente d'un client. Approximation de l'adresse principale en l'absence
     * de commande (emails hors commande).
     *
     * @param int $idCustomer
     * @return array{iso:string, postcode:string}
     */
    private function customerAddressLocation(int $idCustomer): array
    {
        if ($idCustomer <= 0) {
            return ['iso' => '', 'postcode' => ''];
        }

        $row = \Db::getInstance()->getRow(
            'SELECT co.`iso_code`, a.`postcode`
             FROM `' . _DB_PREFIX_ . 'address` a
             INNER JOIN `' . _DB_PREFIX_ . 'country` co ON co.`id_country` = a.`id_country`
             WHERE a.`id_customer` = ' . $idCustomer . '
               AND a.`deleted` = 0
             ORDER BY a.`date_upd` DESC'
        );

        return $this->locationRow($row);
    }

    /**
     * Normalise une ligne SQL {iso_code, postcode} en localisation.
     *
     * @param array|false|null $row
     * @return array{iso:string, postcode:string}
     */
    private function locationRow($row): array
    {
        if (!is_array($row) || empty($row['iso_code'])) {
            return ['iso' => '', 'postcode' => ''];
        }

        return [
            'iso'      => strtoupper((string) $row['iso_code']),
            'postcode' => (string) ($row['postcode'] ?? ''),
        ];
    }

    // ============================================================
    // RÃ‰SOLUTION DES RESSOURCES
    // ============================================================

    /**
     * Nettoie et normalise le nom du template
     * PrestaShop peut passer 'order_conf.html' ou 'order_conf'
     *
     * @param string $raw Nom brut du template
     * @return string Nom normalisÃ© (sans extension)
     */
    private function resolveTemplate(string $raw): string
    {
        // Supprime l'extension si prÃ©sente
        $template = preg_replace('/\.(html?|txt)$/i', '', trim($raw));

        // Supprime les caractÃ¨res non autorisÃ©s (sÃ©curitÃ©)
        $template = preg_replace('/[^a-z0-9_-]/i', '', $template);

        $template = strtolower($template);

        // Alias : remappe les templates PS/modules tiers vers Neria
        return self::TEMPLATE_ALIASES[$template] ?? $template;
    }

    /**
     * Indique si un template est exclu du traitement Neria
     *
     * @param string $template Nom du template
     * @param array  $params   Paramètres de l'email (pour la résolution de langue)
     * @return bool
     */
    private function isExcluded(string $template, array $params = []): bool
    {
        if (in_array($template, self::EXCLUDED_TEMPLATES, true)) {
            return true;
        }
        // Résolution rapide de la langue (idLang uniquement, sans lookup client)
        $lang = '';
        if (!empty($params['idLang'])) {
            $lang = $this->engine->langFromId((int) $params['idLang']);
        }
        return (new BlacklistManager())->isBlacklisted($template, $lang);
    }

    /**
     * RÃ©sout l'URL publique du logo depuis son chemin relatif
     *
     * @param string $relativePath Chemin relatif (ex: data/signatures/logo_1.png)
     * @return string URL absolue ou URL du logo PS par dÃ©faut
     */
    private function resolveLogoUrl(string $relativePath): string
    {
        if (empty($relativePath)) {
            // Fallback : logo de la boutique PrestaShop
            return $this->context->link->getMediaLink(
                _PS_IMG_ . \Configuration::get('PS_LOGO')
            );
        }

        return $this->module->getModuleUrl($relativePath);
    }

    /**
     * RÃ©cupÃ¨re les donnÃ©es de la signature active pour la boutique
     * Retourne un tableau avec url, name, title
     *
     * @return array
     */
    private function resolveSignature(): array
    {
        $table  = _DB_PREFIX_ . 'neria_signature';
        $idShop = (int) $this->context->shop->id;

        $row = \Db::getInstance()->getRow(
            "SELECT `signer_name`, `signer_title`, `image_path`
             FROM `{$table}`
             WHERE `id_shop`  = {$idShop}
               AND `is_active` = 1"
        );

        if (!$row) {
            return ['url' => '', 'name' => '', 'title' => ''];
        }

        $url = !empty($row['image_path'])
            ? $this->module->getModuleUrl($row['image_path'])
            : '';

        return [
            'url'   => $url,
            'name'  => $row['signer_name'],
            'title' => $row['signer_title'],
        ];
    }

    // ============================================================
    // APERÃ‡U BACK-OFFICE (temps rÃ©el)
    // ============================================================

    /**
     * GÃ©nÃ¨re un aperÃ§u HTML d'un template pour le back-office
     * UtilisÃ© par l'onglet Design pour l'aperÃ§u en temps rÃ©el
     *
     * @param string $template    Nom du template (ex: order_conf)
     * @param string $lang        Code langue (ex: fr)
     * @param array  $designOverride Valeurs de design temporaires
     *                              (couleurs/polices non encore sauvegardÃ©es)
     * @return string HTML rendu
     */
    public function renderPreview(
        string $template,
        string $lang,
        array $designOverride = []
    ): string {
        // Chemin vers le template HTML
        $templatePath = $this->module->getModulePath(
            'mails/themes/neria_global/core/' . $template . '.html'
        );

        if (!file_exists($templatePath)) {
            return '<p style="color:red;">Template introuvable : '
                . htmlspecialchars($template) . '</p>';
        }

        // Enregistre {neria_trad} pour le rendu de l'aperÃ§u
        $this->registerSmartyFunction($template, $lang, '');

        // Variables de design (avec override pour l'aperÃ§u temps rÃ©el)
        $design = array_merge($this->config->getDesignConfig(), $designOverride);

        // Variables Smarty pour l'aperÃ§u
        $previewVars = [
            'neria_color_background' => $design['color_background'],
            'neria_color_container'  => $design['color_container'],
            'neria_color_accent'     => $design['color_accent'],
            'neria_color_text'       => $design['color_text'],
            'neria_dark_mode'        => $design['dark_mode'] ? 'true' : 'false',
            'neria_container_width'  => $design['container_width'],
            'neria_logo_width'       => $design['logo_width'],
            'neria_logo_url'         => $this->resolveLogoUrl($design['logo_path']),
            'neria_color_header_bg'   => $design['color_header_bg']   ?? '#ffffff',
            'neria_color_footer_bg'   => $design['color_footer_bg']   ?? '#ffffff',
            'neria_color_footer_text' => $design['color_footer_text'] ?? '#a09990',
            'neria_font_heading_family' => $this->config->getHeadingFontFamily($design['font_heading'] ?? 'Cormorant Garamond'),
            'neria_google_font_link'  => $this->googleFontLinks($lang, $design['font_heading'] ?? 'Cormorant Garamond'),
            'neria_btn_radius'        => (int)($design['btn_radius'] ?? 2),
            'neria_btn_color'         => $design['btn_color'] ?? '#2b2520',
            'neria_section_padding'   => (int)($design['section_padding'] ?? 40),
            'neria_block_spacing'     => (int)($design['block_spacing'] ?? 48),
            'neria_separator_css'     => \ConfigManager::getSeparatorCss($design['separator_style'] ?? 'line'),
            'neria_card_shadow'       => \ConfigManager::getCardShadowCss($design['card_shadow'] ?? 'soft'),
            'neria_font_size'         => (int) ($design['font_size'] ?? 14),
            'neria_line_height'       => number_format((float) ($design['line_height'] ?? 1.8), 1, '.', ''),
            'neria_heading_weight'    => (int) ($design['heading_weight'] ?? 600),
            'neria_font_family'      => $this->fonts()->getCssFamilyForLang($lang),
            'neria_dir'              => $this->engine->isRtl($lang) ? 'rtl' : 'ltr',
            'neria_text_align'       => $this->engine->isRtl($lang) ? 'right' : 'left',
            'neria_is_rtl'           => $this->engine->isRtl($lang),
            'neria_lang'             => $lang,
            'neria_has_social'       => false,
            'neria_has_signature'    => false,
            'neria_tracking_pixel'   => '', // Pas de tracking en aperçu

            // Variables PrestaShop factices pour l'aperÃ§u
            'shop_name'              => \Configuration::get('PS_SHOP_NAME'),
            'shop_url'               => \Tools::getShopDomainSsl(true),
            'order_name'             => 'NR-000123',
            'date'                   => NeriaTools::formatDate(date('Y-m-d H:i:s'), $lang),
            'payment'                => 'Carte bancaire',
            'total_paid'             => '189,00 €',
            'total_products'         => '189,00 €',
            'total_discounts'        => '0,00 €',
            'total_shipping'         => '0,00 €',
            'total_tax_paid'         => '31,50 €',
            'carrier'                => 'Colissimo',
            'delivery_block_html'    => '<p>12 rue de la Paix<br>75001 Paris</p>',
            'invoice_block_html'     => '<p>12 rue de la Paix<br>75001 Paris</p>',
            'history_url'            => '#',
            'guest_tracking_url'     => '#',
            'products'               => $this->getFakeProductsList(),
            'discounts'              => '',
        ];

        // Rendu via Smarty
        $smarty = $this->context->smarty;
        $smarty->assign($previewVars);

        try {
            return $smarty->fetch($templatePath);
        } catch (\Throwable $e) {
            $this->module->log(
                'EmailRenderer::renderPreview erreur â†’ ' . $e->getMessage(),
                3
            );
            return '<p style="color:red;">Erreur de rendu : '
                . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    /**
     * GÃ©nÃ¨re un faux tableau produits HTML pour l'aperÃ§u
     *
     * @return string HTML du tableau produits
     */
    private function getFakeProductsRows(): string
    {
        return '<tr>
            <td>NR-001</td>
            <td>Montre Artisanale Édition Limitée</td>
            <td>189,00 €</td>
            <td>1</td>
            <td style="text-align:right;">189,00 €</td>
        </tr>
        <tr>
            <td>NR-014</td>
            <td>Bracelet Cuir Atelier</td>
            <td>79,00 €</td>
            <td>1</td>
            <td style="text-align:right;">79,00 €</td>
        </tr>';
    }

    private function getFakeProductsList(): string
    {
        return '<ul style="margin:0;padding:0 0 0 18px;">
            <li>× 1 Montre Artisanale Édition Limitée — 189,00 €</li>
            <li>× 2 Bracelet Cuir Atelier — 79,00 €</li>
        </ul>';
    }

    private function resolveFakeProducts(string $template): string
    {
        // Templates avec {products} directement dans un <tbody> de neria-products-table
        $tableTemplates = ['order_conf', 'order_conf_virtual', 'order_changed', 'order_return'];
        return in_array($template, $tableTemplates, true)
            ? $this->getFakeProductsRows()
            : $this->getFakeProductsList();
    }

    /**
     * Génère l'aperçu HTML d'un email pour le back-office (onglet Design).
     * Réutilise le vrai chemin de compilation (str_replace, pas de Smarty
     * fetch), applique un override de design (couleurs/largeur non encore
     * sauvegardées) et injecte des valeurs fictives pour les placeholders
     * PrestaShop restants.
     *
     * @param string $template       Nom du template (ex: order_conf)
     * @param string $lang           Code langue
     * @param array  $designOverride Valeurs de design temporaires
     * @return string HTML de l'aperçu
     */
    public function renderPreviewHtml(string $template, string $lang, array $designOverride = [], bool $variantB = false): string
    {
        // monthly_report a son propre rendu HTML autonome (page complète
        // indépendante de layout.html/core/*.html, cf. MonthlyReportManager::
        // renderHtml) — core/monthly_report.html est un fichier hérité d'une
        // architecture antérieure et ne contient pas les vraies données.
        if ($template === 'monthly_report' && class_exists('MonthlyReportManager')) {
            try {
                return (new \MonthlyReportManager($this->module))->previewHtml($lang);
            } catch (\Throwable $e) {
                return '<p style="padding:40px;font-family:sans-serif;color:#a33;">'
                    . AdminTranslator::tVars('watchdog.preview_unavailable', ['template' => htmlspecialchars($template)])
                    . '</p>';
            }
        }

        $design   = array_merge($this->config->getDesignConfig(), $designOverride);
        $abtestMgr = ($variantB && class_exists('ABTestManager')) ? new \ABTestManager($this->module) : null;
        // Les fausses valeurs de démo (dont {shop_url}, {history_url}...) et le
        // nettoyage des résidus doivent se faire AVANT l'inlining CSS (voir
        // buildCompiledHtml) — sinon les accolades encore présentes dans un
        // href/src sont percent-encodées par le parseur DOM et ne correspondent
        // plus à aucun remplacement ensuite (elles resteraient visibles en
        // %7Bxxx%7D au lieu d'être remplacées par le repère neutre « … »).
        $compiled = $this->buildCompiledHtml($template, $lang, $design, $abtestMgr, $this->buildPreviewFakes($template, $lang), '…');

        if ($compiled === null) {
            return '<p style="padding:40px;font-family:sans-serif;color:#a33;">'
                . AdminTranslator::tVars('watchdog.preview_unavailable', ['template' => htmlspecialchars($template)])
                . '</p>';
        }

        return $compiled;
    }

    /**
     * Rend un template avec de VRAIES variables (snapshot d'un envoi passé),
     * pour l'aperçu fidèle et le renvoi depuis l'historique client. À la
     * différence de renderPreviewHtml() (données fictives pour la démo design),
     * les variables manquantes sont effacées plutôt que remplacées par des
     * valeurs inventées — il s'agit d'un email réel destiné à un client réel.
     *
     * @param string $template
     * @param string $lang
     * @param array  $vars Paires clé (sans accolades) => valeur, ex. ['order_name' => 'NR-123']
     * @return string|null HTML compilé, ou null si le template est introuvable
     */
    public function renderWithVars(string $template, string $lang, array $vars): ?string
    {
        $design = $this->config->getDesignConfig();

        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }
        // Toujours résolus avec les données actuelles de la boutique, pas
        // figés dans le snapshot (l'adresse/le nom de la boutique peuvent
        // avoir changé depuis l'envoi d'origine).
        $replacements['{shop_name}'] = (string) \Configuration::get('PS_SHOP_NAME');
        $replacements['{shop_url}']  = $this->context->link->getBaseLink();

        // Résolus AVANT l'inlining CSS (voir buildCompiledHtml) — un {shop_url}
        // encore présent dans un href au moment du passage DOM serait
        // percent-encodé (accolades invalides en URI) et ne matcherait plus
        // aucun remplacement après coup. Idem pour le nettoyage des résidus
        // (variables non capturées dans le snapshot) : effacés AVANT
        // CssInliner, sinon un résidu dans un href resterait visible sous
        // forme %7Bxxx%7D au lieu d'être proprement effacé.
        $compiled = $this->buildCompiledHtml($template, $lang, $design, null, $replacements, '');
        if ($compiled === null) {
            return null;
        }

        return $compiled;
    }

    /**
     * Compile layout + core en HTML plat (résout {neria_trad} et les variables
     * de design) sans écrire de fichier. Coeur partageable entre l'envoi réel
     * et l'aperçu. Retourne null si le template est introuvable.
     *
     * @param string $template
     * @param string $lang
     * @param array  $design Configuration de design (déjà fusionnée)
     * @param array  $extraReplacements Paires '{cle}' => valeur (ex. {shop_url},
     *               données fictives d'aperçu ou snapshot d'historique) à résoudre
     *               AVANT l'inlining CSS — un placeholder encore présent dans un
     *               href/src au moment du passage par CssInliner (DOMDocument)
     *               est percent-encodé (accolades invalides en URI, { → %7B) et
     *               ne correspond plus à aucun remplacement fait après coup.
     * @param string|null $residualReplacement Si fourni, tout placeholder {xxx}
     *               encore présent après $extraReplacements est remplacé par
     *               cette valeur (ex. '…' pour l'aperçu, '' pour effacer) —
     *               AVANT CssInliner, pour la même raison que ci-dessus.
     *               Si null, les résidus sont laissés tels quels.
     * @return string|null
     */
    private function buildCompiledHtml(string $template, string $lang, array $design, ?\ABTestManager $abtestMgr = null, array $extraReplacements = [], ?string $residualReplacement = null): ?string
    {
        $layoutPath = $this->module->getModulePath('mails/themes/neria_global/layout.html');
        $corePath   = $this->module->getModulePath('mails/themes/neria_global/core/' . $template . '.html');

        if (!file_exists($layoutPath) || !file_exists($corePath)) {
            return null;
        }

        $layout = file_get_contents($layoutPath);
        $core   = file_get_contents($corePath);

        if (!preg_match('/\{block\s+name=[\'"]neria_content[\'\"]\}(.*?)\{\/block\}/s', $core, $m)) {
            return null;
        }

        $compiled = preg_replace('/\{block\s+name=[\'"]neria_content[\'\"]\}\{\/block\}/', trim($m[1]), $layout);
        $compiled = preg_replace('/\{extends\s+[^}]+\}/', '', $compiled);

        $engine   = $this->engine;
        $compiled = preg_replace_callback(
            '/\{neria_trad\s+key=[\'"]([a-z0-9_]+)[\'"]\s*\}/',
            function ($mm) use ($engine, $template, $lang, $abtestMgr) {
                // Variante B : utiliser la valeur B si elle existe, sinon fallback A
                if ($abtestMgr !== null) {
                    $vB = $abtestMgr->getVariantBValue($template, $lang, $mm[1]);
                    if ($vB !== null && $vB !== '') {
                        return self::sanitizeTranslationHtml($vB);
                    }
                }
                $v = self::sanitizeTranslationHtml($engine->get($template, $mm[1], $lang));
                return $v !== '' ? $v : $mm[0];
            },
            $compiled
        );

        $tplVars = [
            '{$neria_color_accent}'     => $design['color_accent'],
            '{$neria_color_background}' => $design['color_background'],
            '{$neria_color_container}'  => $design['color_container'],
            '{$neria_color_text}'       => $design['color_text'],
            '{$neria_font_family}'      => $this->fonts()->getCssFamilyForLang($lang),
            '{$neria_dir}'              => $this->engine->isRtl($lang) ? 'rtl' : 'ltr',
            '{$neria_text_align}'       => $this->engine->isRtl($lang) ? 'right' : 'left',
            // Padding d'une cellule de tableau image+texte (image collée d'un
            // côté, texte de l'autre) : le côté "collé à l'image" doit passer
            // à droite en RTL au lieu de rester à gauche — sinon le titre/texte
            // se retrouve avec un espace du mauvais côté par rapport à l'image
            // (trouvé en réel sur waitlist_available.html/collection_completion.html).
            '{$neria_img_text_pad}'     => $this->engine->isRtl($lang) ? '20px 0 20px 20px' : '20px 20px 20px 0',
            '{$neria_container_width}'  => (string) $design['container_width'],
            '{$neria_logo_width}'       => (string) $design['logo_width'],
            '{$neria_logo_url}'         => $this->resolveLogoUrl($design['logo_path']),
            '{$neria_color_header_bg}'   => $design['color_header_bg']   ?? '#ffffff',
            '{$neria_color_footer_bg}'   => $design['color_footer_bg']   ?? '#ffffff',
            '{$neria_color_footer_text}' => $design['color_footer_text'] ?? '#a09990',
            '{$neria_font_heading_family}' => $this->config->getHeadingFontFamily($design['font_heading'] ?? 'Cormorant Garamond'),
            '{$neria_google_font_link}'  => $this->googleFontLinks($lang, $design['font_heading'] ?? 'Cormorant Garamond'),
            '{$neria_btn_radius}'        => (string)(int)($design['btn_radius'] ?? 2),
            '{$neria_btn_color}'         => $design['btn_color'] ?? '#2b2520',
            '{$neria_section_padding}'   => (string)(int)($design['section_padding'] ?? 40),
            '{$neria_block_spacing}'     => (string)(int)($design['block_spacing'] ?? 48),
            '{$neria_separator_css}'     => \ConfigManager::getSeparatorCss($design['separator_style'] ?? 'line'),
            '{$neria_card_shadow}'       => \ConfigManager::getCardShadowCss($design['card_shadow'] ?? 'soft'),
            '{$neria_font_size}'         => (string)(int)($design['font_size'] ?? 14),
            '{$neria_line_height}'       => number_format((float)($design['line_height'] ?? 1.8), 1, '.', ''),
            '{$neria_heading_weight}'    => (string)(int)($design['heading_weight'] ?? 600),
            '{$neria_tracking_pixel}'   => '',
            '{$neria_social_links}'     => '',
            '{$neria_lang}'             => $lang,
        ];
        // strtr() (et non str_replace() avec des tableaux) : évite qu'une
        // valeur BO (nom de marque, slogan, texte personnalisé) contenant
        // littéralement "{autre_variable}" ne se fasse re-substituer selon
        // l'ordre d'itération — même correctif que TranslationEngine::
        // resolveVariables().
        $compiled = strtr($compiled, $tplVars);

        // ── Placeholders génériques {xxx} (shop_url, fausses valeurs d'aperçu,
        // snapshot d'historique...) — résolus ICI, avant CssInliner (cf. docblock).
        if (!empty($extraReplacements)) {
            $compiled = strtr($compiled, $extraReplacements);
        }

        // ── Blocs conditionnels {if var}...{else}...{/if} (sans isset()) —
        // même logique que compileNeriaTemplate (envoi réel), pour que
        // l'aperçu reflète fidèlement ce qui sera envoyé.
        $compiled = preg_replace_callback(
            '/\{if\s+\$?([a-z_]+)\s*\}(.*?)(?:\{else\}(.*?))?\{\/if\}/s',
            static function ($m) use ($extraReplacements) {
                $val = $extraReplacements['{' . $m[1] . '}'] ?? '';
                return !empty($val) ? $m[2] : ($m[3] ?? '');
            },
            $compiled
        );

        $compiled = preg_replace('/\{if\s[^}]+\}.*?\{\/if\}/s', '', $compiled);
        $compiled = preg_replace('/\{\*.*?\*\}/s', '', $compiled);
        $compiled = preg_replace('/\{\$[a-z_]+\}/', '', $compiled);

        // Résidus {xxx} non couverts par $extraReplacements — résolus ICI,
        // avant CssInliner, pour la même raison (cf. docblock).
        if ($residualReplacement !== null) {
            $compiled = preg_replace('/\{[a-z][a-z0-9_]*\}/i', $residualReplacement, $compiled);
        }

        // Empreinte carbone — injecté AVANT le CSS inlining (DOMDocument déplace
        // les commentaires HTML hors des <table>, le str_replace ne les retrouve plus après)
        $carbonHtml = '';
        if ($this->config->isCarbonEnabled()) {
            $sizeKb  = strlen($compiled) / 1024;
            $co2     = number_format($sizeKb * 0.02, 1, '.', ''); // ~0.3g pour 15 Ko
            $link    = $this->config->getCarbonLink();
            $carbonLabel  = $this->engine->get('_global', 'carbon_label', $lang) ?: 'Empreinte estimée de cet email';
            $carbonMethod = $this->engine->get('_global', 'carbon_method', $lang) ?: 'méthodologie';
            $linkHtml = $link
                ? ' — <a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="color:#a09990;text-decoration:underline;" target="_blank">' . htmlspecialchars($carbonMethod, ENT_QUOTES, 'UTF-8') . '</a>'
                : '';
            $carbonHtml = '<tr><td style="text-align:center;font-family:Georgia,Times New Roman,serif;'
                . 'font-size:11px;color:#a09990;padding:4px 20px 20px;line-height:1.8;">'
                . '🌱 ' . htmlspecialchars($carbonLabel, ENT_QUOTES, 'UTF-8') . '&nbsp;: ~' . $co2 . 'g CO₂' . $linkHtml
                . '</td></tr>';
        }
        $compiled = str_replace('<!-- NERIA_CARBON -->', $carbonHtml, $compiled);

        // Inline CSS pour compatibilité Gmail / Orange / Yahoo (suppriment <style>)
        if (class_exists('CssInliner')) {
            $compiled = CssInliner::inline($compiled);
        }

        return $compiled;
    }

    /**
     * Construit les fausses valeurs de démo pour l'aperçu (nom client, montants,
     * liens neutres...). Retournées en tableau — à résoudre AVANT l'inlining CSS
     * via buildCompiledHtml(), jamais après (cf. docblock de buildCompiledHtml).
     *
     * @param string $template
     * @return array
     */
    private function buildPreviewFakes(string $template = '', string $lang = 'fr'): array
    {
        return [
            // ── Contexte client / boutique / commande ──────────────
            '{shop_name}'          => (string) \Configuration::get('PS_SHOP_NAME'),
            // getBaseLink() (pas getShopDomainSsl seul) : inclut le sous-répertoire
            // __PS_BASE_URI__ avec le / final, comme le fait le vrai envoi — sinon
            // {shop_url}content/... colle "localhost" et "content" sans séparateur.
            '{shop_url}'           => $this->context->link->getBaseLink(),
            '{firstname}'          => 'Sophie',
            '{lastname}'           => 'Durand',
            '{email}'              => (string) \Configuration::get('PS_SHOP_EMAIL'),
            '{order_name}'         => 'NR-000123',
            '{id_order}'           => '123',
            '{date}'               => NeriaTools::formatDate(date('Y-m-d H:i:s'), $lang),
            '{payment}'            => 'Carte bancaire',
            '{total_paid}'         => '189,00 €',
            '{total_products}'     => '189,00 €',
            '{total_shipping}'     => '0,00 €',
            '{total_tax_paid}'     => '31,50 €',
            '{total_discounts}'    => '0,00 €',
            '{total_wrapping}'     => '0,00 €',
            '{total}'              => '189,00 €',
            '{carrier}'            => 'Colissimo',
            '{carrier_name}'       => 'Colissimo',
            '{nbProducts}'         => '2',
            '{products}'           => $this->resolveFakeProducts($template),
            '{discounts}'          => '',
            '{items}'              => '<p>Réf. NER-001 — Montre Élégance Neria × 1 — 89,00 €</p>',
            '{return_address_html}' => 'Neria Retours<br>15 rue du Commerce<br>75015 Paris<br>France',
            // ── Géré ailleurs / vide ───────────────────────────────
            '{custom_message}'     => '',
            '{validity_days}'      => (string) $this->config->getVoucherValidity(),
            '{unsubscribe_url}'    => $this->module->getUnsubscribeUrl('client@example.com'),
            '{preferences_url}'    => '#',
            // ── Liens (aperçu : ancres neutres) ────────────────────
            '{history_url}'        => '#',
            '{guest_tracking_url}' => '#',
            '{tracking_url}'       => '#',
            '{order_url}'          => '#',
            '{order_link}'         => '#',
            '{followup}'           => '#',
            '{link}'               => '#',
            '{url}'                => '#',
            '{contact_url}'        => '#',
            '{sale_url}'           => '#',
            '{cart_url}'           => '#',
            '{product_url}'        => '#',
            '{product_link}'       => '#',
            '{review_url}'         => '#',
            '{rsvp_url}'           => '#',
            '{verif_url}'          => '#',
            // ── Adresses ───────────────────────────────────────────
            '{delivery_block_html}' => '<strong>Sophie Durand</strong><br>12 rue de la Paix<br>75001 Paris<br>France',
            '{invoice_block_html}'  => '<strong>Sophie Durand</strong><br>12 rue de la Paix<br>75001 Paris<br>France',
            '{check_address_html}'  => '12 rue de la Paix<br>75001 Paris',
            '{check_name}'          => (string) \Configuration::get('PS_SHOP_NAME'),
            '{old_address}'         => '10 avenue Montaigne, 75008 Paris, France',
            '{new_address}'         => '12 rue de la Paix, 75001 Paris, France',
            // ── Bons / cartes cadeaux ──────────────────────────────
            '{voucher_num}'        => 'NERIA-VIP-2026',
            '{voucher_code}'       => 'NERIA-VIP-2026',
            '{voucher_amount}'     => '15,00 €',
            '{voucher_usage}'      => 'Saisissez ce code dans votre panier lors de votre prochaine commande.',
            '{discount}'           => '10%',
            '{gift_card_amount}'   => '50,00 €',
            '{gift_card_code}'     => 'GIFT-NERIA-2026',
            '{gift_card_usage}'    => 'Saisissez ce code lors de votre prochaine commande.',
            // ── Produit / atelier / service ────────────────────────
            '{product_name}'       => 'Montre Élégance Neria',
            '{product}'            => 'Montre Élégance Neria',
            '{product_materials}'  => 'Cuir de veau, acier inoxydable',
            '{care_instructions}'  => 'Éviter l\'humidité. Nettoyer avec un chiffon doux.',
            '{craftsmanship_stage}' => 'Assemblage final en atelier',
            '{alteration_status}'  => 'Retouche en cours de finition',
            '{certificate_origin}'    => 'France — Atelier parisien, fondé en 1987',
            '{certificate_materials}' => 'Cuir de veau pleine fleur, acier 316L, verre saphir',
            '{certificate_artisan}'   => 'Maître Jean-Pierre Moreau, Meilleur Ouvrier de France',
            '{warning_coverage}'   => '7 jours',
            '{current_coverage}'   => '3 jours',
            // ── Logistique / commande ──────────────────────────────
            '{apology_reason}'     => 'Un retard de livraison exceptionnel est survenu sur votre commande.',
            '{customs_status}'     => 'En attente de dédouanement',
            '{customs_action}'     => 'Aucune action requise de votre part.',
            '{pickup_point_address}' => 'Tabac Presse — 5 rue de Rivoli, 75001 Paris',
            '{hold_reason}'        => 'Vérification de votre paiement en cours',
            '{new_shipping_date}'  => '15/06/2026',
            '{refund_amount}'      => '89,00 €',
            '{recycled_packaging_label}' => 'Non',
            '{shipped_items}'      => '<p><strong>Colis 1 / 2</strong> — Montre Élégance Neria · Colissimo 6A1234567890</p>',
            '{shipped_items_txt}'  => 'Colis 1 / 2 — Montre Élégance Neria · Colissimo 6A1234567890',
            '{recipients}'         => '<p><strong>Sophie Durand</strong> — Colissimo 6A1234567890</p><p><strong>Jean Martin</strong> — Colissimo 6A0987654321</p>',
            '{meta_products}'      => '<p>Réf. NER-001 — Montre Élégance Neria × 1</p><p>Réf. NER-014 — Bracelet Cuir Atelier × 1</p>',
            // ── Retours ────────────────────────────────────────────
            '{return_id}'          => '1',
            '{id_order_return}'    => '42',
            '{state_order_return}' => 'Retour reçu',
            '{return_state_name}'  => 'Retour reçu',
            '{order_return_state}' => 'Retour reçu',
            // ── Messages / SAV ─────────────────────────────────────
            '{message}'            => 'Bonjour, je souhaite obtenir des informations sur ma commande.',
            '{messages}'           => '<p><strong>Client :</strong> Bonjour, j\'ai une question.</p><p><strong>Support :</strong> Bonjour, je regarde ça immédiatement.</p>',
            '{reply}'              => 'Bonjour, nous avons bien traité votre demande. Cordialement, le service client.',
            '{comment}'            => 'Client prioritaire — traiter en urgence.',
            // ── Fidélité / VIP / événements ────────────────────────
            '{reward_expiry_date}' => '30/06/2026',
            '{new_tier_name}'      => 'Cercle Or',
            '{milestone_count}'    => '5e',
            '{shopper_name}'       => 'Marie-Claire Fontaine',
            '{invitation_location}' => 'Showroom Neria — 8 place Vendôme, 75001 Paris',
            '{invitation_dates}'   => 'Vendredi 20 juin 2026, de 18h à 22h',
            '{gift_message}'       => 'Joyeux anniversaire ma chère Sophie. Avec tout mon amour.',
            // ── Compte / sécurité / virement / divers ──────────────
            '{new_passwd}'         => '••••••••',
            '{token}'              => 'apercu-token',
            '{employee}'           => 'Marie Lefebvre',
            '{filename}'           => 'import_produits.csv',
            '{attached_file}'      => 'Aucun fichier joint',
            '{last_qty}'           => '5',
            '{qty}'                => '0',
            '{subject}'            => 'Aperçu',
            '{virtualProducts}'    => '<p>Patron de couture Neria Vol.1 — <a href="#">Télécharger</a></p>',
            '{bankwire_owner}'     => 'Maison Neria SARL',
            '{bankwire_details}'   => 'IBAN : FR76 3000 6000 0112 3456 7890 189 — BIC : AGRIFRPP',
            '{bankwire_address}'   => '12 rue de la Paix, 75001 Paris, France',
        ];
    }

    /**
     * Compile un template interne (log_alert...) dans la langue de l'idLang
     * donné et renvoie le dossier module à utiliser comme templatePath. Ce
     * template est déclenché par le cœur PS (PrestaShopLogger::addLog()) sans
     * template_path explicite — sans cet appel, Mail::Send() lit le template
     * natif PrestaShop (mails/<iso>/ à la racine du shop, traductions PS
     * standard) au lieu du rendu stylé/traduit Neria.
     */
    public function ensureInternalTemplateCompiled(string $template, int $idLang, string $subject = ''): ?string
    {
        $lang = $this->engine->langFromId($idLang);
        $iso  = \Language::getIsoById($idLang) ?: $lang;

        // Destinataire réel de ces templates internes : le marchand
        // lui-même (adresse boutique), pas un client — les liens
        // désabonnement/préférences pointent donc vers SA propre fiche.
        // Sans ça, layout.html (partagé avec les emails clients) affiche
        // des liens vides (href="") dans le pied de page — cassés, pas
        // juste absents, contrairement aux autres variables qui sont
        // simplement retirées par le filet de sécurité (cf. bug trouvé
        // le 2026-07-20 via le journal Watchdog : log_alert manquait
        // {subject}/{unsubscribe_url}/{preferences_url}/{custom_message}
        // depuis au moins le 18/07, chemin de compilation séparé de
        // applyNeriaRendering() qui les injecte pour tout envoi normal).
        $adminEmail = (string) \Configuration::get('PS_SHOP_EMAIL');
        $templateVars = [
            '{shop_name}'       => (string) \Configuration::get('PS_SHOP_NAME'),
            '{subject}'         => $subject,
            '{custom_message}'  => '',
            '{unsubscribe_url}' => $this->module->getUnsubscribeUrl($adminEmail, $lang),
            '{preferences_url}' => class_exists('PreferencesManager')
                ? (new \PreferencesManager($this->module))->getPreferencesUrl($adminEmail, 0, $lang)
                : '',
        ];
        return $this->compileNeriaTemplate($template, $lang, $iso, $templateVars) !== null
            ? _PS_MODULE_DIR_ . 'neria/mails/'
            : null;
    }

    /**
     * Compile le template Neria en fichier HTML plat (sans heritage Smarty)
     * Fusionne layout.html + core/{template}.html
     */
    private function compileNeriaTemplate(
        string $template,
        string $lang,
        ?string $outIso = null,
        array $templateVars = [],
        bool $suppressResidualLog = false,
        bool $silentIfCoreMissing = false
    ): ?string {
        $layoutPath = $this->module->getModulePath('mails/themes/neria_global/layout.html');
        $corePath   = $this->module->getModulePath('mails/themes/neria_global/core/' . $template . '.html');

        if (!file_exists($layoutPath)) {
            $this->watchdog()->error(
                WatchdogManager::i18nMsg('watchdog.layout_missing'),
                $template,
                'EmailRenderer'
            );
            return null;
        }
        if (!file_exists($corePath)) {
            // Appelé depuis applyNeriaRendering() pour CHAQUE email envoyé par
            // PrestaShop, y compris ceux de modules tiers hors périmètre Neria
            // (aucun fichier core/<template>.html) — c'est le fonctionnement
            // normal documenté juste après (« un template hors périmètre Neria
            // est laissé tel quel à PrestaShop »), pas une erreur. Journaliser
            // ici en 'error' déclenchait une alerte immédiate (watchdog->error()
            // envoie un email throttlé) à CHAQUE envoi d'un email non couvert
            // par Neria — un simple email de formulaire de contact ou d'un
            // module tiers spammait le marchand d'alertes. Seuls les appelants
            // qui attendent un template Neria connu (secours, templates
            // internes) veulent être avertis si son core manque réellement.
            if (!$silentIfCoreMissing) {
                $this->watchdog()->error(
                    WatchdogManager::i18nMsg('watchdog.core_missing', ['template' => $template]),
                    $template,
                    'EmailRenderer'
                );
            }
            return null;
        }

        $layout = file_get_contents($layoutPath);
        $core   = file_get_contents($corePath);

        if (!preg_match('/\{block\s+name=[\'"]neria_content[\'\"]\}(.*?)\{\/block\}/s', $core, $m)) {
            $this->watchdog()->error(
                WatchdogManager::i18nMsg('watchdog.block_missing', ['template' => $template]),
                $template,
                'EmailRenderer'
            );
            return null;
        }

        $compiled = preg_replace('/\{block\s+name=[\'"]neria_content[\'\"]\}\{\/block\}/', trim($m[1]), $layout);
        $compiled = preg_replace('/\{extends\s+[^}]+\}/', '', $compiled);

        // ── Smart Salutation plug-and-play ─────────────────────────────────────
        // Si {time_greeting} est calculé (client identifié + pays ciblé),
        // remplace dear_customer par la salutation horaire + prénom.
        // Aucune retouche de template nécessaire — plug & play.
        // Langues où l'usage exige un honorifique accolé au nom du client
        // (様 en japonais, 님 en coréen) — l'ordre et la ponctuation occidentaux
        // « Bonjour, Prénom, » n'ont pas d'équivalent naturel dans ces langues.
        $nameHonorifics = [
            'ja' => ['suffix' => '様', 'sep' => '、', 'end' => '。'],
            'ko' => ['suffix' => '님', 'sep' => ', ', 'end' => '.'],
        ];

        $plugTimeGreeting = $templateVars['{time_greeting}'] ?? '';
        if ($plugTimeGreeting !== '') {
            // Insère directement la valeur (pas un token {firstname} à
            // résoudre plus tard) — échappement nécessaire ici même,
            // symétrique à celui appliqué plus bas pour le reste du HTML.
            $plugFirstname = htmlspecialchars((string) ($templateVars['{firstname}'] ?? ''), ENT_QUOTES, 'UTF-8');
            if (isset($nameHonorifics[$lang])) {
                $h = $nameHonorifics[$lang];
                $plugSalutation = ($plugFirstname !== '' ? $plugFirstname . $h['suffix'] . $h['sep'] : '')
                    . $plugTimeGreeting
                    . $h['end'];
            } else {
                $plugSalutation = $plugTimeGreeting
                    . ($plugFirstname !== '' ? ', ' . $plugFirstname : '')
                    . ',';
            }
            $compiled = str_replace(
                ["{neria_trad key='dear_customer'}", '{neria_trad key="dear_customer"}'],
                $plugSalutation,
                $compiled
            );
        }

        // ── Résoudre les {neria_trad key='...'} avec les vraies traductions ──
        $engine = $this->engine;

        // ── Salutations dédiées (templates "chantier 2") ────────────────────
        // collection_completion / complete_your_look / ghost_cart / waitlist_available
        // concatènent directement {xxx_greeting} {firstname}, dans leur source.
        // Même correctif honorifique.
        if (isset($nameHonorifics[$lang])) {
            $h = $nameHonorifics[$lang];
            $compiled = preg_replace_callback(
                '/\{neria_trad\s+key=[\'"]([a-z0-9_]*greeting)[\'"]\s*\}\s*\{firstname\},/',
                function ($mm) use ($engine, $template, $lang, $h) {
                    $g = self::sanitizeTranslationHtml($engine->get($template, $mm[1], $lang));
                    return '{firstname}' . $h['suffix'] . $h['sep'] . $g . $h['end'];
                },
                $compiled
            );
        }

        $compiled = preg_replace_callback(
            '/\{neria_trad\s+key=[\'"]([a-z0-9_]+)[\'"]\s*\}/',
            function ($m) use ($engine, $template, $lang) {
                $v = self::sanitizeTranslationHtml($engine->get($template, $m[1], $lang));
                return $v !== '' ? $v : $m[0];
            },
            $compiled
        );

        // ── Résoudre les variables de design ─────────────────────────────
        $design = $this->config->getDesignConfig();
        $tplVars = [
            '{$neria_color_accent}'     => $design['color_accent'],
            '{$neria_color_background}' => $design['color_background'],
            '{$neria_color_container}'  => $design['color_container'],
            '{$neria_color_text}'       => $design['color_text'],
            '{$neria_font_family}'      => $this->fonts()->getCssFamilyForLang($lang),
            '{$neria_dir}'              => $this->engine->isRtl($lang) ? 'rtl' : 'ltr',
            '{$neria_text_align}'       => $this->engine->isRtl($lang) ? 'right' : 'left',
            // Padding d'une cellule de tableau image+texte (image collée d'un
            // côté, texte de l'autre) : le côté "collé à l'image" doit passer
            // à droite en RTL au lieu de rester à gauche — sinon le titre/texte
            // se retrouve avec un espace du mauvais côté par rapport à l'image
            // (trouvé en réel sur waitlist_available.html/collection_completion.html).
            '{$neria_img_text_pad}'     => $this->engine->isRtl($lang) ? '20px 0 20px 20px' : '20px 20px 20px 0',
            '{$neria_container_width}'  => (string) $design['container_width'],
            '{$neria_logo_width}'       => (string) $design['logo_width'],
            '{$neria_logo_url}'         => $this->resolveLogoUrl($design['logo_path']),
            '{$neria_color_header_bg}'   => $design['color_header_bg']   ?? '#ffffff',
            '{$neria_color_footer_bg}'   => $design['color_footer_bg']   ?? '#ffffff',
            '{$neria_color_footer_text}' => $design['color_footer_text'] ?? '#a09990',
            '{$neria_font_heading_family}' => $this->config->getHeadingFontFamily($design['font_heading'] ?? 'Cormorant Garamond'),
            '{$neria_google_font_link}'  => $this->googleFontLinks($lang, $design['font_heading'] ?? 'Cormorant Garamond'),
            '{$neria_btn_radius}'        => (string)(int)($design['btn_radius'] ?? 2),
            '{$neria_btn_color}'         => $design['btn_color'] ?? '#2b2520',
            '{$neria_section_padding}'   => (string)(int)($design['section_padding'] ?? 40),
            '{$neria_block_spacing}'     => (string)(int)($design['block_spacing'] ?? 48),
            '{$neria_separator_css}'     => \ConfigManager::getSeparatorCss($design['separator_style'] ?? 'line'),
            '{$neria_card_shadow}'       => \ConfigManager::getCardShadowCss($design['card_shadow'] ?? 'soft'),
            '{$neria_font_size}'         => (string)(int)($design['font_size'] ?? 14),
            '{$neria_line_height}'       => number_format((float)($design['line_height'] ?? 1.8), 1, '.', ''),
            '{$neria_heading_weight}'    => (string)(int)($design['heading_weight'] ?? 600),
            '{$neria_tracking_pixel}'   => $templateVars['neria_tracking_pixel'] ?? '',
            '{$neria_social_links}'     => $templateVars['neria_social_links']   ?? '',
            '{$neria_signature_url}'    => $templateVars['neria_signature_url']  ?? '',
            '{$neria_signature_name}'   => $templateVars['neria_signature_name'] ?? '',
            '{$neria_signature_title}'  => $templateVars['neria_signature_title'] ?? '',
            '{$neria_lang}'             => $lang,
        ];
        // strtr() (et non str_replace() avec des tableaux) : évite qu'une
        // valeur BO (nom de marque, slogan, texte personnalisé) contenant
        // littéralement "{autre_variable}" ne se fasse re-substituer selon
        // l'ordre d'itération — même correctif que TranslationEngine::
        // resolveVariables().
        $compiled = strtr($compiled, $tplVars);

        // ── Variables PS communes garanties ──────────────────────────────────
        // Appliquées EN PREMIER pour garantir la valeur correcte (shop_url
        // avec le sous-répertoire __PS_BASE_URI__ compris). Si on les applique
        // après la boucle templateVars, le caller a déjà remplacé {shop_url}
        // avec sa valeur incomplète (getShopDomainSsl sans base URI) et le
        // override ne trouve plus rien à remplacer.
        $baseUrl  = rtrim(\Tools::getShopDomainSsl(true), '/') . __PS_BASE_URI__;
        // Sans id_lang explicite, Link::getPageLink() utilise la langue du
        // CONTEXTE courant (celle de l'admin/cron qui déclenche l'envoi) et
        // non celle réelle de l'email — un envoi en anglais depuis le BO
        // produisait des liens préfixés "/fr/" (langue par défaut de la
        // boutique). Résout l'id_lang réel à partir de l'ISO cible ($outIso).
        $urlIdLang = ($outIso ? \Language::getIdByIso($outIso) : null) ?: null;
        $psCommon = [
            '{shop_url}'           => $baseUrl,
            '{history_url}'        => $this->context->link->getPageLink('history', true, $urlIdLang),
            '{guest_tracking_url}' => $this->context->link->getPageLink('guest-tracking', true, $urlIdLang),
        ];
        $compiled = strtr($compiled, $psCommon);

        // ── Résoudre les variables PS-style {var} restantes ──────────────────
        // Les clés dans templateVars ont déjà les accolades : '{firstname}'.
        // Les vars déjà résolues par psCommon (shop_url, etc.) ne sont plus
        // présentes dans $compiled — le str_replace est un no-op pour elles.
        //
        // Durcissement défensif (HTML uniquement — $templateVars original
        // reste intact pour la version .txt plus bas, qui ne doit jamais
        // afficher d'entités HTML) : {firstname}/{lastname} sont normalement
        // filtrés par Validate::isName() du cœur PS (rejette < > { } " etc.)
        // à l'inscription/l'édition BO, donc pas de vecteur d'injection connu
        // aujourd'hui — mais ce sont des variables directement dérivées d'un
        // champ éditable par le client (ou saisi librement, pour
        // message/comment/gift_message — formulaire de contact, message
        // cadeau) qui finissaient non échappées dans le HTML compilé. Un
        // visiteur pouvait y injecter du HTML/JS actif rendu ensuite par le
        // client mail du destinataire (marchand ou client selon le
        // template). Échappées ici en filet de sécurité.
        $htmlTemplateVars = $templateVars;
        foreach (['{firstname}', '{lastname}', '{message}', '{comment}', '{gift_message}'] as $nameKey) {
            if (isset($htmlTemplateVars[$nameKey]) && is_string($htmlTemplateVars[$nameKey])) {
                $htmlTemplateVars[$nameKey] = htmlspecialchars($htmlTemplateVars[$nameKey], ENT_QUOTES, 'UTF-8');
            }
        }
        foreach ($htmlTemplateVars as $key => $value) {
            if (is_string($value)) {
                $compiled = str_replace($key, $value, $compiled);
            }
        }

        // ── Résoudre les blocs conditionnels {if isset($var) && $var}...{/if}
        // (signature manuscrite, réseaux sociaux) selon la valeur réelle de
        // $templateVars['var'] — ce n'est PAS du vrai Smarty, donc sans cette
        // étape le bloc entier (y compris son contenu déjà substitué) était
        // systématiquement supprimé par le nettoyage générique ci-dessous,
        // même quand la condition était vraie.
        $compiled = preg_replace_callback(
            '/\{if\s+isset\(\$([a-z_]+)\)(?:\s*&&\s*\$\1)?\s*\}(.*?)\{\/if\}/s',
            static function ($m) use ($templateVars) {
                return !empty($templateVars[$m[1]]) ? $m[2] : '';
            },
            $compiled
        );

        // ── Blocs conditionnels {if var}...{else}...{/if} (sans isset()) —
        // utilisé par ex. par loyalty_recap pour palier suivant / palier max.
        // Sans cette étape, tout le bloc (les deux branches) était supprimé
        // par le nettoyage générique ci-dessous, quel que soit l'état réel.
        $compiled = preg_replace_callback(
            '/\{if\s+\$?([a-z_]+)\s*\}(.*?)(?:\{else\}(.*?))?\{\/if\}/s',
            static function ($m) use ($templateVars) {
                $val = $templateVars['{' . $m[1] . '}'] ?? '';
                return !empty($val) ? $m[2] : ($m[3] ?? '');
            },
            $compiled
        );

        // ── Nettoyer les résidus Smarty ───────────────────────────────────
        $compiled = preg_replace('/\{if\s[^}]+\}.*?\{\/if\}/s', '', $compiled);
        $compiled = preg_replace('/\{\*.*?\*\}/s', '', $compiled);
        $compiled = preg_replace('/\{\$[a-z_]+\}/', '', $compiled);

        // ── Filet de sécurité : variable de contenu manquante ─────────────
        // Contrairement à l'aperçu (buildCompiledHtml), ce chemin est celui
        // d'un VRAI envoi client — si une variable attendue par le template
        // n'a pas été fournie par l'appelant (PS core, ManualSendManager,
        // cron comportemental...), il ne faut jamais laisser un "{xxx}" brut
        // visible dans l'email livré. On le supprime silencieusement et on
        // journalise pour que le marchand puisse identifier la variable
        // manquante plutôt que de le découvrir via une plainte client.
        if (preg_match_all('/\{[a-z][a-z0-9_]*\}/i', $compiled, $residualMatches)) {
            $residualKeys = array_unique($residualMatches[0]);
            $compiled = preg_replace('/\{[a-z][a-z0-9_]*\}/i', '', $compiled);
            if (!$suppressResidualLog) {
                $this->logResidualVars($template, $residualKeys);
            }
        }

        // ── Empreinte carbone — injecté avant CssInliner (DOMDocument déplace
        // les commentaires hors des <table>) et avant l'écriture du fichier ──
        $carbonHtml = '';
        if ($this->config->isCarbonEnabled()) {
            $sizeKb   = strlen($compiled) / 1024;
            $co2      = number_format($sizeKb * 0.02, 1, '.', '');
            $carbonLink   = $this->config->getCarbonLink();
            $carbonLabel  = $this->engine->get('_global', 'carbon_label', $lang) ?: 'Empreinte estimée de cet email';
            $carbonMethod = $this->engine->get('_global', 'carbon_method', $lang) ?: 'méthodologie';
            $carbonLinkHtml = $carbonLink
                ? ' — <a href="' . htmlspecialchars($carbonLink, ENT_QUOTES, 'UTF-8') . '" style="color:#a09990;text-decoration:underline;" target="_blank">' . htmlspecialchars($carbonMethod, ENT_QUOTES, 'UTF-8') . '</a>'
                : '';
            $carbonHtml = '<tr><td style="text-align:center;font-family:Georgia,Times New Roman,serif;'
                . 'font-size:11px;color:#a09990;padding:4px 20px 20px;line-height:1.8;">'
                . '🌱 ' . htmlspecialchars($carbonLabel, ENT_QUOTES, 'UTF-8') . '&nbsp;: ~' . $co2 . 'g CO₂' . $carbonLinkHtml
                . '</td></tr>';
        }
        $compiled = str_replace('<!-- NERIA_CARBON -->', $carbonHtml, $compiled);

        // ── Inline CSS pour compatibilité Gmail / Orange / Yahoo ─────────────
        if (class_exists('CssInliner')) {
            $compiled = CssInliner::inline($compiled);
        }

        // Dossier de sortie : l'ISO que Mail::send va lire (langue du compte,
        // $outIso) plutôt que la langue détectée du contenu ($lang). Le fichier
        // contient le texte en langue détectée, mais doit résider dans le
        // dossier où PrestaShop ira le chercher.
        $outDir = _PS_MODULE_DIR_ . 'neria/mails/' . ($outIso ?: $lang) . '/';
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $outFile = $outDir . $template . '.html';
        if (file_put_contents($outFile, $compiled) === false) {
            $this->watchdog()->error(
                WatchdogManager::i18nMsg('watchdog.write_error', ['lang' => $outIso ?: $lang, 'template' => $template]),
                $template,
                'EmailRenderer'
            );
            return null;
        }

        // Générer aussi la version .txt (avec résolution des {neria_trad})
        $txtPath = $this->module->getModulePath('mails/themes/neria_global/core/' . $template . '.txt');
        if (file_exists($txtPath)) {
            $compiledTxt = file_get_contents($txtPath);

            // ── Blocs conditionnels {if var}...{else}...{/if} — même logique
            // que la version HTML (voir plus haut), sinon la syntaxe brute
            // reste visible dans le .txt (Mail::send ne sait pas l'interpréter).
            $compiledTxt = preg_replace_callback(
                '/\{if\s+\$?([a-z_]+)\s*\}(.*?)(?:\{else\}(.*?))?\{\/if\}/s',
                static function ($m) use ($templateVars) {
                    $val = $templateVars['{' . $m[1] . '}'] ?? '';
                    return !empty($val) ? $m[2] : ($m[3] ?? '');
                },
                $compiledTxt
            );

            // Smart Salutation — version TXT ({firstname} résolu par Mail::send)
            if ($plugTimeGreeting !== '') {
                $compiledTxt = str_replace(
                    ["{neria_trad key='dear_customer'}", '{neria_trad key="dear_customer"}'],
                    isset($nameHonorifics[$lang])
                        ? '{firstname}' . $nameHonorifics[$lang]['suffix'] . $nameHonorifics[$lang]['sep'] . $plugTimeGreeting . $nameHonorifics[$lang]['end']
                        : $plugTimeGreeting . ', {firstname},',
                    $compiledTxt
                );
            }

            // Salutations dédiées (templates "chantier 2") — version TXT.
            // Même correctif honorifique que la version HTML.
            if (isset($nameHonorifics[$lang])) {
                $h = $nameHonorifics[$lang];
                $compiledTxt = preg_replace_callback(
                    '/\{neria_trad\s+key=[\'"]([a-z0-9_]*greeting)[\'"]\s*\}\s*\{firstname\},/',
                    function ($mm) use ($engine, $template, $lang, $h) {
                        $g = $engine->get($template, $mm[1], $lang);
                        return '{firstname}' . $h['suffix'] . $h['sep'] . $g . $h['end'];
                    },
                    $compiledTxt
                );
            }

            $compiledTxt = preg_replace_callback(
                '/\{neria_trad\s+key=[\'"]([a-z0-9_]+)[\'"]\s*\}/',
                function ($m) use ($engine, $template, $lang) {
                    $v = $engine->get($template, $m[1], $lang);
                    return $v !== '' ? $v : $m[0];
                },
                $compiledTxt
            );

            // ── Résoudre les variables {var} — même logique que la version HTML
            // (voir plus haut). Appliqué APRÈS la salutation intelligente
            // ci-dessus (qui injecte elle-même un nouveau "{firstname}" dans le
            // texte) pour que ce tag fraîchement inséré soit bien résolu avant
            // le filet de sécurité qui suit.
            $compiledTxt = strtr($compiledTxt, $psCommon);
            foreach ($templateVars as $key => $value) {
                if (is_string($value)) {
                    $compiledTxt = str_replace($key, $value, $compiledTxt);
                }
            }

            // ── Filet de sécurité : variable de contenu manquante ─────────────
            // Même logique que la version HTML (voir plus haut) — sans cette
            // étape, une variable non fournie reste affichée brute ("{xxx}")
            // dans l'email texte livré au client.
            if (preg_match_all('/\{[a-z][a-z0-9_]*\}/i', $compiledTxt, $residualTxtMatches)) {
                $residualTxtKeys = array_unique($residualTxtMatches[0]);
                $compiledTxt = preg_replace('/\{[a-z][a-z0-9_]*\}/i', '', $compiledTxt);
                if (!$suppressResidualLog) {
                    $this->logResidualVars($template, $residualTxtKeys);
                }
            }

            // Slot du message personnalisé optionnel (vide par défaut, rempli
            // par Mail::Send via {custom_message_txt} si un message est saisi).
            $compiledTxt = rtrim($compiledTxt, "\n") . "\n{custom_message_txt}\n";
            file_put_contents($outDir . $template . '.txt', $compiledTxt);
        }

        return $outFile;
    }

    /**
     * Reformate le HTML {products} généré par PrestaShop.
     * PrestaShop injecte des tableaux imbriqués dans chaque <td> qui brisent
     * les largeurs de colonnes. Cette méthode remplace chaque <td> imbriqué
     * par un <td> simple avec les bons attributs de style Neria.
     *
     * @param array $templateVars Variables Smarty (passé par référence)
     */
    private function reformatProductsHtml(array &$templateVars): void
    {
        $key = '{products}';
        if (empty($templateVars[$key])) {
            return;
        }

        $html = $templateVars[$key];

        $base = 'padding:14px 12px; border-bottom:1px solid #f0ece6; font-size:13px; color:#2c2c2c; vertical-align:middle;';
        $styles = [
            $base . ' white-space:nowrap;',
            $base,
            $base . ' white-space:nowrap;',
            $base . ' text-align:center; white-space:nowrap;',
            $base . ' text-align:right; white-space:nowrap;',
        ];

        $dom = new \DOMDocument();
        @$dom->loadHTML(
            '<?xml encoding="UTF-8"><html><body><table>' . $html . '</table></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $rows    = $dom->getElementsByTagName('tr');
        $newRows = [];

        foreach ($rows as $tr) {

            $outerTds = [];
            foreach ($tr->childNodes as $node) {
                if ($node->nodeType === XML_ELEMENT_NODE && $node->nodeName === 'td') {
                    $outerTds[] = $node;
                }
            }

            if (empty($outerTds)) {
                continue;
            }

            $firstStyle = $outerTds[0]->getAttribute('style');
            if (strpos($firstStyle, 'border') === false) {
                continue;
            }

            $contents = [];
            foreach ($outerTds as $td) {
                $innerTds = $td->getElementsByTagName('td');
                $content  = '';
                foreach ($innerTds as $inner) {
                    if ($inner->getAttribute('width') === '5') {
                        continue;
                    }
                    $innerHTML = '';
                    foreach ($inner->childNodes as $child) {
                        $innerHTML .= $dom->saveHTML($child);
                    }
                    $innerHTML = trim($innerHTML);
                    if ($innerHTML !== '' && $innerHTML !== '&nbsp;') {
                        $content = $innerHTML;
                        break;
                    }
                }
                $contents[] = $content;
            }

            $cells = '';
            foreach ($contents as $i => $content) {
                $style  = $styles[$i] ?? $base;
                $cells .= '<td style="' . $style . '">' . $content . '</td>';
            }

            $newRows[] = '<tr>' . $cells . '</tr>';
        }

        if (!empty($newRows)) {
            $templateVars[$key] = implode("\n", $newRows);
        }
    }
}
