<?php
/**
 * NERIA — Front controller : désabonnement
 *
 * Cible des en-têtes List-Unsubscribe (RFC 2369 / 8058) et du lien de
 * désabonnement en pied d'email. Désinscrit l'adresse des communications
 * marketing (newsletter client + invités). Les emails transactionnels
 * (commande, mot de passe…) continuent toujours d'être envoyés.
 *
 * - GET  : page de confirmation (clic humain sur le lien).
 * - POST : désabonnement « un clic » (Gmail/Yahoo, List-Unsubscribe=One-Click)
 *          → répond 200 sans page.
 *
 * Sécurité : jeton HMAC-SHA256 de l'email (secret _COOKIE_KEY_) — empêche
 * tout désabonnement d'une adresse arbitraire.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaUnsubscribeModuleFrontController extends ModuleFrontController
{
    /** @var bool Pas de colonne, page autonome */
    public $display_column_left  = false;
    public $display_column_right = false;

    /**
     * Désabonnement « un clic » (RFC 8058) : Gmail/Yahoo envoient un POST.
     * On traite et on répond 200 sans rendu de page.
     */
    public function postProcess()
    {
        if (Tools::strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->processUnsubscribe();
            header('HTTP/1.1 200 OK');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'OK';
            exit;
        }
    }

    /**
     * Clic humain (GET) : effectue le désabonnement puis affiche une
     * confirmation sobre.
     */
    public function initContent()
    {
        parent::initContent();

        $done = $this->processUnsubscribe();

        // Langue du destinataire : transportée par l'email (param neria_lang),
        // sinon langue front courante, sinon anglais. La page s'affiche ainsi
        // dans la langue de l'email reçu.
        if (class_exists('AdminTranslator')) {
            $lang = Tools::strtolower((string) Tools::getValue('neria_lang'));
            if ($lang === '' && isset($this->context->language->iso_code)) {
                $lang = Tools::strtolower((string) $this->context->language->iso_code);
            }
            AdminTranslator::setLang($lang);
            AdminTranslator::register($this->context->smarty);
        }

        $this->context->smarty->assign([
            'neria_unsub_done' => $done,
            'neria_shop_name'  => (string) Configuration::get('PS_SHOP_NAME'),
            'neria_shop_url'   => $this->context->link->getBaseLink(),
            'neria_unsub_dir'  => class_exists('AdminTranslator') ? AdminTranslator::dir() : 'ltr',
        ]);

        $this->setTemplate('module:neria/views/templates/front/unsubscribe.tpl');
    }

    /**
     * Vérifie le jeton et désinscrit l'adresse des communications marketing.
     * Idempotent et défensif : ne lève jamais d'exception.
     *
     * @return bool true si l'adresse est désormais désinscrite
     */
    private function processUnsubscribe(): bool
    {
        $email = trim((string) Tools::getValue('email'));
        $token = (string) Tools::getValue('token');

        if ($email === '' || !Validate::isEmail($email)) {
            return false;
        }

        $expected = substr(hash_hmac('sha256', Tools::strtolower($email), _COOKIE_KEY_), 0, 32);
        if (!is_string($token) || !hash_equals($expected, $token)) {
            return false;
        }

        $db = Db::getInstance();
        $e  = pSQL(Tools::strtolower($email));
        $ok = false;

        // Newsletter des comptes clients
        try {
            $db->execute(
                "UPDATE `" . _DB_PREFIX_ . "customer` SET `newsletter` = 0 WHERE LOWER(`email`) = '" . $e . "'"
            );
            $ok = true;
        } catch (\Throwable $ex) {
            // ignoré : on tente quand même la newsletter invités
        }

        // Newsletter des invités (module ps_emailsubscription), si la table existe
        try {
            $table = _DB_PREFIX_ . 'emailsubscription';
            $exists = $db->executeS("SHOW TABLES LIKE '" . pSQL($table) . "'");
            if (is_array($exists) && count($exists) > 0) {
                $db->execute(
                    "UPDATE `" . $table . "` SET `active` = 0 WHERE LOWER(`email`) = '" . $e . "'"
                );
                $ok = true;
            }
        } catch (\Throwable $ex) {
            // ignoré
        }

        if ($ok && class_exists('WatchdogManager')) {
            try {
                (new WatchdogManager($this->module))->info(
                    WatchdogManager::i18nMsg('watchdog.unsubscribe_marketing_processed'),
                    '',
                    'Unsubscribe',
                    ['email' => $email]
                );
            } catch (\Throwable $ex) {
                // log best-effort
            }
        }

        if ($ok && class_exists('WebhookManager')) {
            try {
                (new WebhookManager($this->module))->trigger('unsubscribed', [
                    'customer_email' => $email,
                ]);
            } catch (\Throwable $ex) {
                // best-effort
            }
        }

        return $ok;
    }
}
