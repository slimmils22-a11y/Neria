<?php
/**
 * NERIA — Front controller : centre de préférences email
 *
 * Accessible via un lien dans le pied de chaque email Neria.
 * Permet au client de choisir quelles catégories d'emails il souhaite recevoir,
 * sans se désabonner complètement.
 *
 * - GET  : affiche le formulaire avec les préférences actuelles.
 * - POST : sauvegarde les préférences et confirme.
 *
 * Sécurité : jeton HMAC-SHA256 sur l'email (même logique que unsubscribe).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaPreferencesModuleFrontController extends ModuleFrontController
{
    public $display_column_left  = false;
    public $display_column_right = false;

    public function initContent()
    {
        parent::initContent();

        $email = trim((string) Tools::getValue('email'));
        $token = (string) Tools::getValue('token');
        $lang  = Tools::strtolower((string) Tools::getValue('lang')) ?: 'fr';

        // Enregistrement de l'AdminTranslator pour le rendu multilingue
        if (class_exists('AdminTranslator')) {
            AdminTranslator::setLang($lang);
            AdminTranslator::register($this->context->smarty);
        }

        // Validation token
        if ($email === '' || !Validate::isEmail($email)) {
            $this->assignError($lang);
            return;
        }

        $expected = PreferencesManager::tokenForEmail($email);
        if (!is_string($token) || !hash_equals($expected, $token)) {
            if (class_exists('WatchdogManager')) {
                (new WatchdogManager($this->module))->warning(
                    'Token de préférences invalide pour ' . $email . ' (ip:' . $_SERVER['REMOTE_ADDR'] . ')',
                    'preferences',
                    'PreferencesController'
                );
            }
            $this->assignError($lang);
            return;
        }

        // Résolution du client par email UNIQUEMENT — le token HMAC n'authentifie
        // que l'email, jamais le paramètre `cid`. Si on faisait confiance à un
        // `cid` fourni par le client sans vérifier qu'il correspond bien à cet
        // email, un client authentifié sur SA PROPRE adresse pourrait changer
        // `cid` pour écraser les préférences d'un autre client (IDOR en écriture).
        $row = Db::getInstance()->getRow(
            "SELECT `id_customer` FROM `" . _DB_PREFIX_ . "customer`
             WHERE LOWER(`email`) = '" . pSQL(strtolower($email)) . "'
             AND `deleted` = 0 LIMIT 1"
        );
        $idCustomer = $row ? (int) $row['id_customer'] : 0;

        $manager = new PreferencesManager($this->module);

        $saved = false;
        if (Tools::isSubmit('neria_prefs_save')) {
            $submitted = [];
            foreach (PreferencesManager::CATEGORIES as $cat) {
                $submitted[$cat] = (int) Tools::getValue('pref_' . $cat, 0);
            }
            try {
                $manager->saveByCustomer($idCustomer, $email, $submitted);
                $saved = true;
                if (class_exists('WatchdogManager')) {
                    $optOut = array_keys(array_filter($submitted, fn($v) => $v === 0));
                    (new WatchdogManager($this->module))->info(
                        'Préférences mises à jour pour ' . $email
                            . ($optOut ? ' — désactivé : ' . implode(', ', $optOut) : ' — tout activé'),
                        'preferences',
                        'PreferencesController'
                    );
                }
            } catch (\Throwable $e) {
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this->module))->error(
                        'Erreur sauvegarde préférences pour ' . $email . ' : ' . $e->getMessage(),
                        'preferences',
                        'PreferencesController'
                    );
                }
            }
        }

        try {
            $prefs = $manager->getByCustomer($idCustomer);
        } catch (\Throwable $e) {
            if (class_exists('WatchdogManager')) {
                try {
                    (new WatchdogManager($this->module))->warning(
                        'Lecture préférences échouée pour ' . $email . ' : ' . $e->getMessage(),
                        'preferences', 'PreferencesController'
                    );
                } catch (\Throwable $ignored) {
                }
            }
            $prefs = [];
        }

        // Labels des catégories (traduits via AdminTranslator si dispo)
        $catLabels = $this->getCatLabels($lang);

        // URL de désabonnement complet
        $unsubToken   = substr(hash_hmac('sha256', strtolower($email), _COOKIE_KEY_), 0, 32);
        $unsubUrl     = rtrim($this->context->link->getBaseLink(), '/')
                      . '/module/neria/unsubscribe'
                      . '?email=' . urlencode($email)
                      . '&token=' . urlencode($unsubToken)
                      . '&lang='  . $lang;

        $this->context->smarty->assign([
            'neria_prefs_email'     => $email,
            'neria_prefs_token'     => $token,
            'neria_prefs_lang'      => $lang,
            'neria_prefs_cid'       => $idCustomer,
            'neria_prefs'           => $prefs,
            'neria_prefs_cats'      => PreferencesManager::CATEGORIES,
            'neria_prefs_labels'    => $catLabels,
            'neria_prefs_saved'     => $saved,
            'neria_shop_name'       => (string) Configuration::get('PS_SHOP_NAME'),
            'neria_shop_url'        => $this->context->link->getBaseLink(),
            'neria_unsub_url'       => $unsubUrl,
            'neria_prefs_dir'       => class_exists('AdminTranslator') ? AdminTranslator::dir() : 'ltr',
        ]);

        $this->setTemplate('module:neria/views/templates/front/preferences.tpl');
    }

    private function assignError(string $lang): void
    {
        $this->context->smarty->assign([
            'neria_prefs_error'  => true,
            'neria_shop_name'    => (string) Configuration::get('PS_SHOP_NAME'),
            'neria_shop_url'     => $this->context->link->getBaseLink(),
            'neria_prefs_dir'    => 'ltr',
            'neria_prefs_saved'  => false,
            'neria_prefs_email'  => '',
            'neria_prefs_token'  => '',
            'neria_prefs_lang'   => $lang,
            'neria_prefs_cid'    => 0,
            'neria_prefs'        => [],
            'neria_prefs_cats'   => [],
            'neria_prefs_labels' => [],
            'neria_unsub_url'    => '',
        ]);
        $this->setTemplate('module:neria/views/templates/front/preferences.tpl');
    }

    private function getCatLabels(string $lang): array
    {
        $map = [
            'fr' => [
                'cart'       => 'Relances panier',
                'post'       => 'Suivi post-achat',
                'loyalty'    => 'Programme de fidélité',
                'behav'      => 'Emails personnalisés',
                'season'     => 'Offres saisonnières',
                'b2b'        => 'Devis & propositions B2B',
                'newsletter' => 'Newsletters & promotions',
            ],
            'en' => [
                'cart'       => 'Cart reminders',
                'post'       => 'Post-purchase follow-up',
                'loyalty'    => 'Loyalty program',
                'behav'      => 'Personalised emails',
                'season'     => 'Seasonal offers',
                'b2b'        => 'Quotes & B2B proposals',
                'newsletter' => 'Newsletters & promotions',
            ],
            'de' => [
                'cart'       => 'Warenkorb-Erinnerungen',
                'post'       => 'Nach dem Kauf',
                'loyalty'    => 'Treueprogramm',
                'behav'      => 'Personalisierte E-Mails',
                'season'     => 'Saisonangebote',
                'b2b'        => 'Angebote & B2B',
                'newsletter' => 'Newsletter & Aktionen',
            ],
            'es' => [
                'cart'       => 'Recordatorios de carrito',
                'post'       => 'Seguimiento post-compra',
                'loyalty'    => 'Programa de fidelidad',
                'behav'      => 'Emails personalizados',
                'season'     => 'Ofertas estacionales',
                'b2b'        => 'Presupuestos B2B',
                'newsletter' => 'Boletines y promociones',
            ],
            'it' => [
                'cart'       => 'Promemoria carrello',
                'post'       => 'Seguito post-acquisto',
                'loyalty'    => 'Programma fedeltà',
                'behav'      => 'Email personalizzate',
                'season'     => 'Offerte stagionali',
                'b2b'        => 'Preventivi B2B',
                'newsletter' => 'Newsletter e promozioni',
            ],
            'pt' => [
                'cart'       => 'Lembretes de carrinho',
                'post'       => 'Acompanhamento pós-compra',
                'loyalty'    => 'Programa de fidelidade',
                'behav'      => 'Emails personalizados',
                'season'     => 'Ofertas sazonais',
                'b2b'        => 'Orçamentos B2B',
                'newsletter' => 'Newsletters e promoções',
            ],
            'nl' => [
                'cart'       => 'Winkelwagen herinneringen',
                'post'       => 'Nazorg na aankoop',
                'loyalty'    => 'Loyaliteitsprogramma',
                'behav'      => 'Gepersonaliseerde e-mails',
                'season'     => 'Seizoensaanbiedingen',
                'b2b'        => 'Offertes B2B',
                'newsletter' => 'Nieuwsbrieven & promoties',
            ],
            'ar' => [
                'cart'       => 'تذكيرات السلة',
                'post'       => 'متابعة ما بعد الشراء',
                'loyalty'    => 'برنامج الولاء',
                'behav'      => 'رسائل مخصصة',
                'season'     => 'عروض موسمية',
                'b2b'        => 'عروض الأسعار',
                'newsletter' => 'النشرات الإخبارية',
            ],
        ];

        return $map[$lang] ?? $map['en'];
    }
}
