<?php
/**
 * NERIA — Luxury Email Suite
 *
 * Module PrestaShop — Emails transactionnels & marketing haut de gamme
 * 18 langues · Adaptation culturelle · Typographie premium
 * Compatible PrestaShop 8.0.0 → 9.x
 *
 * @author    Neria
 * @version   1.0.0
 * @license   AFL (Academic Free License)
 */

// Sécurité : interdire l'accès direct au fichier PHP
if (!defined('_PS_VERSION_')) {
    exit;
}

// ============================================================
// AUTOLOAD : charge automatiquement toutes les classes src/
// ============================================================
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Classe principale du module Neria
 * Gère l'installation, la désinstallation et les hooks PrestaShop
 */
class Neria extends Module
{
    // ============================================================
    // CONSTANTES DU MODULE
    // ============================================================

    /** Version courante du module */
    const VERSION = '1.0.21';

    /** Préfixe de toutes les clés Configuration::get() du module */
    const CONFIG_PREFIX = 'NERIA_';

    /** Langues supportées par le module */
    const SUPPORTED_LANGS = [
        'fr', 'en', 'de', 'it', 'es', 'pt', 'br',
        'ar', 'ja', 'ko', 'zh', 'tw',
        'ru', 'tr', 'sv', 'no', 'da', 'nl',
    ];

    /** Langues RTL (right-to-left) */
    const RTL_LANGS = ['ar'];

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    public function __construct()
    {
        $this->name          = 'neria';
        $this->tab           = 'emailing';
        $this->version       = self::VERSION;
        $this->author        = 'Neria';
        $this->need_instance = 1;
        $this->bootstrap     = true;

        // Compatibilité PrestaShop déclarée
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '9.99.99',
        ];

        // Appel obligatoire AVANT d'accéder à $this->l()
        parent::__construct();

        // Filet de sécurité global : capture les E_ERROR/E_PARSE/E_CORE_ERROR
        // qui ne sont pas rattrapables par try/catch.
        NeriaErrorHandler::register();

        $this->displayName = $this->l('Neria – Luxury Email Suite');
        // Description et confirmation traduites (18 langues) via le dictionnaire
        $this->description      = AdminTranslator::t('msg.module_description');
        $this->confirmUninstall = AdminTranslator::t('msg.confirm_uninstall');
    }

    // ============================================================
    // INSTALLATION
    // ============================================================

    /**
     * Installe le module :
     * 1. Appel du parent (enregistrement en base)
     * 2. Création des tables SQL
     * 3. Enregistrement des hooks
     * 4. Création de l'onglet back-office
     * 5. Import du dictionnaire translations.json
     */
    public function install(): bool
    {
        return parent::install()
            && $this->executeSqlFile('install.sql')
            && $this->registerHooks()
            && $this->installTab()
            && $this->importTranslations()
            && $this->setDefaultConfiguration()
            && $this->configureDeliveredStatus();
    }

    // ============================================================
    // DÉSINSTALLATION
    // ============================================================

    /**
     * Désinstalle le module :
     * 1. Suppression des tables SQL
     * 2. Suppression de l'onglet back-office
     * 3. Nettoyage des clés Configuration
     * 4. Appel du parent
     */
    public function uninstall(): bool
    {
        return $this->restoreDeliveredStatus()
            && $this->executeSqlFile('uninstall.sql')
            && $this->uninstallTab()
            && $this->deleteConfiguration()
            && parent::uninstall();
    }

    // ============================================================
    // ENREGISTREMENT DES HOOKS
    // ============================================================

    /**
     * Liste unique des hooks utilisés par le module — source de vérité pour
     * registerHooks() (installation) ET NeriaTools::runFullDiagnostic()
     * (contrôle de santé). Ne pas dupliquer cette liste ailleurs.
     */
    const HOOKS = [
            // ── Emails ────────────────────────────────────────────
            // Hook principal : intercepte TOUS les envois email PS
            // Permet d'injecter les traductions Neria et le tracking
            'actionEmailSendBefore',

            // Ajoute l'en-tête List-Unsubscribe au message juste avant l'envoi
            'actionMailAlterMessageBeforeSend',

            // ── Back-office ───────────────────────────────────────
            // Charge CSS/JS Neria dans le header du back-office
            'displayBackOfficeHeader',

            // ── Support multi-boutique ────────────────────────────
            // La vérification des occasions calendaires (cron-like) tourne
            // via displayHeader (throttlée à 24h par cache) plutôt que via
            // actionCronJob, qui dépend d'un module tiers de dispatch cron
            // non garanti sur toutes les installations.
            'displayHeader',

            // ── Fiche client : bloc « Emails reçus » ──────────────
            // PS 1.6 legacy : displayAdminCustomersView (object Customer)
            // PS 1.7+/8 (Symfony) : displayAdminCustomers (id_customer brut)
            // On enregistre les deux pour couvrir toutes les versions.
            'displayAdminCustomersView',
            'displayAdminCustomers',

            // ── Attribution de revenus ────────────────────────────
            // actionObjectOrderAddAfter : capture le cookie neria_ref dans le
            // navigateur du client au moment de la commande → stocke en DB.
            // actionOrderStatusPostUpdate : lit la DB quand le statut passe à payé.
            'actionObjectOrderAddAfter',
            'actionOrderStatusPostUpdate',

            // ── Déclencheurs commande (Vague 2) ──────────────────
            // refund_processed : avoir créé
            'actionOrderSlipAdd',
            // return_received : retour marchandise enregistré
            'actionObjectOrderReturnAddAfter',

            // ── Certificat d'authenticité : bloc fiche commande ───
            'displayAdminOrderMainBottom',

            // ── Liste d'attente produits ───────────────────────────
            'displayProductAdditionalInfo',
            'actionUpdateQuantity',

            // ── RGPD : purge à la suppression d'un client ──────────
            'actionDeleteGDPRCustomer',
    ];

    /**
     * Enregistre tous les hooks nécessaires au fonctionnement du module
     */
    private function registerHooks(): bool
    {
        foreach (self::HOOKS as $hook) {
            // registerHook() retourne false si le hook est invalide
            // On ignore les hooks non-existants (compatibilité versions)
            $this->registerHook($hook);
        }

        return true;
    }

    // ============================================================
    // HOOKS — DÉLÉGATION AUX MANAGERS
    // ============================================================

    /**
     * Hook principal : intercepte l'envoi d'emails PrestaShop
     * Remplace les textes natifs par les traductions Neria
     * Injecte le pixel de tracking et les variables personnalisées
     *
     * @param array $params Paramètres passés par PrestaShop :
     *   - $params['template']   : nom du template (ex: order_conf)
     *   - $params['subject']    : sujet de l'email
     *   - $params['to']         : adresse destinataire
     *   - $params['toName']     : nom destinataire
     *   - $params['templateVars'] : variables Smarty du template
     *   - $params['idLang']     : id de langue PrestaShop
     */
    public function hookActionEmailSendBefore(array &$params): bool
    {
        // CRITIQUE : ce hook s'exécute avant CHAQUE email envoyé par
        // PrestaShop (confirmation de commande, réinitialisation mot de
        // passe, facture…), pas seulement les templates Neria. En cas
        // d'exception, on retourne TOUJOURS true (laisser PS envoyer son
        // email natif) — jamais false, qui annulerait silencieusement un
        // email transactionnel critique pour le client.
        try {
            return $this->hookActionEmailSendBeforeImpl($params);
        } catch (\Throwable $e) {
            if (class_exists('WatchdogManager')) {
                try {
                    (new WatchdogManager($this))->critical(
                        WatchdogManager::i18nMsg('watchdog.crash_email_send_before', [
                            'error' => $e->getMessage(),
                            'file'  => basename($e->getFile()),
                            'line'  => $e->getLine(),
                        ]),
                        $params['template'] ?? '',
                        'NeriaErrorHandler'
                    );
                } catch (\Throwable $ignored) {
                }
            }
            return true;
        }
    }

    private function hookActionEmailSendBeforeImpl(array &$params): bool
    {
        // ── Témoin silencieux : copie BCC de CHAQUE email envoyé ──────
        // Volontairement avant tout autre traitement (y compris le check
        // bounce/cooldown) : si l'un de ces contrôles bloque l'envoi plus
        // bas, aucun email ne part de toute façon, donc le bcc devient
        // moot — mais s'il part, il doit toujours porter la copie archive.
        $archiveEmail = trim((string) \Configuration::getGlobalValue('NERIA_ARCHIVE_EMAIL'));
        if ($archiveEmail !== '' && \Validate::isEmail($archiveEmail)) {
            $existingBcc = $params['bcc'] ?? null;
            if ($existingBcc === null || $existingBcc === '') {
                $params['bcc'] = $archiveEmail;
            } elseif (is_array($existingBcc)) {
                if (!in_array($archiveEmail, $existingBcc, true)) {
                    $params['bcc'][] = $archiveEmail;
                }
            } elseif ($existingBcc !== $archiveEmail) {
                $params['bcc'] = [$existingBcc, $archiveEmail];
            }
        }

        // Templates internes Neria : on laisse PS envoyer directement sans
        // passer par l'EmailRenderer (ils ont leur propre rendu autonome).
        $internalTemplates = ['monthly_report', 'log_alert', 'neria_fallback'];
        if (in_array($params['template'] ?? '', $internalTemplates, true)) {
            return true;
        }

        // ── Bounce check : bloquer les adresses invalides ─────────
        if (class_exists('BounceManager')) {
            $toEmail = $params['to'] ?? '';
            if (is_array($toEmail)) {
                $toEmail = reset($toEmail) ?: '';
            }
            if (BounceManager::isBounced((string) $toEmail)) {
                (new WatchdogManager($this))->warning(
                    WatchdogManager::i18nMsg('watchdog.send_cancelled_bounce', ['email' => $toEmail]),
                    $params['template'] ?? '',
                    'BounceManager'
                );
                return false;
            }
        }

        // ── Mode Silence : anti-doublon ───────────────────────────
        if ((new ConfigManager($this))->isCooldownEnabled() && class_exists('CooldownManager')) {
            $to = $params['to'] ?? '';
            if (is_array($to)) {
                $to = reset($to) ?: '';
            }
            $tpl = $params['template'] ?? '';
            $cdMgr = new CooldownManager();
            $cdMinutes = (new ConfigManager($this))->getCooldownMinutes();
            if ($cdMgr->isDuplicate((string) $to, $tpl, $cdMinutes)) {
                (new WatchdogManager($this))->info(
                    WatchdogManager::i18nMsg('watchdog.cooldown_blocked', ['template' => $tpl, 'to' => (string) $to]),
                    $tpl,
                    'CooldownManager'
                );
                return false;
            }
        }

        if (class_exists('EmailRenderer')) {
            $renderer = new EmailRenderer($this);
            // Retourne false pour annuler l'envoi natif de PrestaShop : c'est
            // le cas quand le rendu a échoué et qu'un email de secours élégant
            // a été envoyé à la place (cf. EmailRenderer::handleRenderFailure).
            $result = $renderer->processEmailParams($params);

            // Enregistrement de la stat « envoyé » ICI, pas via un hook
            // « après envoi » : PrestaShop n'a pas de hook actionEmailSendAfter
            // réel dans Mail::Send() (il n'existe nulle part dans le cœur PS,
            // donc n'est jamais déclenché). Le rendu a réussi à ce stade et le
            // token de tracking est posé dans $params par EmailRenderer.
            if ($result && !empty($params['neria_token']) && class_exists('StatsManager')) {
                (new StatsManager($this))->recordSent($params);
            }

            return $result;
        }

        return true;
    }

    /**
     * Hook juste avant l'envoi : ajoute l'en-tête List-Unsubscribe (RFC 2369 /
     * 8058) au message. C'est le principal levier de délivrabilité exigé par
     * Gmail/Yahoo : un moyen de désabonnement standard (mailto + un clic).
     *
     * @param array $params ['message' => Swift_Message par référence]
     */
    public function hookActionMailAlterMessageBeforeSend(array $params): void
    {
        try {
            if (empty($params['message']) || !is_object($params['message'])) {
                return;
            }
            $message = $params['message'];

            // Destinataire (1re adresse du message)
            $to = method_exists($message, 'getTo') ? $message->getTo() : null;
            if (!is_array($to) || empty($to)) {
                return;
            }
            $email = (string) key($to);
            if ($email === '' || !Validate::isEmail($email)) {
                return;
            }

            $headers = $message->getHeaders();
            if (!$headers || $headers->has('List-Unsubscribe')) {
                return;
            }

            $shopEmail = (string) Configuration::get('PS_SHOP_EMAIL');
            $httpsUrl  = $this->getUnsubscribeUrl($email);

            $values = [];
            if ($shopEmail !== '' && Validate::isEmail($shopEmail)) {
                $values[] = '<mailto:' . $shopEmail . '?subject=unsubscribe>';
            }
            if ($httpsUrl !== '') {
                $values[] = '<' . $httpsUrl . '>';
            }
            if (empty($values)) {
                return;
            }

            $headers->addTextHeader('List-Unsubscribe', implode(', ', $values));
            // Désabonnement « un clic » (RFC 8058) — seulement si l'URL https existe
            if ($httpsUrl !== '') {
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        } catch (\Throwable $e) {
            // Best-effort : ne jamais bloquer l'envoi pour un en-tête.
        }
    }

    /**
     * Construit l'URL de désabonnement signée (jeton HMAC) pour une adresse.
     * Utilisée par l'en-tête List-Unsubscribe et le lien en pied d'email ;
     * le front controller `unsubscribe` recalcule et vérifie le même jeton.
     *
     * @param string $email
     * @return string URL absolue, ou '' si email invalide
     */
    public function getUnsubscribeUrl(string $email, string $lang = ''): string
    {
        $email = Tools::strtolower(trim($email));
        if ($email === '' || !Validate::isEmail($email)) {
            return '';
        }
        $token = substr(hash_hmac('sha256', $email, _COOKIE_KEY_), 0, 32);

        $params = ['email' => $email, 'token' => $token];

        // Transporte la langue de l'email pour que la page de désabonnement
        // s'affiche dans la langue du destinataire (et non du visiteur).
        $lang = Tools::strtolower(trim($lang));
        if ($lang !== ''
            && class_exists('TranslationEngine')
            && in_array($lang, TranslationEngine::SUPPORTED_LANGS, true)
        ) {
            $params['neria_lang'] = $lang;
        }

        return $this->context->link->getModuleLink(
            'neria',
            'unsubscribe',
            $params,
            true
        );
    }

    /**
     * Fiche client (BO) : bloc « Emails reçus » — historique des envois
     * Neria à ce client (timeline + tableau, badge d'engagement, alertes,
     * export CSV). S'appuie sur ps_neria_stat, déjà alimentée par
     * StatsManager — aucune nouvelle table nécessaire.
     *
     * Deux formats de paramètres selon la version PrestaShop :
     *   - legacy (displayAdminCustomersView) : $params['object'] = Customer
     *   - Symfony (displayAdminCustomers)     : $params['id_customer'] = int
     */
    public function hookDisplayAdminCustomersView(array $params): string
    {
        return $this->renderCustomerEmailHistory($params);
    }

    public function hookDisplayAdminCustomers(array $params): string
    {
        return $this->renderCustomerEmailHistory($params);
    }

    /**
     * Étape 1 de l'attribution : capture le cookie neria_ref au moment où la
     * commande est créée (contexte navigateur du CLIENT → cookie accessible).
     * Stocke l'association id_order → tracking_token en DB pour que
     * hookActionOrderStatusPostUpdate puisse l'utiliser plus tard, même depuis
     * le BO du marchand (contexte navigateur différent).
     */
    public function hookActionObjectOrderAddAfter(array $params): void
    {
        NeriaErrorHandler::wrapHookVoid('hookActionObjectOrderAddAfter', function () use ($params): void {
            $this->hookActionObjectOrderAddAfterImpl($params);
        }, $this);
    }

    private function hookActionObjectOrderAddAfterImpl(array $params): void
    {
        $order = $params['object'] ?? null;
        if (!$order instanceof Order) {
            return;
        }

        // milestone_order : email au palier 5/10/25/50/100 commandes
        if (class_exists('OrderTriggersManager')) {
            (new OrderTriggersManager($this))->handleNewOrder($order);
        }

        // Attribution de revenus : cookie neria_ref → DB
        if (empty($_COOKIE['neria_ref'])) {
            return;
        }
        $idOrder = (int) $order->id;
        if ($idOrder <= 0) {
            return;
        }

        $parts = explode(':', $_COOKIE['neria_ref'], 3);
        if (count($parts) !== 3) {
            return;
        }
        [, , $token] = $parts;
        if ($token === '') {
            return;
        }

        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'neria_attribution`
             (`id_order`, `tracking_token`, `created_at`)
             VALUES (' . (int) $idOrder . ', \'' . pSQL($token) . '\', NOW())'
        );

        if (class_exists('WatchdogManager')) {
            (new WatchdogManager($this))->info(
                WatchdogManager::i18nMsg('watchdog.attribution_order_linked', ['order' => $idOrder, 'token' => $token]),
                '', 'Attribution'
            );
        }
    }

    /**
     * Étape 2 de l'attribution : déclenché à chaque changement de statut.
     * Lit l'association id_order → token depuis la DB (posée par hookActionObjectOrderAddAfter).
     * N'attribue que si le nouveau statut est "payé" (OrderState::$paid == 1).
     * Idempotent : recordConversion() ignore les tokens déjà convertis.
     */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        NeriaErrorHandler::wrapHookVoid('hookActionOrderStatusPostUpdate', function () use ($params): void {
            $this->hookActionOrderStatusPostUpdateImpl($params);
        }, $this);
    }

    private function hookActionOrderStatusPostUpdateImpl(array $params): void
    {
        $newStatus = $params['newOrderStatus'] ?? null;
        $oldStatus = $params['oldOrderStatus'] ?? null;
        $idOrder   = (int) ($params['id_order'] ?? 0);

        // order_on_hold / order_partial_shipped : statuts custom marchand
        if ($newStatus && $oldStatus && $idOrder > 0 && class_exists('OrderTriggersManager')) {
            (new OrderTriggersManager($this))->handleStatusChange($newStatus, $oldStatus, $idOrder);
        }

        // Attribution de revenus : déclenché uniquement sur statut payé
        if (!$newStatus || !(bool) $newStatus->paid) {
            return;
        }
        $idOrder = (int) ($params['id_order'] ?? 0);
        if ($idOrder <= 0) {
            return;
        }

        $row = Db::getInstance()->getRow(
            'SELECT tracking_token FROM `' . _DB_PREFIX_ . 'neria_attribution`
             WHERE id_order = ' . $idOrder
        );
        if (empty($row['tracking_token'])) {
            return;
        }
        $token = (string) $row['tracking_token'];

        if (!class_exists('StatsManager') || !(new ConfigManager($this))->isStatsEnabled()) {
            return;
        }

        try {
            $order = new Order($idOrder);
            $amount = (float) $order->total_paid_tax_incl;
        } catch (\Throwable $e) {
            $amount = 0.0;
        }

        (new StatsManager($this))->recordConversion($token, $idOrder, $amount);

        if (class_exists('WatchdogManager')) {
            (new WatchdogManager($this))->info(
                WatchdogManager::i18nMsg('watchdog.attribution_conversion_recorded', [
                    'order'  => $idOrder,
                    'amount' => sprintf('%.2f', $amount),
                    'token'  => $token,
                ]),
                '', 'Attribution'
            );
        }

        // Nettoyer l'entrée d'attribution une fois convertie
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'neria_attribution` WHERE id_order = ' . $idOrder
        );
    }

    /**
     * Remboursement / avoir créé → envoie refund_processed
     */
    public function hookActionOrderSlipAdd(array $params): void
    {
        NeriaErrorHandler::wrapHookVoid('hookActionOrderSlipAdd', function () use ($params): void {
            if (!class_exists('OrderTriggersManager')) {
                return;
            }
            $order = $params['order'] ?? null;
            if (!$order instanceof Order) {
                return;
            }
            (new OrderTriggersManager($this))->handleRefund($order, $params['productList'] ?? []);
        }, $this);
    }

    /**
     * Retour marchandise enregistré → envoie return_received
     */
    public function hookActionObjectOrderReturnAddAfter(array $params): void
    {
        NeriaErrorHandler::wrapHookVoid('hookActionObjectOrderReturnAddAfter', function () use ($params): void {
            if (!class_exists('OrderTriggersManager')) {
                return;
            }
            $orderReturn = $params['object'] ?? null;
            if (!$orderReturn instanceof OrderReturn) {
                return;
            }
            (new OrderTriggersManager($this))->handleReturn($orderReturn);
        }, $this);
    }

    /**
     * Bloc certificat d'authenticité sur la fiche commande PS
     * Affiché en bas de la colonne principale (under order details)
     */
    public function hookDisplayAdminOrderMainBottom(array $params): string
    {
        return NeriaErrorHandler::wrapHookString('hookDisplayAdminOrderMainBottom', function () use ($params): string {
            return $this->hookDisplayAdminOrderMainBottomImpl($params);
        }, $this);
    }

    private function hookDisplayAdminOrderMainBottomImpl(array $params): string
    {
        if (!class_exists('CertificateManager')) {
            return '';
        }
        if (!(bool) Configuration::getGlobalValue(CertificateManager::CFG_ENABLED)) {
            return '';
        }

        $idOrder = (int) ($params['id_order'] ?? 0);
        if ($idOrder <= 0) {
            return '';
        }

        $order    = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        // Produits de la commande
        $products = $order->getProducts();

        // Certificats déjà émis pour cette commande
        $certs = (new CertificateManager($this))->getByOrder($idOrder);

        // URL de l'action (retour sur la même page commande)
        $actionUrl = $this->context->link->getAdminLink('AdminModules')
                   . '&configure=' . $this->name
                   . '&neria_action=cert_issue';

        // Vérifie si une signature est disponible
        $hasSig = (bool) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_signature`
             WHERE `is_active` = 1 AND `id_shop` = ' . (int) $this->context->shop->id
        );

        $this->context->smarty->assign([
            'cert_order_id'       => $idOrder,
            'cert_order_products' => $products,
            'cert_existing'       => $certs,
            'cert_action_url'     => $actionUrl,
            'cert_has_signature'  => $hasSig,
            'cert_qr_enabled'     => (bool) Configuration::getGlobalValue(CertificateManager::CFG_QR_ENABLED),
            'cert_bo_url'         => $this->context->link->getAdminLink('AdminModules')
                                   . '&configure=' . $this->name . '&neria_tab=certificates',
        ]);

        // Enregistre le plugin Smarty {neria_admin} — requis hors contexte getContent()
        AdminTranslator::register($this->context->smarty);

        try {
            return $this->renderTemplate('order_certificate_block.tpl');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getSegmentCountries(): array
    {
        $idLang = (int) $this->context->language->id;
        $rows   = Db::getInstance()->executeS(
            "SELECT co.id_country, cl.name
             FROM " . _DB_PREFIX_ . "country co
             INNER JOIN " . _DB_PREFIX_ . "country_lang cl
               ON cl.id_country = co.id_country AND cl.id_lang = {$idLang}
             ORDER BY cl.name"
        );
        return is_array($rows) ? $rows : [];
    }

    private function renderCustomerEmailHistory(array $params): string
    {
        return NeriaErrorHandler::wrapHookString('hookDisplayAdminCustomersView', function () use ($params): string {
            return $this->renderCustomerEmailHistoryImpl($params);
        }, $this);
    }

    private function renderCustomerEmailHistoryImpl(array $params): string
    {
        if (!class_exists('CustomerEmailHistoryManager')) {
            return '';
        }

        $customer   = $params['object'] ?? null;
        $idCustomer = is_object($customer) ? (int) $customer->id : (int) ($params['id_customer'] ?? 0);
        if ($idCustomer <= 0) {
            return '';
        }

        $manager = new CustomerEmailHistoryManager($this);
        $this->maybeOutputHistoryFileResponse($idCustomer, $manager);

        AdminTranslator::register($this->context->smarty);
        $resendMessage = $this->processHistoryResend($idCustomer, $manager);
        $vars = $this->buildHistorySmartyVars($idCustomer, $manager, $resendMessage);

        // Score de risque de désabonnement (ChurnScoreManager)
        if (class_exists('ChurnScoreManager')) {
            $churnMgr = new ChurnScoreManager($this);
            $vars['neria_churn'] = $churnMgr->getCustomerScore($idCustomer);
            $vars['neria_churn_threshold'] = ChurnScoreManager::HIGH_RISK_THRESHOLD;
        }

        // Potentiel client 12 mois (ClvManager)
        if (class_exists('ClvManager')) {
            $vars['neria_clv'] = (new ClvManager($this))->getCustomerClv($idCustomer);
        }

        $this->context->smarty->assign($vars);

        return $this->display($this->name, 'views/templates/admin/customer_email_history.tpl');
    }

    /**
     * Onglet « Historique clients » du panneau Neria : si un client a été
     * sélectionné via la recherche (paramètre neria_hist_customer), prépare
     * les mêmes variables Smarty que le bloc affiché sur la fiche client.
     */
    private function prepareCustomerHistoryTab(): void
    {
        $idCustomer = (int) Tools::getValue('neria_hist_customer', 0);
        if ($idCustomer <= 0 || !class_exists('CustomerEmailHistoryManager')) {
            return;
        }

        $customer = new Customer($idCustomer);
        if (!Validate::isLoadedObject($customer)) {
            return;
        }
        // Même garde que la recherche : un id_customer saisi directement dans
        // l'URL ne doit pas donner accès à l'historique d'un client d'une
        // autre boutique isolée (mode de partage client non partagé).
        $shopRestriction = Shop::addSqlRestriction(Shop::SHARE_CUSTOMER);
        $exists = Db::getInstance()->getValue(
            'SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `id_customer` = ' . $idCustomer . " AND `deleted` = 0 {$shopRestriction}"
        );
        if (!$exists) {
            return;
        }

        $manager = new CustomerEmailHistoryManager($this);
        $this->maybeOutputHistoryFileResponse($idCustomer, $manager);

        $resendMessage = $this->processHistoryResend($idCustomer, $manager);
        $vars = $this->buildHistorySmartyVars($idCustomer, $manager, $resendMessage);
        $vars['neria_hist_selected_customer'] = true;
        $vars['neria_hist_selected_label'] = trim($customer->firstname . ' ' . $customer->lastname)
            . ' — ' . $customer->email;

        $this->context->smarty->assign($vars);
    }

    /**
     * Aperçu (iframe) et export CSV : ces deux actions répondent directement
     * (HTML brut / fichier téléchargé) et coupent le rendu de la page —
     * partagé entre la fiche client (hook) et l'onglet Historique clients.
     */
    private function maybeOutputHistoryFileResponse(int $idCustomer, CustomerEmailHistoryManager $manager): void
    {
        if (Tools::getValue('neria_preview_email') && (int) Tools::getValue('id_customer') === $idCustomer) {
            $idStat = (int) Tools::getValue('id_stat');
            $html   = $manager->buildPreviewHtml($idStat, $idCustomer);
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo $html ?? '<p style="padding:40px;font-family:sans-serif;color:#a33;">Aperçu indisponible.</p>';
            exit;
        }

        if (Tools::getValue('neria_export_csv') && (int) Tools::getValue('id_customer') === $idCustomer) {
            $csv = $manager->buildCsv($idCustomer);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="neria_emails_client_' . $idCustomer . '.csv"');
            echo "\xEF\xBB\xBF" . $csv;
            exit;
        }
    }

    /**
     * Renvoi : POST déclenché par le bouton « Renvoyer » d'une ligne.
     */
    private function processHistoryResend(int $idCustomer, CustomerEmailHistoryManager $manager): ?array
    {
        if (!Tools::isSubmit('neria_resend_email') || (int) Tools::getValue('id_customer') !== $idCustomer) {
            return null;
        }

        $result = $manager->resend((int) Tools::getValue('id_stat'), $idCustomer);

        return [
            'ok'   => $result['ok'],
            'text' => AdminTranslator::tVars($result['message_key'], $result['vars']),
        ];
    }

    private function buildHistorySmartyVars(
        int $idCustomer,
        CustomerEmailHistoryManager $manager,
        ?array $resendMessage
    ): array {
        $data = $manager->buildBlockData($idCustomer);
        foreach ($data['alerts'] as &$alert) {
            $alert['text'] = AdminTranslator::tVars('history.' . $alert['key'], $alert['vars']);
        }
        unset($alert);

        $loyaltyStats = null;
        if (class_exists('LoyaltyManager') && Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')) {
            try {
                $loyaltyStats = (new LoyaltyManager($this))->getCustomerStats($idCustomer);
            } catch (\Throwable $e) {
                // Non-bloquant
            }
        }

        return [
            'neria_history'              => $data,
            'neria_customer_id'          => $idCustomer,
            'neria_resend_message'       => $resendMessage,
            'neria_resend_confirm_texts' => [
                'history.resend_confirm'            => AdminTranslator::t('history.resend_confirm'),
                'history.resend_confirm_no_snapshot' => AdminTranslator::t('history.resend_confirm_no_snapshot'),
            ],
            'neria_loyalty'             => $loyaltyStats,
            'neria_loyalty_enabled'     => (bool) Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED'),
            'currency_symbol'           => $this->context->currency->sign ?? '€',
        ];
    }

    /**
     * Recherche client (AJAX) pour l'onglet Historique clients : retourne
     * un JSON de correspondances nom/email, coupe le rendu de la page.
     */
    private function outputCustomerSearch(): void
    {
        $query = trim((string) Tools::getValue('q', ''));
        try {
            $results = strlen($query) >= 2 ? $this->searchCustomersForHistory($query) : [];
        } catch (\Throwable $e) {
            $results = [];
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($results);
        exit;
    }

    private function searchCustomersForHistory(string $query): array
    {
        $q = pSQL($query);
        // Respecte le mode de partage client PrestaShop — évite qu'un employé
        // restreint à une boutique retrouve/consulte l'historique de clients
        // d'une autre boutique isolée (cf. ManualSendManager::searchCustomers).
        $shopRestriction = Shop::addSqlRestriction(Shop::SHARE_CUSTOMER);
        $rows = Db::getInstance()->executeS(
            "SELECT id_customer, firstname, lastname, email FROM " . _DB_PREFIX_ . "customer
             WHERE deleted = 0
               AND (firstname LIKE '%{$q}%' OR lastname LIKE '%{$q}%' OR email LIKE '%{$q}%')
               {$shopRestriction}
             ORDER BY lastname ASC, firstname ASC
             LIMIT 10"
        );

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'    => (int) $row['id_customer'],
                'label' => trim($row['firstname'] . ' ' . $row['lastname']) . ' — ' . $row['email'],
            ];
        }

        return $out;
    }

    /**
     * Hook back-office : injecte CSS et JS Neria dans le header admin.
     * CRITIQUE : ce hook tourne sur CHAQUE page du BO PrestaShop (pas
     * seulement Neria) — une exception non rattrapée ici casserait
     * l'administration entière de la boutique pour le marchand.
     */
    public function hookDisplayBackOfficeHeader(): void
    {
        NeriaErrorHandler::wrapHookVoid('hookDisplayBackOfficeHeader', function (): void {
            $this->hookDisplayBackOfficeHeaderImpl();
        }, $this);
    }

    private function hookDisplayBackOfficeHeaderImpl(): void
    {
        $controllerName = Tools::getValue('controller') ?: ($this->context->controller->controller_name ?? '');
        $requestUri     = (string) ($_SERVER['REQUEST_URI'] ?? '');

        // CSS/JS chargés sur la page de configuration Neria, et sur la fiche
        // client pour le bloc « Emails reçus ». La fiche client est en
        // Symfony depuis PS 1.7+ (route /sell/customers/{id}/view, pas de
        // paramètre controller=AdminCustomers classique) — on détecte donc
        // aussi via l'URL en complément du nom de contrôleur legacy.
        $onConfigPage   = Tools::getValue('configure') === $this->name;
        $onCustomerView = $controllerName === 'AdminCustomers'
            || (bool) preg_match('#/customers?/\d+#i', $requestUri);

        if ($onConfigPage || $onCustomerView) {
            $this->context->controller->addCSS(
                $this->_path . 'views/css/neria-admin.css?v=' . $this->version
            );
            $this->context->controller->addJS(
                $this->_path . 'views/js/neria-admin.js?v=' . $this->version
            );
        }

        $this->migrateStatTableIfNeeded();
        $this->migrateRevenueColumnIfNeeded();
        $this->migrateMppColumnIfNeeded();
        $this->createAttributionTableIfNeeded();
        $this->createUpsellTableIfNeeded();
        $this->createLoyaltyTablesIfNeeded();
        $this->createSeasonalCampaignTableIfNeeded();

        // Réputation de domaine — refresh auto côté BO (même throttle 24h que front)
        if (class_exists('DomainReputationManager')) {
            $lastCheck = (int) Configuration::get('NERIA_DOMAIN_REP_LAST_CHECK');
            if ((time() - $lastCheck) >= 86400) {
                try {
                    (new DomainReputationManager($this))->runFullCheck();
                } catch (\Throwable $e) {
                    // best-effort
                }
            }
        }

        // Hooks ajoutés après l'install() initial des installations
        // existantes : à enregistrer explicitement, sinon PrestaShop ne les
        // appelle jamais (pas d'entrée en ps_hook_module). registerHook()
        // est idempotent — sans risque de double-enregistrement.
        $this->registerHook('displayAdminCustomersView');
        $this->registerHook('displayAdminCustomers');
        $this->registerHook('actionOrderStatusPostUpdate');
        $this->registerHook('actionObjectOrderAddAfter');

        // Nettoyage : 'actionEmailSendAfter' n'est pas un hook PrestaShop
        // réel (absent du cœur, jamais déclenché) — on retire l'entrée
        // fantôme laissée par une version antérieure du module.
        $this->unregisterHook('actionEmailSendAfter');
    }

    /**
     * Migration légère pour les installations existantes : ajoute la colonne
     * rendered_vars (snapshot JSON) à ps_neria_stat si elle n'existe pas
     * encore. Protégé par un flag Configuration pour ne tourner qu'une fois.
     */
    private function migrateStatTableIfNeeded(): void
    {
        if (Configuration::get('NERIA_MIGRATED_RENDERED_VARS')) {
            return;
        }

        $table  = _DB_PREFIX_ . 'neria_stat';
        $exists = Db::getInstance()->executeS(
            "SHOW COLUMNS FROM `{$table}` LIKE 'rendered_vars'"
        );

        if (empty($exists)) {
            Db::getInstance()->execute(
                "ALTER TABLE `{$table}` ADD COLUMN `rendered_vars` MEDIUMTEXT NULL AFTER `abtest_variant`"
            );
        }

        Configuration::updateValue('NERIA_MIGRATED_RENDERED_VARS', 1);
    }

    private function migrateMppColumnIfNeeded(): void
    {
        if (Configuration::get('NERIA_MIGRATED_MPP_COL')) {
            return;
        }

        $table  = _DB_PREFIX_ . 'neria_stat';
        $exists = Db::getInstance()->executeS(
            "SHOW COLUMNS FROM `{$table}` LIKE 'is_mpp'"
        );

        if (empty($exists)) {
            Db::getInstance()->execute(
                "ALTER TABLE `{$table}` ADD COLUMN `is_mpp` TINYINT(1) NOT NULL DEFAULT 0
                 COMMENT 'Apple MPP : 1 = ouverture probable MPP' AFTER `event_type`"
            );
        }

        Configuration::updateValue('NERIA_MIGRATED_MPP_COL', 1);
    }

    private function migrateRevenueColumnIfNeeded(): void
    {
        if (Configuration::get('NERIA_MIGRATED_REVENUE_COL')) {
            return;
        }

        $table  = _DB_PREFIX_ . 'neria_stat';
        $exists = Db::getInstance()->executeS(
            "SHOW COLUMNS FROM `{$table}` LIKE 'revenue'"
        );

        if (empty($exists)) {
            Db::getInstance()->execute(
                "ALTER TABLE `{$table}` ADD COLUMN `revenue` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `rendered_vars`"
            );
        }

        Configuration::updateValue('NERIA_MIGRATED_REVENUE_COL', 1);
    }

    private function createUpsellTableIfNeeded(): void
    {
        if (Configuration::get('NERIA_CREATED_UPSELL_TABLE')) {
            return;
        }

        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_upsell` (
                `id_upsell`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `id_customer`        INT UNSIGNED  NOT NULL DEFAULT 0,
                `id_order_source`    INT UNSIGNED  NOT NULL DEFAULT 0,
                `id_product_upsell`  INT UNSIGNED  NOT NULL DEFAULT 0,
                `product_name`       VARCHAR(255)  NOT NULL DEFAULT \'\',
                `tier`               ENUM(\'accessory\',\'co_purchase\',\'bestseller\') NOT NULL DEFAULT \'bestseller\',
                `reason`             VARCHAR(100)  NOT NULL DEFAULT \'\',
                `sent_at`            DATETIME      NOT NULL,
                `clicked_at`         DATETIME      NULL DEFAULT NULL,
                `id_order_converted` INT UNSIGNED  NULL DEFAULT NULL,
                `converted_at`       DATETIME      NULL DEFAULT NULL,
                `conversion_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (`id_upsell`),
                KEY `idx_customer` (`id_customer`),
                KEY `idx_product`  (`id_product_upsell`),
                KEY `idx_clicked`  (`clicked_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Configuration::updateValue('NERIA_CREATED_UPSELL_TABLE', 1);
    }

    private function createLoyaltyTablesIfNeeded(): void
    {
        if (Configuration::get('NERIA_CREATED_LOYALTY_TABLES')) {
            return;
        }

        $db = Db::getInstance();

        $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_loyalty_points` (
                `id_point`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `id_customer` INT UNSIGNED  NOT NULL,
                `id_stat`     INT UNSIGNED  NOT NULL,
                `event_type`  ENUM(\'open\',\'click\',\'conversion\') NOT NULL,
                `points`      TINYINT       NOT NULL DEFAULT 0,
                `date_add`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_point`),
                UNIQUE KEY `uq_stat_event` (`id_stat`, `event_type`),
                KEY `idx_customer` (`id_customer`),
                KEY `idx_date`     (`date_add`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_loyalty_rewards` (
                `id_reward`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `id_customer`      INT UNSIGNED  NOT NULL,
                `tier_key`         VARCHAR(20)   NOT NULL,
                `tier_name`        VARCHAR(50)   NOT NULL DEFAULT \'\',
                `points_at_reward` INT           NOT NULL DEFAULT 0,
                `id_cart_rule`     INT UNSIGNED  NOT NULL DEFAULT 0,
                `voucher_code`     VARCHAR(50)   NOT NULL DEFAULT \'\',
                `voucher_amount`   DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
                `is_percent`       TINYINT(1)    NOT NULL DEFAULT 0,
                `sent_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_reward`),
                UNIQUE KEY `uq_customer_tier` (`id_customer`, `tier_key`),
                KEY `idx_customer` (`id_customer`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Configuration::updateValue('NERIA_CREATED_LOYALTY_TABLES', 1);
    }

    private function createSeasonalCampaignTableIfNeeded(): void
    {
        if (Configuration::get('NERIA_CREATED_SEASONAL_TABLE')) {
            return;
        }

        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_seasonal_campaign` (
                `id_campaign`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `id_shop`        INT           NOT NULL DEFAULT 1,
                `name`           VARCHAR(100)  NOT NULL DEFAULT \'\',
                `template`       VARCHAR(100)  NOT NULL DEFAULT \'\',
                `annual_date`    CHAR(5)       NOT NULL DEFAULT \'01-01\',
                `days_before`    TINYINT       NOT NULL DEFAULT 0,
                `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
                `target_segment` VARCHAR(255)  NOT NULL DEFAULT \'\',
                `target_gender`  TINYINT       NOT NULL DEFAULT 0,
                `target_lang`    VARCHAR(255)  NOT NULL DEFAULT \'\',
                `min_age`        TINYINT       NOT NULL DEFAULT 0,
                `max_age`        TINYINT       NOT NULL DEFAULT 0,
                `date_add`       DATETIME      NOT NULL,
                `date_upd`       DATETIME      NOT NULL,
                PRIMARY KEY (`id_campaign`),
                KEY `idx_shop_active` (`id_shop`, `is_active`),
                KEY `idx_date`        (`annual_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Configuration::updateValue('NERIA_CREATED_SEASONAL_TABLE', 1);
    }

    private function createAttributionTableIfNeeded(): void
    {
        if (Configuration::get('NERIA_CREATED_ATTRIBUTION_TABLE')) {
            return;
        }

        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_attribution` (
                `id_order`       INT(10) UNSIGNED NOT NULL,
                `tracking_token` VARCHAR(128)     NOT NULL,
                `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Configuration::updateValue('NERIA_CREATED_ATTRIBUTION_TABLE', 1);
    }

    // ── Liste d'attente ───────────────────────────────────────────

    public function hookDisplayProductAdditionalInfo(array $params): string
    {
        return NeriaErrorHandler::wrapHookString('hookDisplayProductAdditionalInfo', function () use ($params): string {
            return $this->hookDisplayProductAdditionalInfoImpl($params);
        }, $this);
    }

    private function hookDisplayProductAdditionalInfoImpl(array $params): string
    {
        if (!Configuration::getGlobalValue('NERIA_WAITLIST_ENABLED')) return '';
        if (!class_exists('WaitlistManager')) return '';

        $product = $params['product'] ?? null;
        if (!$product) return '';

        $idProduct  = (int) ($product['id_product'] ?? 0);
        $qty        = (int) ($product['quantity'] ?? 0);
        $idCustomer = (int) $this->context->customer->id;

        if ($qty > 0) return '';

        $mgr        = new WaitlistManager($this);
        $registered = $idCustomer > 0 && $mgr->isRegistered($idCustomer, $idProduct);

        $actionUrl = $this->context->link->getModuleLink('neria', 'waitlist');
        $backUrl   = $this->context->link->getProductLink($idProduct);

        if (class_exists('AdminTranslator')) {
            AdminTranslator::setLang((string) ($this->context->language->iso_code ?? ''));
            AdminTranslator::register($this->context->smarty);
        }

        $this->context->smarty->assign([
            'waitlist_oos'             => true,
            'waitlist_registered'      => $registered,
            'waitlist_id_product'      => $idProduct,
            'waitlist_subscribe_url'   => $actionUrl,
            'waitlist_unsubscribe_url' => $actionUrl . '?action=unsubscribe&id_product=' . $idProduct . '&back=' . urlencode($backUrl),
            'waitlist_back_url'        => $backUrl,
        ]);

        $html = $this->display(__FILE__, 'views/templates/front/waitlist_button.tpl');

        // Masquer le bouton ps_emailalerts pour éviter le double bouton
        if ($qty <= 0 && Module::isInstalled('ps_emailalerts') && Module::isEnabled('ps_emailalerts')) {
            $html .= '<style>.js-mailalert,.tabs:has(.js-mailalert){display:none!important;}</style>';
        }

        return $html;
    }

    public function hookActionUpdateQuantity(array $params): void
    {
        if (!Configuration::getGlobalValue('NERIA_WAITLIST_ENABLED')) return;
        if (!class_exists('WaitlistManager')) return;

        $idProduct = (int) ($params['id_product'] ?? 0);
        $quantity  = (int) ($params['quantity']   ?? 0);
        $idShop    = (int) ($params['id_shop']    ?? $this->context->shop->id);

        if ($idProduct <= 0 || $quantity <= 0) return;

        try {
            (new WaitlistManager($this))->notifyProduct($idProduct, $idShop);
        } catch (\Throwable $e) {
            $this->log('WaitlistManager::notifyProduct() erreur : ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Hook RGPD natif PrestaShop : déclenché quand un marchand supprime un
     * client via « Supprimer + effacer les données personnelles » (BO
     * Clients, ou via le module psgdpr). PrestaShop appelle ce hook avec
     * l'objet Customer casté en tableau (clés : 'id', 'email', ...) —
     * cf. modules/ps_emailsubscription/ps_emailsubscription.php pour le
     * contrat exact. Purge les données Neria liées à ce client (stats,
     * comportemental, fidélité, etc.) — GdprAuditManager::purgeCustomerData().
     */
    public function hookActionDeleteGDPRCustomer($customer): void
    {
        NeriaErrorHandler::wrapHookVoid('hookActionDeleteGDPRCustomer', function () use ($customer): void {
            $this->hookActionDeleteGDPRCustomerImpl($customer);
        }, $this);
    }

    private function hookActionDeleteGDPRCustomerImpl($customer): void
    {
        if (!class_exists('GdprAuditManager')) {
            return;
        }

        $data       = (array) $customer;
        $idCustomer = (int) ($data['id'] ?? $data['id_customer'] ?? 0);
        $email      = (string) ($data['email'] ?? '');

        if ($idCustomer <= 0 || $email === '') {
            return;
        }

        $purged = (new GdprAuditManager($this))->purgeCustomerData($idCustomer, $email);

        if (class_exists('WatchdogManager')) {
            (new WatchdogManager($this))->info(
                WatchdogManager::i18nMsg('watchdog.gdpr_customer_purged', [
                    'customer' => $idCustomer,
                    'email'    => $email,
                    'n'        => $purged,
                ]),
                '', 'GdprAuditManager'
            );
        }
    }

    /**
     * Hook cron-like : vérifie les occasions calendaires du jour
     * Déclenché par l'action displayHeader (toutes les 24h via cache)
     */
    public function hookDisplayHeader(): void
    {
        NeriaErrorHandler::wrapDisplayHeader(function (): void {
            $this->hookDisplayHeaderImpl();
        }, $this);
    }

    /**
     * Corps réel de hookDisplayHeader() — appelé via NeriaErrorHandler::wrapDisplayHeader().
     * Ce hook tourne sur CHAQUE page front-office ; une exception non
     * rattrapée ici casserait la boutique pour tout visiteur.
     */
    private function hookDisplayHeaderImpl(): void
    {
        $this->runBackgroundJobs();
    }

    /**
     * Corps commun de toutes les tâches de fond de Neria (queue, crons
     * comportementaux, rapports, digest Watchdog…). Appelé depuis deux
     * points d'entrée :
     *  - hookDisplayHeader() : déclenchement passif, sur chaque page
     *    front-office (fallback qui ne dépend d'aucune configuration
     *    serveur, fonctionne sur toute installation "out of the box").
     *  - controllers/front/cron.php : déclenchement actif, via un vrai
     *    cron serveur (crontab) protégé par jeton — recommandé en
     *    production pour ne pas dépendre du trafic visiteurs.
     * Chaque tâche a son propre throttle interne (Configuration ou table
     * neria_cron_health) : appeler cette méthode plus souvent que
     * nécessaire est sans danger, aucune tâche ne s'exécute deux fois
     * dans sa fenêtre.
     *
     * @return array<string,bool> Résumé "tâche => a été exécutée" (utilisé
     *                            par le contrôleur cron pour son rapport JSON)
     */
    public function runBackgroundJobs(): array
    {
        $ran = [];

        $health = new HealthCheckManager($this);
        $health->recordDisplayHeaderRun();
        $health->runAutoChecksIfDue();
        $ran['health_checks'] = true;

        // ── Queue d'envoi (toutes les 5 min) — emails programmés à l'heure
        // préférée du client (fenêtre d'achat), relances comportementales…
        if (class_exists('QueueManager')) {
            $now      = time();
            $lastQueue = (int) \Configuration::get('neria_queue_last_process');
            if (($now - $lastQueue) >= 300) {
                \Configuration::updateValue('neria_queue_last_process', $now);
                try {
                    $sent = (new QueueManager($this))->processQueue();
                    $ran['queue'] = true;
                    if (class_exists('WatchdogManager')) {
                        (new WatchdogManager($this))->cronHeartbeat('queue', 'ok', $sent);
                    }
                } catch (\Throwable $e) {
                    // best-effort — ne bloque jamais le front
                }
            }
        }

        // ── Queue webhook (toutes les 5 min) ──────────────────────────
        if (class_exists('WebhookManager')) {
            $now = time();
            $lastWebhook = (int) \Configuration::get('neria_webhook_last_process');
            if (($now - $lastWebhook) >= 300) {
                \Configuration::updateValue('neria_webhook_last_process', $now);
                try {
                    (new WebhookManager($this))->processQueue();
                    $ran['webhook'] = true;
                    if (class_exists('WatchdogManager')) {
                        (new WatchdogManager($this))->cronHeartbeat('webhook');
                    }
                } catch (\Throwable $e) {
                    // best-effort — ne bloque jamais le front
                }
            }
        }

        if (class_exists('CalendarManager')) {
            $calendar = new CalendarManager($this);
            $calendar->checkAndSendDailyEvents();
            $ran['calendar'] = true;
            if (class_exists('WatchdogManager')) {
                try { (new WatchdogManager($this))->cronHeartbeat('calendar'); } catch (\Throwable $e) {}
            }
        }

        if (class_exists('MonthlyReportManager')) {
            try {
                (new MonthlyReportManager($this))->checkAndSend();
                $ran['monthly_report'] = true;
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this))->cronHeartbeat('monthly_report');
                }
            } catch (\Throwable $e) {
                // best-effort — ne bloque jamais le front
            }
        }

        // ── Réputation de domaine (rafraîchissement auto 24h) ─────────
        if (class_exists('DomainReputationManager')) {
            try {
                (new DomainReputationManager($this))->getReport(false);
                $ran['domain_reputation'] = true;
            } catch (\Throwable $e) {
                // best-effort — ne bloque jamais le front
            }
        }

        // ── Tâches quotidiennes comportementales (fallback sans cron serveur) ─
        // CRON_LAST_BEHAVIORAL est mis à jour par BehavioralCronManager::run() lui-même
        if (class_exists('BehavioralCronManager')) {
            $lastBehavioral = (int) strtotime(
                (string) \Configuration::get(HealthCheckManager::CRON_LAST_BEHAVIORAL)
            );
            if ((time() - $lastBehavioral) >= 86400) {
                try {
                    (new BehavioralCronManager($this))->run();
                    $ran['behavioral'] = true;
                } catch (\Throwable $e) {
                    // best-effort — ne bloque jamais le front
                }
                if (class_exists('UpsellManager')) {
                    try {
                        (new UpsellManager($this))->checkConversions();
                        $ran['upsell_conversions'] = true;
                        if (class_exists('WatchdogManager')) {
                            (new WatchdogManager($this))->cronHeartbeat('upsell_conversions');
                        }
                    } catch (\Throwable $e) {}
                }
                if (class_exists('LoyaltyManager') && \Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')) {
                    try {
                        (new LoyaltyManager($this))->sendMonthlyRecaps();
                        $ran['loyalty_recaps'] = true;
                        if (class_exists('WatchdogManager')) {
                            (new WatchdogManager($this))->cronHeartbeat('loyalty_recaps');
                        }
                    } catch (\Throwable $e) {}
                }
                if (class_exists('SeasonalCampaignManager')) {
                    try {
                        (new SeasonalCampaignManager($this))->runDueCampaigns();
                        $ran['seasonal_campaigns'] = true;
                        if (class_exists('WatchdogManager')) {
                            (new WatchdogManager($this))->cronHeartbeat('seasonal_campaigns');
                        }
                    } catch (\Throwable $e) {}
                }
            }
        }

        // ── Watchdog — digest quotidien (throttle interne 24h) ────────
        if (class_exists('WatchdogManager')) {
            try {
                (new WatchdogManager($this))->sendDailyDigestIfDue();
                $ran['watchdog_digest'] = true;
            } catch (\Throwable $e) {
                // best-effort — ne bloque jamais le front
            }
        }

        return $ran;
    }

    // ============================================================
    // PANNEAU DE CONFIGURATION BACK-OFFICE
    // ============================================================

    /**
     * Point d'entrée du panneau de configuration
     * PrestaShop appelle cette méthode quand le marchand
     * clique sur "Configurer" dans la liste des modules
     */
    public function getContent(): string
    {
        return NeriaErrorHandler::wrapGetContent(function (): string {
            return $this->getContentImpl();
        }, $this);
    }

    /** Corps réel du panneau de configuration — appelé depuis getContent() via NeriaErrorHandler. */
    private function getContentImpl(): string
    {
        // ── Traductions du back-office (18 langues) ───────────────
        // Enregistre le helper Smarty {neria_admin key='...'} sur l'instance
        // courante. La langue affichée = celle de l'employé connecté.
        AdminTranslator::register($this->context->smarty);

        // ── Migrations runtime (installations existantes) ─────────
        // hookDisplayBackOfficeHeader s'exécute APRÈS getContent() ;
        // les tables doivent exister avant les Smarty assigns.
        $this->createSeasonalCampaignTableIfNeeded();

        // ── Aperçu email (iframe de l'onglet Design) ──────────────
        // Ne rend QUE l'email et coupe le rendu. Sinon l'iframe, dont le src
        // pointe vers cette même page, rechargerait toute la page admin (qui
        // contient l'iframe) → récursion infinie → surchauffe CPU.
        if (Tools::getValue('neria_action') === 'preview') {
            $this->outputEmailPreview();
        }

        // ── AJAX : rafraîchissement Watchdog ─────────────────────────
        if (Tools::getValue('neria_action') === 'watchdog_refresh') {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            try {
                $wh = (new WatchdogManager($this))->getWatchdogHealthScore();
                $wh['anomalies'] = class_exists('StatsManager')
                    ? (new StatsManager($this))->detectAnomalies()
                    : [];
                echo json_encode($wh);
            } catch (\Exception $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
        }

        // ── AJAX : fermeture définitive de l'assistant de démarrage
        //    Design ("Nouveau sur Neria ?") ─────────────────────────
        if (Tools::getValue('neria_action') === 'dismiss_design_wizard' && class_exists('ConfigManager')) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            (new ConfigManager($this))->set(ConfigManager::KEY_DESIGN_WIZARD_SEEN, 1);
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── AJAX : aperçu signature manuscrite (onglet Configurer) ────
        if (Tools::getValue('neria_action') === 'preview_signature') {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            try {
                $sigName  = (string) Tools::getValue('sig_name', '');
                $sigTitle = (string) Tools::getValue('sig_title', '');
                $sigStyle = (string) Tools::getValue('sig_style', 'great_vibes');
                $sigColor = (string) Tools::getValue('sig_color', '#b38b59');

                if ($sigName === '') {
                    $sigName = trim((string) Configuration::get('PS_SHOP_NAME')) ?: 'Signature';
                }

                $preview = class_exists('SignatureGenerator')
                    ? (new SignatureGenerator($this))->generatePreview($sigName, $sigTitle, $sigStyle, $sigColor)
                    : false;

                echo json_encode(['preview' => $preview ?: null]);
            } catch (\Throwable $e) {
                echo json_encode(['preview' => null, 'error' => $e->getMessage()]);
            }
            exit;
        }

        // ── Actions AJAX pures — doivent sortir avant le rendu PS ──────
        $earlyAction = Tools::getValue('neria_action');

        if ($earlyAction === 'search_translations') {
            header('Content-Type: application/json; charset=utf-8');
            $q = preg_replace('/[^a-z0-9àâäéèêëîïôùûüç\s\-_]/i', '', (string) Tools::getValue('q', ''));
            if (mb_strlen($q) < 2) { echo json_encode(['results' => []]); exit; }
            try {
                $tableTrad = _DB_PREFIX_ . 'neria_translation';
                $rows = Db::getInstance()->executeS(
                    "SELECT `template`, `lang`, `translation_key`, `translation_value`
                     FROM `{$tableTrad}`
                     WHERE `translation_value` LIKE '%" . pSQL($q, true) . "%'
                        OR `translation_key`   LIKE '%" . pSQL($q, true) . "%'
                     ORDER BY `template`, `lang`, `translation_key`
                     LIMIT 60"
                );
                $templateLabels = \AdminTranslator::templateLabels();
                $results = [];
                foreach ((array) $rows as $row) {
                    $results[] = [
                        'template'       => $row['template'],
                        'template_label' => $templateLabels[$row['template']] ?? $row['template'],
                        'lang'           => $row['lang'],
                        'key'            => $row['translation_key'],
                        'value'          => mb_substr($row['translation_value'], 0, 120),
                    ];
                }
            } catch (\Throwable $e) {
                $results = [];
            }
            echo json_encode(['results' => $results]);
            exit;
        }

        if ($earlyAction === 'auto_translate_template') {
            header('Content-Type: application/json; charset=utf-8');
            $tplKey  = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('trad_template', ''));
            $tplLang = preg_replace('/[^a-z]/i', '', (string) Tools::getValue('trad_lang', 'fr'));
            $config  = new ConfigManager($this);
            $deeplKey = CryptoManager::decrypt(trim((string) $config->get(ConfigManager::KEY_DEEPL_KEY, '')));

            if ($deeplKey === '') {
                echo json_encode(['error' => AdminTranslator::t('msg.deepl_key_missing_full')]);
                exit;
            }

            $deeplTargetMap = [
                'fr'=>'FR','en'=>'EN-GB','de'=>'DE','it'=>'IT','es'=>'ES',
                'pt'=>'PT-PT','br'=>'PT-BR','nl'=>'NL','ru'=>'RU','tr'=>'TR',
                'sv'=>'SV','no'=>'NB','da'=>'DA','ja'=>'JA','ko'=>'KO',
                'zh'=>'ZH','tw'=>'ZH','ar'=>'AR',
            ];
            $deeplTarget = $deeplTargetMap[$tplLang] ?? null;
            if (!$deeplTarget) {
                echo json_encode(['error' => AdminTranslator::tVars('msg.deepl_lang_unsupported', ['lang' => $tplLang])]);
                exit;
            }

            $tableTrad = _DB_PREFIX_ . 'neria_translation';
            $rows = Db::getInstance()->executeS(
                "SELECT `translation_key`, `translation_value`
                 FROM `{$tableTrad}`
                 WHERE `template` = '" . pSQL($tplKey) . "'
                   AND `lang` = 'fr'"
            );
            if (!$rows) {
                echo json_encode(['error' => AdminTranslator::t('msg.deepl_no_source_text')]);
                exit;
            }

            if ($tplLang === 'fr') {
                echo json_encode(['error' => AdminTranslator::t('msg.deepl_french_is_source_full')]);
                exit;
            }

            $translated   = 0;
            $errors       = [];
            $firstErrBody = null;
            $firstErrCode = null;
            $now          = date('Y-m-d H:i:s');
            $isFreeKey    = str_ends_with($deeplKey, ':fx');
            $apiHost      = $isFreeKey ? 'api-free.deepl.com' : 'api.deepl.com';

            // Valeurs actuelles de la langue cible (pour l'historique + détection champs déjà personnalisés)
            $currentRows = Db::getInstance()->executeS(
                "SELECT `translation_key`, `translation_value`, `is_custom`
                 FROM `{$tableTrad}`
                 WHERE `template` = '" . pSQL($tplKey) . "'
                   AND `lang`     = '" . pSQL($tplLang) . "'"
            );
            $currentVals   = [];
            $customizedKeys = [];
            foreach ((array) $currentRows as $r) {
                $currentVals[$r['translation_key']] = $r['translation_value'];
                if ((int) $r['is_custom'] === 1 && trim($r['translation_value']) !== '') {
                    $customizedKeys[$r['translation_key']] = true;
                }
            }
            $histMgr = class_exists('TranslationHistoryManager') ? new TranslationHistoryManager() : null;
            $employee = $this->context->employee;
            $author   = trim($employee->firstname . ' ' . $employee->lastname) ?: 'DeepL';

            $skipped = 0;
            foreach ($rows as $row) {
                // Ne pas écraser un champ déjà personnalisé manuellement par le marchand
                if (isset($customizedKeys[$row['translation_key']])) { $skipped++; continue; }
                $text = $row['translation_value'];
                if (trim($text) === '') { continue; }

                $ch = curl_init("https://{$apiHost}/v2/translate");
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => http_build_query([
                        'text'        => $text,
                        'source_lang' => 'FR',
                        'target_lang' => $deeplTarget,
                        'tag_handling'=> 'html',
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: DeepL-Auth-Key ' . $deeplKey,
                        'Accept: application/json',
                    ],
                ]);
                $resp = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($code !== 200 || !$resp) {
                    if ($firstErrCode === null) {
                        $firstErrCode = $code;
                        $firstErrBody = $curlErr ?: (string) $resp;
                    }
                    $errors[] = $row['translation_key'];
                    continue;
                }
                $json   = json_decode($resp, true);
                $result = $json['translations'][0]['text'] ?? null;
                if ($result === null) { $errors[] = $row['translation_key']; continue; }

                // Une clé en échec (DB, historique) ne doit jamais interrompre
                // le lot entier — les autres clés doivent quand même se traduire.
                try {
                    if ($histMgr !== null) {
                        $histMgr->record($tplKey, $tplLang, $row['translation_key'], $currentVals[$row['translation_key']] ?? '', $result, $author . ' (DeepL)');
                    }

                    Db::getInstance()->execute(
                        "INSERT INTO `{$tableTrad}` (`template`,`lang`,`translation_key`,`translation_value`,`is_custom`,`date_add`,`date_upd`)
                         VALUES ('" . pSQL($tplKey) . "','" . pSQL($tplLang) . "','" . pSQL($row['translation_key']) . "','" . pSQL($result, true) . "',1,'{$now}','{$now}')
                         ON DUPLICATE KEY UPDATE `translation_value`='" . pSQL($result, true) . "', `is_custom`=1, `date_upd`='{$now}'"
                    );
                    $translated++;
                } catch (\Throwable $e) {
                    $errors[] = $row['translation_key'];
                }
            }

            if (class_exists('TranslationEngine')) { (new TranslationEngine($this))->clearCache(); }
            if (class_exists('WatchdogManager') && $translated > 0) {
                (new WatchdogManager($this))->info(
                    $skipped > 0
                        ? WatchdogManager::i18nMsg('watchdog.deepl_translated_skipped', [
                            'n' => $translated, 'template' => $tplKey, 'lang' => $tplLang, 'skipped' => $skipped,
                        ])
                        : WatchdogManager::i18nMsg('watchdog.deepl_translated', [
                            'n' => $translated, 'template' => $tplKey, 'lang' => $tplLang,
                        ]),
                    $tplKey, 'Traductions'
                );
            }

            if ($translated === 0 && !empty($errors)) {
                $detail = '';
                if ($firstErrCode !== null) {
                    $detail = " (HTTP {$firstErrCode}" . ($firstErrBody ? ': ' . substr($firstErrBody, 0, 120) : '') . ')';
                }
                echo json_encode(['error' => AdminTranslator::tVars('msg.deepl_zero_translated', ['count' => count($errors), 'detail' => $detail])]);
                exit;
            }

            $msg = AdminTranslator::tVars('msg.deepl_success_summary', [
                'n'           => $translated,
                'skippedPart' => $skipped > 0 ? AdminTranslator::tVars('msg.deepl_skipped_part', ['n' => $skipped]) : '',
                'errorsPart'  => !empty($errors) ? AdminTranslator::tVars('msg.deepl_errors_part', ['n' => count($errors)]) : '',
            ]);
            echo json_encode([
                'success'    => true,
                'translated' => $translated,
                'skipped'    => $skipped,
                'errors'     => $errors,
                'message'    => $msg,
            ]);
            exit;
        }

        // ── Action : traduction DeepL automatique — Variante B ────────
        if ($earlyAction === 'auto_translate_variant_b') {
            header('Content-Type: application/json; charset=utf-8');
            $tplKey    = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('trad_template', ''));
            $tplLang   = preg_replace('/[^a-z]/i', '', (string) Tools::getValue('trad_lang', 'fr'));
            $idAbtestB = (int) Tools::getValue('id_abtest_b', 0);
            $config    = new ConfigManager($this);
            $deeplKey  = CryptoManager::decrypt(trim((string) $config->get(ConfigManager::KEY_DEEPL_KEY, '')));

            if ($deeplKey === '') {
                echo json_encode(['error' => AdminTranslator::t('msg.deepl_key_missing_short')]);
                exit;
            }
            if (!$this->abtestBelongsToShop($idAbtestB)) {
                echo json_encode(['error' => AdminTranslator::t('msg.deepl_abtest_not_found')]);
                exit;
            }

            $deeplTargetMap = [
                'fr'=>'FR','en'=>'EN-GB','de'=>'DE','it'=>'IT','es'=>'ES',
                'pt'=>'PT-PT','br'=>'PT-BR','nl'=>'NL','ru'=>'RU','tr'=>'TR',
                'sv'=>'SV','no'=>'NB','da'=>'DA','ja'=>'JA','ko'=>'KO',
                'zh'=>'ZH','tw'=>'ZH','ar'=>'AR',
            ];
            $deeplTarget = $deeplTargetMap[$tplLang] ?? null;
            if (!$deeplTarget) {
                echo json_encode(['error' => AdminTranslator::tVars('msg.deepl_lang_unsupported', ['lang' => $tplLang])]);
                exit;
            }
            if ($tplLang === 'fr') {
                echo json_encode(['error' => AdminTranslator::t('msg.deepl_french_is_source_short')]);
                exit;
            }

            // Source : traductions A en FR
            $tableTrad  = _DB_PREFIX_ . 'neria_translation';
            $tableTradB = _DB_PREFIX_ . 'neria_abtest_translation';
            $rows = Db::getInstance()->executeS(
                "SELECT `translation_key`, `translation_value`
                 FROM `{$tableTrad}`
                 WHERE `template` = '" . pSQL($tplKey) . "'
                   AND `lang` = 'fr'"
            );
            if (!$rows) {
                echo json_encode(['error' => AdminTranslator::t('msg.deepl_no_source_text_short')]);
                exit;
            }

            // Champs déjà renseignés en variante B (considérés comme personnalisés — on ne les écrase pas)
            $customBRows = Db::getInstance()->executeS(
                "SELECT `translation_key`, `translation_value`
                 FROM `{$tableTradB}`
                 WHERE `id_abtest` = {$idAbtestB}
                   AND `lang` = '" . pSQL($tplLang) . "'"
            );
            $customBKeys = [];
            foreach ((array) $customBRows as $r) {
                if (trim($r['translation_value']) !== '') {
                    $customBKeys[$r['translation_key']] = true;
                }
            }

            $isFreeKey = str_ends_with($deeplKey, ':fx');
            $apiHost   = $isFreeKey ? 'api-free.deepl.com' : 'api.deepl.com';
            $now       = date('Y-m-d H:i:s');
            $translated = 0;
            $skipped    = 0;
            $errors     = [];
            $firstErrCode = null;
            $firstErrBody = null;

            foreach ($rows as $row) {
                if (isset($customBKeys[$row['translation_key']])) { $skipped++; continue; }
                $text = $row['translation_value'];
                if (trim($text) === '') { continue; }

                $ch = curl_init("https://{$apiHost}/v2/translate");
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => http_build_query([
                        'text'        => $text,
                        'source_lang' => 'FR',
                        'target_lang' => $deeplTarget,
                        'tag_handling'=> 'html',
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: DeepL-Auth-Key ' . $deeplKey,
                        'Accept: application/json',
                    ],
                ]);
                $resp    = curl_exec($ch);
                $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($code !== 200 || !$resp) {
                    if ($firstErrCode === null) { $firstErrCode = $code; $firstErrBody = $curlErr ?: (string) $resp; }
                    $errors[] = $row['translation_key'];
                    continue;
                }
                $json   = json_decode($resp, true);
                $result = $json['translations'][0]['text'] ?? null;
                if ($result === null) { $errors[] = $row['translation_key']; continue; }

                try {
                    Db::getInstance()->execute(
                        "INSERT INTO `{$tableTradB}` (`id_abtest`,`lang`,`translation_key`,`translation_value`,`date_add`,`date_upd`)
                         VALUES ({$idAbtestB},'" . pSQL($tplLang) . "','" . pSQL($row['translation_key']) . "','" . pSQL($result, true) . "','{$now}','{$now}')
                         ON DUPLICATE KEY UPDATE `translation_value`='" . pSQL($result, true) . "', `date_upd`='{$now}'"
                    );
                    $translated++;
                } catch (\Throwable $e) {
                    $errors[] = $row['translation_key'];
                }
            }

            if (class_exists('WatchdogManager') && $translated > 0) {
                (new WatchdogManager($this))->info(
                    WatchdogManager::i18nMsg('watchdog.deepl_translated_variant_b', [
                        'n' => $translated, 'template' => $tplKey, 'lang' => $tplLang,
                    ]),
                    $tplKey, 'Traductions'
                );
            }

            if ($translated === 0 && !empty($errors)) {
                $detail = $firstErrCode ? " (HTTP {$firstErrCode})" : '';
                echo json_encode(['error' => AdminTranslator::tVars('msg.deepl_zero_translated_short', ['detail' => $detail])]);
                exit;
            }
            $msg = AdminTranslator::tVars('msg.deepl_success_summary_variant_b', [
                'n'           => $translated,
                'skippedPart' => $skipped > 0 ? AdminTranslator::tVars('msg.deepl_skipped_part_variant_b', ['n' => $skipped]) : '',
                'errorsPart'  => !empty($errors) ? AdminTranslator::tVars('msg.deepl_errors_part', ['n' => count($errors)]) : '',
            ]);
            echo json_encode(['success' => true, 'translated' => $translated, 'skipped' => $skipped, 'message' => $msg]);
            exit;
        }

        // ── Action : envoi d'un email de test ─────────────────────
        if (Tools::getValue('neria_action') === 'send_test') {
            $this->sendTestEmail();
        }

        // ── Action : recherche client (AJAX, onglet Historique clients) ──
        if (Tools::getValue('neria_action') === 'search_customers') {
            $this->outputCustomerSearch();
        }

        // ── Action : test manuel du pixel HTTP ───────────────────
        if (Tools::getValue('neria_action') === 'health_pixel_test') {
            $result = (new HealthCheckManager($this))->testPixelHttp();
            $this->context->smarty->assign('health_pixel_result', $result);
        }

        // ── Action : sauvegarder config alertes email ──────────────
        if (Tools::getValue('neria_action') === 'save_alert_config') {
            $alertEmail     = trim(Tools::getValue('neria_alert_email'));
            $alertImmediate = (int) (bool) Tools::getValue('neria_alert_immediate');
            $alertDigest    = (int) (bool) Tools::getValue('neria_alert_digest');

            if ($alertEmail !== '' && !Validate::isEmail($alertEmail)) {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('help.alert_invalid_email'));
            } else {
                Configuration::updateGlobalValue(WatchdogManager::CFG_ALERT_EMAIL, $alertEmail);
                Configuration::updateGlobalValue(WatchdogManager::CFG_ALERT_IMMEDIATE, $alertImmediate);
                Configuration::updateGlobalValue(WatchdogManager::CFG_ALERT_DIGEST, $alertDigest);
                $this->context->smarty->assign('neria_success', AdminTranslator::t('help.alert_saved'));
            }
        }

        // ── Action : sauvegarder l'email du Témoin silencieux ───────
        if (Tools::getValue('neria_action') === 'save_archive_config') {
            $archiveEmail = trim(Tools::getValue('neria_archive_email'));
            if ($archiveEmail !== '' && !Validate::isEmail($archiveEmail)) {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('configure.archive_invalid_email'));
            } else {
                Configuration::updateGlobalValue('NERIA_ARCHIVE_EMAIL', $archiveEmail);
                $this->context->smarty->assign('neria_success', AdminTranslator::t('configure.archive_saved'));
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this))->info(
                        $archiveEmail !== ''
                            ? WatchdogManager::i18nMsg('watchdog.silent_witness_toggled', ['email' => $archiveEmail])
                            : WatchdogManager::i18nMsg('watchdog.silent_witness_disabled'),
                        '', 'ConfigManager'
                    );
                }
            }
        }

        // ── Action : régénérer le token d'urgence ─────────────────
        if (Tools::getValue('neria_action') === 'regenerate_emergency_token') {
            Configuration::updateGlobalValue('NERIA_EMERGENCY_TOKEN', bin2hex(random_bytes(24)));
            $this->context->smarty->assign('neria_success', AdminTranslator::t('help.emergency_token_regenerated'));
        }

        if (Tools::getValue('neria_action') === 'regenerate_cron_token') {
            Configuration::updateGlobalValue('NERIA_CRON_TOKEN', bin2hex(random_bytes(24)));
            $this->context->smarty->assign('neria_success', AdminTranslator::t('help.cron_token_regenerated'));
        }

        if (Tools::getValue('neria_action') === 'cron_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_CRON_ENABLED');
            Configuration::updateGlobalValue('NERIA_CRON_ENABLED', $current ? 0 : 1);
            $this->context->smarty->assign('neria_success', AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled'));
        }

        // ── Action : diagnostic complet à la demande ──────────────
        if (Tools::getValue('neria_action') === 'run_full_diagnostic') {
            $hcm     = new HealthCheckManager($this);
            $results = $hcm->runFullDiagnostic();
            $this->context->smarty->assign('health_results', $results);
            $this->context->smarty->assign('health_last_run', date('d/m/Y H:i'));
            $this->context->smarty->assign('neria_success', AdminTranslator::t('help.diagnostic_done'));
        }

        // ── Action : scan du code du module (syntaxe, traductions, classes) ──
        if (Tools::getValue('neria_action') === 'run_code_diagnostic') {
            $hcm     = new HealthCheckManager($this);
            $results = $hcm->runCodeDiagnostic();
            $this->context->smarty->assign('code_diag_results', $results);
            $this->context->smarty->assign('code_diag_last_run', date('d/m/Y H:i'));
            $anyIssue = false;
            foreach ($results as $r) {
                if (($r['status'] ?? 'ok') !== 'ok') {
                    $anyIssue = true;
                    break;
                }
            }
            $this->context->smarty->assign(
                $anyIssue ? 'neria_error' : 'neria_success',
                $anyIssue ? 'Scan de code terminé — des anomalies ont été détectées, voir le détail ci-dessous.'
                          : 'Scan de code terminé — aucune anomalie détectée.'
            );
        }

        // ── Action : envoyer le journal Watchdog par email (PDF) ─────
        if (Tools::getValue('neria_action') === 'send_log_email') {
            try {
                $err = $this->sendWatchdogLogByEmail();
                if ($err === '') {
                    $dest = Configuration::getGlobalValue(WatchdogManager::CFG_ALERT_EMAIL)
                         ?: Configuration::get('PS_SHOP_EMAIL');
                    $this->context->smarty->assign('neria_success',
                        AdminTranslator::t('help.log_email_sent') . ' ' . $dest);
                } else {
                    $this->context->smarty->assign('neria_error', $err);
                }
            } catch (\Throwable $e) {
                $this->context->smarty->assign('neria_error', AdminTranslator::tVars('msg.log_email_crash', [
                    'class' => get_class($e), 'error' => $e->getMessage(),
                    'file'  => basename($e->getFile()), 'line' => $e->getLine(),
                ]));
            }
        }

        // ── Action : vider le journal watchdog ────────────────────
        if (Tools::getValue('neria_action') === 'clear_logs') {
            $watchdog = new WatchdogManager($this);
            $watchdog->clearLogs();
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.logs_cleared'));
        }

        // ── Action : détection automatique de la langue ───────────
        if (Tools::getValue('neria_action') === 'toggle_autolang') {
            $current = (bool) Configuration::get(self::CONFIG_PREFIX . 'AUTO_LANG');
            $enabled = !$current;
            Configuration::updateValue(self::CONFIG_PREFIX . 'AUTO_LANG', (int) $enabled);
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.autolang_toggled', [
                'state' => AdminTranslator::t($enabled ? 'msg.state_enabled' : 'msg.state_disabled'),
            ]));
        }

        // ── Action : journalisation des emails internes ───────────
        if (Tools::getValue('neria_action') === 'save_log_internal') {
            Configuration::updateValue(
                self::CONFIG_PREFIX . 'LOG_INTERNAL',
                (int) Tools::getValue('neria_log_internal', 0)
            );
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        // ── Action : configuration du rapport mensuel ─────────────
        if (Tools::getValue('neria_action') === 'toggle_report') {
            $current = (bool) Configuration::get(MonthlyReportManager::CONFIG_ENABLED);
            $enabled = !$current;
            Configuration::updateValue(MonthlyReportManager::CONFIG_ENABLED, (int) $enabled);
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.report_toggled', [
                'state' => AdminTranslator::t($enabled ? 'msg.state_enabled_m' : 'msg.state_disabled_m'),
            ]));
        }

        if (Tools::getValue('neria_action') === 'save_report_config') {
            $recipients = strip_tags((string) Tools::getValue('neria_report_recipients', ''));
            Configuration::updateValue(MonthlyReportManager::CONFIG_RECIPIENTS, $recipients);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        // ── Action : envoi manuel du rapport ──────────────────────
        if (Tools::getValue('neria_action') === 'send_report_now') {
            if (class_exists('MonthlyReportManager')) {
                $rm    = new MonthlyReportManager($this);
                $year  = (int) date('Y', strtotime('last month'));
                $month = (int) date('n', strtotime('last month'));
                $rm->sendReport($year, $month);
            }
        }

        // ── Action : blacklist templates ──────────────────────────
        if (Tools::getValue('neria_action') === 'add_blacklist') {
            $tpl  = trim((string) Tools::getValue('neria_bl_template'));
            $lang = trim((string) Tools::getValue('neria_bl_lang'));
            if ($tpl !== '' && (new BlacklistManager())->add($tpl, $lang)) {
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.blacklist_added'));
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.blacklist_invalid'));
            }
        }

        if (Tools::getValue('neria_action') === 'remove_blacklist') {
            $id = (int) Tools::getValue('neria_bl_id');
            if ($id > 0 && (new BlacklistManager())->remove($id)) {
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.blacklist_removed'));
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.blacklist_invalid'));
            }
        }

        if (Tools::getValue('neria_action') === 'reset_blacklist') {
            (new BlacklistManager())->reset();
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.blacklist_reset'));
        }

        // ── Action : mode silence (anti-doublon) ──────────────────
        if (Tools::getValue('neria_action') === 'toggle_cooldown') {
            $cfg     = new ConfigManager($this);
            $enabled = !$cfg->isCooldownEnabled();
            $cfg->setCooldownEnabled($enabled);
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.cooldown_toggled', [
                'state' => AdminTranslator::t($enabled ? 'msg.state_enabled_m' : 'msg.state_disabled_m'),
            ]));
        }

        if (Tools::getValue('neria_action') === 'save_cooldown') {
            $minutes = (int) Tools::getValue('neria_cooldown_minutes', 10);
            $minutes = max(1, min(60, $minutes));
            Configuration::updateValue(self::CONFIG_PREFIX . 'COOLDOWN_MINUTES', $minutes);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        if (Tools::getValue('neria_action') === 'save_smtp_quota') {
            $quota = max(0, (int) Tools::getValue('neria_smtp_quota', 0));
            Configuration::updateValue('NERIA_SMTP_DAILY_QUOTA', $quota);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.smtp_quota_saved'));
        }

        // ── Google Postmaster Tools : sauvegarde credentials ─────
        if (Tools::getValue('neria_action') === 'save_postmaster_config' && class_exists('PostmasterManager')) {
            $clientId     = trim((string) Tools::getValue('postmaster_client_id', ''));
            $clientSecret = trim((string) Tools::getValue('postmaster_client_secret', ''));
            Configuration::updateValue(PostmasterManager::CONFIG_CLIENT_ID,     $clientId);
            Configuration::updateValue(PostmasterManager::CONFIG_CLIENT_SECRET, CryptoManager::encrypt($clientSecret));
            // Efface les anciens tokens si les credentials changent
            Configuration::deleteByName(PostmasterManager::CONFIG_ACCESS_TOKEN);
            Configuration::deleteByName(PostmasterManager::CONFIG_REFRESH_TOKEN);
            Configuration::deleteByName(PostmasterManager::CONFIG_TOKEN_EXPIRY);
            Configuration::deleteByName(PostmasterManager::CONFIG_CACHE);
            Configuration::deleteByName(PostmasterManager::CONFIG_CACHE_TIME);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.google_credentials_saved'));
        }

        // ── Google Postmaster Tools : connexion (redirect OAuth) ──
        if (Tools::getValue('neria_action') === 'connect_postmaster' && class_exists('PostmasterManager')) {
            $manager   = new PostmasterManager($this);
            $returnUrl = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats';
            $authUrl   = $manager->getAuthUrl($returnUrl);
            Tools::redirectAdmin($authUrl);
        }

        // ── Google Postmaster Tools : déconnexion ─────────────────
        if (Tools::getValue('neria_action') === 'disconnect_postmaster' && class_exists('PostmasterManager')) {
            (new PostmasterManager($this))->disconnect();
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.google_account_disconnected'));
        }

        // ── Google Postmaster Tools : rafraîchissement forcé ──────
        if (Tools::getValue('neria_action') === 'refresh_postmaster' && class_exists('PostmasterManager')) {
            $manager = new PostmasterManager($this);
            Configuration::deleteByName(PostmasterManager::CONFIG_CACHE);
            Configuration::deleteByName(PostmasterManager::CONFIG_CACHE_TIME);
            $stats = $manager->getStats();
            if ($stats !== null) {
                $this->context->smarty->assign([
                    'postmaster_stats'     => $stats,
                    'postmaster_cache_age' => 0,
                    'neria_success'        => AdminTranslator::t('msg.postmaster_refreshed'),
                ]);
                // Watchdog : alerte si réputation ou taux de spam dégradés
                if (class_exists('WatchdogManager')) {
                    $wd = new WatchdogManager($this);
                    foreach ($stats as $ps) {
                        $domain   = $ps['domain']            ?? '?';
                        $rep      = $ps['domain_reputation'] ?? null;
                        $spamRate = $ps['spam_rate']         ?? null;

                        if ($rep === 'BAD') {
                            $wd->error(WatchdogManager::i18nMsg('watchdog.postmaster_reputation_bad', ['domain' => $domain]), '', 'PostmasterTools');
                        } elseif ($rep === 'LOW') {
                            $wd->error(WatchdogManager::i18nMsg('watchdog.postmaster_reputation_low', ['domain' => $domain]), '', 'PostmasterTools');
                        } elseif ($rep === 'MEDIUM') {
                            $wd->warning(WatchdogManager::i18nMsg('watchdog.postmaster_reputation_medium', ['domain' => $domain]), '', 'PostmasterTools');
                        }

                        if ($spamRate !== null && $spamRate > 0.3) {
                            $wd->error(WatchdogManager::i18nMsg('watchdog.postmaster_spam_critical', ['domain' => $domain, 'rate' => $spamRate]), '', 'PostmasterTools');
                        } elseif ($spamRate !== null && $spamRate > 0.1) {
                            $wd->warning(WatchdogManager::i18nMsg('watchdog.postmaster_spam_attention', ['domain' => $domain, 'rate' => $spamRate]), '', 'PostmasterTools');
                        } elseif ($rep === 'HIGH' && ($spamRate === null || $spamRate < 0.1)) {
                            $wd->info(WatchdogManager::i18nMsg('watchdog.postmaster_reputation_high_ok', ['domain' => $domain]), '', 'PostmasterTools');
                        }
                    }
                }
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.postmaster_fetch_failed'));
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this))->warning(WatchdogManager::i18nMsg('watchdog.postmaster_fetch_failed'), '', 'PostmasterTools');
                }
            }
        }

        // ── PageSpeed Insights : sauvegarde clé API + URL ────────
        if (Tools::getValue('neria_action') === 'save_pagespeed_key') {
            $key       = trim((string) Tools::getValue('pagespeed_api_key', ''));
            $targetUrl = trim((string) Tools::getValue('pagespeed_target_url', ''));
            $urlError  = '';

            if ($targetUrl !== '') {
                $parsed      = parse_url($targetUrl);
                $enteredHost = strtolower(preg_replace('/^www\./', '', $parsed['host'] ?? ''));
                $shopHost    = strtolower(preg_replace('/^www\./', '', Tools::getShopDomain()));
                if ($enteredHost === '' || $enteredHost !== $shopHost) {
                    $urlError = AdminTranslator::tVars('msg.url_wrong_domain', ['domain' => Tools::getShopDomain()]);
                }
            }

            if ($urlError) {
                $this->context->smarty->assign('neria_error', $urlError);
            } else {
                Configuration::updateValue(PageSpeedManager::CONFIG_API_KEY,    CryptoManager::encrypt($key));
                Configuration::updateValue(PageSpeedManager::CONFIG_TARGET_URL, $targetUrl);
                Configuration::deleteByName(PageSpeedManager::CONFIG_CACHE);
                Configuration::deleteByName(PageSpeedManager::CONFIG_CACHE_TIME);
                Configuration::deleteByName('NERIA_PAGESPEED_LAST_ERROR');
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.pagespeed_config_saved'));
            }
        }

        // ── PageSpeed Insights : rafraîchissement forcé ───────────
        if (Tools::getValue('neria_action') === 'refresh_pagespeed' && class_exists('PageSpeedManager')) {
            $mgr    = new PageSpeedManager($this);
            $report = $mgr->runCheck();
            if ($report) {
                $this->context->smarty->assign([
                    'pagespeed_report'    => $report,
                    'pagespeed_cache_age' => 0,
                    'neria_success'       => AdminTranslator::t('msg.pagespeed_refreshed'),
                ]);
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.pagespeed_fetch_failed'));
            }
        }

        // ── Search Console : sauvegarde credentials ───────────────
        if (Tools::getValue('neria_action') === 'save_searchconsole_config') {
            $clientId     = trim((string) Tools::getValue('sc_client_id', ''));
            $clientSecret = trim((string) Tools::getValue('sc_client_secret', ''));
            Configuration::updateValue(SearchConsoleManager::CONFIG_CLIENT_ID,     $clientId);
            Configuration::updateValue(SearchConsoleManager::CONFIG_CLIENT_SECRET, CryptoManager::encrypt($clientSecret));
            Configuration::deleteByName(SearchConsoleManager::CONFIG_ACCESS_TOKEN);
            Configuration::deleteByName(SearchConsoleManager::CONFIG_REFRESH_TOKEN);
            Configuration::deleteByName(SearchConsoleManager::CONFIG_TOKEN_EXPIRY);
            Configuration::deleteByName(SearchConsoleManager::CONFIG_CACHE);
            Configuration::deleteByName(SearchConsoleManager::CONFIG_CACHE_TIME);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.searchconsole_credentials_saved'));
        }

        // ── Search Console : connexion OAuth ──────────────────────
        if (Tools::getValue('neria_action') === 'connect_searchconsole' && class_exists('SearchConsoleManager')) {
            $manager   = new SearchConsoleManager($this);
            $returnUrl = $this->context->link->getAdminLink('AdminModules', true, [], [
                'configure' => $this->name,
            ]) . '&neria_tab=stats';
            $authUrl = $manager->getAuthUrl($returnUrl);
            Tools::redirectAdmin($authUrl);
        }

        // ── Search Console : déconnexion ──────────────────────────
        if (Tools::getValue('neria_action') === 'disconnect_searchconsole' && class_exists('SearchConsoleManager')) {
            (new SearchConsoleManager($this))->disconnect();
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.searchconsole_disconnected'));
        }

        // ── Search Console : rafraîchissement forcé ───────────────
        if (Tools::getValue('neria_action') === 'refresh_searchconsole' && class_exists('SearchConsoleManager')) {
            $mgr    = new SearchConsoleManager($this);
            Configuration::deleteByName(SearchConsoleManager::CONFIG_CACHE);
            Configuration::deleteByName(SearchConsoleManager::CONFIG_CACHE_TIME);
            $stats = $mgr->getStats();
            if ($stats !== null) {
                $this->context->smarty->assign([
                    'searchconsole_stats'     => $stats,
                    'searchconsole_cache_age' => 0,
                    'neria_success'           => AdminTranslator::t('msg.searchconsole_refreshed'),
                ]);
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.searchconsole_fetch_failed'));
            }
        }

        // ── SEO API payante : sauvegarde config ───────────────────
        if (Tools::getValue('neria_action') === 'save_seo_config') {
            $provider  = in_array(Tools::getValue('seo_provider'), ['semrush', 'moz', ''], true)
                ? (string) Tools::getValue('seo_provider')
                : '';
            $semrushKey = trim((string) Tools::getValue('seo_semrush_key', ''));
            $mozAccess  = trim((string) Tools::getValue('seo_moz_access', ''));
            $mozSecret  = trim((string) Tools::getValue('seo_moz_secret', ''));
            Configuration::updateValue(SeoApiManager::CONFIG_PROVIDER,    $provider);
            if ($semrushKey !== '') {
                Configuration::updateValue(SeoApiManager::CONFIG_SEMRUSH_KEY, CryptoManager::encrypt($semrushKey));
            }
            if ($mozAccess !== '') {
                Configuration::updateValue(SeoApiManager::CONFIG_MOZ_ACCESS, CryptoManager::encrypt($mozAccess));
            }
            if ($mozSecret !== '') {
                Configuration::updateValue(SeoApiManager::CONFIG_MOZ_SECRET, CryptoManager::encrypt($mozSecret));
            }
            Configuration::deleteByName(SeoApiManager::CONFIG_CACHE);
            Configuration::deleteByName(SeoApiManager::CONFIG_CACHE_TIME);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.seo_config_saved'));
        }

        // ── SEO API payante : rafraîchissement forcé ──────────────
        if (Tools::getValue('neria_action') === 'refresh_seo_api' && class_exists('SeoApiManager')) {
            $mgr    = new SeoApiManager($this);
            $report = $mgr->runCheck();
            if ($report) {
                $this->context->smarty->assign([
                    'seo_report'    => $report,
                    'seo_cache_age' => 0,
                    'neria_success' => AdminTranslator::t('msg.seo_refreshed'),
                ]);
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.seo_fetch_failed'));
            }
        }

        // ── Action : empreinte carbone ────────────────────────────
        if (Tools::getValue('neria_action') === 'save_carbon') {
            Configuration::updateValue(
                self::CONFIG_PREFIX . 'CARBON_ENABLED',
                (int) Tools::getValue('neria_carbon_enabled', 0)
            );
            Configuration::updateValue(
                self::CONFIG_PREFIX . 'CARBON_LINK',
                (string) Tools::getValue('neria_carbon_link', '')
            );
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        // ── Réseaux sociaux : sauvegarde ──────────────────────────
        if (Tools::getValue('neria_action') === 'save_social' && class_exists('ConfigManager')) {
            $socialData = [
                'social_instagram' => (string) Tools::getValue('social_instagram', ''),
                'social_pinterest' => (string) Tools::getValue('social_pinterest', ''),
                'social_facebook'  => (string) Tools::getValue('social_facebook', ''),
                'social_twitter'   => (string) Tools::getValue('social_twitter', ''),
                'social_youtube'   => (string) Tools::getValue('social_youtube', ''),
                'social_tiktok'    => (string) Tools::getValue('social_tiktok', ''),
            ];
            (new ConfigManager($this))->saveSocialConfig($socialData);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        // ── Onglet Design : sauvegarde ─────────────────────────────
        if (Tools::getValue('neria_action') === 'save_design' && class_exists('ConfigManager')) {
            $designMgr  = new ConfigManager($this);
            $designData = [
                'color_background'  => (string) Tools::getValue('color_background', ''),
                'color_container'   => (string) Tools::getValue('color_container', ''),
                'color_accent'      => (string) Tools::getValue('color_accent', ''),
                'color_text'        => (string) Tools::getValue('color_text', ''),
                'btn_color'         => (string) Tools::getValue('btn_color', ''),
                'color_header_bg'   => (string) Tools::getValue('color_header_bg', ''),
                'color_footer_bg'   => (string) Tools::getValue('color_footer_bg', ''),
                'color_footer_text' => (string) Tools::getValue('color_footer_text', ''),
                'dark_mode'         => (int) Tools::getValue('dark_mode', 0),
                'container_width'   => (int) Tools::getValue('container_width', 0),
                'logo_width'        => (int) Tools::getValue('logo_width', 0),
                'font_heading'      => (string) Tools::getValue('font_heading', ''),
                'btn_radius'        => (int) Tools::getValue('btn_radius', 2),
                'section_padding'   => (int) Tools::getValue('section_padding', 0),
                'block_spacing'     => (int) Tools::getValue('block_spacing', 0),
                'separator_style'   => (string) Tools::getValue('separator_style', ''),
                'card_shadow'       => (string) Tools::getValue('card_shadow', ''),
            ];
            $designMgr->saveDesignConfig($designData);

            $logoUploadFailed = false;
            if (!empty($_FILES['logo']['tmp_name'])) {
                if (!$designMgr->uploadLogo($_FILES['logo'])) {
                    $logoUploadFailed = true;
                    $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.logo_upload_failed_banner'));
                }
            }

            if (!$logoUploadFailed) {
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
            }
        }

        // ── Onglet Design : réinitialisation (couleurs/police/bouton/
        //    espacement/séparateur/ombre uniquement — ni logo, ni les
        //    réglages des autres onglets) ─────────────────────────
        if (Tools::getValue('neria_action') === 'reset_design' && class_exists('ConfigManager')) {
            (new ConfigManager($this))->resetDesignConfig();
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        // ── Onglet Typographie : sauvegarde ────────────────────────
        if (Tools::getValue('neria_action') === 'save_typography' && class_exists('ConfigManager')) {
            $typoData = [
                'font_latin'               => (string) Tools::getValue('font_latin', ''),
                'font_arabic'              => (string) Tools::getValue('font_arabic', ''),
                'font_japanese'            => (string) Tools::getValue('font_japanese', ''),
                'font_korean'              => (string) Tools::getValue('font_korean', ''),
                'font_chinese_simplified'  => (string) Tools::getValue('font_chinese_simplified', ''),
                'font_chinese_traditional' => (string) Tools::getValue('font_chinese_traditional', ''),
                'font_size'                => (int) Tools::getValue('font_size', 0),
                'line_height'              => (float) Tools::getValue('line_height', 0),
                'heading_weight'           => (int) Tools::getValue('heading_weight', 0),
            ];
            (new ConfigManager($this))->saveTypographyConfig($typoData);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        // ── Variables personnalisées : sauvegarde ──────────────────
        if (Tools::getValue('neria_action') === 'save_custom_vars' && class_exists('ConfigManager')) {
            $varsData = [
                'maison_name'            => (string) Tools::getValue('maison_name', ''),
                'slogan'                 => (string) Tools::getValue('slogan', ''),
                'founder_name'           => (string) Tools::getValue('founder_name', ''),
                'founder_title'          => (string) Tools::getValue('founder_title', ''),
                'signature_closing'      => (string) Tools::getValue('signature_closing', ''),
                'return_address'         => (string) Tools::getValue('return_address', ''),
                'return_deadline_days'   => (string) Tools::getValue('return_deadline_days', ''),
                'return_processing_days' => (string) Tools::getValue('return_processing_days', ''),
            ];
            (new ConfigManager($this))->saveCustomVariables($varsData);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        // ── Signature manuscrite : génération ──────────────────────
        if (Tools::getValue('neria_action') === 'generate_signature'
            && class_exists('SignatureGenerator') && class_exists('ConfigManager')
        ) {
            $sigStyle = (string) Tools::getValue('sig_style', 'great_vibes');
            $sigColor = (string) Tools::getValue('sig_color', '#b38b59');

            // Le nom/titre viennent des Variables personnalisées déjà enregistrées
            // (formulaire séparé sur la même page) — pas de champs dédiés dans
            // le formulaire de signature.
            $customVars = array_column(
                (new ConfigManager($this))->getCustomVariables(),
                'variable_value',
                'variable_key'
            );
            $sigName  = trim((string) ($customVars['founder_name']  ?? ''));
            $sigTitle = trim((string) ($customVars['founder_title'] ?? ''));

            if ($sigName === '') {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.signature_missing_founder_name'));
            } else {
                $idShop = (int) $this->context->shop->id;
                $path   = (new SignatureGenerator($this))->generate($sigName, $sigTitle, $sigStyle, $sigColor, $idShop);

                if ($path) {
                    $db = Db::getInstance();
                    // Une seule signature active par boutique — désactive les
                    // précédentes avant d'insérer la nouvelle (cohérent avec
                    // EmailRenderer::resolveSignature() qui lit WHERE is_active=1).
                    $db->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'neria_signature` SET `is_active` = 0 WHERE `id_shop` = ' . $idShop
                    );
                    $db->insert('neria_signature', [
                        'id_shop'      => $idShop,
                        'signer_name'  => pSQL($sigName),
                        'signer_title' => pSQL($sigTitle),
                        'font_style'   => pSQL($sigStyle),
                        'color'        => pSQL($sigColor),
                        'image_path'   => pSQL($path),
                        'is_active'    => 1,
                        'date_add'     => date('Y-m-d H:i:s'),
                        'date_upd'     => date('Y-m-d H:i:s'),
                    ]);
                    $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
                } else {
                    $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.signature_generation_failed'));
                }
            }
        }

        // ── Actions : calendrier des occasions ───────────────────
        if (Tools::getValue('neria_action') === 'add_calendar_event') {
            // Si l'occasion est personnalisée, on lit le champ texte libre
            $rawKey    = (string) Tools::getValue('cal_event_key', '');
            $eventKey  = $rawKey === '__custom__'
                ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) Tools::getValue('cal_custom_key', '')))
                : preg_replace('/[^a-z0-9_]/', '', strtolower($rawKey));

            $lang       = preg_replace('/[^a-z]/', '', strtolower((string) Tools::getValue('cal_lang', '')));
            $country    = strtoupper(preg_replace('/[^a-zA-Z]/', '', (string) Tools::getValue('cal_country', '')));
            $template   = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) Tools::getValue('cal_template', '')));
            $days       = max(1, min(60, (int) Tools::getValue('cal_days', 7)));
            $active     = (int) Tools::getValue('cal_active', 0) > 0 ? 1 : 0;
            $idShop     = (int) $this->context->shop->id;

            // Date personnalisée : validation format MM-DD
            $rawDate    = preg_replace('/[^0-9\-]/', '', (string) Tools::getValue('cal_custom_date', ''));
            $customDate = (preg_match('/^\d{2}-\d{2}$/', $rawDate)) ? $rawDate : '';

            if ($eventKey && $lang && $template) {
                Db::getInstance()->execute(
                    'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'neria_calendar_event`
                     (`id_shop`, `event_key`, `lang`, `country_code`, `custom_date`, `template`, `send_days_before`, `is_active`, `date_add`, `date_upd`)
                     VALUES (' . $idShop . ', \'' . pSQL($eventKey) . '\', \'' . pSQL($lang) . '\',
                             \'' . pSQL($country) . '\', \'' . pSQL($customDate) . '\',
                             \'' . pSQL($template) . '\', ' . $days . ', ' . $active . ', NOW(), NOW())'
                );
                $this->context->smarty->assign('neria_success', AdminTranslator::t('calendar.added'));
            }
        }

        if (Tools::getValue('neria_action') === 'save_calendar_event') {
            $idEvent = (int) Tools::getValue('cal_id', 0);
            $days    = max(1, min(60, (int) Tools::getValue('cal_days', 7)));
            if ($idEvent > 0) {
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'neria_calendar_event`
                     SET `send_days_before` = ' . $days . ', `date_upd` = NOW()
                     WHERE `id_event` = ' . $idEvent . ' AND `id_shop` = ' . (int) $this->context->shop->id
                );
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
            }
        }

        if (Tools::getValue('neria_action') === 'toggle_calendar_event') {
            $idEvent = (int) Tools::getValue('cal_id', 0);
            if ($idEvent > 0) {
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'neria_calendar_event`
                     SET `is_active` = 1 - `is_active`, `date_upd` = NOW()
                     WHERE `id_event` = ' . $idEvent . ' AND `id_shop` = ' . (int) $this->context->shop->id
                );
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
            }
        }

        if (Tools::getValue('neria_action') === 'delete_calendar_event') {
            $idEvent = (int) Tools::getValue('cal_id', 0);
            if ($idEvent > 0) {
                Db::getInstance()->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'neria_calendar_event`
                     WHERE `id_event` = ' . $idEvent . ' AND `id_shop` = ' . (int) $this->context->shop->id
                );
                $this->context->smarty->assign('neria_success', AdminTranslator::t('calendar.deleted'));
            }
        }

        // ── Action : multi-expéditeur par langue ──────────────────
        if (Tools::getValue('neria_action') === 'save_senders') {
            $senders = [];
            foreach (TranslationEngine::SUPPORTED_LANGS as $iso) {
                $name  = trim((string) Tools::getValue('neria_sender_name_' . $iso, ''));
                $email = trim((string) Tools::getValue('neria_sender_email_' . $iso, ''));
                if ($name !== '' || ($email !== '' && Validate::isEmail($email))) {
                    $senders[$iso] = [
                        'name'  => $name,
                        'email' => $email !== '' && Validate::isEmail($email) ? $email : '',
                    ];
                }
            }
            Configuration::updateValue(
                self::CONFIG_PREFIX . 'SENDERS_JSON',
                json_encode($senders, JSON_UNESCAPED_UNICODE)
            );
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        // ── Action : durée de validité des bons ───────────────────
        if (Tools::getValue('neria_action') === 'save_voucher_validity') {
            $days = (int) Tools::getValue('neria_voucher_validity', 30);
            $days = max(1, min(365, $days));
            Configuration::updateValue(self::CONFIG_PREFIX . 'VOUCHER_VALIDITY', $days);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        if (Tools::getValue('neria_action') === 'save_target_countries') {
            $raw      = Tools::getValue('neria_target_countries', []);
            $selected = is_array($raw) ? array_filter(array_map('strval', $raw)) : [];
            (new ConfigManager($this))->saveTargetCountries($selected);
            $total = count(ConfigManager::getAllCountries());
            $count = count($selected);
            (new WatchdogManager($this))->info(
                WatchdogManager::i18nMsg('watchdog.target_countries_updated', ['n' => $count, 'total' => $total])
            );
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.target_countries_saved', ['n' => $count]));
        }

        if (Tools::getValue('neria_action') === 'save_time_greetings') {
            $langs    = TranslationEngine::SUPPORTED_LANGS;
            $slots    = ['morning', 'afternoon', 'evening', 'night'];
            $greetings = [];
            foreach ($langs as $lang) {
                foreach ($slots as $slot) {
                    $val = trim((string) Tools::getValue('neria_tg_' . $lang . '_' . $slot));
                    if ($val !== '') {
                        $greetings[$lang][$slot] = $val;
                    }
                }
            }
            (new ConfigManager($this))->saveTimeGreetings($greetings);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.time_greetings_saved'));
        }

        if (Tools::getValue('neria_action') === 'reset_time_greetings_all') {
            (new ConfigManager($this))->resetTimeGreetings();
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.time_greetings_reset_all'));
        }

        if (Tools::getValue('neria_action') === 'reset_time_greetings_lang') {
            $lang = trim((string) Tools::getValue('neria_reset_lang'));
            if ($lang && in_array($lang, TranslationEngine::SUPPORTED_LANGS, true)) {
                (new ConfigManager($this))->resetTimeGreetings($lang);
                $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.time_greetings_reset_lang', ['lang' => strtoupper($lang)]));
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.invalid_lang'));
            }
        }

        if (Tools::getValue('neria_action') === 'toggle_time_greeting') {
            $cfg     = new ConfigManager($this);
            $enabled = !$cfg->isTimeGreetingEnabled();
            $cfg->setTimeGreetingEnabled($enabled);
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.smart_salutation_toggled', [
                'state' => AdminTranslator::t($enabled ? 'msg.state_enabled' : 'msg.state_disabled'),
            ]));
        }

        if (Tools::getValue('neria_action') === 'toggle_multi_sender') {
            $cfg     = new ConfigManager($this);
            $enabled = !$cfg->isMultiSenderEnabled();
            $cfg->setMultiSenderEnabled($enabled);
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.multisender_toggled', [
                'state' => AdminTranslator::t($enabled ? 'msg.state_enabled_m' : 'msg.state_disabled_m'),
            ]));
        }

        if (Tools::getValue('neria_action') === 'toggle_signature') {
            $cfg     = new ConfigManager($this);
            $enabled = !$cfg->isSignatureEnabled();
            $cfg->setSignatureEnabled($enabled);
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.signature_toggled', [
                'state' => AdminTranslator::t($enabled ? 'msg.state_enabled' : 'msg.state_disabled'),
            ]));
        }

        if (Tools::getValue('neria_action') === 'toggle_firstname_fallback') {
            $cfg     = new ConfigManager($this);
            $enabled = !$cfg->isFirstnameFallbackEnabled();
            $cfg->setFirstnameFallbackEnabled($enabled);
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.smart_fallbacks_toggled', [
                'state' => AdminTranslator::t($enabled ? 'msg.state_activated' : 'msg.state_deactivated'),
            ]));
        }

        if (Tools::getValue('neria_action') === 'save_firstname_fallbacks') {
            $langs     = TranslationEngine::SUPPORTED_LANGS;
            $fallbacks = [];
            foreach ($langs as $lang) {
                $val = trim((string) Tools::getValue('neria_fallback_' . $lang));
                if ($val !== '') {
                    $fallbacks[$lang] = $val;
                }
            }
            (new ConfigManager($this))->saveFirstnameFallbacks($fallbacks);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.firstname_fallbacks_saved'));
        }

        // ── AJAX : autocomplétion client (envoi manuel) ──────────────────
        if (Tools::getValue('neria_action') === 'customer_autocomplete') {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            $q = (string) Tools::getValue('q', '');
            try {
                $results = class_exists('ManualSendManager')
                    ? (new ManualSendManager($this))->searchCustomers($q)
                    : [];
                echo json_encode($results);
            } catch (\Throwable $e) {
                echo json_encode([]);
            }
            exit;
        }

        // ── AJAX : détection de doublon (envoi manuel) ────────────────────
        if (Tools::getValue('neria_action') === 'check_send_duplicate') {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            $email    = (string) Tools::getValue('neria_email', '');
            $template = (string) Tools::getValue('neria_template', '');
            try {
                $status = class_exists('ManualSendManager')
                    ? (new ManualSendManager($this))->checkDuplicate($email, $template)
                    : ['blocked' => false, 'message' => ''];
                echo json_encode($status);
            } catch (\Throwable $e) {
                echo json_encode(['blocked' => false, 'message' => '']);
            }
            exit;
        }

        // ── AJAX : prévisualisation email (envoi manuel, avec langue client) ─
        if (Tools::getValue('neria_action') === 'preview_manual') {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
            $tpl  = preg_replace('/[^a-z0-9_-]/i', '', (string) Tools::getValue('neria_template', 'order_conf'));
            $mail = trim((string) Tools::getValue('neria_email', ''));
            $lang = 'fr';
            if ($mail !== '' && class_exists('ManualSendManager')) {
                $cust = (new ManualSendManager($this))->findCustomerPublic($mail);
                if ($cust && !empty($cust['id_lang'])) {
                    $lr = Language::getLanguage((int) $cust['id_lang']);
                    if ($lr) { $lang = $lr['iso_code']; }
                }
            }
            $html = class_exists('EmailRenderer')
                ? (new EmailRenderer($this))->renderPreviewHtml($tpl, $lang)
                : '<p style="font-family:sans-serif;padding:20px">EmailRenderer non disponible.</p>';
            echo $html;
            exit;
        }

        // ── AJAX : traitement manuel de la file d'attente ────────────────
        if (Tools::getValue('neria_action') === 'process_queue_now') {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            try {
                if (class_exists('QueueManager')) {
                    $sent = (new QueueManager($this))->processQueue();
                    echo json_encode(['ok' => true, 'sent' => $sent]);
                } else {
                    echo json_encode(['ok' => false, 'sent' => 0, 'error' => 'QueueManager introuvable']);
                }
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'sent' => 0, 'error' => $e->getMessage()]);
            }
            exit;
        }

        // ── Action : envoi manuel d'un template à un client ───────
        // ── Garde-fou anniversaire : vérification AJAX ───────────────
        if (Tools::getValue('neria_action') === 'check_anniversary_guard') {
            $email    = trim((string) Tools::getValue('neria_email'));
            $template = trim((string) Tools::getValue('neria_template'));
            try {
                $status = class_exists('ManualSendManager')
                    ? (new ManualSendManager($this))->getAnniversaryGuardStatus($email, $template)
                    : ['blocked' => false, 'sent' => false, 'message' => ''];
            } catch (\Throwable $e) {
                $status = ['blocked' => false, 'sent' => false, 'message' => ''];
            }
            header('Content-Type: application/json');
            die(json_encode($status));
        }

        if (Tools::getValue('neria_action') === 'send_manual') {
            $manual      = new ManualSendManager($this);
            $contentVars = Tools::getValue('neria_var');
            if (!is_array($contentVars)) {
                $contentVars = [];
            }
            $sendAt = trim((string) Tools::getValue('neria_send_at', ''));
            if ($sendAt !== '' && class_exists('QueueManager')) {
                $res = $manual->scheduleManual(
                    (string) Tools::getValue('neria_template'),
                    (string) Tools::getValue('neria_email'),
                    (string) Tools::getValue('neria_order_ref'),
                    (string) Tools::getValue('neria_subject'),
                    $contentVars,
                    $sendAt
                );
            } else {
                $res = $manual->send(
                    (string) Tools::getValue('neria_template'),
                    (string) Tools::getValue('neria_email'),
                    (string) Tools::getValue('neria_order_ref'),
                    (string) Tools::getValue('neria_subject'),
                    $contentVars
                );
            }
            $this->context->smarty->assign(
                $res['ok'] ? 'neria_success' : 'neria_error',
                $res['message']
            );
        }

        // ── Action : score de délivrabilité (onglet Statistiques) ──
        // ── Réputation de domaine : rafraîchissement manuel ──────────
        if (Tools::getValue('neria_action') === 'refresh_domain_reputation' && class_exists('DomainReputationManager')) {
            try {
                $domRep = (new DomainReputationManager($this))->runFullCheck();
                $this->context->smarty->assign('domain_reputation', $domRep);
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.domain_reputation_refreshed'));
                if (class_exists('WatchdogManager')) {
                    $wd      = new WatchdogManager($this);
                    $hits    = count($domRep['blacklists']['hits'] ?? []);
                    $score   = $domRep['score'];
                    $msg     = WatchdogManager::i18nMsg('watchdog.domain_reputation_manual', [
                        'domain' => $domRep['domain'] ?? '',
                        'score'  => $score,
                        'grade'  => $domRep['grade'],
                        'hits'   => $hits,
                    ]);
                    if ($score < 50 || $hits > 0) {
                        $wd->error($msg, '', 'DomainReputation');
                    } elseif ($score < 75) {
                        $wd->warning($msg, '', 'DomainReputation');
                    } else {
                        $wd->info($msg, '', 'DomainReputation');
                    }
                }
            } catch (\Throwable $e) {
                $this->context->smarty->assign('neria_error', AdminTranslator::tVars('msg.dns_check_error', ['error' => $e->getMessage()]));
            }
        }

        if (Tools::getValue('neria_action') === 'deliverability_score') {
            $scoreTemplate = (string) Tools::getValue('score_template', 'order_conf');
            $scoreLang     = (string) Tools::getValue('score_lang', 'fr');

            if (class_exists('EmailRenderer') && class_exists('DeliverabilityScorer')) {
                try {
                    $renderer = new EmailRenderer($this);
                    $html     = $renderer->renderPreviewHtml($scoreTemplate, $scoreLang);
                    $engine   = new TranslationEngine($this);
                    $subject  = trim(strip_tags($engine->get($scoreTemplate, 'greeting_main', $scoreLang)));

                    $scorer = new DeliverabilityScorer();
                    $result = $scorer->score($html, $subject, $scoreLang);

                    $this->context->smarty->assign('neria_deliverability', $result);

                    // Watchdog : trace de l'analyse (warning si score faible)
                    if (class_exists('WatchdogManager')) {
                        $wd  = new WatchdogManager($this);
                        $msg = WatchdogManager::i18nMsg('watchdog.deliverability_analyzed', [
                            'score' => $result['score'],
                            'grade' => $result['grade'],
                        ]);
                        if ($result['score'] < 60) {
                            $wd->warning($msg, $scoreTemplate, 'DeliverabilityScorer');
                        } else {
                            $wd->info($msg, $scoreTemplate, 'DeliverabilityScorer');
                        }
                    }
                } catch (\Throwable $e) {
                    if (class_exists('WatchdogManager')) {
                        (new WatchdogManager($this))->error(
                            WatchdogManager::i18nMsg('watchdog.deliverability_failed', ['error' => $e->getMessage()]),
                            $scoreTemplate,
                            'DeliverabilityScorer'
                        );
                    }
                    $this->context->smarty->assign(
                        'neria_deliverability_error',
                        AdminTranslator::t('msg.score_error')
                    );
                }
            }
        }

        // ── Onglet A/B Testing : création / désactivation ────────────
        if (Tools::getValue('neria_action') === 'create_abtest' && class_exists('ABTestManager')) {
            $tplKey      = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('abtest_template', ''));
            $variantAName = pSQL(trim((string) Tools::getValue('variant_a_name', 'Variante A')));
            $variantBName = pSQL(trim((string) Tools::getValue('variant_b_name', 'Variante B')));
            $splitPercent = (int) Tools::getValue('split_percent', 50);

            if ($tplKey !== '') {
                $ab = new ABTestManager($this);
                $ab->deleteTests($tplKey);
                $idA = $ab->createTest($tplKey, $variantAName, $variantBName, $splitPercent);
                if ($idA) {
                    $ab->activateTest($tplKey);
                    $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
                } else {
                    $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.error'));
                }
            }
        }

        if (Tools::getValue('neria_action') === 'deactivate_abtest' && class_exists('ABTestManager')) {
            $tplKey = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('abtest_template', ''));
            if ($tplKey !== '') {
                $ab     = new ABTestManager($this);
                $report = (new StatsManager($this))->getABTestReport($tplKey, 9999);
                $sig    = $report['significance'] ?? [];
                $winner = (string) ($sig['overall_winner'] ?? '');
                $conf   = (int) max($sig['open']['confidence'] ?? 0, $sig['click']['confidence'] ?? 0);
                $ab->archiveTest($tplKey, $report, $winner, $conf, false);
                $ab->deactivateTest($tplKey);
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.abtest_stopped_archived'));
            }
        }

        if (Tools::getValue('neria_action') === 'apply_abtest_winner' && class_exists('ABTestManager')) {
            $tplKey = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('abtest_template', ''));
            $winner = Tools::getValue('abtest_winner', '');
            $winner = in_array($winner, ['A', 'B'], true) ? $winner : '';
            if ($tplKey !== '' && $winner !== '') {
                $ab     = new ABTestManager($this);
                $report = (new StatsManager($this))->getABTestReport($tplKey, 9999);
                $sig    = $report['significance'] ?? [];
                $conf   = (int) max($sig['open']['confidence'] ?? 0, $sig['click']['confidence'] ?? 0);
                $ab->archiveTest($tplKey, $report, $winner, $conf, true);
                $ab->applyWinner($tplKey, $winner);
                $msg = AdminTranslator::t($winner === 'B' ? 'msg.abtest_applied_b' : 'msg.abtest_confirmed_a');
                $this->context->smarty->assign('neria_success', $msg);
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this))->info(
                        WatchdogManager::i18nMsg('watchdog.abtest_applied_manual', [
                            'template' => $tplKey, 'winner' => $winner, 'confidence' => $conf,
                        ]),
                        $tplKey, 'ABTestManager'
                    );
                }
            }
        }

        // ── Onglet Traductions : chargement / sauvegarde / reset ─────
        $tradAction = Tools::getValue('neria_action');

        // ── Recharger toutes les traductions par défaut depuis le JSON ──
        // N'écrase que les lignes is_custom=0 (importFromJson/importTemplate
        // suppriment puis réinsèrent uniquement les valeurs non personnalisées) —
        // utile pour récupérer des corrections de texte livrées avec une mise
        // à jour du module sans attendre un bump de version qui déclenche
        // l'upgrade automatique.
        if ($tradAction === 'reload_all_translations' && class_exists('TranslationInstaller')) {
            $installer = new TranslationInstaller($this);
            $ok = $installer->importFromJson(_PS_MODULE_DIR_ . 'neria/data/translations.json');
            if ($ok) {
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.translations_reloaded_from_source'));
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.translations_reload_failed'));
            }
        }

        // ── Export CSV traductions ────────────────────────────────────
        if ($tradAction === 'export_translations_csv') {
            $tplKey  = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('trad_template', ''));
            $allLangs = (int) Tools::getValue('all_langs', 0) === 1;
            $tableTrad = _DB_PREFIX_ . 'neria_translation';

            if ($allLangs) {
                $rows = Db::getInstance()->executeS(
                    "SELECT `template`, `lang`, `translation_key`, `translation_value`, `is_custom`
                     FROM `{$tableTrad}`
                     WHERE `template` = '" . pSQL($tplKey) . "'
                     ORDER BY `lang`, `translation_key`"
                );
                $filename = "neria_translations_{$tplKey}_all_langs_" . date('Ymd') . '.csv';
            } else {
                $tplLang = preg_replace('/[^a-z]/i', '', (string) Tools::getValue('trad_lang', 'fr'));
                $rows = Db::getInstance()->executeS(
                    "SELECT `template`, `lang`, `translation_key`, `translation_value`, `is_custom`
                     FROM `{$tableTrad}`
                     WHERE `template` = '" . pSQL($tplKey) . "'
                       AND `lang`     = '" . pSQL($tplLang) . "'
                     ORDER BY `translation_key`"
                );
                $filename = "neria_translations_{$tplKey}_{$tplLang}_" . date('Ymd') . '.csv';
            }

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 pour Excel
            fputcsv($out, ['template', 'lang', 'key', 'value', 'is_custom'], ';');
            foreach ((array) $rows as $row) {
                fputcsv($out, [
                    $row['template'],
                    $row['lang'],
                    $row['translation_key'],
                    $row['translation_value'],
                    $row['is_custom'] ? '1' : '0',
                ], ';');
            }
            fclose($out);
            exit;
        }

        // ── Import CSV traductions ────────────────────────────────────
        if ($tradAction === 'import_translations_csv') {
            $tplKey  = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('trad_template', ''));
            $tplLang = preg_replace('/[^a-z]/i', '', (string) Tools::getValue('trad_lang', 'fr'));

            if (!empty($_FILES['neria_csv']['tmp_name']) && is_uploaded_file($_FILES['neria_csv']['tmp_name'])) {
                $tableTrad = _DB_PREFIX_ . 'neria_translation';
                $handle    = fopen($_FILES['neria_csv']['tmp_name'], 'r');

                // Détecter et sauter le BOM
                $bom = fread($handle, 3);
                if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) {
                    rewind($handle);
                }

                $header  = fgetcsv($handle, 0, ';');
                $count   = 0;
                $now     = date('Y-m-d H:i:s');

                while (($line = fgetcsv($handle, 0, ';')) !== false) {
                    if (count($line) < 4) { continue; }
                    $template = preg_replace('/[^a-z0-9_\-]/i', '', $line[0]);
                    $lang     = preg_replace('/[^a-z]/i', '', $line[1]);
                    $key      = preg_replace('/[^a-z0-9_\.\-]/i', '', $line[2]);
                    $value    = $line[3];
                    if ($template === '' || $lang === '' || $key === '') { continue; }

                    Db::getInstance()->execute(
                        "INSERT INTO `{$tableTrad}` (`template`,`lang`,`translation_key`,`translation_value`,`is_custom`,`date_add`,`date_upd`)
                         VALUES ('" . pSQL($template) . "','" . pSQL($lang) . "','" . pSQL($key) . "','" . pSQL($value, true) . "',1,'{$now}','{$now}')
                         ON DUPLICATE KEY UPDATE `translation_value` = '" . pSQL($value, true) . "', `is_custom` = 1, `date_upd` = '{$now}'"
                    );
                    $count++;
                }
                fclose($handle);

                if (class_exists('TranslationEngine')) { (new TranslationEngine($this))->clearCache(); }
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this))->info(
                        WatchdogManager::i18nMsg('watchdog.csv_import_count', ['n' => $count]), '', 'Traductions'
                    );
                }
                $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.csv_import_success', ['n' => $count]));
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.csv_import_no_file'));
            }
        }

        // ── Export CSV Variante B ────────────────────────────────────
        if ($tradAction === 'export_variant_b_csv') {
            $tplKey    = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('trad_template', ''));
            $tplLang   = preg_replace('/[^a-z]/i', '', (string) Tools::getValue('trad_lang', 'fr'));
            $idAbtestB = (int) Tools::getValue('id_abtest_b', 0);
            $tableTradB = _DB_PREFIX_ . 'neria_abtest_translation';

            $rows = $this->abtestBelongsToShop($idAbtestB) ? Db::getInstance()->executeS(
                "SELECT `translation_key`, `translation_value`
                 FROM `{$tableTradB}`
                 WHERE `id_abtest` = {$idAbtestB}
                   AND `lang`      = '" . pSQL($tplLang) . "'
                 ORDER BY `translation_key` ASC"
            ) : [];

            $filename = 'neria_variantb_' . $tplKey . '_' . $tplLang . '_' . date('Ymd') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['template', 'lang', 'key', 'value'], ';');
            foreach ((array) $rows as $row) {
                fputcsv($out, [$tplKey, $tplLang, $row['translation_key'], $row['translation_value']], ';');
            }
            fclose($out);
            exit;
        }

        // ── Import CSV Variante B ────────────────────────────────────
        if ($tradAction === 'import_variant_b_csv') {
            $tplKey    = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('trad_template', ''));
            $tplLang   = preg_replace('/[^a-z]/i', '', (string) Tools::getValue('trad_lang', 'fr'));
            $idAbtestB = (int) Tools::getValue('id_abtest_b', 0);

            if ($this->abtestBelongsToShop($idAbtestB) && !empty($_FILES['neria_csv_b']['tmp_name']) && is_uploaded_file($_FILES['neria_csv_b']['tmp_name'])) {
                $tableTradB = _DB_PREFIX_ . 'neria_abtest_translation';
                $handle     = fopen($_FILES['neria_csv_b']['tmp_name'], 'r');
                $bom = fread($handle, 3);
                if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) { rewind($handle); }
                fgetcsv($handle, 0, ';'); // header
                $count = 0;
                $now   = date('Y-m-d H:i:s');
                while (($line = fgetcsv($handle, 0, ';')) !== false) {
                    if (count($line) < 4) { continue; }
                    $key   = preg_replace('/[^a-z0-9_\.\-]/i', '', $line[2]);
                    $value = $line[3];
                    if ($key === '') { continue; }
                    Db::getInstance()->execute(
                        "INSERT INTO `{$tableTradB}` (`id_abtest`,`lang`,`translation_key`,`translation_value`,`date_add`,`date_upd`)
                         VALUES ({$idAbtestB},'" . pSQL($tplLang) . "','" . pSQL($key) . "','" . pSQL($value, true) . "','{$now}','{$now}')
                         ON DUPLICATE KEY UPDATE `translation_value`='" . pSQL($value, true) . "', `date_upd`='{$now}'"
                    );
                    $count++;
                }
                fclose($handle);
                $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.csv_import_success_variant_b', ['n' => $count]));
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.csv_import_no_file_variant_b'));
            }
        }

        // ── Réinitialiser ce template dans TOUTES les langues ─────────
        if ($tradAction === 'reset_template_all_langs') {
            $tplKey    = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('trad_template', ''));
            $tableTrad = _DB_PREFIX_ . 'neria_translation';
            $jsonPath  = __DIR__ . '/data/translations.json';
            $jsonData  = is_file($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

            if ($tplKey !== '' && !empty($jsonData[$tplKey])) {
                Db::getInstance()->execute(
                    "DELETE FROM `{$tableTrad}` WHERE `template` = '" . pSQL($tplKey) . "'"
                );
                $now   = date('Y-m-d H:i:s');
                $batch = [];
                foreach ($jsonData[$tplKey] as $lang => $fields) {
                    foreach ($fields as $fKey => $fVal) {
                        if (is_string($fVal)) {
                            $batch[] = sprintf(
                                "('%s','%s','%s','%s',0,'%s','%s')",
                                pSQL($tplKey), pSQL($lang), pSQL($fKey), pSQL($fVal, true), $now, $now
                            );
                        }
                    }
                }
                if ($batch) {
                    Db::getInstance()->execute("SET NAMES 'utf8mb4'");
                    Db::getInstance()->execute(sprintf(
                        "INSERT INTO `%s` (`template`,`lang`,`translation_key`,`translation_value`,`is_custom`,`date_add`,`date_upd`) VALUES %s",
                        $tableTrad, implode(',', $batch)
                    ));
                }
                if (class_exists('TranslationEngine')) { (new TranslationEngine($this))->clearCache(); }
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this))->warning(
                        WatchdogManager::i18nMsg('watchdog.template_reset_all_langs', ['template' => $tplKey]), '', 'Traductions'
                    );
                }
                $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.template_reset_all_langs_banner', ['template' => $tplKey]));
            }
        }

        // ── Réinitialiser TOUT (tous templates, toutes langues) ───────
        if ($tradAction === 'reset_all_translations') {
            $tableTrad = _DB_PREFIX_ . 'neria_translation';
            $jsonPath  = __DIR__ . '/data/translations.json';
            $jsonData  = is_file($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

            if (!empty($jsonData) && is_array($jsonData)) {
                Db::getInstance()->execute("TRUNCATE TABLE `{$tableTrad}`");
                $now   = date('Y-m-d H:i:s');
                $batch = [];
                foreach ($jsonData as $tpl => $langs) {
                    foreach ($langs as $lang => $fields) {
                        foreach ($fields as $fKey => $fVal) {
                            if (is_string($fVal)) {
                                $batch[] = sprintf(
                                    "('%s','%s','%s','%s',0,'%s','%s')",
                                    pSQL($tpl), pSQL($lang), pSQL($fKey), pSQL($fVal, true), $now, $now
                                );
                            }
                        }
                    }
                    // Insérer par lots de 500
                    if (count($batch) >= 500) {
                        Db::getInstance()->execute("SET NAMES 'utf8mb4'");
                        Db::getInstance()->execute(sprintf(
                            "INSERT INTO `%s` (`template`,`lang`,`translation_key`,`translation_value`,`is_custom`,`date_add`,`date_upd`) VALUES %s",
                            $tableTrad, implode(',', $batch)
                        ));
                        $batch = [];
                    }
                }
                if ($batch) {
                    Db::getInstance()->execute("SET NAMES 'utf8mb4'");
                    Db::getInstance()->execute(sprintf(
                        "INSERT INTO `%s` (`template`,`lang`,`translation_key`,`translation_value`,`is_custom`,`date_add`,`date_upd`) VALUES %s",
                        $tableTrad, implode(',', $batch)
                    ));
                }
                if (class_exists('TranslationEngine')) { (new TranslationEngine($this))->clearCache(); }
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this))->warning(WatchdogManager::i18nMsg('watchdog.translations_reset_global'), '', 'Traductions');
                }
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.translations_reset_global_banner'));
            }
        }

        // ── Empreinte vocale : sauvegarde du profil ───────────────────
        if ($tradAction === 'save_voice_profile' && class_exists('VoiceProfileManager')) {
            $voiceLang = preg_replace('/[^a-z]/i', '', (string) Tools::getValue('trad_lang', 'fr'));
            $banned    = (string) Tools::getValue('voice_banned_words', '');
            $preferred = (string) Tools::getValue('voice_preferred_words', '');
            $toneNotes = (string) Tools::getValue('voice_tone_notes', '');

            if ($voiceLang !== '') {
                (new VoiceProfileManager($this))->saveProfile($voiceLang, $banned, $preferred, $toneNotes);
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this))->info(
                        WatchdogManager::i18nMsg('watchdog.voice_profile_updated', ['lang' => $voiceLang]), '', 'Traductions'
                    );
                }
                $this->context->smarty->assign('neria_success', AdminTranslator::t('translations.voice_saved'));
            }
        }

        // ── Empreinte vocale : audit de cohérence sur toutes les trads ─
        if ($tradAction === 'check_voice_profile' && class_exists('VoiceProfileManager')) {
            $voiceLang = preg_replace('/[^a-z]/i', '', (string) Tools::getValue('trad_lang', 'fr'));
            if ($voiceLang !== '') {
                $audit = (new VoiceProfileManager($this))->auditTranslations($voiceLang);
                $audit['summary'] = sprintf(
                    AdminTranslator::t('translations.voice_audit_summary'),
                    $audit['templates_scanned'],
                    $audit['entries_scanned']
                );
                $this->context->smarty->assign('voice_audit', $audit);
            }
        }

        // ── Sauvegarde clé DeepL ──────────────────────────────────────
        if ($tradAction === 'save_deepl_key') {
            $key = trim((string) Tools::getValue('deepl_key', ''));
            (new ConfigManager($this))->set(ConfigManager::KEY_DEEPL_KEY, CryptoManager::encrypt($key));
            if (class_exists('WatchdogManager')) {
                (new WatchdogManager($this))->info(
                    $key !== ''
                        ? WatchdogManager::i18nMsg('watchdog.deepl_key_configured')
                        : WatchdogManager::i18nMsg('watchdog.deepl_key_removed'),
                    '', 'Traductions'
                );
            }
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.deepl_key_saved'));
        }

        if (in_array($tradAction, ['load_translations', 'save_translations', 'reset_template', 'save_variant_b', 'restore_translation', 'reset_variant_b', 'restore_variant_b', 'delete_history'], true)) {
            $tplKey  = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('trad_template', ''));
            $tplLang = preg_replace('/[^a-z]/i', '',    (string) Tools::getValue('trad_lang', 'fr'));

            if ($tplKey !== '' && $tplLang !== '' && class_exists('TranslationEngine')) {
                $engine    = new TranslationEngine($this);
                $tableTrad = _DB_PREFIX_ . 'neria_translation';

                if ($tradAction === 'save_translations') {
                    $fields = Tools::getValue('fields', []);
                    if (is_array($fields)) {
                        // Enregistre les changements dans l'historique avant d'écraser
                        if (class_exists('TranslationHistoryManager')) {
                            $histMgr  = new TranslationHistoryManager();
                            $employee = $this->context->employee;
                            $author   = trim($employee->firstname . ' ' . $employee->lastname) ?: 'Admin';

                            $currentRows = Db::getInstance()->executeS(
                                "SELECT `translation_key`, `translation_value`
                                 FROM `{$tableTrad}`
                                 WHERE `template` = '" . pSQL($tplKey) . "'
                                   AND `lang`     = '" . pSQL($tplLang) . "'"
                            );
                            $currentVals = [];
                            foreach ((array) $currentRows as $r) {
                                $currentVals[$r['translation_key']] = $r['translation_value'];
                            }

                            foreach ($fields as $fKey => $fVal) {
                                $fKey = preg_replace('/[^a-z0-9_]/i', '', (string) $fKey);
                                if ($fKey !== '') {
                                    $histMgr->record(
                                        $tplKey,
                                        $tplLang,
                                        $fKey,
                                        $currentVals[$fKey] ?? '',
                                        (string) $fVal,
                                        $author
                                    );
                                }
                            }
                        }

                        foreach ($fields as $key => $value) {
                            $key = preg_replace('/[^a-z0-9_]/i', '', (string) $key);
                            if ($key !== '') {
                                $engine->update($tplKey, $tplLang, $key, (string) $value);
                            }
                        }
                    }
                    // Watchdog : log le nombre de champs réellement modifiés
                    if (class_exists('WatchdogManager') && isset($histMgr)) {
                        $changedCount = count(array_filter(
                            array_keys((array) $fields),
                            fn($k) => preg_replace('/[^a-z0-9_]/i', '', (string) $k) !== ''
                                   && ($currentVals[preg_replace('/[^a-z0-9_]/i', '', (string) $k)] ?? null)
                                      !== (string) $fields[$k]
                        ));
                        if ($changedCount > 0) {
                            (new WatchdogManager($this))->info(
                                WatchdogManager::i18nMsg('watchdog.translation_fields_changed', [
                                    'n' => $changedCount, 'template' => $tplKey, 'lang' => $tplLang,
                                ]),
                                '',
                                'Traductions'
                            );
                        }
                    }

                    // Empreinte vocale : signale (sans jamais bloquer la sauvegarde)
                    // les mots bannis présents dans les valeurs qui viennent d'être
                    // enregistrées.
                    if (class_exists('VoiceProfileManager') && is_array($fields)) {
                        $bannedWords = (new VoiceProfileManager($this))->getBannedWords($tplLang);
                        if (!empty($bannedWords)) {
                            $voiceHits = [];
                            foreach ($fields as $fVal) {
                                foreach (VoiceProfileManager::textContainsWords((string) $fVal, $bannedWords) as $w) {
                                    $voiceHits[$w] = true;
                                }
                            }
                            if ($voiceHits) {
                                $this->context->smarty->assign(
                                    'neria_voice_warning',
                                    sprintf(
                                        AdminTranslator::t('translations.voice_warning_found'),
                                        implode(', ', array_keys($voiceHits))
                                    )
                                );
                            }
                        }
                    }

                    $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
                }

                if ($tradAction === 'reset_template') {
                    $jsonPath = __DIR__ . '/data/translations.json';
                    $jsonAll  = [];
                    if (file_exists($jsonPath)) {
                        $decoded = json_decode((string) file_get_contents($jsonPath), true);
                        if (isset($decoded[$tplKey][$tplLang]) && is_array($decoded[$tplKey][$tplLang])) {
                            $jsonAll = $decoded[$tplKey][$tplLang];
                        }
                    }

                    // 1. Sauvegarde les valeurs custom dans le changelog avant écrasement
                    if (class_exists('TranslationHistoryManager') && !empty($jsonAll)) {
                        $histMgr  = new TranslationHistoryManager();
                        $employee = $this->context->employee;
                        $author   = trim($employee->firstname . ' ' . $employee->lastname) ?: 'Admin';

                        $customRows = Db::getInstance()->executeS(
                            "SELECT `translation_key`, `translation_value`
                             FROM `{$tableTrad}`
                             WHERE `template` = '" . pSQL($tplKey) . "'
                               AND `lang`     = '" . pSQL($tplLang) . "'
                               AND `is_custom` = 1"
                        );
                        foreach ((array) $customRows as $r) {
                            $histMgr->record(
                                $tplKey,
                                $tplLang,
                                $r['translation_key'],
                                $r['translation_value'],
                                $jsonAll[$r['translation_key']] ?? '',
                                $author . ' (réinitialisation)'
                            );
                        }
                    }

                    // 2. Supprime toutes les lignes du template+lang (custom ET défaut)
                    Db::getInstance()->execute(
                        "DELETE FROM `{$tableTrad}`
                         WHERE `template` = '" . pSQL($tplKey) . "'
                           AND `lang`     = '" . pSQL($tplLang) . "'"
                    );

                    // 3. Réinsère les valeurs d'usine depuis translations.json
                    if (!empty($jsonAll)) {
                        $batch = [];
                        $now   = date('Y-m-d H:i:s');
                        foreach ($jsonAll as $fKey => $fVal) {
                            if (is_string($fVal)) {
                                $batch[] = sprintf(
                                    "('%s', '%s', '%s', '%s', 0, '%s', '%s')",
                                    pSQL($tplKey), pSQL($tplLang),
                                    pSQL($fKey), pSQL($fVal, true),
                                    $now, $now
                                );
                            }
                        }
                        if ($batch) {
                            Db::getInstance()->execute("SET NAMES 'utf8mb4'");
                            Db::getInstance()->execute(sprintf(
                                "INSERT INTO `%s` (`template`, `lang`, `translation_key`, `translation_value`, `is_custom`, `date_add`, `date_upd`) VALUES %s",
                                $tableTrad,
                                implode(', ', $batch)
                            ));
                        }
                    }

                    $engine->clearCache();

                    // Watchdog : réinitialisation = action forte → niveau warning
                    if (class_exists('WatchdogManager')) {
                        $resetCount = isset($customRows) ? count((array) $customRows) : 0;
                        (new WatchdogManager($this))->warning(
                            WatchdogManager::i18nMsg('watchdog.template_reset_lang', [
                                'template' => $tplKey, 'lang' => $tplLang, 'n' => $resetCount,
                            ]),
                            '',
                            'Traductions'
                        );
                    }
                    $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.template_reset_default'));
                }

                if ($tradAction === 'reset_variant_b') {
                    $idAbtestB  = (int) Tools::getValue('id_abtest_b', 0);
                    $tableTradB = _DB_PREFIX_ . 'neria_abtest_translation';
                    if ($this->abtestBelongsToShop($idAbtestB)) {
                        // Archive dans l'historique avant suppression
                        if (class_exists('TranslationHistoryManager')) {
                            $prevRowsB = Db::getInstance()->executeS(
                                "SELECT `translation_key`, `translation_value`
                                 FROM `{$tableTradB}`
                                 WHERE `id_abtest` = {$idAbtestB}
                                   AND `lang` = '" . pSQL($tplLang) . "'"
                            );
                            if ($prevRowsB) {
                                $histMgrB = new TranslationHistoryManager();
                                $employee = $this->context->employee;
                                $authorB  = trim($employee->firstname . ' ' . $employee->lastname) ?: 'Admin';
                                foreach ($prevRowsB as $r) {
                                    $histMgrB->record(
                                        'variantb_' . $tplKey,
                                        $tplLang,
                                        $r['translation_key'],
                                        $r['translation_value'],
                                        '',
                                        $authorB . ' (réinitialisation)'
                                    );
                                }
                            }
                        }
                        Db::getInstance()->execute(
                            "DELETE FROM `{$tableTradB}`
                             WHERE `id_abtest` = {$idAbtestB}
                               AND `lang` = '" . pSQL($tplLang) . "'"
                        );
                        if (class_exists('WatchdogManager')) {
                            (new WatchdogManager($this))->warning(
                                WatchdogManager::i18nMsg('watchdog.variant_b_reset', ['template' => $tplKey, 'lang' => $tplLang]),
                                '', 'Traductions'
                            );
                        }
                    }
                    $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.variant_b_reset_banner'));
                }

                if ($tradAction === 'save_variant_b' && class_exists('ABTestManager')) {
                    $idAbtestB = (int) Tools::getValue('id_abtest_b', 0);
                    $fieldsB   = Tools::getValue('fields_b', []);
                    if ($this->abtestBelongsToShop($idAbtestB) && is_array($fieldsB)) {
                        // Enregistre l'historique des modifications Variante B avant sauvegarde
                        if (class_exists('TranslationHistoryManager')) {
                            $tableTradB = _DB_PREFIX_ . 'neria_abtest_translation';
                            $histMgrB   = new TranslationHistoryManager();
                            $employee   = $this->context->employee;
                            $authorB    = trim($employee->firstname . ' ' . $employee->lastname) ?: 'Admin';
                            $prevRowsB  = Db::getInstance()->executeS(
                                "SELECT `translation_key`, `translation_value`
                                 FROM `{$tableTradB}`
                                 WHERE `id_abtest` = {$idAbtestB}
                                   AND `lang` = '" . pSQL($tplLang) . "'"
                            );
                            $prevValsB = [];
                            foreach ((array) $prevRowsB as $r) {
                                $prevValsB[$r['translation_key']] = $r['translation_value'];
                            }
                            foreach ($fieldsB as $fKey => $fVal) {
                                $fKey = preg_replace('/[^a-z0-9_]/i', '', (string) $fKey);
                                if ($fKey !== '' && ($prevValsB[$fKey] ?? '') !== (string) $fVal) {
                                    $histMgrB->record(
                                        'variantb_' . $tplKey,
                                        $tplLang,
                                        $fKey,
                                        $prevValsB[$fKey] ?? '',
                                        (string) $fVal,
                                        $authorB
                                    );
                                }
                            }
                        }
                        (new ABTestManager($this))->saveVariantBTranslations($idAbtestB, $tplLang, $fieldsB);
                    }
                    $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
                }

                if ($tradAction === 'restore_variant_b' && class_exists('TranslationHistoryManager')) {
                    $idHistory = (int) Tools::getValue('id_history', 0);
                    $idAbtestB = (int) Tools::getValue('id_abtest_b', 0);
                    if ($idHistory > 0 && $this->abtestBelongsToShop($idAbtestB)) {
                        $histMgr = new TranslationHistoryManager();
                        $entry   = $histMgr->getById($idHistory);
                        if ($entry) {
                            $restoreKey = $entry['translation_key'];
                            $restoreVal = $entry['old_value'];
                            $tableTradB = _DB_PREFIX_ . 'neria_abtest_translation';
                            $now        = date('Y-m-d H:i:s');
                            $employee   = $this->context->employee;
                            $author     = trim($employee->firstname . ' ' . $employee->lastname) ?: 'Admin';

                            $currentVal = Db::getInstance()->getValue(
                                "SELECT `translation_value` FROM `{$tableTradB}`
                                 WHERE `id_abtest` = {$idAbtestB}
                                   AND `lang` = '" . pSQL($tplLang) . "'
                                   AND `translation_key` = '" . pSQL($restoreKey) . "'"
                            );

                            Db::getInstance()->execute(
                                "INSERT INTO `{$tableTradB}` (`id_abtest`,`lang`,`translation_key`,`translation_value`,`date_add`,`date_upd`)
                                 VALUES ({$idAbtestB},'" . pSQL($tplLang) . "','" . pSQL($restoreKey) . "','" . pSQL($restoreVal, true) . "','{$now}','{$now}')
                                 ON DUPLICATE KEY UPDATE `translation_value`='" . pSQL($restoreVal, true) . "', `date_upd`='{$now}'"
                            );

                            $histMgr->record(
                                'variantb_' . $tplKey,
                                $tplLang,
                                $restoreKey,
                                $currentVal ?: '',
                                $restoreVal,
                                $author . ' (restauration B)'
                            );
                        }
                    }
                    $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
                }

                if ($tradAction === 'restore_translation' && class_exists('TranslationHistoryManager')) {
                    $idHistory = (int) Tools::getValue('id_history', 0);
                    if ($idHistory > 0) {
                        $histMgr = new TranslationHistoryManager();
                        $entry   = $histMgr->getById($idHistory);
                        if ($entry) {
                            $restoreKey = $entry['translation_key'];
                            $restoreVal = $entry['old_value'];
                            $employee   = $this->context->employee;
                            $author     = trim($employee->firstname . ' ' . $employee->lastname) ?: 'Admin';

                            // Valeur actuelle avant restauration
                            $currentVal = Db::getInstance()->getValue(
                                "SELECT `translation_value` FROM `{$tableTrad}`
                                 WHERE `template` = '" . pSQL($tplKey) . "'
                                   AND `lang`     = '" . pSQL($tplLang) . "'
                                   AND `translation_key` = '" . pSQL($restoreKey) . "'"
                            );

                            $engine->update($tplKey, $tplLang, $restoreKey, $restoreVal);

                            // La restauration est elle-même une entrée d'historique
                            $histMgr->record(
                                $tplKey,
                                $tplLang,
                                $restoreKey,
                                $currentVal ?: '',
                                $restoreVal,
                                $author . ' (restauration)'
                            );

                            // Watchdog : restauration depuis l'historique
                            if (class_exists('WatchdogManager')) {
                                (new WatchdogManager($this))->info(
                                    WatchdogManager::i18nMsg('watchdog.translation_field_restored', [
                                        'key' => $restoreKey, 'template' => $tplKey, 'lang' => $tplLang, 'author' => $author,
                                    ]),
                                    '',
                                    'Traductions'
                                );
                            }
                        }
                    }
                    $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
                }

                if ($tradAction === 'delete_history' && class_exists('TranslationHistoryManager')) {
                    $idHistory = (int) Tools::getValue('id_history', 0);
                    if ($idHistory > 0) {
                        Db::getInstance()->execute(
                            "DELETE FROM `" . _DB_PREFIX_ . "neria_translation_history`
                             WHERE `id_history` = " . $idHistory . "
                               AND `id_shop` = " . (int) $this->context->shop->id
                        );
                    }
                }

                // Charge (ou recharge après save/reset) les traductions A
                $rows = Db::getInstance()->executeS(
                    "SELECT `translation_key`, `translation_value`, `is_custom`
                     FROM `{$tableTrad}`
                     WHERE `template` = '" . pSQL($tplKey) . "'
                       AND `lang`     = '" . pSQL($tplLang) . "'
                     ORDER BY `translation_key` ASC"
                );

                $translations = [];
                $isCustom     = [];
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $translations[$row['translation_key']] = $row['translation_value'];
                        $isCustom[$row['translation_key']]     = (bool) $row['is_custom'];
                    }
                }

                $subjectSpamJson = '[]';
                if (class_exists('DeliverabilityScorer')) {
                    $subjectSpamJson = json_encode(
                        (new DeliverabilityScorer())->getSubjectSpamTriggers(),
                        JSON_UNESCAPED_UNICODE
                    );
                }

                $nsaLabelsJson = json_encode([
                    'fr' => ['t' => 'Sujet de l\'email',       's' => '⚠ Mots à risque :',        'u' => 'car.',    'e' => 'vide',        'c' => 'trop court',       'o' => 'optimal',      'l1' => 'un peu long',       'l2' => 'trop long'],
                    'en' => ['t' => 'Email subject',            's' => '⚠ Risk words:',             'u' => 'chars',   'e' => 'empty',       'c' => 'too short',        'o' => 'optimal',      'l1' => 'a bit long',        'l2' => 'too long'],
                    'de' => ['t' => 'E-Mail-Betreff',           's' => '⚠ Risikowörter:',           'u' => 'Zeichen', 'e' => 'leer',        'c' => 'zu kurz',          'o' => 'optimal',      'l1' => 'etwas lang',        'l2' => 'zu lang'],
                    'es' => ['t' => 'Asunto del email',         's' => '⚠ Palabras de riesgo:',     'u' => 'car.',    'e' => 'vacío',       'c' => 'demasiado corto',  'o' => 'óptimo',       'l1' => 'algo largo',        'l2' => 'demasiado largo'],
                    'it' => ['t' => 'Oggetto email',            's' => '⚠ Parole a rischio:',       'u' => 'car.',    'e' => 'vuoto',       'c' => 'troppo corto',     'o' => 'ottimale',     'l1' => 'un po\' lungo',     'l2' => 'troppo lungo'],
                    'pt' => ['t' => 'Assunto do email',         's' => '⚠ Palavras de risco:',      'u' => 'car.',    'e' => 'vazio',       'c' => 'muito curto',      'o' => 'ótimo',        'l1' => 'um pouco longo',    'l2' => 'muito longo'],
                    'nl' => ['t' => 'E-mailonderwerp',          's' => '⚠ Risicowoorden:',          'u' => 'tek.',    'e' => 'leeg',        'c' => 'te kort',          'o' => 'optimaal',     'l1' => 'iets lang',         'l2' => 'te lang'],
                    'pl' => ['t' => 'Temat e-maila',            's' => '⚠ Ryzykowne słowa:',        'u' => 'zn.',     'e' => 'puste',       'c' => 'za krótki',        'o' => 'optymalny',    'l1' => 'trochę długi',      'l2' => 'za długi'],
                    'sv' => ['t' => 'E-postämne',               's' => '⚠ Riskord:',                'u' => 'tkn',     'e' => 'tomt',        'c' => 'för kort',         'o' => 'optimalt',     'l1' => 'lite långt',        'l2' => 'för långt'],
                    'da' => ['t' => 'E-mail emne',              's' => '⚠ Risikoord:',              'u' => 'tegn',    'e' => 'tomt',        'c' => 'for kort',         'o' => 'optimalt',     'l1' => 'lidt langt',        'l2' => 'for langt'],
                    'fi' => ['t' => 'Sähköpostin aihe',         's' => '⚠ Riskisanat:',             'u' => 'merk.',   'e' => 'tyhjä',       'c' => 'liian lyhyt',      'o' => 'optimaalinen', 'l1' => 'hieman pitkä',      'l2' => 'liian pitkä'],
                    'no' => ['t' => 'E-postemne',               's' => '⚠ Risikoord:',              'u' => 'tegn',    'e' => 'tomt',        'c' => 'for kort',         'o' => 'optimalt',     'l1' => 'litt langt',        'l2' => 'for langt'],
                    'tr' => ['t' => 'E-posta konusu',           's' => '⚠ Riskli kelimeler:',       'u' => 'kar.',    'e' => 'boş',         'c' => 'çok kısa',         'o' => 'optimal',      'l1' => 'biraz uzun',        'l2' => 'çok uzun'],
                    'cs' => ['t' => 'Předmět e-mailu',          's' => '⚠ Riziková slova:',         'u' => 'zn.',     'e' => 'prázdný',     'c' => 'příliš krátký',    'o' => 'optimální',    'l1' => 'trochu dlouhý',     'l2' => 'příliš dlouhý'],
                    'hu' => ['t' => 'E-mail tárgya',            's' => '⚠ Kockázatos szavak:',      'u' => 'kar.',    'e' => 'üres',        'c' => 'túl rövid',        'o' => 'optimális',    'l1' => 'kicsit hosszú',     'l2' => 'túl hosszú'],
                    'ro' => ['t' => 'Subiect email',            's' => '⚠ Cuvinte de risc:',        'u' => 'car.',    'e' => 'gol',         'c' => 'prea scurt',       'o' => 'optim',        'l1' => 'puțin lung',        'l2' => 'prea lung'],
                    'ru' => ['t' => 'Тема письма',              's' => '⚠ Рискованные слова:',      'u' => 'симв.',   'e' => 'пусто',       'c' => 'слишком коротко',  'o' => 'оптимально',   'l1' => 'немного длинно',    'l2' => 'слишком длинно'],
                    'ar' => ['t' => 'موضوع البريد',             's' => '⚠ كلمات خطرة:',             'u' => 'حرف',     'e' => 'فارغ',        'c' => 'قصير جداً',        'o' => 'مثالي',        'l1' => 'طويل قليلاً',       'l2' => 'طويل جداً'],
                    'zh' => ['t' => '邮件主题',                  's' => '⚠ 风险词汇：',               'u' => '字',      'e' => '空白',         'c' => '太短',             'o' => '最佳',          'l1' => '稍长',              'l2' => '太长'],
                    'ja' => ['t' => 'メールの件名',              's' => '⚠ リスクワード：',           'u' => '文字',    'e' => '空白',         'c' => '短すぎ',           'o' => '最適',         'l1' => 'やや長い',          'l2' => '長すぎ'],
                    'ko' => ['t' => '이메일 제목',               's' => '⚠ 위험 단어:',              'u' => '자',      'e' => '비어 있음',    'c' => '너무 짧음',        'o' => '최적',         'l1' => '약간 긴',           'l2' => '너무 김'],
                ], JSON_UNESCAPED_UNICODE);

                // Historique des modifications pour l'onglet Traductions
                $translationHistory = [];
                if (class_exists('TranslationHistoryManager')) {
                    $rawHistory = (new TranslationHistoryManager())->getHistoryForTemplate($tplKey, $tplLang, 40);
                    foreach ($rawHistory as $entry) {
                        $entry['date_formatted'] = date('d/m/Y H:i', strtotime($entry['date_add']));
                        $translationHistory[]    = $entry;
                    }
                }

                $this->context->smarty->assign([
                    'selected_template'         => $tplKey,
                    'selected_lang'             => $tplLang,
                    'translations'              => $translations ?: null,
                    'is_custom'                 => $isCustom,
                    'subject_spam_triggers_json' => $subjectSpamJson,
                    'nsa_labels_json'           => $nsaLabelsJson,
                    'translation_history'       => $translationHistory,
                    'voice_profile'             => class_exists('VoiceProfileManager')
                        ? (new VoiceProfileManager($this))->getProfile($tplLang)
                        : ['banned_words' => '', 'preferred_words' => '', 'tone_notes' => ''],
                ]);

                // Charge les traductions variante B si un test A/B est actif
                if (class_exists('ABTestManager')) {
                    $ab = new ABTestManager($this);
                    if ($ab->hasActiveTest($tplKey)) {
                        $idAbtestB = 0;
                        foreach ($ab->getAllActiveTests() as $row) {
                            if ($row['template'] === $tplKey && $row['variant'] === 'B') {
                                $idAbtestB = (int) $row['id_abtest'];
                                break;
                            }
                        }

                        if ($idAbtestB > 0) {
                            $tableTradB = _DB_PREFIX_ . 'neria_abtest_translation';
                            $rowsB = Db::getInstance()->executeS(
                                "SELECT `translation_key`, `translation_value`
                                 FROM `{$tableTradB}`
                                 WHERE `id_abtest` = {$idAbtestB}
                                   AND `lang`      = '" . pSQL($tplLang) . "'"
                            );

                            $translationsB = [];
                            $isCustomB     = [];
                            if (is_array($rowsB)) {
                                foreach ($rowsB as $row) {
                                    $translationsB[$row['translation_key']] = $row['translation_value'];
                                    $isCustomB[$row['translation_key']]     = true; // présent en DB = personnalisé
                                }
                            }

                            // Les champs non encore renseignés en B affichent le texte A comme point de départ
                            foreach ($translations as $key => $value) {
                                if (!isset($translationsB[$key])) {
                                    $translationsB[$key] = $value;
                                }
                            }

                            // Historique des modifications Variante B
                            $translationHistoryB = [];
                            if (class_exists('TranslationHistoryManager')) {
                                $rawHistB = (new TranslationHistoryManager())->getHistoryForTemplate('variantb_' . $tplKey, $tplLang, 40);
                                foreach ($rawHistB as $entry) {
                                    $entry['date_formatted'] = date('d/m/Y H:i', strtotime($entry['date_add']));
                                    $translationHistoryB[]   = $entry;
                                }
                            }

                            $this->context->smarty->assign([
                                'abtest_active'         => true,
                                'id_abtest_b'           => $idAbtestB,
                                'translations_b'        => $translationsB,
                                'is_custom_b'           => $isCustomB,
                                'translation_history_b' => $translationHistoryB,
                            ]);
                        }
                    }
                }
            }
        }

        // ── Prévisualisation multi-client : rendu simulé (form POST) ─
        if (Tools::getValue('neria_action') === 'multipreview_render' && class_exists('MultiClientPreviewManager') && class_exists('EmailRenderer')) {
            $mpTemplate = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('mp_template', 'order_conf'));
            $mpLang     = preg_replace('/[^a-z\-]/i', '',   (string) Tools::getValue('mp_lang', 'fr'));
            if ($mpTemplate === '') { $mpTemplate = 'order_conf'; }
            if ($mpLang     === '') { $mpLang     = 'fr'; }
            $rawHtml = (new EmailRenderer($this))->renderPreviewHtml($mpTemplate, $mpLang);
            $mgr     = new MultiClientPreviewManager();
            $previews = [];
            $styleCountRaw = preg_match_all('/<style\b/i', $rawHtml);

            // Détection des anomalies pour le résumé affiché au clic sur le badge ⚠
            $diffChecks = [
                'Balises <style> supprimées'            => fn ($r, $t) => (bool) preg_match('/<style\b/i', $r) && !preg_match('/<style\b/i', $t),
                'Liens <link> CSS externes supprimés'   => fn ($r, $t) => substr_count($r, 'rel="stylesheet"') > substr_count($t, 'rel="stylesheet"'),
                'background-image supprimé'             => fn ($r, $t) => substr_count($r, 'background-image') > substr_count($t, 'background-image'),
                'border-radius supprimé'                => fn ($r, $t) => substr_count($r, 'border-radius') > substr_count($t, 'border-radius'),
                'text-shadow / box-shadow supprimés'    => fn ($r, $t) => substr_count($r, '-shadow') > substr_count($t, '-shadow'),
                'display:flex neutralisé (→ block)'     => fn ($r, $t) => substr_count($r, 'flex') > substr_count($t, 'flex'),
                'gap (flexbox) supprimé'                => fn ($r, $t) => substr_count($r, 'gap:') > substr_count($t, 'gap:'),
                'position supprimée'                    => fn ($r, $t) => substr_count($r, 'position:') > substr_count($t, 'position:'),
                '@media queries supprimées'             => fn ($r, $t) => substr_count($r, '@media') > substr_count($t, '@media'),
                'Attributs style="" en ligne supprimés' => fn ($r, $t) => substr_count($r, ' style=') > substr_count($t, ' style='),
            ];

            foreach (array_keys(MultiClientPreviewManager::CLIENTS) as $clientId) {
                $transformed = $mgr->transformForClient($rawHtml, $clientId);
                $detail = [];
                foreach ($diffChecks as $label => $check) {
                    if ($check($rawHtml, $transformed)) {
                        $detail[] = $label;
                    }
                }
                $previews[$clientId] = [
                    'html'   => $transformed,
                    'issues' => max(0, $styleCountRaw - preg_match_all('/<style\b/i', $transformed)),
                    'detail' => $detail,
                ];
            }
            // Sauvegarde chaque aperçu dans un fichier temp — évite la troncature Smarty
            $previewDir = _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR
                        . 'cache' . DIRECTORY_SEPARATOR . 'neria_previews' . DIRECTORY_SEPARATOR;
            if (!is_dir($previewDir)) {
                @mkdir($previewDir, 0755, true);
            }
            $mpToken = bin2hex(random_bytes(10));
            foreach ($previews as $clientId => $data) {
                file_put_contents($previewDir . $clientId . '_' . $mpToken . '.html', (string) ($data['html'] ?? ''));
            }
            // Nettoyage des fichiers > 2 h
            foreach (glob($previewDir . '*.html') ?: [] as $old) {
                if (filemtime($old) < time() - 7200) {
                    @unlink($old);
                }
            }
            $this->context->smarty->assign([
                'mp_previews_meta'     => array_map(fn ($pv) => [
                    'issues' => (int) ($pv['issues'] ?? 0),
                    'detail' => $pv['detail'] ?? [],
                ], $previews),
                'mp_token'             => $mpToken,
                'mp_preview_base'      => rtrim($this->context->link->getBaseLink(), '/') . '/modules/neria/getpreview.php',
                'mp_selected_template' => $mpTemplate,
                'mp_selected_lang'     => $mpLang,
            ]);
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.multipreview_generated', ['n' => count($previews)]));
        }

        // ── Multi-preview : soumission + sondage Litmus / Email on Acid ──
        // Endpoints JSON purs, en dehors du try/catch général : re-rendent le
        // HTML depuis mp_template/mp_lang (pas besoin de session côté serveur)
        // et relaient l'appel à l'API tierce configurée par le marchand.
        if (in_array(Tools::getValue('neria_action'), [
            'multipreview_submit_litmus', 'multipreview_poll_litmus',
            'multipreview_submit_eoa', 'multipreview_poll_eoa',
        ], true) && class_exists('MultiClientPreviewManager')) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }

            $mpAction = Tools::getValue('neria_action');
            $mgr      = new MultiClientPreviewManager();

            try {
                if ($mpAction === 'multipreview_submit_litmus' || $mpAction === 'multipreview_submit_eoa') {
                    $tpl  = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('mp_template', 'order_conf'));
                    $lang = preg_replace('/[^a-z\-]/i', '', (string) Tools::getValue('mp_lang', 'fr'));
                    $tpl  = $tpl !== '' ? $tpl : 'order_conf';
                    $lang = $lang !== '' ? $lang : 'fr';

                    $html = class_exists('EmailRenderer')
                        ? (new EmailRenderer($this))->renderPreviewHtml($tpl, $lang)
                        : '';

                    $result = $mpAction === 'multipreview_submit_litmus'
                        ? $mgr->submitToLitmus($html)
                        : $mgr->submitToEmailOnAcid($html);
                } else {
                    $testId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) Tools::getValue('test_id', ''));
                    $result = ['results' => $testId !== ''
                        ? ($mpAction === 'multipreview_poll_litmus' ? $mgr->pollLitmus($testId) : $mgr->pollEmailOnAcid($testId))
                        : []];
                }
                echo json_encode($result);
            } catch (\Throwable $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
        }

        // ── Prévisualisation multi-client : sauvegarde clés API ──
        if (Tools::getValue('neria_action') === 'save_multipreview_keys') {
            $litmusKey = trim((string) Tools::getValue('litmus_key', ''));
            $eoaKey    = trim((string) Tools::getValue('eoa_key', ''));
            if (class_exists('MultiClientPreviewManager')) {
                Configuration::updateValue(MultiClientPreviewManager::CONFIG_LITMUS_KEY, CryptoManager::encrypt($litmusKey));
                Configuration::updateValue(MultiClientPreviewManager::CONFIG_EOA_KEY, CryptoManager::encrypt($eoaKey));
            }
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
        }

        // ── Webhooks : sauvegarde ─────────────────────────────────
        if (Tools::getValue('neria_action') === 'save_webhooks') {
            $whUrl    = trim((string) Tools::getValue('webhook_url', ''));
            $whSecret = trim((string) Tools::getValue('webhook_secret', ''));
            $whEvents = Tools::getValue('webhook_events', []);

            if ($whUrl !== '' && !WebhookManager::isPublicUrl($whUrl)) {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.webhook_save_url_invalid'));
            } else {
                Configuration::updateValue(WebhookManager::CONFIG_URL, $whUrl);
                if ($whSecret === '' && $whUrl !== '') {
                    $whSecret = WebhookManager::generateSecret();
                }
                if ($whSecret !== '') {
                    Configuration::updateValue(WebhookManager::CONFIG_SECRET, CryptoManager::encrypt($whSecret));
                }
                Configuration::updateValue(
                    WebhookManager::CONFIG_EVENTS,
                    json_encode(is_array($whEvents) ? $whEvents : [])
                );
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
            }
        }

        // ── Webhooks : test de livraison ──────────────────────────
        if (Tools::getValue('neria_action') === 'test_webhook') {
            $result = (new WebhookManager($this))->sendTest();
            if ($result['ok']) {
                $this->context->smarty->assign(
                    'neria_success',
                    AdminTranslator::tVars('msg.webhook_test_sent', ['code' => (string) ($result['http_code'] ?? '200')])
                );
            } else {
                $errDetail = $result['error'] ?? ('HTTP ' . ($result['http_code'] ?? '0'));
                $this->context->smarty->assign(
                    'neria_error',
                    AdminTranslator::tVars('msg.webhook_test_failed', ['error' => $errDetail])
                );
            }
        }

        // ── Webhooks : traiter la file maintenant ──────────────────
        if (Tools::getValue('neria_action') === 'process_webhook_queue_now' && class_exists('WebhookManager')) {
            try {
                (new WebhookManager($this))->processQueue();
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.webhook_queue_processed'));
            } catch (\Throwable $e) {
                $this->context->smarty->assign('neria_error', AdminTranslator::tVars('msg.webhook_process_error', ['error' => $e->getMessage()]));
            }
        }

        // ── Webhooks : relance manuelle d'une livraison échouée ─────
        if (Tools::getValue('neria_action') === 'retry_webhook' && class_exists('WebhookManager')) {
            $idWebhook = (int) Tools::getValue('id_webhook', 0);
            if ($idWebhook > 0 && (new WebhookManager($this))->retryOne($idWebhook)) {
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.webhook_requeued'));
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.webhook_retry_failed'));
            }
        }

        // ── Churn : recalcul manuel ─────────────────────────────────
        if (Tools::getValue('neria_action') === 'recompute_churn') {
            if (class_exists('ChurnScoreManager')) {
                try {
                    $n = (new ChurnScoreManager($this))->recomputeAll();
                    $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.churn_recomputed', ['n' => $n]));
                } catch (\Throwable $e) {
                    if (class_exists('WatchdogManager')) {
                        (new WatchdogManager($this))->error(
                            WatchdogManager::i18nMsg('watchdog.churn_recompute_manual_failed', ['error' => $e->getMessage()]),
                            '', 'ChurnScoreManager'
                        );
                    }
                    $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.recompute_failed_watchdog'));
                }
            }
        }

        // ── Segments : calcul manuel ────────────────────────────────
        if (Tools::getValue('neria_action') === 'recompute_segments') {
            if (class_exists('SegmentManager')) {
                try {
                    $n = (new SegmentManager($this))->recomputeAll();
                    $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.segments_updated', ['n' => $n]));
                } catch (\Throwable $e) {
                    if (class_exists('WatchdogManager')) {
                        (new WatchdogManager($this))->error(
                            WatchdogManager::i18nMsg('watchdog.segment_recompute_manual_failed', ['error' => $e->getMessage()]),
                            '', 'SegmentManager'
                        );
                    }
                    $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.recompute_failed_watchdog'));
                }
            }
        }

        // ── Segments : envoi de campagne ────────────────────────────
        if (Tools::getValue('neria_action') === 'send_segment_campaign') {
            $seg      = (string) Tools::getValue('campaign_segment', '');
            $template = (string) Tools::getValue('campaign_template', '');
            $filters  = array_filter([
                'slot'       => (string) Tools::getValue('campaign_slot', ''),
                'lang_iso'   => (string) Tools::getValue('campaign_lang', ''),
                'id_country' => (int)    Tools::getValue('campaign_country', 0),
            ]);
            if ($seg !== '' && $template !== '' && class_exists('SegmentManager')) {
                $res = (new SegmentManager($this))->sendToSegment($seg, $template, $filters);
                if (($res['error'] ?? '') === 'preflight_failed') {
                    $this->context->smarty->assign(
                        'neria_error',
                        AdminTranslator::tVars('msg.segment_send_cancelled', ['issues' => implode(' ', $res['preflight']['issues'] ?? [])])
                    );
                } elseif (isset($res['error'])) {
                    $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.segment_template_not_allowed_generic'));
                } else {
                    $this->context->smarty->assign(
                        'neria_success',
                        AdminTranslator::tVars('msg.segment_campaign_result', [
                            'sent'        => $res['sent'],
                            'failedPart'  => $res['failed']  > 0 ? AdminTranslator::tVars('msg.segment_failed_part', ['n' => $res['failed']]) : '',
                            'skippedPart' => $res['skipped'] > 0 ? AdminTranslator::tVars('msg.segment_skipped_part', ['n' => $res['skipped']]) : '',
                        ])
                    );
                }
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.segment_and_template_required'));
            }
        }

        // ── RGPD : purge d'une table ──────────────────────────────
        if (Tools::getValue('neria_action') === 'gdpr_purge' && class_exists('GdprAuditManager')) {
            $gdprTable   = preg_replace('/[^a-z0-9_]/i', '', (string) Tools::getValue('gdpr_table', ''));
            $gdprDateCol = preg_replace('/[^a-z0-9_]/i', '', (string) Tools::getValue('gdpr_date_col', ''));
            $gdprMonths  = max(1, (int) Tools::getValue('gdpr_months', 36));
            $def = GdprAuditManager::getTableDef($gdprTable);
            if ($def && $gdprDateCol === $def['date_col']) {
                try {
                    $purged = (new GdprAuditManager(__DIR__))->purgeTable($gdprTable, $gdprDateCol, $gdprMonths);
                    if (class_exists('WatchdogManager')) {
                        (new WatchdogManager($this))->warning(
                            WatchdogManager::i18nMsg('watchdog.gdpr_purge_manual', [
                                'n' => $purged, 'table' => $gdprTable, 'months' => $gdprMonths,
                            ]),
                            '', 'RGPD'
                        );
                    }
                    $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.gdpr_purge_success', ['n' => $purged, 'table' => $gdprTable]));
                } catch (\Throwable $e) {
                    if (class_exists('WatchdogManager')) {
                        (new WatchdogManager($this))->error(
                            WatchdogManager::i18nMsg('watchdog.gdpr_purge_manual_failed', ['table' => $gdprTable, 'error' => $e->getMessage()]),
                            '', 'RGPD'
                        );
                    }
                    $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.purge_failed_watchdog'));
                }
            }
        }

        // ── Anniversaire relation client : toggle ─────────────────
        if (Tools::getValue('neria_action') === 'relationship_anniversary_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED');
            Configuration::updateGlobalValue('NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-relationship-anniversary-section');
        }

        // ── Checkout Abandonment : toggle activer/désactiver ─────
        if (Tools::getValue('neria_action') === 'checkout_abandonment_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_CHECKOUT_ABANDONMENT_ENABLED');
            Configuration::updateGlobalValue('NERIA_CHECKOUT_ABANDONMENT_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-checkout-abandonment-section');
        }

        // ── Liste d'attente : toggle activer/désactiver ──────────
        if (Tools::getValue('neria_action') === 'waitlist_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_WAITLIST_ENABLED');
            Configuration::updateGlobalValue('NERIA_WAITLIST_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-waitlist-section');
        }

        if (Tools::getValue('neria_action') === 'waitlist_reservation_save') {
            $hours = max(1, min(72, (int) Tools::getValue('waitlist_reservation_hours')));
            Configuration::updateGlobalValue('NERIA_WAITLIST_RESERVATION_HOURS', $hours);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t('msg.saved')) . '#neria-waitlist-section');
        }

        // ── Panier fantôme : toggle activer/désactiver ───────────
        if (Tools::getValue('neria_action') === 'ghost_cart_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_GHOST_CART_ENABLED');
            Configuration::updateGlobalValue('NERIA_GHOST_CART_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-ghost-cart-section');
        }

        // ── Complétion de collection : toggle activer/désactiver ─
        if (Tools::getValue('neria_action') === 'collection_completion_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_COLLECTION_COMPLETION_ENABLED');
            Configuration::updateGlobalValue('NERIA_COLLECTION_COMPLETION_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-collection-section');
        }

        // ── Complétez votre look : toggle activer/désactiver ─────
        if (Tools::getValue('neria_action') === 'look_completion_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_LOOK_COMPLETION_ENABLED');
            Configuration::updateGlobalValue('NERIA_LOOK_COMPLETION_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-look-section');
        }

        // ── Devis B2B : toggle ───────────────────────────────────
        if (Tools::getValue('neria_action') === 'quote_reminder_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_QUOTE_REMINDERS_ENABLED');
            Configuration::updateGlobalValue('NERIA_QUOTE_REMINDERS_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-quote-section');
        }

        // ── Réconciliation post-remboursement : toggle ────────────
        if (Tools::getValue('neria_action') === 'reconciliation_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_REFUND_RECONCILIATION_ENABLED');
            Configuration::updateGlobalValue('NERIA_REFUND_RECONCILIATION_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-reconciliation-section');
        }

        // ── Score de propension : toggle ──────────────────────────────
        if (Tools::getValue('neria_action') === 'propensity_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_PROPENSITY_ENABLED');
            Configuration::updateGlobalValue('NERIA_PROPENSITY_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-propensity-section');
        }

        // ── Rappel fin de vie produit : toggle ───────────────────────
        if (Tools::getValue('neria_action') === 'lifespan_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_LIFESPAN_ENABLED');
            Configuration::updateGlobalValue('NERIA_LIFESPAN_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-lifespan-section');
        }

        if (Tools::getValue('neria_action') === 'purchase_window_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED');
            Configuration::updateGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-purchase-window-section');
        }

        // ── Rappel fin de vie produit : ajouter ──────────────────────
        if (Tools::getValue('neria_action') === 'lifespan_add') {
            $idProduct    = (int) Tools::getValue('lifespan_id_product');
            $lifespanDays = (int) Tools::getValue('lifespan_days');
            $alertDays    = max(1, (int) Tools::getValue('lifespan_alert_days'));
            if ($idProduct > 0 && $lifespanDays > 0) {
                Db::getInstance()->execute(
                    'INSERT INTO `' . _DB_PREFIX_ . 'neria_product_lifespan`
                     (id_shop, id_product, lifespan_days, alert_days, date_add, date_upd)
                     VALUES (' . (int) $this->context->shop->id . ', ' . $idProduct . ', '
                    . $lifespanDays . ', ' . $alertDays . ', NOW(), NOW())
                     ON DUPLICATE KEY UPDATE lifespan_days = ' . $lifespanDays . ',
                     alert_days = ' . $alertDays . ', date_upd = NOW()'
                );
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.lifespan_product_added'));
            } else {
                $this->context->smarty->assign('neria_error', AdminTranslator::t('msg.lifespan_invalid_input'));
            }
        }

        // ── Rappel fin de vie produit : supprimer ─────────────────────
        if (Tools::getValue('neria_action') === 'lifespan_delete') {
            $idLifespan = (int) Tools::getValue('lifespan_id');
            if ($idLifespan > 0) {
                Db::getInstance()->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'neria_product_lifespan`
                     WHERE id_lifespan = ' . $idLifespan . ' AND id_shop = ' . (int) $this->context->shop->id
                );
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.lifespan_product_removed'));
            }
        }

        // ── Devis B2B : ajouter un devis ─────────────────────────
        if (Tools::getValue('neria_action') === 'quote_add') {
            // Le champ « client » accepte un ID numérique OU un email :
            // le marchand connaît souvent l'email de son client B2B, pas l'ID PS.
            $custInput  = trim((string) Tools::getValue('quote_id_customer'));
            $quoteRef   = pSQL(trim((string) Tools::getValue('quote_ref')));
            $quoteTotal = (float) str_replace(',', '.', Tools::getValue('quote_total'));
            $expiryDate = pSQL(trim((string) Tools::getValue('quote_expiry_date')));
            $idCurrency = (int) (Tools::getValue('quote_id_currency') ?: Configuration::get('PS_CURRENCY_DEFAULT'));

            $idCustomer = 0;
            if (ctype_digit($custInput)) {
                // Saisie numérique → vérifier que ce client existe vraiment.
                $idCustomer = (int) Db::getInstance()->getValue(
                    'SELECT id_customer FROM `' . _DB_PREFIX_ . 'customer`
                     WHERE id_customer = ' . (int) $custInput . ' AND deleted = 0'
                );
            } elseif ($custInput !== '' && Validate::isEmail($custInput)) {
                // Saisie email → résoudre vers l'id_customer.
                $idCustomer = (int) Db::getInstance()->getValue(
                    'SELECT id_customer FROM `' . _DB_PREFIX_ . 'customer`
                     WHERE email = \'' . pSQL($custInput) . '\' AND deleted = 0
                     ORDER BY id_customer DESC'
                );
            }

            // Anti-doublon : même référence déjà suivie pour ce client dans cette
            // boutique. Évite deux séquences de relance parallèles sur un même devis
            // (le client recevrait chaque email en double) et des stats faussées.
            $alreadyTracked = ($idCustomer > 0 && $quoteRef !== '')
                ? (int) Db::getInstance()->getValue(
                    'SELECT id_quote FROM `' . _DB_PREFIX_ . 'neria_quote`
                     WHERE id_shop = ' . (int) $this->context->shop->id . '
                       AND id_customer = ' . (int) $idCustomer . '
                       AND quote_ref = \'' . pSQL($quoteRef) . '\''
                )
                : 0;

            if ($custInput === '' || $quoteRef === '' || $expiryDate === '') {
                $this->assignQuoteMsg('error', AdminTranslator::t('msg.quote_required_fields'));
            } elseif ($idCustomer <= 0) {
                $this->assignQuoteMsg(
                    'error',
                    AdminTranslator::tVars('msg.quote_customer_not_found', ['input' => htmlspecialchars($custInput)])
                );
            } elseif ($alreadyTracked > 0) {
                $this->assignQuoteMsg(
                    'error',
                    AdminTranslator::tVars('msg.quote_already_tracked', ['ref' => htmlspecialchars($quoteRef)])
                );
            } else {
                Db::getInstance()->execute(
                    'INSERT INTO `' . _DB_PREFIX_ . 'neria_quote`
                     (id_shop, id_customer, quote_ref, quote_total, id_currency, expiry_date, status, date_add, date_upd)
                     VALUES (' . (int) $this->context->shop->id . ', ' . $idCustomer . ', \'' . $quoteRef . '\',
                     ' . $quoteTotal . ', ' . $idCurrency . ', \'' . $expiryDate . '\', \'active\', NOW(), NOW())'
                );
                $this->assignQuoteMsg('success', AdminTranslator::t('msg.quote_added'));
            }
        }

        // ── Devis B2B : marquer comme gagné ──────────────────────
        if (Tools::getValue('neria_action') === 'quote_mark_won') {
            $idQuote = (int) Tools::getValue('id_quote');
            if ($idQuote > 0) {
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'neria_quote`
                     SET status = \'won\', date_upd = NOW()
                     WHERE id_quote = ' . $idQuote . ' AND id_shop = ' . (int) $this->context->shop->id
                );
                $this->assignQuoteMsg('success', AdminTranslator::t('msg.quote_marked_won'));
            }
        }

        // ── Devis B2B : marquer comme perdu ──────────────────────
        if (Tools::getValue('neria_action') === 'quote_mark_lost') {
            $idQuote = (int) Tools::getValue('id_quote');
            if ($idQuote > 0) {
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'neria_quote`
                     SET status = \'lost\', date_upd = NOW()
                     WHERE id_quote = ' . $idQuote . ' AND id_shop = ' . (int) $this->context->shop->id
                );
                $this->assignQuoteMsg('success', AdminTranslator::t('msg.quote_marked_lost'));
            }
        }

        // ── Devis B2B : supprimer ─────────────────────────────────
        if (Tools::getValue('neria_action') === 'quote_delete') {
            $idQuote = (int) Tools::getValue('id_quote');
            if ($idQuote > 0) {
                Db::getInstance()->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'neria_quote`
                     WHERE id_quote = ' . $idQuote . ' AND id_shop = ' . (int) $this->context->shop->id
                );
                $this->assignQuoteMsg('success', AdminTranslator::t('msg.quote_deleted'));
            }
        }



        // ── Look completion : ajouter règle ──────────────────────
        if (Tools::getValue('neria_action') === 'look_rule_add' && class_exists('LookCompletionManager')) {
            $idCat = (int) Tools::getValue('look_category_id');
            $rawIds  = trim((string) Tools::getValue('look_product_ids'));
            $pids    = array_filter(array_map('intval', explode(',', $rawIds)));
            $msgKey = 'error:msg.look_rule_invalid';
            if ($idCat > 0 && count($pids) >= 2) {
                (new LookCompletionManager($this))->createRule($idCat, array_slice($pids, 0, 3));
                $msgKey = 'success:msg.look_rule_added';
            }
            [$msgType, $msgTransKey] = explode(':', $msgKey);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_' . $msgType . '=' . urlencode(AdminTranslator::t($msgTransKey)) . '#neria-look-section');
        }

        // ── Look completion : activer/désactiver règle ────────────
        if (Tools::getValue('neria_action') === 'look_rule_toggle' && class_exists('LookCompletionManager')) {
            $id  = (int) Tools::getValue('look_rule_id');
            $mgr = new LookCompletionManager($this);
            $r   = $mgr->getRuleById($id);
            $msgKey = 'msg.item_activated';
            if ($r) {
                $mgr->updateRule($id, (int) $r['id_category'], json_decode($r['product_ids'], true), !(bool) $r['active']);
                $msgKey = $r['active'] ? 'msg.item_deactivated' : 'msg.item_activated';
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($msgKey)) . '#neria-look-section');
        }

        // ── Look completion : supprimer règle ─────────────────────
        if (Tools::getValue('neria_action') === 'look_rule_delete' && class_exists('LookCompletionManager')) {
            $id = (int) Tools::getValue('look_rule_id');
            if ($id > 0) {
                (new LookCompletionManager($this))->deleteRule($id);
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t('msg.look_rule_deleted')) . '#neria-look-section');
        }

        // ── Collection : ajouter ─────────────────────────────────
        if (Tools::getValue('neria_action') === 'collection_add' && class_exists('CollectionManager')) {
            $name       = trim((string) Tools::getValue('collection_name'));
            $rawIds     = trim((string) Tools::getValue('collection_product_ids'));
            $productIds = array_filter(array_map('intval', explode(',', $rawIds)));
            $msgKey = 'error:msg.collection_invalid';
            if ($name !== '' && count($productIds) >= 2) {
                (new CollectionManager($this))->create($name, $productIds);
                $msgKey = 'success:msg.collection_added';
            }
            [$msgType, $msgTransKey] = explode(':', $msgKey);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_' . $msgType . '=' . urlencode(AdminTranslator::t($msgTransKey)) . '#neria-collection-section');
        }

        // ── Collection : activer/désactiver ──────────────────────
        if (Tools::getValue('neria_action') === 'collection_toggle' && class_exists('CollectionManager')) {
            $id  = (int) Tools::getValue('collection_id');
            $mgr = new CollectionManager($this);
            $col = $mgr->getById($id);
            $msgKey = 'msg.item_activated';
            if ($col) {
                $mgr->update($id, $col['name'], json_decode($col['product_ids'], true), !(bool) $col['active']);
                $msgKey = $col['active'] ? 'msg.item_deactivated' : 'msg.item_activated';
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($msgKey)) . '#neria-collection-section');
        }

        // ── Collection : supprimer ────────────────────────────────
        if (Tools::getValue('neria_action') === 'collection_delete' && class_exists('CollectionManager')) {
            $id = (int) Tools::getValue('collection_id');
            if ($id > 0) {
                (new CollectionManager($this))->delete($id);
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t('msg.collection_deleted')) . '#neria-collection-section');
        }

        // ── Upsell : toggle activer/désactiver ───────────────────
        if (Tools::getValue('neria_action') === 'upsell_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_UPSELL_ENABLED');
            Configuration::updateGlobalValue('NERIA_UPSELL_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=stats&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-upsell-section');
        }

        // ── Upsell : aperçu AJAX d'un produit suggéré ────────────
        if (Tools::getValue('neria_action') === 'upsell_preview' && class_exists('UpsellManager')) {
            // Accepte le numéro interne (ex: 12) OU la référence (ex: NER-000123)
            $query   = trim((string) Tools::getValue('order_q', (string) Tools::getValue('id_order', '')));
            $idOrder = 0;
            if ($query !== '') {
                if (ctype_digit($query)) {
                    $idOrder = (int) $query;
                } else {
                    $row = Db::getInstance()->getRow(
                        'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders`
                         WHERE `reference` = \'' . pSQL($query) . '\'
                           AND `id_shop` = ' . (int) $this->context->shop->id . '
                         ORDER BY `id_order` DESC'
                    );
                    $idOrder = (int) ($row['id_order'] ?? 0);
                }
            }
            // La commande existe-t-elle réellement (et appartient-elle à cette
            // boutique) ? Distingue « introuvable » de « trouvée sans suggestion ».
            $orderExists = $idOrder > 0 && (bool) Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'orders`
                 WHERE `id_order` = ' . $idOrder . ' AND `id_shop` = ' . (int) $this->context->shop->id
            );

            if (!$orderExists) {
                die(json_encode(['status' => 'not_found']));
            }

            $idLang = (int) $this->context->language->id;
            $result = ['status' => 'no_suggestion'];
            try {
                $upsellMgr = new UpsellManager($this);
                $upsell    = $upsellMgr->getUpsellProduct($idOrder, $idLang);
                if ($upsell) {
                    // Renvoie le bloc HTML EXACT inséré dans l'email du client
                    $result = [
                        'status' => 'found',
                        'html'   => $upsellMgr->buildHtmlBlock($upsell, new ConfigManager($this)),
                    ];
                }
            } catch (\Throwable $e) {
                $result = ['status' => 'error', 'message' => $e->getMessage()];
            }

            die(json_encode($result));
        }

        // ── RGPD : chiffrement des enregistrements existants ─────
        if (Tools::getValue('neria_action') === 'gdpr_encrypt_all' && class_exists('GdprAuditManager')) {
            $done = (new GdprAuditManager(__DIR__))->encryptExistingRecords();
            if (class_exists('WatchdogManager')) {
                (new WatchdogManager($this))->info(
                    WatchdogManager::i18nMsg('watchdog.gdpr_encrypt_retroactive', ['n' => $done]),
                    '', 'RGPD'
                );
            }
            $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.records_encrypted', ['n' => $done]));
        }

        // ── Fidélité : activation / désactivation ────────────────
        if (Tools::getValue('neria_action') === 'loyalty_toggle') {
            $current = (bool) Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED');
            Configuration::updateGlobalValue('NERIA_LOYALTY_ENABLED', $current ? 0 : 1);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]) . '&neria_tab=configure&neria_success=' . urlencode(AdminTranslator::t($current ? 'msg.feature_disabled' : 'msg.feature_enabled')) . '#neria-loyalty-section');
        }

        // ── Fidélité : sauvegarde des paliers ─────────────────
        if (Tools::getValue('neria_action') === 'save_loyalty_tiers' && class_exists('LoyaltyManager')) {
            $tiers = [];
            $keys  = ['bronze', 'silver', 'gold'];
            foreach ($keys as $k) {
                $tiers[] = [
                    'key'        => $k,
                    'name'       => pSQL(Tools::getValue('loyalty_name_' . $k, ucfirst($k))),
                    'points'     => max(1, (int) Tools::getValue('loyalty_points_' . $k, 50)),
                    'amount'     => max(0.01, (float) Tools::getValue('loyalty_amount_' . $k, 5)),
                    'is_percent' => (bool) Tools::getValue('loyalty_percent_' . $k, 0),
                ];
            }
            (new LoyaltyManager($this))->saveTiers($tiers);
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.loyalty_tiers_saved'));
        }

        // ── Campagnes saisonnières : créer / modifier ─────────────
        if (Tools::getValue('neria_action') === 'save_seasonal_campaign' && class_exists('SeasonalCampaignManager')) {
            $mgr = new SeasonalCampaignManager($this);
            $id  = (int) Tools::getValue('id_campaign', 0);
            $data = [
                'name'           => Tools::getValue('seasonal_name', ''),
                'template'       => Tools::getValue('seasonal_template', ''),
                'annual_date'    => Tools::getValue('seasonal_annual_date', '01-01'),
                'days_before'    => (int) Tools::getValue('seasonal_days_before', 0),
                'is_active'      => (int) (bool) Tools::getValue('seasonal_is_active', 1),
                'target_segment' => implode(',', array_filter((array) Tools::getValue('seasonal_segments', []))),
                'target_gender'  => (int) Tools::getValue('seasonal_gender', 0),
                'target_lang'    => implode(',', array_filter((array) Tools::getValue('seasonal_langs', []))),
                'min_age'        => (int) Tools::getValue('seasonal_min_age', 0),
                'max_age'        => (int) Tools::getValue('seasonal_max_age', 0),
                'gift_mode'      => (int) (bool) Tools::getValue('seasonal_gift_mode', 0),
            ];
            if ($id > 0) {
                $mgr->update($id, $data);
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.seasonal_campaign_updated'));
            } else {
                $mgr->create($data);
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.seasonal_campaign_created'));
            }
        }

        // ── Campagnes saisonnières : supprimer ────────────────────
        if (Tools::getValue('neria_action') === 'delete_seasonal_campaign' && class_exists('SeasonalCampaignManager')) {
            $id = (int) Tools::getValue('id_campaign', 0);
            if ($id > 0) {
                (new SeasonalCampaignManager($this))->delete($id);
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.seasonal_campaign_deleted'));
            }
        }

        // ── Campagnes saisonnières : activer / désactiver ─────────
        if (Tools::getValue('neria_action') === 'toggle_seasonal_campaign' && class_exists('SeasonalCampaignManager')) {
            $id = (int) Tools::getValue('id_campaign', 0);
            if ($id > 0) {
                (new SeasonalCampaignManager($this))->toggle($id);
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.saved'));
            }
        }

        // ── Bounces : sauvegarde configuration ───────────────────────
        if (Tools::getValue('neria_action') === 'save_bounce_config' && class_exists('BounceManager')) {
            Configuration::updateValue(BounceManager::CFG_ENABLED,        (int)    Tools::getValue('bounce_enabled', 0));
            Configuration::updateValue(BounceManager::CFG_IMAP_HOST,      (string) Tools::getValue('bounce_imap_host', ''));
            Configuration::updateValue(BounceManager::CFG_IMAP_PORT,      (int)    Tools::getValue('bounce_imap_port', 993));
            Configuration::updateValue(BounceManager::CFG_IMAP_USER,      (string) Tools::getValue('bounce_imap_user', ''));
            $pass = (string) Tools::getValue('bounce_imap_pass', '');
            if ($pass !== '') {
                Configuration::updateValue(BounceManager::CFG_IMAP_PASS,  CryptoManager::encrypt($pass));
            }
            Configuration::updateValue(BounceManager::CFG_IMAP_SSL,       (int)    Tools::getValue('bounce_imap_ssl', 1));
            Configuration::updateValue(BounceManager::CFG_IMAP_FOLDER,    (string) Tools::getValue('bounce_imap_folder', 'INBOX'));
            Configuration::updateValue(BounceManager::CFG_SOFT_THRESHOLD, max(1, (int) Tools::getValue('bounce_soft_threshold', 3)));
            $secret = (string) Tools::getValue('bounce_webhook_secret', '');
            if ($secret !== '') {
                Configuration::updateValue(BounceManager::CFG_WEBHOOK_SECRET, CryptoManager::encrypt($secret));
            }
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.bounce_config_saved'));
        }

        // ── Bounces : test connexion IMAP (AJAX) ─────────────────────
        if (Tools::getValue('neria_action') === 'test_imap_connection' && class_exists('BounceManager')) {
            // Précharge les valeurs du formulaire avant de tester
            Configuration::updateValue(BounceManager::CFG_IMAP_HOST,   (string) Tools::getValue('bounce_imap_host', ''));
            Configuration::updateValue(BounceManager::CFG_IMAP_PORT,   (int)    Tools::getValue('bounce_imap_port', 993));
            Configuration::updateValue(BounceManager::CFG_IMAP_USER,   (string) Tools::getValue('bounce_imap_user', ''));
            $p = (string) Tools::getValue('bounce_imap_pass', '');
            if ($p !== '') {
                Configuration::updateValue(BounceManager::CFG_IMAP_PASS, CryptoManager::encrypt($p));
            }
            Configuration::updateValue(BounceManager::CFG_IMAP_SSL,    (int) Tools::getValue('bounce_imap_ssl', 1));
            Configuration::updateValue(BounceManager::CFG_IMAP_FOLDER, (string) Tools::getValue('bounce_imap_folder', 'INBOX'));
            header('Content-Type: application/json; charset=utf-8');
            try {
                echo json_encode((new BounceManager($this))->testImapConnection());
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }

        // ── Bounces : lancer le check IMAP maintenant ────────────────
        if (Tools::getValue('neria_action') === 'run_bounce_check' && class_exists('BounceManager')) {
            $result = (new BounceManager($this))->checkBounceMailbox();
            $this->context->smarty->assign('bounce_run_result', $result);
        }

        // ── Bounces : ajouter manuellement ───────────────────────────
        if (Tools::getValue('neria_action') === 'add_manual_bounce' && class_exists('BounceManager')) {
            $email = trim((string) Tools::getValue('bounce_email', ''));
            $type  = Tools::getValue('bounce_type', 'hard') === 'soft' ? 'soft' : 'hard';
            if ($email !== '' && Validate::isEmail($email)) {
                (new BounceManager($this))->addManualBounce($email, $type);
                $this->context->smarty->assign('neria_success', AdminTranslator::tVars('msg.bounce_manual_added', ['email' => $email, 'type' => $type]));
            }
        }

        // ── Bounces : ignorer / réactiver / supprimer ────────────────
        if (in_array(Tools::getValue('neria_action'), ['ignore_bounce', 'reactivate_bounce', 'delete_bounce'], true)
            && class_exists('BounceManager')) {
            $email = trim((string) Tools::getValue('bounce_email', ''));
            $mgr   = new BounceManager($this);
            $action = Tools::getValue('neria_action');
            if ($email !== '') {
                if ($action === 'ignore_bounce') {
                    $mgr->ignoreBounce($email);
                    $msgKey = 'msg.bounce_ignored';
                } elseif ($action === 'reactivate_bounce') {
                    $mgr->reactivateBounce($email);
                    $msgKey = 'msg.bounce_reactivated';
                } else {
                    $mgr->deleteBounce($email);
                    $msgKey = 'msg.bounce_deleted';
                }
                $this->context->smarty->assign('neria_success', AdminTranslator::t($msgKey));
            }
        }

        // ── Certificat : configuration ────────────────────────────
        if (Tools::getValue('neria_action') === 'cert_save_config' && class_exists('CertificateManager')) {
            Configuration::updateGlobalValue(CertificateManager::CFG_ENABLED,       (int)    Tools::getValue('cert_enabled', 0));
            Configuration::updateGlobalValue(CertificateManager::CFG_SERIAL_PREFIX, pSQL(trim((string) Tools::getValue('cert_prefix', 'CERT'))));
            Configuration::updateGlobalValue(CertificateManager::CFG_TITLE,         pSQL(trim((string) Tools::getValue('cert_title', ''))));
            Configuration::updateGlobalValue(CertificateManager::CFG_SUBTITLE,      pSQL(trim((string) Tools::getValue('cert_subtitle', ''))));
            Configuration::updateGlobalValue(CertificateManager::CFG_BODY,          pSQL(trim((string) Tools::getValue('cert_body', ''))));
            Configuration::updateGlobalValue(CertificateManager::CFG_QR_ENABLED,    (int) Tools::getValue('cert_qr_enabled', 0));
            Configuration::updateGlobalValue(CertificateManager::CFG_QR_URL,        pSQL(trim((string) Tools::getValue('cert_qr_url', ''))));
            $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.certificate_config_saved'));
        }

        // ── Certificat : émission depuis fiche commande ───────────
        if (Tools::getValue('neria_action') === 'cert_issue' && class_exists('CertificateManager')) {
            $idOrder       = (int) Tools::getValue('cert_id_order', 0);
            $idProduct     = (int) Tools::getValue('cert_id_product', 0);
            $idOrderDetail = (int) Tools::getValue('cert_id_order_detail', 0);
            $serialOverride = trim((string) Tools::getValue('cert_serial', ''));
            $artisanNote    = trim((string) Tools::getValue('cert_note', ''));
            $sendEmail      = (bool) Tools::getValue('cert_send_email', 1);

            if ($idOrder > 0 && $idProduct > 0) {
                $err = (new CertificateManager($this))->issue(
                    $idOrder, $idProduct, $idOrderDetail,
                    $serialOverride, $artisanNote, $sendEmail
                );
                $this->context->smarty->assign(
                    $err === '' ? 'neria_success' : 'neria_error',
                    $err === '' ? AdminTranslator::t('msg.certificate_issued') : $err
                );
            }
        }

        // ── Certificat : re-téléchargement ────────────────────────
        if (Tools::getValue('neria_action') === 'cert_download' && class_exists('CertificateManager')) {
            $idCert = (int) Tools::getValue('id_certificate', 0);
            if ($idCert > 0) {
                $result = (new CertificateManager($this))->redownload($idCert);
                if (isset($result['error'])) {
                    $this->context->smarty->assign('neria_error', $result['error']);
                } else {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
                    header('Content-Length: ' . strlen($result['content']));
                    echo $result['content'];
                    exit;
                }
            }
        }

        // ── Certificat : suppression ──────────────────────────────
        if (Tools::getValue('neria_action') === 'cert_delete' && class_exists('CertificateManager')) {
            $idCert = (int) Tools::getValue('id_certificate', 0);
            if ($idCert > 0) {
                (new CertificateManager($this))->delete($idCert);
                $this->context->smarty->assign('neria_success', AdminTranslator::t('msg.certificate_deleted'));
            }
        }

        // ── RGPD : rapport PDF (rendu HTML print-ready) ───────────
        if (Tools::getValue('neria_action') === 'gdpr_pdf' && class_exists('GdprAuditManager')) {
            $audit    = (new GdprAuditManager(__DIR__))->runAudit();
            $shopName = Configuration::get('PS_SHOP_NAME') ?: 'Boutique';
            $html     = (new GdprAuditManager(__DIR__))->generateReport($audit, $shopName);
            echo $html;
            exit;
        }

        // ── Surveillance actions POST silencieuses ────────────────
        // Si une action a été soumise mais n'a assigné ni neria_success
        // ni neria_error, le marchand ne voit rien — on le logue.
        $this->checkSilentPostAction();

        // Détermine l'onglet actif (par défaut : configure)
        $activeTab = Tools::getValue('neria_tab', 'configure');

        // ── Instanciation des managers ────────────────────────────
        $config    = new ConfigManager($this);
        $stats     = new StatsManager($this);
        $calendar  = new CalendarManager($this);
        $fonts     = new FontManager($this);
        $signature = new SignatureGenerator($this);

        // ── Variables communes à tous les onglets ─────────────────
        // neria_msg_action : action POST qui vient d'être traitée. Écrite sur la
        // bannière de message (data-neria-action) pour que le JS la repositionne
        // dans la section concernée — robuste même au rechargement (Ctrl+F5).
        $this->context->smarty->assign([
            'link'             => $this->context->link,
            'neria_version'    => self::VERSION,
            'neria_module_dir' => $this->_path,
            'neria_active_tab' => $activeTab,
            'neria_msg_action' => (string) Tools::getValue('neria_action'),
            'neria_bo_lang'    => AdminTranslator::currentLang(),
            'neria_bo_dir'     => AdminTranslator::dir(),
            'neria_active'     => $config->isActive(),
            'auto_lang_enabled' => $config->isAutoLangEnabled(),
            'log_internal_enabled' => $config->isInternalLogEnabled(),
            'archive_email'        => (string) Configuration::getGlobalValue('NERIA_ARCHIVE_EMAIL'),
            'voucher_validity'        => $config->getVoucherValidity(),
            'firstname_fallbacks'          => $config->getFirstnameFallbacks(),
            'firstname_fallback_enabled'   => $config->isFirstnameFallbackEnabled(),
            'time_greetings'               => $config->getTimeGreetings(),
            'time_greeting_enabled'        => $config->isTimeGreetingEnabled(),
            'target_countries'             => $config->getTargetCountries(),
            'all_countries'                => ConfigManager::getAllCountries(),
            'cooldown_enabled'      => $config->isCooldownEnabled(),
            'cooldown_minutes'      => $config->getCooldownMinutes(),
            'smtp_daily_quota'      => (int) Configuration::get('NERIA_SMTP_DAILY_QUOTA'),
            'carbon_enabled'        => $config->isCarbonEnabled(),
            'carbon_link'           => $config->getCarbonLink(),
            'multi_sender_enabled'  => $config->isMultiSenderEnabled(),
            'signature_enabled'     => $config->isSignatureEnabled(),
            'senders_config'        => $config->getAllSenders(),
            'blacklist'             => (new BlacklistManager())->getAll(),
            'report_enabled'    => $this->getReportEnabledConfig(),
            'report_recipients' => (string) Configuration::get(MonthlyReportManager::CONFIG_RECIPIENTS),
            'report_last_sent'  => (string) Configuration::get(MonthlyReportManager::CONFIG_LAST_SENT),
            'neria_tabs'       => $this->getBackOfficeTabs(),

            // Libellés et drapeaux des 18 langues supportées
            'lang_labels'      => NeriaTools::getLangLabels(),
            'lang_flags'       => NeriaTools::getLangFlags(),

            // Libellés des 125 templates, traduits dans la langue du BO
            'template_labels'  => AdminTranslator::templateLabels(),

            // Clé DeepL pour la traduction automatique
            'deepl_key'        => CryptoManager::decrypt((string) $config->get(ConfigManager::KEY_DEEPL_KEY, '')),

            // Configuration design (couleurs, logo, typo…)
            'design'           => $config->getDesignConfig(),

            // Styles rapides ("One-Click Apply") — onglet Design
            'design_presets'      => ConfigManager::DESIGN_PRESETS,
            'design_wizard_seen'  => (bool) $config->get(ConfigManager::KEY_DESIGN_WIZARD_SEEN),

            // Variables personnalisées du marchand — transformées en tableau associatif
            // getCustomVariables() retourne [['variable_key'=>'...','variable_value'=>'...'], ...]
            // configure.tpl accède via $custom_vars.maison_name → tableau associatif requis
            'custom_vars'      => array_column(
                $config->getCustomVariables(),
                'variable_value',
                'variable_key'
            ),

            // Liens réseaux sociaux configurés
            'social_links'     => $config->getSocialLinks(),

            // KPIs des 30 derniers jours (onglet configure)
            'kpis'             => $stats->getKpis(30),

            // Rapports complets pour stats.tpl ($stats.kpis, $stats.global_30, etc.)
            'stats'            => (function () use ($stats): array {
                $statsDays = (int) Tools::getValue('stats_days', 30);
                $cached    = $stats->getCachedReports();
                // Injecte la clé 'kpis' selon la période sélectionnée
                if ($statsDays === 7 && !empty($cached['kpis_7'])) {
                    $cached['kpis'] = $cached['kpis_7'];
                } elseif (!empty($cached['kpis_30'])) {
                    $cached['kpis'] = $cached['kpis_30'];
                } else {
                    $cached['kpis'] = $stats->getKpis($statsDays);
                }
                return $cached;
            })(),
            'stats_days'       => (int) Tools::getValue('stats_days', 30),
            'golden_hour'      => (new GoldenHourManager())->getRecommendations(90),
            'revenue'          => (new StatsManager($this))->getRevenueStats(90),
            'currency_symbol'  => $this->context->currency->sign ?? '€',

            // Graphique CA par catégorie — 4 périodes (stats.tpl)
            'revenue_chart_7'   => json_encode($stats->getRevenueDailyByCategory(7)),
            'revenue_chart_30'  => json_encode($stats->getRevenueDailyByCategory(30)),
            'revenue_chart_90'  => json_encode($stats->getRevenueDailyByCategory(90)),
            'revenue_chart_365' => json_encode($stats->getRevenueDailyByCategory(365)),

            // Statistiques avancées — nouveaux blocs stats.tpl
            'kpi_trends'              => $stats->getKpiTrends(),
            'engagement_chart_30'     => json_encode($stats->getEngagementDailyChart(30)),
            'engagement_chart_90'     => json_encode($stats->getEngagementDailyChart(90)),
            'open_heatmap'            => json_encode($stats->getOpenHeatmap(90)),
            'top_templates_open'      => $stats->getTopTemplatesByMetric('rate_open', 10),
            'top_templates_click'     => $stats->getTopTemplatesByMetric('rate_click', 10),
            'top_templates_revenue'   => $stats->getTopTemplatesByRevenue(10),
            'monthly_comparison'      => $stats->getMonthlyComparison(),
            'health_score'            => $stats->getHealthScore(),

            // Prochaines occasions calendaires (onglet configure)
            'upcoming_events'  => $calendar->getUpcomingDates(),

            // Polices : $font_scripts = metadata scripts, $fonts_by_script = polices par script
            'font_scripts'     => $fonts->getAllScripts(),
            'fonts_by_script'  => array_combine(
                array_keys($fonts->getAllScripts()),
                array_map(
                    fn($script) => $fonts->getFontsForScript($script),
                    array_keys($fonts->getAllScripts())
                )
            ),
            'current_fonts'             => $config->getTypographyConfig(),
            'typography_font_size'      => $config->getDesignConfig()['font_size']      ?? 14,
            'typography_line_height'    => $config->getDesignConfig()['line_height']    ?? 1.8,
            'typography_heading_weight' => $config->getDesignConfig()['heading_weight'] ?? 600,

            // Styles de signature disponibles (onglet configure)
            'signature_styles' => SignatureGenerator::STYLES,
            'current_signature' => $config->getSignatureConfig(),

            // Diagnostic complet pour l'onglet Aide
            'diagnostic'       => NeriaTools::getDiagnosticReport($this),
            'health_results'   => (new HealthCheckManager($this))->getLastResults(),
            'health_last_run'  => (string) Configuration::get(HealthCheckManager::CONFIG_LAST_RUN),

            // Alertes email Watchdog
            'alert_email'      => (string) Configuration::getGlobalValue(WatchdogManager::CFG_ALERT_EMAIL),
            'alert_immediate'  => (bool) Configuration::getGlobalValue(WatchdogManager::CFG_ALERT_IMMEDIATE),
            'alert_digest'     => (bool) Configuration::getGlobalValue(WatchdogManager::CFG_ALERT_DIGEST),

            // URL d'urgence Watchdog (page autonome sans PS)
            'emergency_token'  => (string) Configuration::getGlobalValue('NERIA_EMERGENCY_TOKEN'),
            'emergency_url'    => Tools::getShopDomainSsl(true)
                . __PS_BASE_URI__ . 'modules/neria/neria-emergency.php?token='
                . urlencode((string) Configuration::getGlobalValue('NERIA_EMERGENCY_TOKEN')),

            // URL du point d'entrée cron externe (surveillance active — recommandé)
            'cron_enabled'     => (bool) Configuration::getGlobalValue('NERIA_CRON_ENABLED'),
            'cron_token'       => (string) Configuration::getGlobalValue('NERIA_CRON_TOKEN'),
            'cron_url'         => Tools::getShopDomainSsl(true) . __PS_BASE_URI__
                . 'index.php?fc=module&module=neria&controller=cron&token='
                . urlencode((string) Configuration::getGlobalValue('NERIA_CRON_TOKEN')),
            'cron_last_hit'    => class_exists('WatchdogManager') ? (new WatchdogManager($this))->getLastCronEndpointHit() : null,

            // Journal watchdog pour l'onglet Aide — messages traduits à l'affichage
            'logs'             => $this->translateWatchdogLogs((new WatchdogManager($this))->getLogs(100)),
            'log_counts'       => (new WatchdogManager($this))->getCountByLevel(),
            'log_templates'    => (new WatchdogManager($this))->getTemplatesWithErrors(),

            // Watchdog v2 — score santé, crons, queue, anomalies métriques
            'watchdog_health'  => (new WatchdogManager($this))->getWatchdogHealthScore(),
            'anomaly_warnings' => class_exists('StatsManager') ? (new StatsManager($this))->detectAnomalies() : [],

            // Variables pour send.tpl (envoi manuel — vague 1)
            // Libellés des champs traduits dans la langue du back-office.
            'send_templates'    => (new ManualSendManager($this))->getSendableTemplates(),
            'send_editable_map' => (new ManualSendManager($this))->getEditableFieldsMap(
                $this->context->language->iso_code
            ),
            'send_queue_pending' => class_exists('QueueManager')
                ? (new QueueManager($this))->getPendingManual()
                : [],
            'send_queue_pending_total' => class_exists('QueueManager')
                ? (new QueueManager($this))->countPendingManual()
                : 0,

            // Variables pour abtest.tpl
            'eligible_templates' => (new ABTestManager($this))->getEligibleTemplates(),
            'tests_status'       => $this->getAbtestStatusMap(new ABTestManager($this)),
            'tests_data'         => $this->getAbtestDataMap(new ABTestManager($this)),
            'ab_reports'         => $this->getAbtestReportsMap($stats, new ABTestManager($this)),
            'ab_history'         => class_exists('ABTestManager') ? (new ABTestManager($this))->getHistory(30) : [],

            // Rapport A/B focalisé — utilisé par stats.tpl quand on arrive via "Voir les stats"
            'abtest_focus_key'   => preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('abtest_template', '')),

            // Variables pour calendar.tpl
            'calendar_events'     => array_map(function ($ev) use ($calendar) {
                $ev['display_info'] = $calendar->getEventDisplayInfo($ev);
                return $ev;
            }, Db::getInstance()->executeS(
                'SELECT * FROM `' . _DB_PREFIX_ . 'neria_calendar_event`
                 WHERE `id_shop` = ' . (int) $this->context->shop->id . '
                 ORDER BY `event_key` ASC, `lang` ASC'
            ) ?: []),
            'calendar_templates'  => $this->getCalendarTemplatesList(),
            'calendar_known_keys' => $this->getCalendarKnownKeys(),
            'calendar_countries'  => $this->getCountriesListForSelect(),

            // Variables pour webhooks.tpl
            'webhook_url'         => (string) Configuration::get(WebhookManager::CONFIG_URL),
            'webhook_secret'      => CryptoManager::decrypt((string) Configuration::get(WebhookManager::CONFIG_SECRET)),
            'webhook_events'      => json_decode(
                (string) Configuration::get(WebhookManager::CONFIG_EVENTS), true
            ) ?? [],
            'webhook_all_events'  => WebhookManager::ALL_EVENTS,
            'webhook_deliveries'  => (new WebhookManager($this))->getRecentDeliveries(10),

            // Variables pour segments.tpl
            'segment_counts'      => class_exists('SegmentManager')
                ? (new SegmentManager($this))->getSegmentCounts()
                : [],
            'segment_customers'   => (function () use ($activeTab) {
                if (!class_exists('SegmentManager')) {
                    return [];
                }
                $seg = (string) Tools::getValue('filter_segment', '');
                if ($seg === '' && $activeTab === 'segments') {
                    $seg = SegmentManager::AMBASSADOR;
                }
                return $seg !== '' ? (new SegmentManager($this))->getCustomersBySegment($seg, 50) : [];
            })(),
            'segment_filter'      => (string) Tools::getValue('filter_segment', SegmentManager::AMBASSADOR),
            'segment_all'         => class_exists('SegmentManager') ? SegmentManager::getAllSegments() : [],
            'segment_campaign_templates' => class_exists('SegmentManager') ? SegmentManager::CAMPAIGN_TEMPLATES : [],
            'segment_recommended' => class_exists('SegmentManager') ? SegmentManager::RECOMMENDED_TEMPLATES : [],
            'segment_languages'   => [
                ['iso' => 'fr', 'name' => 'Français'],
                ['iso' => 'en', 'name' => 'English'],
                ['iso' => 'de', 'name' => 'Deutsch'],
                ['iso' => 'it', 'name' => 'Italiano'],
                ['iso' => 'es', 'name' => 'Español'],
                ['iso' => 'pt', 'name' => 'Português (PT)'],
                ['iso' => 'br', 'name' => 'Português (BR)'],
                ['iso' => 'ar', 'name' => 'العربية'],
                ['iso' => 'ja', 'name' => '日本語'],
                ['iso' => 'ko', 'name' => '한국어'],
                ['iso' => 'zh', 'name' => '中文简体'],
                ['iso' => 'tw', 'name' => '中文繁體'],
                ['iso' => 'ru', 'name' => 'Русский'],
                ['iso' => 'tr', 'name' => 'Türkçe'],
                ['iso' => 'sv', 'name' => 'Svenska'],
                ['iso' => 'no', 'name' => 'Norsk'],
                ['iso' => 'da', 'name' => 'Dansk'],
                ['iso' => 'nl', 'name' => 'Nederlands'],
            ],
            'segment_countries'   => $this->getSegmentCountries(),
            'segment_slots'       => [
                'morning'   => 'Matin (6h–12h)',
                'afternoon' => 'Après-midi (12h–18h)',
                'evening'   => 'Soir (18h–22h)',
                'night'     => 'Nuit (22h–6h)',
            ],

            // Variables pour la section Churn dans segments.tpl
            'churn_high_risk'     => class_exists('ChurnScoreManager')
                ? (new ChurnScoreManager($this))->getHighRiskCustomers(30)
                : [],
            'churn_threshold'     => ChurnScoreManager::HIGH_RISK_THRESHOLD ?? 70,

            // Variables CLV — potentiel client 12 mois (segments.tpl + fiche client)
            'clv_top_customers'   => ($activeTab === 'segments' && class_exists('ClvManager'))
                ? (new ClvManager($this))->getTopCustomers(20)
                : [],

            // Variables pour la section Score de propension (onglet Statistiques)
            'propensity_enabled'     => (bool) Configuration::getGlobalValue('NERIA_PROPENSITY_ENABLED'),
            'propensity_alerts'      => class_exists('PropensityScoreManager')
                ? (new PropensityScoreManager($this))->getAlertCustomers(20)
                : [],
            'propensity_threshold'   => PropensityScoreManager::ALERT_THRESHOLD,

            // Fenêtre d'achat individuelle (onglet Statistiques)
            'purchase_window_enabled' => (bool) Configuration::getGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED'),
            'purchase_window_stats'   => class_exists('QueueManager')
                ? (new QueueManager($this))->getStats()
                : ['pending' => 0, 'sent_30d' => 0, 'failed_30d' => 0, 'avg_delay_min' => null, 'coverage_pct' => 0, 'peak_hour' => null],

            // Variables pour la section Rappel fin de vie produit (onglet Statistiques)
            'lifespan_enabled'  => (bool) Configuration::getGlobalValue('NERIA_LIFESPAN_ENABLED'),
            'lifespan_products' => Db::getInstance()->executeS(
                'SELECT pl.id_lifespan, pl.id_product, pl.lifespan_days, pl.alert_days,
                        pla.name AS product_name, p.reference
                 FROM `' . _DB_PREFIX_ . 'neria_product_lifespan` pl
                 LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_product = pl.id_product
                 LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pla
                      ON pla.id_product = pl.id_product
                      AND pla.id_lang = ' . (int) $this->context->language->id . '
                      AND pla.id_shop = pl.id_shop
                 WHERE pl.id_shop = ' . (int) $this->context->shop->id . '
                 ORDER BY pla.name ASC'
            ) ?: [],

            // Variables pour la section Réconciliation post-remboursement (onglet Statistiques)
            'reconciliation_enabled' => (bool) Configuration::getGlobalValue('NERIA_REFUND_RECONCILIATION_ENABLED'),
            'reconciliation_stats'   => Db::getInstance()->getRow(
                'SELECT
                    COUNT(*) AS total,
                    SUM(sent_1) AS step1_sent,
                    SUM(sent_2) AS step2_sent,
                    SUM(sent_3) AS step3_sent,
                    SUM(IF(status = \'cancelled\', 1, 0)) AS cancelled,
                    SUM(IF(status = \'active\' AND sent_3 = 1, 1, 0)) AS completed
                 FROM `' . _DB_PREFIX_ . 'neria_reconciliation`
                 WHERE `id_shop` = ' . (int) $this->context->shop->id
            ) ?: ['total' => 0, 'step1_sent' => 0, 'step2_sent' => 0, 'step3_sent' => 0, 'cancelled' => 0, 'completed' => 0],

            // Variables pour la section Devis B2B (onglet Statistiques)
            'quote_reminders_enabled' => (bool) Configuration::getGlobalValue('NERIA_QUOTE_REMINDERS_ENABLED'),
            'quote_stats'             => class_exists('BehavioralCronManager')
                ? (new BehavioralCronManager($this))->getQuoteStats()
                : ['total_quotes' => 0, 'quotes_won' => 0, 'quotes_active' => 0, 'quotes_lost' => 0, 'revenue_won' => 0.0, 'win_rate' => 0.0],
            'quote_list'              => Db::getInstance()->executeS(
                'SELECT q.*, CONCAT(c.firstname, " ", c.lastname) AS customer_name, c.email
                 FROM `' . _DB_PREFIX_ . 'neria_quote` q
                 LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = q.id_customer
                 WHERE q.id_shop = ' . (int) $this->context->shop->id . '
                 ORDER BY q.expiry_date ASC LIMIT 50'
            ) ?: [],

            'look_completion_enabled'   => (bool) Configuration::getGlobalValue('NERIA_LOOK_COMPLETION_ENABLED'),
            'collection_completion_enabled' => (bool) Configuration::getGlobalValue('NERIA_COLLECTION_COMPLETION_ENABLED'),

            // Variables pour la section Complétez votre look
            'look_rules'      => class_exists('LookCompletionManager')
                ? (new LookCompletionManager($this))->getAllRules()
                : [],
            'look_stats'      => class_exists('LookCompletionManager')
                ? (new LookCompletionManager($this))->getStats()
                : ['rules' => 0, 'active' => 0, 'sent' => 0, 'sent30' => 0],
            'look_categories' => class_exists('LookCompletionManager')
                ? (new LookCompletionManager($this))->getCategories()
                : [],

            // Variables pour la section Complétion de collection
            'collections'      => class_exists('CollectionManager')
                ? (new CollectionManager($this))->getAll()
                : [],
            'collection_stats' => class_exists('CollectionManager')
                ? (new CollectionManager($this))->getStats()
                : ['total' => 0, 'active' => 0, 'sent' => 0, 'sentLast30' => 0],

            // Variables pour la section Liste d'attente
            'waitlist_enabled'           => (bool) Configuration::getGlobalValue('NERIA_WAITLIST_ENABLED'),
            'waitlist_reservation_hours' => (int) Configuration::getGlobalValue('NERIA_WAITLIST_RESERVATION_HOURS') ?: 4,
            'waitlist_stats'    => class_exists('WaitlistManager')
                ? (new WaitlistManager($this))->getStats()
                : ['subscribers' => 0, 'products' => 0, 'notified' => 0, 'notified30' => 0],
            'waitlist_top_products' => class_exists('WaitlistManager')
                ? (new WaitlistManager($this))->getTopProducts(10)
                : [],

            // Panier fantôme récurrent
            'ghost_cart_enabled' => (bool) Configuration::getGlobalValue('NERIA_GHOST_CART_ENABLED'),

            // Variables pour la section Abandon de Caisse dans send.tpl
            'relationship_anniversary_enabled' => (bool) Configuration::getGlobalValue('NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED'),
            'relationship_anniversary_stats'   => class_exists('BehavioralCronManager')
                ? (new BehavioralCronManager($this))->getRelationshipAnniversaryStats()
                : ['emails_sent' => 0, 'orders_attributed' => 0, 'revenue_attributed' => 0.0, 'avg_order_value' => 0.0],

            'checkout_abandonment_enabled' => (bool) Configuration::getGlobalValue('NERIA_CHECKOUT_ABANDONMENT_ENABLED'),
            'checkout_abandonment_stats'   => class_exists('BehavioralCronManager')
                ? (new BehavioralCronManager($this))->getCheckoutAbandonmentStats()
                : ['emails_sent' => 0, 'orders_recovered' => 0, 'revenue_recovered' => 0.0, 'conversion_rate' => 0.0],

            // Variables pour la section Upsell (onglet Statistiques)
            'upsell_enabled'      => (bool) Configuration::getGlobalValue('NERIA_UPSELL_ENABLED'),
            'upsell_stats'        => class_exists('UpsellManager')
                ? (new UpsellManager($this))->getStats(90)
                : [],
            'upsell_log'          => class_exists('UpsellManager')
                ? (new UpsellManager($this))->getLog((int) $this->context->language->id, 30)
                : [],
            'upsell_action_url'   => $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name,

            // Réputation de domaine (onglet stats) — lecture du cache uniquement
            'domain_reputation' => class_exists('DomainReputationManager')
                ? (new DomainReputationManager($this))->getReport(false)
                : null,

            // Google Postmaster Tools
            'postmaster_configured'  => class_exists('PostmasterManager') && (new PostmasterManager($this))->isConfigured(),
            'postmaster_connected'   => class_exists('PostmasterManager') && (new PostmasterManager($this))->isConnected(),
            'pm_redirect_uri'        => class_exists('PostmasterManager') ? (new PostmasterManager($this))->getRedirectUri() : '',
            'postmaster_client_id'   => class_exists('PostmasterManager') ? (string) Configuration::get(PostmasterManager::CONFIG_CLIENT_ID) : '',
            'postmaster_stats'       => (function () {
                if (!class_exists('PostmasterManager')) {
                    return null;
                }
                $mgr = new PostmasterManager($this);
                if (!$mgr->isConnected()) {
                    return null;
                }
                return $mgr->getCachedStats();
            })(),
            'postmaster_cache_age'   => class_exists('PostmasterManager') ? (new PostmasterManager($this))->getCacheAge() : null,

            // ── Visibilité boutique ──────────────────────────────────
            // PageSpeed Insights
            'pagespeed_configured'  => class_exists('PageSpeedManager') && (new PageSpeedManager($this))->isConfigured(),
            'pagespeed_api_key'     => class_exists('PageSpeedManager') ? CryptoManager::decrypt((string) Configuration::get(PageSpeedManager::CONFIG_API_KEY)) : '',
            'pagespeed_target_url'  => class_exists('PageSpeedManager') ? (string) Configuration::get(PageSpeedManager::CONFIG_TARGET_URL) : '',
            'pagespeed_last_error'  => (string) Configuration::get('NERIA_PAGESPEED_LAST_ERROR'),
            'pagespeed_report'     => (function () {
                if (!class_exists('PageSpeedManager')) {
                    return null;
                }
                $mgr = new PageSpeedManager($this);
                if (!$mgr->isConfigured()) {
                    return null;
                }
                return $mgr->getCachedReport();
            })(),
            'pagespeed_cache_age'  => class_exists('PageSpeedManager') ? (new PageSpeedManager($this))->getCacheAge() : null,

            // Google Search Console
            'searchconsole_configured' => class_exists('SearchConsoleManager') && (new SearchConsoleManager($this))->isConfigured(),
            'searchconsole_connected'  => class_exists('SearchConsoleManager') && (new SearchConsoleManager($this))->isConnected(),
            'searchconsole_client_id'  => class_exists('SearchConsoleManager') ? (string) Configuration::get(SearchConsoleManager::CONFIG_CLIENT_ID) : '',
            'sc_redirect_uri'          => class_exists('SearchConsoleManager') ? (new SearchConsoleManager($this))->getRedirectUri() : '',
            'searchconsole_stats'      => (function () {
                if (!class_exists('SearchConsoleManager')) {
                    return null;
                }
                $mgr = new SearchConsoleManager($this);
                if (!$mgr->isConnected()) {
                    return null;
                }
                return $mgr->getCachedStats();
            })(),
            'searchconsole_cache_age'  => class_exists('SearchConsoleManager') ? (new SearchConsoleManager($this))->getCacheAge() : null,

            // SEO API payante (Semrush / Moz)
            'seo_provider'     => class_exists('SeoApiManager') ? (new SeoApiManager($this))->getProvider() : '',
            'seo_configured'   => class_exists('SeoApiManager') && (new SeoApiManager($this))->isConfigured(),
            'seo_semrush_key'  => class_exists('SeoApiManager') ? CryptoManager::decrypt((string) Configuration::get(SeoApiManager::CONFIG_SEMRUSH_KEY)) : '',
            'seo_moz_access'   => class_exists('SeoApiManager') ? CryptoManager::decrypt((string) Configuration::get(SeoApiManager::CONFIG_MOZ_ACCESS)) : '',
            'seo_providers'    => class_exists('SeoApiManager') ? SeoApiManager::PROVIDERS : [],
            'seo_report'       => (function () {
                if (!class_exists('SeoApiManager')) {
                    return null;
                }
                $mgr = new SeoApiManager($this);
                if (!$mgr->isConfigured()) {
                    return null;
                }
                return $mgr->getCachedReport();
            })(),
            'seo_cache_age'    => class_exists('SeoApiManager') ? (new SeoApiManager($this))->getCacheAge() : null,

            // Variables pour la section Fidélité dans configure.tpl
            'loyalty_enabled'     => (bool) Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED'),
            'loyalty_tiers'       => class_exists('LoyaltyManager')
                ? (new LoyaltyManager($this))->getTiers()
                : LoyaltyManager::DEFAULT_TIERS,
            'loyalty_global_stats' => class_exists('LoyaltyManager') && Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')
                ? (new LoyaltyManager($this))->getGlobalStats()
                : null,
            'loyalty_top_customers' => class_exists('LoyaltyManager') && Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')
                ? (new LoyaltyManager($this))->getTopCustomers(10)
                : [],

            // Variables pour seasonal.tpl (campagnes saisonnières)
            'seasonal_campaigns'  => class_exists('SeasonalCampaignManager')
                ? (new SeasonalCampaignManager($this))->getAll()
                : [],
            'seasonal_calendar'   => class_exists('SeasonalCampaignManager')
                ? (new SeasonalCampaignManager($this))->getCalendarData()
                : array_fill(1, 12, []),
            'seasonal_edit'       => (function () {
                $editId = (int) Tools::getValue('edit_campaign', 0);
                if ($editId > 0 && class_exists('SeasonalCampaignManager')) {
                    return (new SeasonalCampaignManager($this))->getById($editId);
                }
                return null;
            })(),
            'seasonal_edit_seg_map' => (function () {
                $editId = (int) Tools::getValue('edit_campaign', 0);
                if ($editId > 0 && class_exists('SeasonalCampaignManager')) {
                    $c = (new SeasonalCampaignManager($this))->getById($editId);
                    if ($c && $c['target_segment'] !== '') {
                        return array_flip(array_filter(array_map('trim', explode(',', $c['target_segment']))));
                    }
                }
                return [];
            })(),
            'seasonal_edit_lang_map' => (function () {
                $editId = (int) Tools::getValue('edit_campaign', 0);
                if ($editId > 0 && class_exists('SeasonalCampaignManager')) {
                    $c = (new SeasonalCampaignManager($this))->getById($editId);
                    if ($c && $c['target_lang'] !== '') {
                        return array_flip(array_filter(array_map('trim', explode(',', $c['target_lang']))));
                    }
                }
                return [];
            })(),
            'seasonal_templates'  => class_exists('ManualSendManager')
                ? (new ManualSendManager($this))->getSendableTemplates()
                : [],
            'seasonal_segments'   => class_exists('SegmentManager')
                ? SegmentManager::getAllSegments()
                : [],
            'ac'                  => $this->loadAcademyStrings(),
            // ── Bounces ──────────────────────────────────────────────
            'bounce_stats'          => class_exists('BounceManager') ? (new BounceManager($this))->getBounceStats() : [],
            'bounce_enabled'        => (bool) Configuration::get(BounceManager::CFG_ENABLED),
            'bounce_soft_threshold' => (int) Configuration::get(BounceManager::CFG_SOFT_THRESHOLD) ?: 3,
            'bounce_webhook_url'    => class_exists('BounceManager') ? BounceManager::getWebhookUrl() : '',
            'bounce_webhook_secret' => CryptoManager::decrypt((string) Configuration::get(BounceManager::CFG_WEBHOOK_SECRET)),
            'bounce_cfg'            => [
                'host'   => (string) Configuration::get(BounceManager::CFG_IMAP_HOST),
                'port'   => (int)    Configuration::get(BounceManager::CFG_IMAP_PORT) ?: 993,
                'user'   => (string) Configuration::get(BounceManager::CFG_IMAP_USER),
                'pass'   => CryptoManager::decrypt((string) Configuration::get(BounceManager::CFG_IMAP_PASS)),
                'ssl'    => (bool)   Configuration::get(BounceManager::CFG_IMAP_SSL),
                'folder' => (string) Configuration::get(BounceManager::CFG_IMAP_FOLDER) ?: 'INBOX',
            ],

            // ── Certificats d'authenticité ───────────────────────────
            'cert_enabled'     => (bool) Configuration::getGlobalValue(CertificateManager::CFG_ENABLED),
            'cert_prefix'      => (string) Configuration::getGlobalValue(CertificateManager::CFG_SERIAL_PREFIX) ?: 'CERT',
            'cert_title'       => (string) Configuration::getGlobalValue(CertificateManager::CFG_TITLE),
            'cert_subtitle'    => (string) Configuration::getGlobalValue(CertificateManager::CFG_SUBTITLE),
            'cert_body'        => (string) Configuration::getGlobalValue(CertificateManager::CFG_BODY),
            'cert_qr_enabled'  => (bool) Configuration::getGlobalValue(CertificateManager::CFG_QR_ENABLED),
            'cert_qr_url'      => (string) Configuration::getGlobalValue(CertificateManager::CFG_QR_URL),
            'cert_list'        => class_exists('CertificateManager')
                ? (new CertificateManager($this))->getAll(50)
                : [],
            'cert_count'       => class_exists('CertificateManager')
                ? (new CertificateManager($this))->countAll()
                : 0,
            'cert_stats'       => class_exists('CertificateManager')
                ? (new CertificateManager($this))->getStats()
                : null,
        ] + $this->getBounceListVars());

        // Charge le rapport A/B focalisé si un template est ciblé
        $focusKey = preg_replace('/[^a-z0-9_\-]/i', '', (string) Tools::getValue('abtest_template', ''));
        if ($focusKey !== '' && class_exists('ABTestManager')) {
            $abMgr = new ABTestManager($this);
            $rows  = $abMgr->getTestsByTemplate($focusKey);
            if ($rows) {
                $focusData = [];
                foreach ($rows as $row) {
                    $focusData[strtolower($row['variant'])] = $row;
                }
                $focusReport = $stats->getABTestReport($focusKey);
                $focusReport['significance'] = $stats->computeSignificance(
                    $focusReport['A'] ?? [],
                    $focusReport['B'] ?? []
                );
                $this->context->smarty->assign([
                    'abtest_focus'        => $focusData,
                    'abtest_focus_report' => $focusReport,
                    'abtest_focus_label'  => AdminTranslator::templateLabels()[$focusKey] ?? $focusKey,
                ]);
            }
        }

        // ── Réseaux sociaux ───────────────────────────────────────
        $this->context->smarty->assign('social_networks', [
            'instagram' => [
                'icon'        => '◉',
                'label'       => $this->l('Instagram'),
                'placeholder' => 'https://instagram.com/votre_compte',
            ],
            'pinterest' => [
                'icon'        => '⊕',
                'label'       => $this->l('Pinterest'),
                'placeholder' => 'https://pinterest.com/votre_compte',
            ],
            'facebook' => [
                'icon'        => '◈',
                'label'       => $this->l('Facebook'),
                'placeholder' => 'https://facebook.com/votre_page',
            ],
            'twitter' => [
                'icon'        => '◇',
                'label'       => $this->l('X (Twitter)'),
                'placeholder' => 'https://x.com/votre_compte',
            ],
            'youtube' => [
                'icon'        => '▷',
                'label'       => $this->l('YouTube'),
                'placeholder' => 'https://youtube.com/@votre_chaine',
            ],
            'tiktok' => [
                'icon'        => '◎',
                'label'       => $this->l('TikTok'),
                'placeholder' => 'https://tiktok.com/@votre_compte',
            ],
        ]);

        // ── Onglet « Historique clients » : client sélectionné ────
        if ($activeTab === 'customer_history') {
            $this->prepareCustomerHistoryTab();

            // URL de base pour les liens (résultats de recherche + chips récents)
            $histBaseUrl = $_SERVER['REQUEST_URI'] ?? '';
            $histBaseUrl = preg_replace('/&?neria_hist_q=[^&]*/', '', $histBaseUrl);
            $histBaseUrl = preg_replace('/&?neria_hist_customer=[^&]*/', '', $histBaseUrl);
            $histBaseUrl = rtrim($histBaseUrl, '?&');
            $this->context->smarty->assign('neria_hist_search_base', $histBaseUrl);

            // Recherche via formulaire GET (neria_hist_q)
            $histQ = trim((string) Tools::getValue('neria_hist_q', ''));
            if ($histQ !== '') {
                $results = strlen($histQ) >= 2 ? $this->searchCustomersForHistory($histQ) : [];
                $this->context->smarty->assign([
                    'neria_hist_q'              => $histQ,
                    'neria_hist_search_results' => $results,
                ]);
            }
        }

        // ── Onglet « Prévisualisation multi-client » ──────────────
        if ($activeTab === 'multipreview' && class_exists('MultiClientPreviewManager')) {
            $mpMgr = new MultiClientPreviewManager();
            $this->context->smarty->assign([
                'mp_clients'    => MultiClientPreviewManager::CLIENTS,
                'mp_has_litmus' => $mpMgr->hasLitmusKey(),
                'mp_has_eoa'    => $mpMgr->hasEoaKey(),
                'mp_litmus_key' => $mpMgr->hasLitmusKey() ? '••••••••' : '',
                'mp_eoa_key'    => $mpMgr->hasEoaKey()    ? '••••••••' : '',
            ]);
        }

        // ── RGPD : audit chargé à la demande (onglet actif uniquement) ──
        if ($activeTab === 'gdpr' && class_exists('GdprAuditManager')) {
            $this->context->smarty->assign(
                'gdpr_audit',
                (new GdprAuditManager(__DIR__))->runAudit()
            );
        }

        // Feedback transmis en GET (redirect post-toggle, callback OAuth
        // Postmaster/Search Console…) — doit être assigné AVANT le rendu de
        // navigation.tpl, seul template affichant la bannière neria_success/
        // neria_error. Ne pas écraser un feedback déjà assigné par une
        // action POST traitée plus haut dans cette méthode.
        $existingVars = $this->context->smarty->getTemplateVars();
        if (empty($existingVars['neria_success']) && empty($existingVars['neria_error'])) {
            $getSuccess = (string) Tools::getValue('neria_success');
            $getError   = (string) Tools::getValue('neria_error');
            if ($getSuccess !== '') {
                $this->context->smarty->assign('neria_success', $getSuccess);
            } elseif ($getError !== '') {
                $this->context->smarty->assign('neria_error', $getError);
            }
        }

        // ── Rendu navigation + contenu ────────────────────────────
        $navigation = $this->renderTemplate('navigation.tpl');
        $content    = $this->renderTab($activeTab);

        return $navigation
            . '<div class="neria-bo-content">'
            . $content
            . '</div>';
    }

    /** Variables Smarty pour la liste paginée des bounces (onglet bounces). */
    private function getBounceListVars(): array
    {
        if (!class_exists('BounceManager')) {
            return ['bounce_list' => [], 'bounce_count' => 0, 'bounce_filter' => '', 'bounce_page' => 1, 'bounce_total_pages' => 1];
        }
        $mgr    = new BounceManager($this);
        $filter = trim((string) Tools::getValue('nb_filter', ''));
        $page   = max(1, (int) Tools::getValue('nb_page', 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;
        $total  = $mgr->getBounceCount($filter);
        return [
            'bounce_list'        => $mgr->getBounceList($limit, $offset, $filter),
            'bounce_count'       => $total,
            'bounce_filter'      => $filter,
            'bounce_page'        => $page,
            'bounce_total_pages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * Envoie un email de test au marchand
     * Utilise le template "test" pour vérifier que le rendu fonctionne
     */
    /**
     * Génère un PDF du journal Watchdog et l'envoie par email.
     * Retourne '' si succès, message d'erreur sinon.
     */
    private function sendWatchdogLogByEmail(): string
    {
        // Destinataire
        $to = (string) Configuration::getGlobalValue(WatchdogManager::CFG_ALERT_EMAIL);
        if ($to === '' || !Validate::isEmail($to)) {
            $to = (string) Configuration::get('PS_SHOP_EMAIL');
        }
        if (!Validate::isEmail($to)) {
            return 'Aucun email destinataire configuré.';
        }

        // Récupère les 200 derniers logs
        $table = _DB_PREFIX_ . 'neria_log';
        $idShop = (int) $this->context->shop->id;
        $logs = Db::getInstance()->executeS(
            "SELECT `date_add`, `level`, `class`, `template`, `message`
             FROM `{$table}`
             WHERE `id_shop` = {$idShop}
             ORDER BY `date_add` DESC
             LIMIT 200"
        );
        if (empty($logs)) {
            return 'Le journal est vide.';
        }

        // Charge TCPDF (inclus dans PrestaShop)
        $tcpdfPath = _PS_ROOT_DIR_ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (!file_exists($tcpdfPath)) {
            return 'TCPDF introuvable (' . $tcpdfPath . ').';
        }
        require_once $tcpdfPath;

        $shopName   = (string) Configuration::get('PS_SHOP_NAME');
        $shopDomain = Tools::getShopDomainSsl(true);
        $now        = date('d/m/Y H:i');

        // Génère le HTML du tableau
        $rows = '';
        $colors = ['error' => '#ffebee', 'critical' => '#fce4e4', 'warning' => '#fffde7', 'info' => '#ffffff'];
        foreach ($logs as $log) {
            $bg   = $colors[$log['level']] ?? '#ffffff';
            $lvl  = strtoupper(htmlspecialchars($log['level']));
            $msg  = htmlspecialchars(strip_tags(str_replace(['::i18n::'], '', $log['message'])));
            $rows .= '<tr style="background:' . $bg . ';">'
                . '<td style="padding:5px 8px;border-bottom:1px solid #eee;white-space:nowrap;font-size:9pt;">' . htmlspecialchars(substr($log['date_add'], 0, 16)) . '</td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #eee;font-weight:700;font-size:9pt;">' . $lvl . '</td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:9pt;">' . htmlspecialchars($log['class'] ?? '') . '</td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:9pt;">' . htmlspecialchars($log['template'] ?? '—') . '</td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:9pt;">' . $msg . '</td>'
                . '</tr>';
        }

        $html = '<h2 style="color:#1a1a2e;font-family:sans-serif;border-bottom:2px solid #b38b59;padding-bottom:8px;">'
            . 'Neria — Journal Watchdog</h2>'
            . '<p style="font-family:sans-serif;font-size:10pt;color:#666;">'
            . htmlspecialchars($shopName) . ' &mdash; ' . $shopDomain . ' &mdash; Exporté le ' . $now
            . '</p>'
            . '<table border="0" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">'
            . '<thead><tr style="background:#1a1a2e;color:#ffffff;">'
            . '<th style="padding:6px 8px;text-align:left;font-size:9pt;">Date</th>'
            . '<th style="padding:6px 8px;text-align:left;font-size:9pt;">Niveau</th>'
            . '<th style="padding:6px 8px;text-align:left;font-size:9pt;">Classe</th>'
            . '<th style="padding:6px 8px;text-align:left;font-size:9pt;">Template</th>'
            . '<th style="padding:6px 8px;text-align:left;font-size:9pt;">Message</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';

        // Génère le PDF avec TCPDF
        try {
            $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('Neria');
            $pdf->SetAuthor($shopName);
            $pdf->SetTitle('Journal Watchdog Neria');
            $pdf->SetMargins(10, 10, 10);
            $pdf->SetAutoPageBreak(true, 10);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->AddPage();
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdfContent = $pdf->Output('watchdog.pdf', 'S');
        } catch (\Throwable $e) {
            return 'Erreur TCPDF : ' . $e->getMessage();
        }

        // Envoie l'email avec pièce jointe via mail() natif — pas d'assainissement
        // automatique des en-têtes, donc on retire tout retour à la ligne des
        // valeurs interpolées (mêmes précautions que sendCertificateEmail).
        $boundary  = '----=_NeriaBoundary_' . md5(uniqid());
        $fromEmail = str_replace(["\r", "\n"], '', (string) Configuration::get('PS_SHOP_EMAIL') ?: 'noreply@' . parse_url($shopDomain, PHP_URL_HOST));
        $subject   = str_replace(["\r", "\n"], '', '[Neria] Journal Watchdog — ' . $shopName . ' — ' . $now);

        $headers = "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n"
                 . "From: Neria <{$fromEmail}>\r\n"
                 . "X-Mailer: Neria-WatchdogExport/1.0\r\n";

        $body = "--{$boundary}\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . "Bonjour,\r\n\r\nVeuillez trouver ci-joint le journal Watchdog Neria ({$shopName}).\r\n\r\nExporté le {$now}.\r\n\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: application/pdf; name=\"watchdog_neria_{$now}.pdf\"\r\n"
              . "Content-Transfer-Encoding: base64\r\n"
              . "Content-Disposition: attachment; filename=\"watchdog_neria_{$now}.pdf\"\r\n\r\n"
              . chunk_split(base64_encode($pdfContent)) . "\r\n"
              . "--{$boundary}--";

        $sent = @mail($to, $subject, $body, $headers);
        return $sent ? '' : 'La fonction mail() a retourné false. Vérifiez la configuration SMTP.';
    }

    private function sendTestEmail(): void
    {
        $adminEmail = Configuration::get('PS_SHOP_EMAIL');
        $shopName   = Configuration::get('PS_SHOP_NAME');

        // La langue de test vient du picker Neria (neria_bo_lang) transmis dans l'URL.
        // $this->context->language->id reflète l'employé en base (fr=1 si non changé),
        // pas la langue affichée dans le BO (gérée côté client par PS).
        $testLang = (string) Tools::getValue('neria_test_lang', '');
        $supported = TranslationEngine::SUPPORTED_LANGS;
        if ($testLang !== '' && in_array($testLang, $supported, true)) {
            $resolvedId = (int) Language::getIdByIso($testLang);
            $idLang     = $resolvedId > 0 ? $resolvedId : (int) $this->context->language->id;
        } else {
            $idLang = (int) $this->context->language->id;
        }

        $result = Mail::Send(
            $idLang,
            'test',
            AdminTranslator::t('msg.test_subject'),
            [
                '{firstname}' => 'Admin',
                '{lastname}'  => '',
                '{email}'     => $adminEmail,
                '{shop_name}' => $shopName,
                '{shop_url}'  => Tools::getShopDomainSsl(true, true),
            ],
            $adminEmail,
            $shopName,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'neria/mails/',
            false,
            (int) $this->context->shop->id
        );

        if ($result) {
            $this->context->smarty->assign('neria_success',
                AdminTranslator::t('msg.test_sent') . $adminEmail
            );
        } else {
            $this->context->smarty->assign('neria_error',
                AdminTranslator::t('msg.send_failed')
            );
        }
    }

    /**
     * Rend l'aperçu d'un email (iframe de l'onglet Design) et coupe le rendu.
     * Ne renvoie QUE le HTML de l'email — jamais la page admin complète — pour
     * éviter que l'iframe ne recharge la page entière (récursion infinie).
     */
    private function outputEmailPreview(): void
    {
        $template = preg_replace('/[^a-z0-9_-]/i', '', (string) Tools::getValue('neria_template', 'order_conf'));
        $lang     = preg_replace('/[^a-z-]/i', '', (string) Tools::getValue('neria_lang', 'fr'));
        if ($template === '') {
            $template = 'order_conf';
        }
        if ($lang === '') {
            $lang = 'fr';
        }

        // Override de design (valeurs non sauvegardées), validées pour éviter
        // toute injection dans le HTML de l'email.
        $override = [];
        foreach (['color_background', 'color_container', 'color_accent', 'color_text'] as $field) {
            $value = (string) Tools::getValue('preview_' . $field, '');
            if ($value !== '' && preg_match('/^#?[0-9a-fA-F]{3,8}$/', $value)) {
                $override[$field] = $value;
            }
        }
        $width = (int) Tools::getValue('preview_container_width', 0);
        if ($width >= 480 && $width <= 800) {
            $override['container_width'] = $width;
        }
        $logoWidth = (int) Tools::getValue('preview_logo_width', 0);
        if ($logoWidth >= 80 && $logoWidth <= 320) {
            $override['logo_width'] = $logoWidth;
        }
        $fontSize = (int) Tools::getValue('preview_font_size', 0);
        if ($fontSize >= 12 && $fontSize <= 16) {
            $override['font_size'] = $fontSize;
        }
        $lineHeight = (float) Tools::getValue('preview_line_height', 0);
        if ($lineHeight >= 1.4 && $lineHeight <= 2.0) {
            $override['line_height'] = $lineHeight;
        }
        $headingWeight = (int) Tools::getValue('preview_heading_weight', 0);
        if (in_array($headingWeight, [400, 600, 700], true)) {
            $override['heading_weight'] = $headingWeight;
        }

        // Champs "Design avancé" — mêmes règles de validation que
        // ConfigManager::saveDesignConfig(), pour rester cohérent avec ce
        // que le formulaire accepterait réellement à la sauvegarde.
        foreach (['btn_color', 'color_header_bg', 'color_footer_bg', 'color_footer_text'] as $field) {
            $value = (string) Tools::getValue('preview_' . $field, '');
            if ($value !== '' && preg_match('/^#?[0-9a-fA-F]{3,8}$/', $value)) {
                $override[$field] = $value;
            }
        }
        $fontHeading = (string) Tools::getValue('preview_font_heading', '');
        if (class_exists('ConfigManager') && array_key_exists($fontHeading, ConfigManager::HEADING_FONT_OPTIONS)) {
            $override['font_heading'] = $fontHeading;
        }
        $btnRadius = (int) Tools::getValue('preview_btn_radius', -1);
        if (in_array($btnRadius, [0, 2, 6, 24], true)) {
            $override['btn_radius'] = $btnRadius;
        }
        $sectionPadding = (int) Tools::getValue('preview_section_padding', 0);
        if ($sectionPadding >= 16 && $sectionPadding <= 64) {
            $override['section_padding'] = $sectionPadding;
        }
        $blockSpacing = (int) Tools::getValue('preview_block_spacing', 0);
        if ($blockSpacing >= 16 && $blockSpacing <= 80) {
            $override['block_spacing'] = $blockSpacing;
        }
        $separatorStyle = (string) Tools::getValue('preview_separator_style', '');
        if (in_array($separatorStyle, ['none', 'line', 'dotted', 'double'], true)) {
            $override['separator_style'] = $separatorStyle;
        }
        $cardShadow = (string) Tools::getValue('preview_card_shadow', '');
        if (in_array($cardShadow, ['none', 'soft', 'medium', 'strong'], true)) {
            $override['card_shadow'] = $cardShadow;
        }

        $variantB = Tools::getValue('neria_variant') === 'b';
        $html = '';
        if (class_exists('EmailRenderer')) {
            $html = (new EmailRenderer($this))->renderPreviewHtml($template, $lang, $override, $variantB);
        }

        // Vide tout buffer admin et ne renvoie que l'email, puis stoppe.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $html;
        exit;
    }

    /**
     * Retourne la liste des onglets du back-office
     * Utilisé par navigation.tpl pour construire le menu
     */
    private function getBackOfficeTabs(): array
    {
        return [
            'configure'      => AdminTranslator::t('nav.home'),
            'design'         => AdminTranslator::t('nav.design'),
            'typography'     => AdminTranslator::t('nav.typography'),
            'translations'   => AdminTranslator::t('nav.translations'),
            'social'         => AdminTranslator::t('nav.social'),
            'stats'          => AdminTranslator::t('nav.stats'),
            'abtest'         => AdminTranslator::t('nav.abtest'),
            'send'           => AdminTranslator::t('nav.manual_send'),
            'multipreview'   => AdminTranslator::t('nav.multipreview'),
            'customer_history' => AdminTranslator::t('nav.customer_history'),
            'calendar'         => AdminTranslator::t('nav.calendar'),
            'webhooks'         => AdminTranslator::t('nav.webhooks'),
            'segments'         => AdminTranslator::t('nav.segments'),
            'seasonal'         => AdminTranslator::t('nav.seasonal'),
            'bounces'          => AdminTranslator::t('nav.bounces'),
            'gdpr'             => 'RGPD',
            'academy'          => AdminTranslator::t('nav.academy'),
            'certificates'     => AdminTranslator::t('nav.certificates'),
            'help'           => AdminTranslator::t('nav.help'),
        ];
    }

    /**
     * Charge et retourne le contenu d'un onglet back-office
     *
     * @param string $tab Nom de l'onglet
     */
    private function renderTab(string $tab): string
    {
        $allowedTabs = array_keys($this->getBackOfficeTabs());

        // Sécurité : vérifie que l'onglet demandé est valide
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'configure';
        }

        $this->checkSmartyCriticalVars($tab);

        return $this->renderTemplate($tab . '.tpl');
    }

    /**
     * Détecte les actions POST qui se terminent sans feedback (ni neria_success
     * ni neria_error assigné à Smarty). Indique une action silencieuse — le
     * marchand ne sait pas si ça a fonctionné.
     */
    private function checkSilentPostAction(): void
    {
        if (!class_exists('WatchdogManager')) {
            return;
        }

        $action = (string) Tools::getValue('neria_action');
        if ($action === '') {
            return;
        }

        // Actions qui sortent via exit() ou redirect — pas de feedback Smarty attendu
        $exitActions = [
            'preview', 'send_test', 'search_customers', 'health_pixel_test',
            'run_full_diagnostic', 'gdpr_pdf', 'cert_download',
            'upsell_preview', 'test_imap_connection',
        ];
        if (in_array($action, $exitActions, true)) {
            return;
        }

        $vars = $this->context->smarty->getTemplateVars();
        $hasSuccess = !empty($vars['neria_success']);
        $hasError   = !empty($vars['neria_error']);
        $hasInfo    = !empty($vars['neria_info']);

        if (!$hasSuccess && !$hasError && !$hasInfo) {
            (new WatchdogManager($this))->warning(
                WatchdogManager::i18nMsg('watchdog.bo_post_action_silent', ['action' => $action])
            );
        }
    }

    /**
     * Vérifie que les variables Smarty critiques de l'onglet sont bien assignées.
     * Log un WARNING dans le Watchdog pour chaque variable manquante ou nulle.
     * Whitelist par onglet — seules les variables dont l'absence casse l'affichage.
     */
    private function checkSmartyCriticalVars(string $tab): void
    {
        if (!class_exists('WatchdogManager')) {
            return;
        }

        static $whitelist = [
            'stats' => [
                'revenue_chart_7'   => 'Graphique CA 7j (getRevenueDailyByCategory)',
                'revenue_chart_30'  => 'Graphique CA 30j (getRevenueDailyByCategory)',
                'revenue_chart_90'  => 'Graphique CA 90j (getRevenueDailyByCategory)',
                'revenue_chart_365' => 'Graphique CA 365j (getRevenueDailyByCategory)',
                'stats'             => 'Rapports stats (getCachedReports)',
                'kpis'              => 'KPIs 30j (getKpis)',
                'revenue'           => 'Revenus 90j (getRevenueStats)',
            ],
            'configure' => [
                'kpis'          => 'KPIs 30j (getKpis)',
                'custom_vars'   => 'Variables personnalisées (getCustomVars)',
                'upcoming_events' => 'Calendrier (getUpcomingDates)',
            ],
            'design' => [
                'font_scripts'   => 'Scripts de polices (getAllScripts)',
                'fonts_by_script' => 'Polices par script',
            ],
            'send' => [
                'templates_list' => 'Liste des templates',
                'currency_symbol' => 'Symbole devise',
            ],
            'abtest' => [
                'tests_status' => 'Tests A/B actifs',
                'ab_reports'   => 'Rapports A/B',
            ],
            'segments' => [
                'segment_counts' => 'Segments comportementaux',
            ],
        ];

        if (!isset($whitelist[$tab])) {
            return;
        }

        $smartyVars = $this->context->smarty->getTemplateVars();
        $missing    = [];

        foreach ($whitelist[$tab] as $var => $desc) {
            if (!array_key_exists($var, $smartyVars) || $smartyVars[$var] === null) {
                $missing[] = "\${$var} ({$desc})";
            }
        }

        if (empty($missing)) {
            return;
        }

        (new WatchdogManager($this))->warning(
            WatchdogManager::i18nMsg('watchdog.bo_smarty_vars_missing', ['tab' => $tab, 'missing' => implode(', ', $missing)])
        );
    }

    /**
     * Charge un template Smarty depuis views/templates/admin/
     *
     * @param string $template Nom du fichier .tpl
     */
    private function renderTemplate(string $template): string
    {
        $templatePath = 'module:neria/views/templates/admin/' . $template;

        return $this->context->smarty->fetch($templatePath);
    }

    // ============================================================
    // ONGLET BACK-OFFICE (menu latéral PrestaShop)
    // ============================================================

    /**
     * Crée l'entrée "Neria" dans le menu latéral du back-office
     * Apparaît sous l'onglet "Modules"
     */
    private function installTab(): bool
    {
        // Supprimer un éventuel tab existant avant de recréer
        $this->uninstallTab();

        $tab             = new Tab();
        $tab->active     = 1;
        $tab->class_name = 'AdminNeria';
        $tab->module     = $this->name;
        $tab->icon       = 'email';
        $tab->id_parent  = (int) Tab::getIdFromClassName('IMPROVE');
        $tab->name       = [];

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Neria';
        }

        return (bool) $tab->add();
    }

    /**
     * Supprime l'entrée "Neria" du menu latéral du back-office
     */
    private function uninstallTab(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminNeria');

        if ($idTab) {
            $tab = new Tab($idTab);
            return (bool) $tab->delete();
        }

        return true;
    }

    // ============================================================
    // SQL
    // ============================================================

    /**
     * Assigne le message d'une action « Relances Devis B2B ».
     * Passe par la bannière globale (neria_success / neria_error) : le script de
     * navigation.tpl la repositionne ensuite automatiquement dans la section où
     * l'action a été déclenchée, et y défile — comportement uniforme sur tout le
     * module, plus besoin de variables ni de bannière locales.
     *
     * @param string $type 'success' ou 'error'
     * @param string $msg  Texte du message
     */
    private function assignQuoteMsg(string $type, string $msg): void
    {
        $this->context->smarty->assign(
            $type === 'error' ? 'neria_error' : 'neria_success',
            $msg
        );
    }

    /**
     * Vérifie qu'un id_abtest appartient bien à la boutique courante avant
     * toute lecture/écriture sur neria_abtest_translation — cette table
     * n'a pas de colonne id_shop propre (liée uniquement via la FK
     * id_abtest → neria_abtest), donc sans ce contrôle un id_abtest_b
     * appartenant à une autre boutique (install multi-boutiques) serait
     * accepté tel quel.
     */
    private function abtestBelongsToShop(int $idAbtest): bool
    {
        if ($idAbtest <= 0) {
            return false;
        }
        return (bool) Db::getInstance()->getValue(
            'SELECT `id_abtest` FROM `' . _DB_PREFIX_ . 'neria_abtest`
             WHERE `id_abtest` = ' . $idAbtest . '
               AND `id_shop` = ' . (int) $this->context->shop->id
        );
    }

    /**
     * Exécute un fichier SQL depuis le dossier sql/
     * Remplace PREFIX_ par le vrai préfixe de la BDD
     *
     * @param string $filename Nom du fichier (ex: install.sql)
     */
    private function executeSqlFile(string $filename): bool
    {
        $filePath = __DIR__ . '/sql/' . $filename;

        if (!file_exists($filePath)) {
            $this->_errors[] = sprintf(
                AdminTranslator::t('msg.sql_file_missing'),
                $filename
            );
            return false;
        }

        $sql = file_get_contents($filePath);

        // Remplace PREFIX_ par le vrai préfixe (ex: ps_)
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        // Supprime les commentaires SQL et découpe en requêtes
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
        $queries = array_filter(
            array_map('trim', explode(';', $sql)),
            fn(string $q): bool => !empty($q)
        );

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                $this->_errors[] = sprintf(
                    AdminTranslator::t('msg.sql_error'),
                    $filename,
                    Db::getInstance()->getMsgError()
                );
                return false;
            }
        }

        return true;
    }

    // ============================================================
    // IMPORT DES TRADUCTIONS
    // ============================================================

    /**
     * Importe translations.json en base de données
     * Délégué à TranslationInstaller pour le bulk insert optimisé
     */
    private function importTranslations(): bool
    {
        if (!class_exists('TranslationInstaller')) {
            $this->_errors[] = AdminTranslator::t('msg.translation_installer_missing');
            return false;
        }

        $installer = new TranslationInstaller($this);
        return $installer->importFromJson(
            __DIR__ . '/data/translations.json'
        );
    }

    // ============================================================
    // CONFIGURATION PAR DÉFAUT
    // ============================================================

    /**
     * Définit les valeurs de configuration par défaut
     * Ces valeurs sont également insérées dans install.sql
     * mais Configuration::get() est aussi utilisé dans le code
     */
    private function setDefaultConfiguration(): bool
    {
        $defaults = [
            self::CONFIG_PREFIX . 'ACTIVE'           => 1,
            self::CONFIG_PREFIX . 'COLOR_ACCENT'     => '#b38b59',
            self::CONFIG_PREFIX . 'COLOR_BACKGROUND' => '#f4f1eb',
            self::CONFIG_PREFIX . 'DARK_MODE'        => 0,
            self::CONFIG_PREFIX . 'CONTAINER_WIDTH'  => 620,
            self::CONFIG_PREFIX . 'STATS_ENABLED'    => 1,
            self::CONFIG_PREFIX . 'ABTEST_ENABLED'   => 0,
            self::CONFIG_PREFIX . 'AUTO_LANG'        => 1,
            self::CONFIG_PREFIX . 'LOG_INTERNAL'     => 0,
            'NERIA_CRON_ENABLED'                         => 1,
            'NERIA_ARCHIVE_EMAIL'                        => '',
            'NERIA_CHECKOUT_ABANDONMENT_ENABLED'         => 1,
            'NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED'     => 1,
            'NERIA_QUOTE_REMINDERS_ENABLED'              => 1,
            'NERIA_COLLECTION_COMPLETION_ENABLED'        => 1,
            'NERIA_LOOK_COMPLETION_ENABLED'              => 1,
            'NERIA_WAITLIST_ENABLED'                     => 1,
            'NERIA_WAITLIST_RESERVATION_HOURS'           => 4,
            'NERIA_GHOST_CART_ENABLED'                   => 1,
            'NERIA_REFUND_RECONCILIATION_ENABLED'    => 1,
            'NERIA_LIFESPAN_ENABLED'                 => 1,
            'NERIA_PROPENSITY_ENABLED'               => 1,
            'NERIA_PURCHASE_WINDOW_ENABLED'          => 1,
            self::CONFIG_PREFIX . 'VOUCHER_VALIDITY'          => 30,
            self::CONFIG_PREFIX . 'INSTALLED_AT'               => date('Y-m-d H:i:s'),
            MonthlyReportManager::CONFIG_ENABLED               => 1,
            MonthlyReportManager::CONFIG_RECIPIENTS            => '',
            HealthCheckManager::CONFIG_LAST_RUN                => '',
            HealthCheckManager::CONFIG_HDR_LAST                => '',
            'NERIA_INSTALLED_VERSION'                          => self::VERSION,
            WatchdogManager::CFG_ALERT_EMAIL                   => (string) Configuration::get('PS_SHOP_EMAIL'),
            WatchdogManager::CFG_ALERT_IMMEDIATE               => 1,
            WatchdogManager::CFG_ALERT_DIGEST                  => 0,
            WatchdogManager::CFG_ALERT_LAST_SENT               => 0,
            WatchdogManager::CFG_DIGEST_LAST                   => 0,
        ];

        foreach ($defaults as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        if (class_exists('CryptoManager')) {
            \CryptoManager::generateAndStoreKey();
        }

        // Génère le token d'urgence Watchdog s'il n'existe pas encore
        if (!Configuration::getGlobalValue('NERIA_EMERGENCY_TOKEN')) {
            Configuration::updateGlobalValue('NERIA_EMERGENCY_TOKEN', bin2hex(random_bytes(24)));
        }

        // Génère le token du point d'entrée cron externe s'il n'existe pas encore
        if (!Configuration::getGlobalValue('NERIA_CRON_TOKEN')) {
            Configuration::updateGlobalValue('NERIA_CRON_TOKEN', bin2hex(random_bytes(24)));
        }

        return true;
    }

    /**
     * Supprime toutes les clés Configuration du module
     * Appelé lors de la désinstallation
     */
    private function deleteConfiguration(): bool
    {
        $keys = [
            self::CONFIG_PREFIX . 'ACTIVE',
            self::CONFIG_PREFIX . 'COLOR_ACCENT',
            self::CONFIG_PREFIX . 'COLOR_BACKGROUND',
            self::CONFIG_PREFIX . 'DARK_MODE',
            self::CONFIG_PREFIX . 'CONTAINER_WIDTH',
            self::CONFIG_PREFIX . 'STATS_ENABLED',
            self::CONFIG_PREFIX . 'ABTEST_ENABLED',
            self::CONFIG_PREFIX . 'AUTO_LANG',
            self::CONFIG_PREFIX . 'LOG_INTERNAL',
            self::CONFIG_PREFIX . 'VOUCHER_VALIDITY',
            self::CONFIG_PREFIX . 'INSTALLED_AT',
            MonthlyReportManager::CONFIG_ENABLED,
            MonthlyReportManager::CONFIG_RECIPIENTS,
            MonthlyReportManager::CONFIG_LAST_SENT,
            'NERIA_MIGRATED_RENDERED_VARS',
            'NERIA_MIGRATED_REVENUE_COL',
            'NERIA_MIGRATED_MPP_COL',
            'NERIA_ENCRYPTION_KEY',
            HealthCheckManager::CONFIG_LAST_RUN,
            HealthCheckManager::CONFIG_HDR_LAST,
            HealthCheckManager::CONFIG_RESULTS,
            'NERIA_CHECKOUT_ABANDONMENT_ENABLED',
            'NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED',
            'NERIA_QUOTE_REMINDERS_ENABLED',
            'NERIA_PURCHASE_WINDOW_ENABLED',
            'NERIA_CRON_ENABLED',
            'NERIA_CRON_TOKEN',
            'NERIA_EMERGENCY_TOKEN',
            'NERIA_ARCHIVE_EMAIL',
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    // ============================================================
    // STATUT « LIVRÉ » → TEMPLATE delivered
    // ============================================================

    /**
     * Configure le statut de commande « Livré » pour qu'il envoie l'email avec
     * le template Neria `delivered`. Par défaut, PrestaShop n'envoie AUCUN
     * email pour ce statut (delivered n'est pas un template natif). Sans cette
     * config, le template delivered ne partirait jamais.
     *
     * L'état précédent est sauvegardé pour être restauré à la désinstallation.
     * Non bloquant : retourne toujours true (ne doit pas faire échouer l'install).
     */
    private function translateWatchdogLogs(array $logs): array
    {
        foreach ($logs as &$entry) {
            $msg = $entry['message'] ?? '';
            if (str_starts_with($msg, '::i18n::')) {
                $decoded = json_decode(substr($msg, 8), true);
                if (is_array($decoded) && isset($decoded['k'])) {
                    $str = AdminTranslator::t($decoded['k']);
                    foreach ($decoded['v'] ?? [] as $k => $v) {
                        $str = str_replace('{' . $k . '}', (string) $v, $str);
                    }
                    $entry['message'] = $str;
                }
            }
        }
        unset($entry);
        return $logs;
    }

    private function getReportEnabledConfig(): bool
    {
        $val = Configuration::get(MonthlyReportManager::CONFIG_ENABLED);
        if ($val === false) {
            Configuration::updateValue(MonthlyReportManager::CONFIG_ENABLED, 1);
            return true;
        }
        return (bool) $val;
    }

    private function loadAcademyStrings(): array
    {
        $boLang   = class_exists('AdminTranslator') ? AdminTranslator::currentLang() : 'fr';
        $supported = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl'];
        if (!in_array($boLang, $supported, true)) {
            $boLang = 'fr';
        }
        $path = _PS_MODULE_DIR_ . 'neria/data/academy/' . $boLang . '.json';
        if (!file_exists($path)) {
            $path = _PS_MODULE_DIR_ . 'neria/data/academy/fr.json';
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function configureDeliveredStatus(): bool
    {
        $idState = (int) Configuration::get('PS_OS_DELIVERED');
        if (!$idState) {
            return true;
        }

        $orderState = new OrderState($idState);
        if (!Validate::isLoadedObject($orderState)) {
            return true;
        }

        // Sauvegarde de l'état précédent (pour restauration à la désinstallation)
        $prevTemplate = is_array($orderState->template)
            ? (string) reset($orderState->template)
            : (string) $orderState->template;
        Configuration::updateValue(self::CONFIG_PREFIX . 'OSD_SEND', (int) $orderState->send_email);
        Configuration::updateValue(self::CONFIG_PREFIX . 'OSD_TPL', $prevTemplate);

        // Active l'email + template `delivered` pour toutes les langues
        $orderState->send_email = true;
        $tplByLang = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tplByLang[(int) $lang['id_lang']] = 'delivered';
        }
        $orderState->template = $tplByLang;
        $orderState->save();

        return true;
    }

    /**
     * Restaure le statut « Livré » dans son état d'avant l'installation Neria.
     * Non bloquant : retourne toujours true.
     */
    private function restoreDeliveredStatus(): bool
    {
        $idState = (int) Configuration::get('PS_OS_DELIVERED');
        if (!$idState) {
            return true;
        }

        $orderState = new OrderState($idState);
        if (!Validate::isLoadedObject($orderState)) {
            return true;
        }

        $prevSend = Configuration::get(self::CONFIG_PREFIX . 'OSD_SEND');
        $prevTpl  = (string) Configuration::get(self::CONFIG_PREFIX . 'OSD_TPL');

        $orderState->send_email = ($prevSend !== false) ? (bool) (int) $prevSend : false;
        $tplByLang = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tplByLang[(int) $lang['id_lang']] = $prevTpl;
        }
        $orderState->template = $tplByLang;
        $orderState->save();

        Configuration::deleteByName(self::CONFIG_PREFIX . 'OSD_SEND');
        Configuration::deleteByName(self::CONFIG_PREFIX . 'OSD_TPL');

        return true;
    }

    // ============================================================
    // UTILITAIRES PUBLICS
    // Utilisés par les classes src/ qui reçoivent $this (le module)
    // ============================================================

    /**
     * Construit la map statut A/B pour abtest.tpl
     * ['template_name' => 'active|draft|none', ...]
     */
    private function getAbtestStatusMap(ABTestManager $ab): array
    {
        $map = [];
        foreach ($ab->getEligibleTemplates() as $tpl => $label) {
            $map[$tpl] = $ab->getTestStatus($tpl);
        }
        return $map;
    }

    /**
     * Construit la map données A/B pour abtest.tpl
     * ['template_name' => ['a' => [...], 'b' => [...]], ...]
     */
    private function getAbtestDataMap(ABTestManager $ab): array
    {
        $map  = [];
        $rows = $ab->getAllActiveTests();
        foreach ($rows as $row) {
            $tpl     = $row['template'];
            $variant = strtolower($row['variant']);
            if (!isset($map[$tpl])) {
                $map[$tpl] = [];
            }
            $map[$tpl][$variant] = $row;
        }
        return $map;
    }

    /**
     * Construit la map rapports A/B pour abtest.tpl
     * ['template_name' => ['A' => [...], 'B' => [...], 'days_remaining' => int|null], ...]
     */
    private function getAbtestReportsMap(StatsManager $stats, ABTestManager $ab): array
    {
        $map = [];
        foreach ($ab->getEligibleTemplates() as $tpl => $label) {
            if ($ab->hasActiveTest($tpl)) {
                $report                  = $stats->getABTestReport($tpl, 30);
                $report['days_remaining'] = $ab->estimateDaysRemaining($tpl, $report);
                $map[$tpl]               = $report;
            }
        }
        return $map;
    }

    private function getCalendarTemplatesList(): array
    {
        $dir  = __DIR__ . '/mails/themes/neria_global/core/';
        $list = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '*.html') as $file) {
                $tpl = basename($file, '.html');
                // On exclut layout et les templates système
                if (!in_array($tpl, ['layout', 'neria_layout', 'monthly_report'], true)) {
                    $list[] = $tpl;
                }
            }
        }
        sort($list);
        return $list;
    }

    private function getCalendarKnownKeys(): array
    {
        return [
            'christmas'       => 'Noël / Christmas',
            'new_year'        => 'Nouvel An / New Year',
            'valentine'       => 'Saint-Valentin / Valentine\'s Day',
            'halloween'       => 'Halloween',
            'easter'          => 'Pâques / Easter',
            'black_friday'    => 'Black Friday',
            'mothers_day_fr'  => 'Fête des Mères (France)',
            'mothers_day_us'  => 'Mother\'s Day (US/UK)',
            'fathers_day'     => 'Fête des Pères / Father\'s Day',
            'grandparents_day'=> 'Fête des Grands-parents',
            'eid'             => 'Aïd el-Fitr',
            'eid_adha'        => 'Aïd el-Adha',
            'ramadan'         => 'Ramadan',
            'lunar_new_year'  => 'Nouvel An Chinois / Lunar New Year',
            'seollal'         => 'Seollal (Corée)',
            'diwali'          => 'Diwali',
            'hanukkah'        => 'Hanukkah',
            'nowruz'          => 'Norouz (Nouvel An Persan)',
            'setsubun'        => 'Setsubun (Japon)',
        ];
    }

    /**
     * Liste des pays (ISO 2 lettres → nom) pour le menu déroulant du
     * formulaire Calendrier, triée alphabétiquement par nom.
     */
    private function getCountriesListForSelect(): array
    {
        $idLang = (int) $this->context->language->id;
        $rows   = Country::getCountries($idLang, false, false, false);
        $list   = [];
        foreach ($rows as $row) {
            $iso = strtoupper((string) ($row['iso_code'] ?? ''));
            if ($iso === '') {
                continue;
            }
            $list[$iso] = (string) ($row['name'] ?? $iso);
        }
        asort($list, SORT_STRING | SORT_FLAG_CASE);
        return $list;
    }

    /**
     * Retourne le chemin absolu vers un fichier du module
     *
     * @param string $relativePath Chemin relatif depuis la racine du module
     */
    public function getModulePath(string $relativePath = ''): string
    {
        return __DIR__ . ($relativePath ? '/' . ltrim($relativePath, '/') : '');
    }

    /**
     * Retourne l'URL publique vers un fichier du module
     *
     * @param string $relativePath Chemin relatif depuis la racine du module
     */
    public function getModuleUrl(string $relativePath = ''): string
    {
        return $this->_path . ($relativePath ? ltrim($relativePath, '/') : '');
    }

    /**
     * Log une erreur dans le système de logs PrestaShop
     *
     * @param string $message  Message d'erreur
     * @param int    $severity Niveau : 1=info, 2=warn, 3=error, 4=critical
     */
    public function log(string $message, int $severity = 1): void
    {
        PrestaShopLogger::addLog(
            '[Neria] ' . $message,
            $severity,
            null,
            'Neria',
            0,
            true
        );
    }
}