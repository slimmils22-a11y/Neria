<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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

        // Round 269 : signé via NeriaTools::trackingSignKey() — doit rester
        // en cohérence avec Neria::getUnsubscribeUrl(), qui génère ce même
        // jeton avec la même clé (NERIA_ENCRYPTION_KEY en priorité, jamais
        // affectée par une rotation de _COOKIE_KEY_ côté PrestaShop).
        $expected = substr(hash_hmac('sha256', Tools::strtolower($email), \NeriaTools::trackingSignKey()), 0, 32);
        if (!is_string($token) || !hash_equals($expected, $token)) {
            return false;
        }

        // Round 247 : même philosophie que track.php (round 164) -- ne
        // jamais bloquer l'action visible (le lien de désabonnement DOIT
        // toujours répondre normalement, RFC 8058 l'exige pour le POST
        // "un clic"), mais éviter que le REJEU du même lien légitime en
        // boucle (script) ne déclenche indéfiniment la chaîne complète
        // UPDATE customer + SELECT + PreferencesManager::saveByCustomer()
        // (autre UPDATE/INSERT) + SHOW TABLES/UPDATE emailsubscription +
        // WebhookManager::trigger() (appel HTTP sortant configurable par
        // le marchand) — épuisement DB/CPU, voire amplification réseau
        // vers un tiers, pour un endpoint accessible sans authentification
        // dès qu'un token valide (reçu dans UN email) est connu. Le
        // désabonnement étant idempotent (remettre à 0 une valeur déjà à
        // 0 ne change rien), sauter le traitement au-delà du seuil et
        // renvoyer directement "déjà traité" est sûr : la toute première
        // requête légitime a déjà fait le travail réel. Fail-open si APCu
        // indisponible (best-effort, jamais bloquant).
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $ip  = (string) (Tools::getRemoteAddr() ?: '0.0.0.0');
            $key = 'neria_unsub_rl_' . md5($ip . '|' . $token);
            $hits = (int) apcu_fetch($key, $ok2);
            if (!$ok2) {
                apcu_store($key, 1, 10);
            } else {
                $hits++;
                apcu_store($key, $hits, 10);
                if ($hits > 5) {
                    return true;
                }
            }
        }

        $db     = Db::getInstance();
        $e      = pSQL(Tools::strtolower($email));
        $idShop = (int) $this->context->shop->id;
        $ok     = false;

        // Newsletter des comptes clients — scopé à la boutique courante :
        // `customer` a une colonne id_shop et, en multiboutique sans partage
        // de comptes, la même adresse email peut correspondre à des lignes
        // client distinctes par boutique. Sans ce filtre, cliquer sur le
        // lien de désabonnement reçu de la boutique A désabonnait aussi
        // silencieusement le même email sur les boutiques B, C...
        try {
            $db->execute(
                "UPDATE `" . _DB_PREFIX_ . "customer` SET `newsletter` = 0
                 WHERE LOWER(`email`) = '" . $e . "' AND `id_shop` = " . $idShop
            );
            $ok = true;
        } catch (\Throwable $ex) {
            // ignoré : on tente quand même la newsletter invités
        }

        // Catégories Neria (ps_neria_preferences) — TOUTES à 0. Auparavant
        // absent : ce contrôleur ne touchait que ps_customer.newsletter (et
        // ps_emailsubscription pour les invités), jamais neria_preferences.
        // Or c'est EXCLUSIVEMENT neria_preferences que consulte
        // PreferencesManager::isAllowed() avant chaque envoi Neria — un
        // client qui cliquait sur "se désabonner" (ou dont le client mail
        // envoyait le POST one-click RFC 8058) voyait une confirmation de
        // désabonnement tout en continuant à recevoir la totalité des
        // emails comportementaux/saisonniers/fidélité/B2B, puisque
        // isAllowed() reste "true" par défaut tant qu'aucune ligne
        // neria_preferences n'existe. Toutes les catégories sont mises à 0
        // (pas seulement celle du template d'origine) : un désabonnement
        // "un clic" doit être total, conformément à la RFC 8058.
        // Round 266 : $prefsOk trace le succès de CE canal précis, distinct
        // du $ok global partagé par les 3 canaux — nécessaire pour détecter
        // plus bas un échec partiel silencieux (ex. ps_customer.newsletter
        // mis à 0 avec succès, MAIS cette étape en échec) : sans ce
        // distinguo, $ok restait déjà "true" grâce à un canal précédent et
        // masquait totalement l'échec de neria_preferences — la SEULE table
        // consultée par PreferencesManager::isAllowed(), donc le client
        // voyait la confirmation de désabonnement tout en continuant à
        // recevoir la totalité des emails comportementaux/fidélité/
        // saisonniers/B2B Neria, sans aucune trace exploitable par le
        // marchand pour détecter le problème.
        $prefsOk = false;
        // Round 290 : $customerId hissé hors du bloc PreferencesManager pour
        // être réutilisable par le webhook 'unsubscribed' plus bas (minimisation
        // des données — customer_id plutôt que l'email en clair quand
        // l'adresse correspond à un vrai compte client, aligné sur les 4
        // autres événements du module qui transmettent tous customer_id).
        $customerId = 0;
        if (class_exists('PreferencesManager')) {
            try {
                $customerId = (int) $db->getValue(
                    "SELECT `id_customer` FROM `" . _DB_PREFIX_ . "customer`
                     WHERE LOWER(`email`) = '" . $e . "' AND `id_shop` = " . $idShop
                );
                // Round 188 : la branche invité (id_customer=0, adresse
                // jamais devenue client PrestaShop — cas d'un abonné
                // newsletter/newsletter_voucher uniquement via
                // ps_emailsubscription) était absente : saveByCustomer()
                // n'était appelée QUE si $customerId > 0. PreferencesManager
                // gère pourtant explicitement id_customer=0 + email
                // (isAllowed()/saveByCustomer(), cf. commentaire round 178
                // sur la clé unique incluant l'email pour ce cas précis) —
                // sans cette branche, un invité cliquant sur le lien "un
                // clic" recevait bien la confirmation de désabonnement, mais
                // continuait à recevoir toutes les autres catégories d'email
                // Neria pour son adresse, faute de ligne neria_preferences
                // créée (opt-in par défaut tant qu'aucune ligne n'existe).
                (new \PreferencesManager($this->module))->saveByCustomer(
                    $customerId,
                    $email,
                    array_fill_keys(\PreferencesManager::CATEGORIES, 0)
                );
                $ok      = true;
                $prefsOk = true;
            } catch (\Throwable $ex) {
                // ignoré pour l'affichage : les autres canaux de désabonnement
                // ci-dessus restent traités — mais journalisé plus bas via
                // Watchdog si un autre canal a réussi, pour rester détectable.
            }
        }

        // Newsletter des invités (module ps_emailsubscription), si la table existe
        // — même raisonnement : la table est explicitement scopée par id_shop.
        try {
            $table = _DB_PREFIX_ . 'emailsubscription';
            $exists = $db->executeS("SHOW TABLES LIKE '" . pSQL($table) . "'");
            if (is_array($exists) && count($exists) > 0) {
                $db->execute(
                    "UPDATE `" . $table . "` SET `active` = 0
                     WHERE LOWER(`email`) = '" . $e . "' AND `id_shop` = " . $idShop
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

        // Round 266 : $ok est vrai dès qu'UN SEUL des 3 canaux a réussi, ce
        // qui masquait un échec de neria_preferences (canal exclusivement
        // consulté par PreferencesManager::isAllowed() avant tout envoi
        // Neria) tant que ps_customer.newsletter ou ps_emailsubscription
        // avaient réussi de leur côté. Le rendu de la page de confirmation
        // reste inchangé (toujours best-effort, jamais bloquant — cf. round
        // 247), mais ce cas devient désormais détectable par le marchand.
        if ($ok && !$prefsOk && class_exists('PreferencesManager') && class_exists('WatchdogManager')) {
            try {
                (new WatchdogManager($this->module))->warning(
                    WatchdogManager::i18nMsg('watchdog.unsubscribe_preferences_channel_failed', ['email' => $email]),
                    '',
                    'Unsubscribe',
                    ['email' => $email]
                );
            } catch (\Throwable $ex) {
                // log best-effort
            }
        }

        // Round 265 : le throttle APCu ci-dessus (5 requêtes/10s) protège
        // la DB/le CPU contre une rafale rapide, mais ne protège PAS le
        // webhook sortant 'unsubscribed' contre un REJEU espacé de plus de
        // 10 secondes du même lien légitime (rechargement de la page de
        // confirmation, retry réseau du client mail sur le POST one-click
        // RFC 8058, scanner de sécurité d'entreprise qui pré-visite le lien
        // List-Unsubscribe) — le désabonnement lui-même reste idempotent
        // ($ok=true à chaque fois), mais WebhookManager::trigger() met
        // inconditionnellement un NOUVEL événement en file à chaque appel,
        // sans vérifier qu'un événement équivalent a déjà été notifié
        // récemment pour ce même token. Un service tiers non idempotent
        // (CRM, plateforme externe) recevrait alors plusieurs notifications
        // 'unsubscribed' pour un seul désabonnement réel. Fenêtre 24h
        // (indépendante du throttle DB ci-dessus, dédiée à CE seul appel) :
        // fail-open si APCu indisponible, comme le throttle plus haut.
        $webhookAlreadyNotified = false;
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $webhookKey = 'neria_unsub_webhook_' . md5($token);
            apcu_fetch($webhookKey, $webhookAlreadyNotified);
            if (!$webhookAlreadyNotified) {
                apcu_store($webhookKey, 1, 86400);
            }
        }

        if ($ok && !$webhookAlreadyNotified && class_exists('WebhookManager')) {
            try {
                // Round 290 : customer_id (comme les 4 autres événements
                // webhook du module) plutôt que l'email en clair quand
                // l'adresse correspond à un vrai compte client — l'email
                // reste transmis SEULEMENT en repli pour un invité
                // (id_customer=0, jamais devenu client PrestaShop, cf.
                // round 188 plus haut) : un id interne à cette boutique ne
                // serait alors pas exploitable par le récepteur externe, qui
                // ne connaît cette adresse que par son email.
                $webhookPayload = $customerId > 0
                    ? ['customer_id' => $customerId]
                    : ['customer_email' => $email];
                (new WebhookManager($this->module))->trigger('unsubscribed', $webhookPayload);
            } catch (\Throwable $ex) {
                // best-effort
            }
        }

        return $ok;
    }
}
