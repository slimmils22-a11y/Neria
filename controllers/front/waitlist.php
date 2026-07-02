<?php
/**
 * Neria — Contrôleur front liste d'attente
 * URL : /module/neria/waitlist
 * Actions : subscribe / unsubscribe
 */

if (!defined('_PS_VERSION_')) exit;

class NeriaWaitlistModuleFrontController extends ModuleFrontController
{
    public $display_column_left  = false;
    public $display_column_right = false;
    public $auth                 = true; // PS redirige vers login si non connecté
    public $authRedirection      = '';

    public function postProcess(): void
    {
        if (!$this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication');
        }

        $action    = Tools::getValue('action');
        $idProduct = (int) Tools::getValue('id_product');
        $back      = Tools::getValue('back');
        $redirect  = $idProduct
            ? $this->context->link->getProductLink($idProduct)
            : 'index.php?controller=my-account';
        if ($back) $redirect = urldecode($back);

        if (!$idProduct || !in_array($action, ['subscribe', 'unsubscribe'])) {
            Tools::redirect($redirect);
        }

        if (!class_exists('WaitlistManager')) {
            Tools::redirect($redirect);
        }

        try {
            $mgr        = new WaitlistManager($this->module);
            $idCustomer = (int) $this->context->customer->id;
            $idShop     = (int) $this->context->shop->id;

            if ($action === 'subscribe') {
                $mgr->register($idCustomer, $idProduct, $idShop);
            } else {
                $mgr->unregister($idCustomer, $idProduct);
            }
        } catch (\Throwable $e) {
            if (class_exists('WatchdogManager')) {
                try {
                    (new WatchdogManager($this->module))->warning(
                        "Liste d'attente [{$action}] — exception : " . $e->getMessage(),
                        '', 'WaitlistController'
                    );
                } catch (\Throwable $ignored) {
                }
            }
            // Le client est quand même redirigé — jamais de page cassée sur un clic.
        }

        Tools::redirect($redirect);
    }
}
