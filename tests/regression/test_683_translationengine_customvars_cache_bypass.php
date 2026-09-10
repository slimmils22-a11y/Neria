<?php
/**
 * Régression : `TranslationEngine::loadCustomVars()` lisait
 * `neria_custom_variable` sans jamais passer `$use_cache=false` — même
 * famille de bug que les rounds 210-216 sur les 3 AUTRES `executeS()` de
 * ce même fichier (`loadBlock()`, `getAvailableTemplates()`,
 * `getAvailableLangs()`), qui ont tous déjà ce garde-fou. Sans lui, un
 * marchand modifiant une variable personnalisée en BO
 * (`ConfigManager::setCustomVariable()`, ex. `{maison_name}`, `{slogan}`)
 * puis déclenchant un envoi d'email juste après (nouvelle requête PHP-FPM)
 * pouvait recevoir l'ancienne valeur si le cache SQL PrestaShop était actif
 * sous ce texte de requête.
 *
 * Bug identifié le 10/09/2026 (round 335, audit TranslationEngine/
 * TranslationInstaller/SeoApiManager).
 *
 * Corrigé le 10/09/2026 (round 335) : `executeS($sql, true, false)` dans
 * `loadCustomVars()`, alignée sur les 3 autres executeS() du fichier.
 *
 * Test structurel (comme test_665/test_681 — `Db::$is_cache_enabled` est
 * vide dans cet environnement de dev, le cache SQL n'y a donc aucun effet
 * observable localement) + comportemental sur le fonctionnement NOMINAL :
 * insère une vraie traduction contenant `{neriatest683var}` et une vraie
 * variable personnalisée du même nom, vérifie que
 * `TranslationEngine::get()` résout bien la substitution — garantissant
 * que l'ajout de `$use_cache=false` n'a pas cassé le comportement normal.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif ───────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php');
    neria_assert($src !== false, 'Impossible de lire src/TranslationEngine.php');

    $posFn = strpos($src, 'private function loadCustomVars(): void');
    neria_assert($posFn !== false, 'loadCustomVars() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 900);
    neria_assert(
        strpos($body, "WHERE `id_shop` = {\$idShop}\",\n            true,\n            false\n        );") !== false,
        "TranslationEngine::loadCustomVars() n'a plus \$use_cache=false sur son executeS() — régression du bug corrigé le 10/09/2026 (round 335) : une variable personnalisée fraîchement modifiée en BO pourrait de nouveau ne pas être reflétée immédiatement dans les emails envoyés juste après"
    );

    // ── Vérification comportementale du chemin nominal ───────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $module = neria_test_module();

    $template = 'regtest683_template';
    $key      = 'regtest683_key';
    $varKey   = 'neriatest683var';
    $varTable = $prefix . 'neria_custom_variable';
    $trTable  = $prefix . 'neria_translation';

    // Nettoyage préventif (au cas où un run précédent aurait échoué avant le finally)
    $db->execute("DELETE FROM {$trTable} WHERE template = '" . pSQL($template) . "'");
    $db->execute("DELETE FROM {$varTable} WHERE id_shop = {$idShop} AND variable_key = '" . pSQL($varKey) . "'");

    $db->execute(
        "INSERT INTO {$trTable} (template, lang, translation_key, translation_value, is_custom, date_add, date_upd)
         VALUES ('" . pSQL($template) . "', 'fr', '" . pSQL($key) . "', 'Bonjour {" . pSQL($varKey) . "} !', 0, NOW(), NOW())"
    );

    try {
        $configMgr = new ConfigManager($module);
        $set = $configMgr->setCustomVariable($varKey, 'Maison Regtest683');
        neria_assert($set, "ConfigManager::setCustomVariable() a échoué — jeu de test invalide");

        $engine = new TranslationEngine($module);
        $resolved = $engine->get($template, $key, 'fr');

        neria_assert(
            $resolved === 'Bonjour Maison Regtest683 !',
            "TranslationEngine::get() ne résout plus correctement la variable personnalisée fraîchement enregistrée (attendu 'Bonjour Maison Regtest683 !', obtenu '{$resolved}') — comportement nominal cassé par l'ajout de \$use_cache=false"
        );

        return [
            'pass'    => true,
            'message' => "TranslationEngine::loadCustomVars() bypasse désormais le cache SQL (\$use_cache=false), comportement nominal de résolution des variables personnalisées préservé — bug corrigé le 10/09/2026 (round 335)",
        ];
    } finally {
        $db->execute("DELETE FROM {$trTable} WHERE template = '" . pSQL($template) . "'");
        $db->execute("DELETE FROM {$varTable} WHERE id_shop = {$idShop} AND variable_key = '" . pSQL($varKey) . "'");
    }
}
