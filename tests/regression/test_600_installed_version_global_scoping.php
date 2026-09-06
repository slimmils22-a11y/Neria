<?php
/**
 * Régression : NERIA_INSTALLED_VERSION était écrite via
 * Configuration::updateValue() — qui écrit sur la boutique du CONTEXTE BO
 * COURANT dès que le multi-boutique est actif, pas globalement — à 3
 * endroits (upgrade-1.0.45.php x2, neria.php::setDefaultConfiguration())
 * et lue sans forcer id_shop=0 à 2 autres endroits (HealthCheckManager::
 * checkVersionSync(), neria.php action repair_module_version). Exécuté
 * depuis un contexte BO positionné sur une boutique précise (pas "Toutes
 * les boutiques"), seule cette boutique obtenait la ligne — désynchronisant
 * checkVersionSync() pour toutes les autres boutiques de l'installation,
 * déclenchant un faux avertissement "upgrade script non exécuté" — même
 * classe de bug ("Bug 2") déjà corrigée pour 4 autres clés dans
 * upgrade-1.0.40.php, jamais étendue à celle-ci.
 *
 * Corrigé le 06/09/2026 (round 312) : les 3 écritures passent à
 * updateGlobalValue() ; les 2 lectures forcent id_shop=0 explicite.
 *
 * Test comportemental réel : écrit la version ACTUELLE en GLOBAL, insère
 * une valeur STALE dans une ligne PAR BOUTIQUE (simule l'anomalie de
 * données du scénario), puis vérifie que checkVersionSync() (via
 * Reflection) renvoie bien 'ok' avec la version globale à jour — pas un
 * avertissement basé sur la valeur par boutique périmée. Même technique
 * que test_590 (round 309) : Shop::$feature_active forcé via Reflection
 * pour exercer le vrai chemin multi-boutique du cœur sur cette install de
 * dev mono-boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $idShop = (int) Context::getContext()->shop->id;
    $key    = 'NERIA_INSTALLED_VERSION';
    $module = neria_test_module();

    $originalGlobal = Configuration::get($key, null, null, 0);

    $featureActiveProp = new ReflectionProperty('Shop', 'feature_active');
    $featureActiveProp->setAccessible(true);
    $originalFeatureActive = $featureActiveProp->getValue();
    $featureActiveProp->setValue(null, true);

    try {
        $currentVersion = $module->version;

        // 1) Valeur fraîche écrite en GLOBAL (comportement réel du module).
        Configuration::updateGlobalValue($key, $currentVersion);

        // 2) Anomalie de données simulée : une ligne PAR BOUTIQUE périmée
        //    (version antérieure) existe pour la boutique du contexte courant.
        Configuration::updateValue($key, '0.0.1-stale', false, null, $idShop);

        $bareRead = Configuration::get($key);
        neria_assert(
            $bareRead === '0.0.1-stale',
            "jeu de test invalide : Configuration::get('{$key}') sans \$idShop renvoie '{$bareRead}' au lieu de la valeur par boutique périmée attendue — le scénario de bug n'est pas reproductible tel quel sur cet environnement"
        );

        $mgr = new HealthCheckManager($module);
        $ref = new ReflectionMethod($mgr, 'checkVersionSync');
        $ref->setAccessible(true);
        $result = $ref->invoke($mgr);

        neria_assert(
            ($result['status'] ?? '') === HealthCheckManager::STATUS_OK,
            "HealthCheckManager::checkVersionSync() renvoie le statut '" . ($result['status'] ?? '?') . "' au lieu de 'ok' alors que la version globale à jour existe bien — régression du bug corrigé le 06/09/2026 (round 312) : une ligne id_shop-scopée périmée est de nouveau préférée à la ligne globale à jour, déclenchant un faux avertissement 'upgrade non exécuté'. Détail : " . ($result['detail'] ?? '')
        );

        // Nettoyage intermédiaire.
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'configuration WHERE name = "' . pSQL($key) . '" AND id_shop = ' . $idShop
        );
        Configuration::loadConfiguration();

        return [
            'pass'    => true,
            'message' => "NERIA_INSTALLED_VERSION est bien écrite/lue globalement (updateGlobalValue()/id_shop=0), ignorant toute ligne id_shop-scopée périmée — bug corrigé le 06/09/2026 (round 312)",
        ];
    } finally {
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'configuration WHERE name = "' . pSQL($key) . '" AND id_shop = ' . $idShop
        );
        if ($originalGlobal !== false && $originalGlobal !== null && $originalGlobal !== '') {
            Configuration::updateGlobalValue($key, $originalGlobal);
        }
        Configuration::loadConfiguration();
        $featureActiveProp->setValue(null, $originalFeatureActive);
    }
}
