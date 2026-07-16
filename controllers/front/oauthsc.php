<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Front controller : callback OAuth 2.0 (Google Search Console)
 *
 * Google redirige ici après que le marchand a accordé l'accès Search Console.
 * Ce contrôleur échange le code d'autorisation contre des tokens,
 * les stocke en base, puis redirige vers l'onglet Statistiques du BO.
 *
 * URL : /index.php?fc=module&module=neria&controller=oauthsc
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaOauthscModuleFrontController extends ModuleFrontController
{
    public function init(): void
    {
        parent::init();

        // Pas de check employee ici : le callback arrive côté front (fc=module),
        // l'employee n'est pas chargé dans ce contexte. La sécurité est assurée
        // par le paramètre state (CSRF token vérifié dans handleCallback).

        if (!class_exists('WatchdogManager')) {
            require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';
        }
        if (!class_exists('AdminTranslator')) {
            require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
        }
        \AdminTranslator::setLang(\WatchdogManager::shopLang());

        $code  = (string) \Tools::getValue('code',  '');
        $state = (string) \Tools::getValue('state', '');
        $error = (string) \Tools::getValue('error', '');

        if (!class_exists('SearchConsoleManager')) {
            require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';
        }
        $manager = new \SearchConsoleManager($this->module);

        // URL de retour associée à CE state précis (plusieurs flux OAuth
        // peuvent être en attente simultanément — cf. SearchConsoleManager::
        // resolveReturnUrl()), pas une valeur globale unique.
        $returnUrl = $manager->resolveReturnUrl($state);
        if (!$returnUrl) {
            $returnUrl = $this->context->link->getAdminLink('AdminModules', true, [], [
                'configure' => 'neria',
            ]) . '&neria_tab=stats';
        }

        if ($error !== '') {
            \Tools::redirectAdmin($returnUrl . '&neria_error=' . urlencode(\AdminTranslator::tVars('msg.oauth_cancelled', ['error' => $error])));
        }

        if ($code === '' || $state === '') {
            \Tools::redirectAdmin($returnUrl . '&neria_error=' . urlencode(\AdminTranslator::t('msg.oauth_missing_params')));
        }

        try {
            $ok = $manager->handleCallback($code, $state);
        } catch (\Throwable $e) {
            if (class_exists('WatchdogManager')) {
                try {
                    (new \WatchdogManager($this->module))->error(
                        \WatchdogManager::i18nMsg('watchdog.gsc_oauth_failed', ['error' => $e->getMessage()]),
                        '', 'OauthController'
                    );
                } catch (\Throwable $ignored) {
                }
            }
            $ok = false;
        }

        if ($ok) {
            \Tools::redirectAdmin($returnUrl . '&neria_success=' . urlencode(\AdminTranslator::t('msg.gsc_oauth_connected')));
        } else {
            \Tools::redirectAdmin($returnUrl . '&neria_error=' . urlencode(\AdminTranslator::t('msg.gsc_oauth_exchange_failed')));
        }
    }
}
