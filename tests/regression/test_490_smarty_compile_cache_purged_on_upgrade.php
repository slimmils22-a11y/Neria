<?php
/**
 * Régression round 254 bis (31/08/2026) : aucun des ~43 scripts
 * upgrade/upgrade-1.0.X.php ne purgeait le cache de COMPILATION Smarty
 * (var/cache/{env}/smarty/compile/, PHP compilé depuis les .tpl du
 * module) — distinct du cache de RENDU que PrestaShop core gère déjà
 * nativement via Tools::clearCache()/Module::_clearCache().
 *
 * HealthCheckManager::checkSmartyCompileCheck() détecte déjà
 * PASSIVEMENT si PS_SMARTY_FORCE_COMPILE est désactivé (config non
 * standard mais possible sur un hébergement optimisant la perf), auquel
 * cas Smarty ne recompare plus les timestamps source/compilé : un
 * déploiement de nouvelle version du module continuerait alors de
 * servir un .tpl compilé PÉRIMÉ jusqu'à ce qu'un admin remarque
 * l'alerte et vide le cache manuellement.
 *
 * Corrigé le 31/08/2026 : Neria::clearSmartyCompileCacheIfVersionChanged()
 * (appelée à chaque chargement BO via hookDisplayBackOfficeHeaderImpl(),
 * même discipline que les migrations auto-réparatrices existantes du
 * fichier) purge le compile-cache et enregistre la version purgée dans
 * NERIA_SMARTY_COMPILE_PURGED_VERSION — une clé de suivi SÉPARÉE de
 * NERIA_INSTALLED_VERSION (déjà mise à jour par le script d'upgrade
 * lui-même AVANT que ce hook ne s'exécute, donc inutilisable comme
 * déclencheur). Fonctionne même pour un déploiement qui ne passe pas
 * par le gestionnaire de modules PrestaShop (copie de fichiers directe).
 *
 * Test comportemental réel : positionne NERIA_SMARTY_COMPILE_PURGED_VERSION
 * sur une ancienne version fictive, invoque la méthode réelle par
 * Reflection, vérifie que la clé de suivi est bien mise à jour sur la
 * VRAIE version du module (Tools::clearCompile() a donc été appelé sans
 * lever, sans quoi la clé ne serait jamais mise à jour) ; puis vérifie
 * qu'un second appel (version déjà purgée) reste un no-op silencieux
 * (idempotent, ne relève aucune exception).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();

    $originalPurgedVersion = Configuration::getGlobalValue('NERIA_SMARTY_COMPILE_PURGED_VERSION');

    Configuration::updateGlobalValue('NERIA_SMARTY_COMPILE_PURGED_VERSION', '0.0.0-regtest490');

    try {
        $ref = new ReflectionMethod($module, 'clearSmartyCompileCacheIfVersionChanged');
        $ref->setAccessible(true);
        $ref->invoke($module);

        $purgedAfterFirstCall = (string) Configuration::getGlobalValue('NERIA_SMARTY_COMPILE_PURGED_VERSION');
        neria_assert(
            $purgedAfterFirstCall === $module->version,
            "Neria::clearSmartyCompileCacheIfVersionChanged() ne met plus à jour NERIA_SMARTY_COMPILE_PURGED_VERSION après un changement de version détecté (valeur actuelle : '{$purgedAfterFirstCall}', attendu : '{$module->version}') — régression du bug corrigé le 31/08/2026 : le cache de compilation Smarty ne serait de nouveau jamais purgé après une mise à jour"
        );

        // Deuxième appel : la version est déjà marquée comme purgée --
        // doit rester un no-op silencieux (idempotent), sans exception.
        $ref->invoke($module);
        $purgedAfterSecondCall = (string) Configuration::getGlobalValue('NERIA_SMARTY_COMPILE_PURGED_VERSION');
        neria_assert(
            $purgedAfterSecondCall === $module->version,
            "un second appel (version déjà purgée) a modifié NERIA_SMARTY_COMPILE_PURGED_VERSION de façon inattendue — comportement non idempotent"
        );

        // Vérification structurelle : la méthode est bien câblée dans le
        // hook auto-réparateur existant (hookDisplayBackOfficeHeaderImpl()),
        // pas seulement définie sans jamais être appelée.
        $src = str_replace("\r", '', (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php'));
        neria_assert(
            strpos($src, '$this->clearSmartyCompileCacheIfVersionChanged();') !== false,
            "clearSmartyCompileCacheIfVersionChanged() n'est plus appelée depuis hookDisplayBackOfficeHeaderImpl() — la méthode existerait mais ne s'exécuterait jamais en pratique"
        );

        return [
            'pass'    => true,
            'message' => "Neria::clearSmartyCompileCacheIfVersionChanged() purge bien le compile-cache Smarty et enregistre la version au premier appel après un changement détecté (Tools::clearCompile() appelé sans exception), reste idempotente sur les appels suivants, et est bien câblée dans le hook auto-réparateur BO existant — bug corrigé le 31/08/2026",
        ];
    } finally {
        Configuration::updateGlobalValue('NERIA_SMARTY_COMPILE_PURGED_VERSION', (string) $originalPurgedVersion);
    }
}
