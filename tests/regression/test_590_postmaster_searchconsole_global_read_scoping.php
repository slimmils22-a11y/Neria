<?php
/**
 * Régression : PostmasterManager/SearchConsoleManager écrivent leurs
 * clés OAuth (client_id/secret, access/refresh token, expiry, last_error*)
 * via Configuration::updateGlobalValue() (round 185 — une seule connexion
 * OAuth pour toute l'installation, décision de conception explicite), mais
 * les LISAIENT via un simple Configuration::get($key) sans $idShop
 * explicite. Le cœur PrestaShop (classes/Configuration.php::get()) résout
 * alors $idShop sur la boutique du CONTEXTE COURANT et préfère une
 * éventuelle ligne id_shop-scopée existante à la ligne globale — toute
 * installation ayant eu une ligne PAR BOUTIQUE pour l'une de ces clés
 * (version du module antérieure au round 185, édition manuelle, ou tout
 * autre chemin d'écriture non-global) continuerait à lire indéfiniment
 * cette ancienne valeur périmée pour cette boutique précise, malgré
 * chaque rafraîchissement réussi écrivant bien la nouvelle valeur en
 * global.
 *
 * Corrigé le 06/09/2026 (round 309) : toutes les lectures de ces clés
 * passent désormais par une méthode privée cfgGlobal() qui force
 * $idShop=0 (Configuration::get($key, null, null, 0)), dans les deux
 * classes.
 *
 * Test comportemental réel : écrit la valeur ACTUELLE (fraîche) en
 * GLOBAL, puis insère une valeur STALE dans une ligne PAR BOUTIQUE pour la
 * boutique du contexte courant (simule l'anomalie de données du
 * scénario) — vérifie que cfgGlobal() (via Reflection, méthode privée)
 * renvoie bien la valeur fraîche globale, jamais la valeur périmée par
 * boutique, dans les deux classes.
 *
 * Note d'environnement : Shop::isFeatureActive() (cœur PrestaShop) exige
 * PS_MULTISHOP_FEATURE_ACTIVE=1 ET plus d'une boutique réelle en base —
 * cette install de dev n'a qu'une seule boutique. Or Configuration::get()
 * ignore tout $idShop explicite (y compris 0) tant que
 * Shop::isFeatureActive() est faux (il retombe alors toujours sur la
 * boutique du contexte courant, quel que soit le paramètre reçu) — le bug
 * ET son correctif ne sont donc observables qu'avec le multi-boutique
 * réellement actif. On force temporairement Shop::$feature_active à true
 * via Reflection (propriété protégée, jamais persistée en base) pour
 * exercer le vrai chemin de code multi-boutique du cœur, sans créer de
 * fausse boutique ni toucher aux données réelles.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    $idShop = (int) Context::getContext()->shop->id;
    $module = neria_test_module();

    $featureActiveProp = new ReflectionProperty('Shop', 'feature_active');
    $featureActiveProp->setAccessible(true);
    $originalFeatureActive = $featureActiveProp->getValue();
    $featureActiveProp->setValue(null, true);

    $cases = [
        ['class' => 'PostmasterManager', 'key' => PostmasterManager::CONFIG_CLIENT_ID],
        ['class' => 'SearchConsoleManager', 'key' => SearchConsoleManager::CONFIG_CLIENT_ID],
    ];

    $originalGlobals = [];
    foreach ($cases as $c) {
        $originalGlobals[$c['key']] = Configuration::get($c['key'], null, null, 0);
    }

    try {
        foreach ($cases as $c) {
            $key = $c['key'];

            // 1) Valeur fraîche écrite en GLOBAL (comportement réel du module).
            Configuration::updateGlobalValue($key, 'FRESH_GLOBAL_589');

            // 2) Anomalie de données simulée : une ligne PAR BOUTIQUE périmée
            //    existe pour cette même clé, sur la boutique du contexte
            //    courant (ex. version antérieure au round 185, édition
            //    manuelle).
            Configuration::updateValue($key, 'STALE_SHOP_589', false, null, $idShop);

            // Jeu de test invalide si le cœur PrestaShop ne reproduit plus
            // le scénario (Configuration::get() sans $idShop doit bien
            // renvoyer la valeur PAR BOUTIQUE périmée ici, prouvant que le
            // scénario de bug est réel et pas juste théorique).
            $bareRead = Configuration::get($key);
            neria_assert(
                $bareRead === 'STALE_SHOP_589',
                "jeu de test invalide pour {$c['class']} : Configuration::get('{$key}') sans \$idShop renvoie '{$bareRead}' au lieu de la valeur par boutique périmée attendue — le scénario de bug n'est pas reproductible tel quel sur cet environnement"
            );

            // 3) La méthode réelle de la classe DOIT ignorer cette ligne par
            //    boutique et renvoyer la valeur globale fraîche.
            $mgr = new $c['class']($module);
            $ref = new ReflectionMethod($mgr, 'cfgGlobal');
            $ref->setAccessible(true);
            $result = $ref->invoke($mgr, $key);

            neria_assert(
                $result === 'FRESH_GLOBAL_589',
                "{$c['class']}::cfgGlobal('{$key}') renvoie '{$result}' au lieu de la valeur globale fraîche 'FRESH_GLOBAL_589' — régression du bug corrigé le 06/09/2026 (round 309) : une ligne id_shop-scopée périmée est de nouveau préférée à la ligne globale à jour"
            );

            // Nettoyage intermédiaire de la ligne par boutique avant le cas suivant.
            Db::getInstance()->execute(
                'DELETE FROM ' . _DB_PREFIX_ . 'configuration WHERE name = "' . pSQL($key) . '" AND id_shop = ' . $idShop
            );
            Configuration::loadConfiguration();
        }

        return [
            'pass'    => true,
            'message' => "PostmasterManager/SearchConsoleManager lisent bien leurs clés OAuth volontairement globales via cfgGlobal() (id_shop=0 forcé), ignorant toute ligne id_shop-scopée périmée — bug corrigé le 06/09/2026 (round 309)",
        ];
    } finally {
        foreach ($cases as $c) {
            $key = $c['key'];
            Db::getInstance()->execute(
                'DELETE FROM ' . _DB_PREFIX_ . 'configuration WHERE name = "' . pSQL($key) . '" AND id_shop = ' . $idShop
            );
            $orig = $originalGlobals[$key];
            if ($orig !== false && $orig !== null && $orig !== '') {
    Configuration::updateGlobalValue($key, $orig);
            } else {
                Configuration::deleteByName($key);
            }
        }
        Configuration::loadConfiguration();
        $featureActiveProp->setValue(null, $originalFeatureActive);
    }
}
