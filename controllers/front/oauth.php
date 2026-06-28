<?php
/**
 * NERIA — Front controller : callback OAuth 2.0 (Google Postmaster Tools)
 *
 * Google redirige ici après que le marchand a accordé l'accès.
 * Ce contrôleur échange le code d'autorisation contre des tokens,
 * les stocke en base, puis redirige vers l'onglet Statistiques du BO.
 *
 * URL : /index.php?fc=module&module=neria&controller=oauth
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaOauthModuleFrontController extends ModuleFrontController
{
    public function init(): void
    {
        parent::init();

        // Ce controller ne sert qu'en backoffice — doit être appelé par un admin
        if (!$this->context->employee || !$this->context->employee->id) {
            // Essaie de récupérer l'URL de retour avant de renvoyer vers le login BO
            $returnUrl = (string) \Configuration::get(\PostmasterManager::CONFIG_RETURN_URL);
            \Tools::redirectAdmin($returnUrl ?: 'index.php');
        }

        $code  = (string) \Tools::getValue('code',  '');
        $state = (string) \Tools::getValue('state', '');
        $error = (string) \Tools::getValue('error', '');

        // URL de retour stockée avant la redirection Google
        $returnUrl = (string) \Configuration::get(\PostmasterManager::CONFIG_RETURN_URL);
        if (!$returnUrl) {
            $returnUrl = $this->context->link->getAdminLink('AdminModules', true, [], [
                'configure' => 'neria',
            ]) . '&neria_tab=stats';
        }

        if ($error !== '') {
            $msg = urlencode('Connexion Google annulée : ' . $error);
            \Tools::redirectAdmin($returnUrl . '&neria_error=' . $msg);
        }

        if ($code === '' || $state === '') {
            \Tools::redirectAdmin($returnUrl . '&neria_error=' . urlencode('Paramètres OAuth manquants.'));
        }

        if (!class_exists('PostmasterManager')) {
            require_once _PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php';
        }

        $manager = new \PostmasterManager($this->module);
        $ok      = $manager->handleCallback($code, $state);

        if ($ok) {
            \Tools::redirectAdmin($returnUrl . '&neria_success=' . urlencode('Google Postmaster Tools connecté avec succès !'));
        } else {
            \Tools::redirectAdmin($returnUrl . '&neria_error=' . urlencode('Échec de l\'échange de code OAuth. Vérifiez vos Client ID et Secret.'));
        }
    }
}
