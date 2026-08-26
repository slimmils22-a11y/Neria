<?php
/**
 * Régression : CooldownManager::isDuplicate() n'avait pas $use_cache=false
 * sur son check-then-act anti-doublon — même famille de bug systémique que
 * le round 210 (Db::getValue() met en cache SQL le résultat d'une requête
 * par défaut).
 *
 * Bug réel identifié le 25/08/2026 (round 211) : isDuplicate() est appelée
 * en boucle sur un même lot par plusieurs managers (QueueManager,
 * BehavioralCronManager, OrderTriggersManager, WaitlistManager...). Deux
 * appels consécutifs pour le même customer+template+scope, dans le MÊME
 * process PHP, ont un texte SQL strictement identique. Sans
 * $use_cache=false, un 2e appel pouvait lire un COUNT(*)=0 mis en cache
 * AVANT l'insertion de la ligne 'sent' du 1er envoi — contournant
 * silencieusement le Mode Silence dans ce cas précis, dans le même run.
 *
 * Corrigé le 25/08/2026 (round 211) : $use_cache=false ajouté.
 *
 * Test comportemental réel : avec le cache SQL PrestaShop RÉELLEMENT actif
 * (backend en mémoire injecté via Cache::setInstanceForTesting(), le
 * backend Memcache par défaut de cet environnement n'étant pas disponible
 * en CLI — même technique que test_440), on seed une ligne 'sent' RÉELLE
 * en base puis on vérifie qu'isDuplicate() la détecte immédiatement,
 * malgré un premier appel "négatif" (pas de doublon) fait juste avant
 * l'insertion avec le MÊME texte SQL exact — prouvant que le résultat
 * n'est pas resservi depuis un cache périmé.
 */
require_once __DIR__ . '/bootstrap.php';

/** Cache minimal en mémoire, pour ce test uniquement (même que test_440). */
if (!class_exists('NeriaTestInMemoryCache')) {
    class NeriaTestInMemoryCache extends Cache
    {
        private array $store = [];
        protected function _set($key, $value, $ttl = 0)
        {
            $this->store[$key] = $value;
            return true;
        }
        protected function _get($key)
        {
            return $this->store[$key] ?? false;
        }
        protected function _exists($key)
        {
            return isset($this->store[$key]);
        }
        protected function _delete($key)
        {
            unset($this->store[$key]);
            return true;
        }
        protected function _writeKeys()
        {
            return true;
        }
        public function flush()
        {
            $this->store = [];
            return true;
        }
    }
}

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CooldownManager.php';

    $db = Db::getInstance();
    $idShop = (int) Context::getContext()->shop->id;
    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
    $email = 'round211cooldowntest@example.test';
    $template = 'round211_test_template';

    $idCustomer = (int) Customer::customerExists($email, true);

    $originalCache = Cache::getInstance();
    Cache::setInstanceForTesting(new NeriaTestInMemoryCache());
    $db->enableCache();

    try {
        if (!$idCustomer) {
            $c = new Customer();
            $c->firstname = 'Roundcooldown';
            $c->lastname  = 'Testeleven';
            $c->email     = $email;
            $c->passwd    = Tools::hash('round211test');
            $c->id_lang   = $idLang;
            $c->add();
            $idCustomer = (int) $c->id;
        }

        $mgr = new CooldownManager();

        // 1) Aucun envoi encore enregistré : pas de doublon (requête
        // réellement exécutée, résultat "false" mis en cache sous ce
        // texte SQL exact — cache actif).
        $before = $mgr->isDuplicate($email, $template, 60, $idShop);
        neria_assert(
            $before === false,
            "isDuplicate() détecte à tort un doublon avant tout envoi — jeu de test invalide"
        );

        // 2) Un envoi RÉEL vient d'avoir lieu — insertion directe de la
        // ligne 'sent' correspondante (comme le ferait Mail::Send() suivi
        // du log StatsManager).
        $db->insert('neria_stat', [
            'id_shop'        => $idShop,
            'template'       => pSQL($template),
            'lang'           => 'fr',
            'country_code'   => '',
            'id_customer'    => $idCustomer,
            'id_order'       => 0,
            'ref_scope'      => '',
            'tracking_token' => sha1(uniqid('round211', true)),
            'event_type'     => 'sent',
            'date_add'       => date('Y-m-d H:i:s'),
        ]);

        // 3) MÊME appel exact (même customer/template/fenêtre/shop, donc
        // même texte SQL) — doit maintenant détecter le doublon fraîchement
        // inséré, PAS resservir le "false" mis en cache à l'étape 1.
        $after = $mgr->isDuplicate($email, $template, 60, $idShop);
        neria_assert(
            $after === true,
            "CooldownManager::isDuplicate() ne détecte pas le doublon fraîchement inséré (renvoie {$after} au lieu de true) — régression du bug corrigé le 25/08/2026 (round 211) : le Mode Silence redeviendrait contournable par le cache SQL sur un lot avec doublon client+template+scope"
        );
    } finally {
        $db->execute("DELETE FROM `" . _DB_PREFIX_ . "neria_stat` WHERE template = '" . pSQL($template) . "'");
        $db->disableCache();
        Cache::setInstanceForTesting($originalCache);
        if ($idCustomer) {
            $c = new Customer($idCustomer);
            if (Validate::isLoadedObject($c)) { $c->delete(); }
        }
    }

    return [
        'pass'    => true,
        'message' => "CooldownManager::isDuplicate() détecte bien un doublon fraîchement inséré même avec le cache SQL PrestaShop actif — bug corrigé le 25/08/2026 (round 211)",
    ];
}
