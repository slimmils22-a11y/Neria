<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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
                    WatchdogManager::i18nMsg('watchdog.preferences_token_invalid', ['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR']]),
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
        // Scopé à la boutique courante : en multiboutique sans partage de
        // comptes, la même adresse peut correspondre à des lignes client
        // distinctes par boutique (colonne id_shop). Sans ce filtre, un
        // lien de préférences reçu de la boutique A pouvait résoudre le
        // compte client d'une autre boutique et lire/écrire ses préférences.
        $row = Db::getInstance()->getRow(
            "SELECT `id_customer` FROM `" . _DB_PREFIX_ . "customer`
             WHERE LOWER(`email`) = '" . pSQL(strtolower($email)) . "'
             AND `deleted` = 0 AND `id_shop` = " . (int) $this->context->shop->id
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

                // Synchronise ps_customer.newsletter avec la catégorie
                // 'newsletter' Neria — sans ce pont, les deux systèmes de
                // désabonnement (natif PrestaShop / Neria) représentaient
                // deux "vérités" distinctes et jamais synchronisées : un
                // client qui désactivait tout ici gardait newsletter=1 côté
                // PrestaShop natif (et tout module tiers qui s'y fie), et
                // inversement (cf. le correctif symétrique dans
                // unsubscribe.php côté one-click RFC 8058).
                if ($idCustomer > 0) {
                    try {
                        Db::getInstance()->execute(
                            "UPDATE `" . _DB_PREFIX_ . "customer` SET `newsletter` = " . (int) $submitted['newsletter'] . "
                             WHERE `id_customer` = " . $idCustomer . " AND `id_shop` = " . (int) $this->context->shop->id
                        );
                    } catch (\Throwable $ex) {
                        // best-effort : les préférences Neria restent la source
                        // de vérité prioritaire même si cette synchro échoue
                    }
                }
                if (class_exists('WatchdogManager')) {
                    $optOut = array_keys(array_filter($submitted, fn($v) => $v === 0));
                    $prevLang = AdminTranslator::currentLang();
                    AdminTranslator::setLang(WatchdogManager::shopLang());
                    $detail = $optOut
                        ? AdminTranslator::tVars('watchdog.preferences_opted_out', ['list' => implode(', ', $optOut)])
                        : AdminTranslator::t('watchdog.preferences_all_enabled');
                    AdminTranslator::setLang($prevLang);
                    (new WatchdogManager($this->module))->info(
                        WatchdogManager::i18nMsg('watchdog.preferences_customer_updated', ['email' => $email, 'detail' => $detail]),
                        'preferences',
                        'PreferencesController'
                    );
                }
            } catch (\Throwable $e) {
                if (class_exists('WatchdogManager')) {
                    (new WatchdogManager($this->module))->error(
                        WatchdogManager::i18nMsg('watchdog.preferences_save_error', ['email' => $email, 'error' => $e->getMessage()]),
                        'preferences',
                        'PreferencesController'
                    );
                }
            }
        }

        try {
            $prefs = $manager->getByCustomer($idCustomer, $email);
        } catch (\Throwable $e) {
            if (class_exists('WatchdogManager')) {
                try {
                    (new WatchdogManager($this->module))->warning(
                        WatchdogManager::i18nMsg('watchdog.preferences_read_failed', ['email' => $email, 'error' => $e->getMessage()]),
                        'preferences', 'PreferencesController'
                    );
                } catch (\Throwable $ignored) {
                }
            }
            $prefs = [];
        }

        // Labels des catégories (traduits via AdminTranslator si dispo)
        $catLabels = $this->getCatLabels($lang);

        // URL de désabonnement complet — round 148 : construite en dur
        // ('/module/neria/unsubscribe?...'), cassée sans URL rewriting
        // (PS_REWRITING_SETTINGS=0), même pattern déjà corrigé sur
        // waitlist.php aux rounds 54/67. getUnsubscribeUrl() passe déjà par
        // getModuleLink() (rewriting/non-rewriting + segment de langue) et
        // génère le même jeton HMAC que celui vérifié par le contrôleur
        // unsubscribe.
        $unsubUrl = $this->module->getUnsubscribeUrl($email, $lang);

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
            'ja' => [
                'cart'       => 'カートリマインダー',
                'post'       => '購入後フォロー',
                'loyalty'    => 'ロイヤルティプログラム',
                'behav'      => 'パーソナライズされたメール',
                'season'     => '季節限定オファー',
                'b2b'        => '見積もり・法人向け提案',
                'newsletter' => 'ニュースレター・プロモーション',
            ],
            'ko' => [
                'cart'       => '장바구니 알림',
                'post'       => '구매 후 후속 조치',
                'loyalty'    => '로열티 프로그램',
                'behav'      => '맞춤 이메일',
                'season'     => '시즌 오퍼',
                'b2b'        => '견적 및 B2B 제안',
                'newsletter' => '뉴스레터 및 프로모션',
            ],
            'zh' => [
                'cart'       => '购物车提醒',
                'post'       => '购后跟进',
                'loyalty'    => '忠诚度计划',
                'behav'      => '个性化邮件',
                'season'     => '季节性优惠',
                'b2b'        => '报价与B2B提案',
                'newsletter' => '通讯与促销',
            ],
            'tw' => [
                'cart'       => '購物車提醒',
                'post'       => '購後跟進',
                'loyalty'    => '忠誠度計畫',
                'behav'      => '個人化郵件',
                'season'     => '季節性優惠',
                'b2b'        => '報價與B2B提案',
                'newsletter' => '電子報與促銷',
            ],
            'ru' => [
                'cart'       => 'Напоминания о корзине',
                'post'       => 'Сопровождение после покупки',
                'loyalty'    => 'Программа лояльности',
                'behav'      => 'Персонализированные письма',
                'season'     => 'Сезонные предложения',
                'b2b'        => 'Коммерческие предложения B2B',
                'newsletter' => 'Рассылки и акции',
            ],
            'tr' => [
                'cart'       => 'Sepet hatırlatmaları',
                'post'       => 'Satın alma sonrası takip',
                'loyalty'    => 'Sadakat programı',
                'behav'      => 'Kişiselleştirilmiş e-postalar',
                'season'     => 'Sezonluk teklifler',
                'b2b'        => 'Teklifler ve B2B önerileri',
                'newsletter' => 'Bültenler ve promosyonlar',
            ],
            'sv' => [
                'cart'       => 'Varukorgspåminnelser',
                'post'       => 'Uppföljning efter köp',
                'loyalty'    => 'Lojalitetsprogram',
                'behav'      => 'Personliga e-postmeddelanden',
                'season'     => 'Säsongserbjudanden',
                'b2b'        => 'Offerter och B2B-förslag',
                'newsletter' => 'Nyhetsbrev och kampanjer',
            ],
            'no' => [
                'cart'       => 'Handlekurvpåminnelser',
                'post'       => 'Oppfølging etter kjøp',
                'loyalty'    => 'Lojalitetsprogram',
                'behav'      => 'Personlige e-poster',
                'season'     => 'Sesongtilbud',
                'b2b'        => 'Tilbud og B2B-forslag',
                'newsletter' => 'Nyhetsbrev og kampanjer',
            ],
            'da' => [
                'cart'       => 'Kurvpåmindelser',
                'post'       => 'Opfølgning efter køb',
                'loyalty'    => 'Loyalitetsprogram',
                'behav'      => 'Personlige e-mails',
                'season'     => 'Sæsontilbud',
                'b2b'        => 'Tilbud og B2B-forslag',
                'newsletter' => 'Nyhedsbreve og kampagner',
            ],
        ];

        return $map[$lang] ?? $map['en'];
    }
}
