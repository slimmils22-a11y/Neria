<?php
/**
 * Régression : EmailRenderer::isExcluded() doit retomber sur la langue par
 * défaut de la boutique (PS_LANG_DEFAULT) quand $params['idLang'] est
 * absent, plutôt que de laisser $lang = '' — sinon une règle de blacklist
 * ciblée sur une langue précise ne matchait JAMAIS quand idLang manquait
 * dans le contexte d'appel.
 *
 * Bug réel corrigé le 05/08/2026 (round 54) : BlacklistManager::
 * isBlacklisted($template, '') ne matche que les règles "toutes langues"
 * (lang=''), jamais une règle lang='fr' — un template blacklisté
 * spécifiquement pour le français partait quand même si idLang n'était
 * pas transmis dans ce contexte d'appel précis.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BlacklistManager.php';

    $module      = neria_test_module();
    $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
    $langIso     = (new TranslationEngine($module))->langFromId($defaultLang);
    $template    = 'regtest_isexcluded_' . uniqid();

    $bl = new BlacklistManager();
    $bl->add($template, $langIso);

    try {
        $renderer = new EmailRenderer($module);
        $ref = new ReflectionMethod($renderer, 'isExcluded');
        $ref->setAccessible(true);

        // $params SANS idLang — c'est le scénario du bug.
        $result = $ref->invoke($renderer, $template, []);

        neria_assert(
            $result === true,
            "isExcluded() ne bloque plus un template blacklisté pour la langue par défaut quand idLang est absent des params — régression du bug de contournement de blacklist corrigé le 05/08/2026 (obtenu {$result})"
        );

        return [
            'pass'    => true,
            'message' => 'isExcluded() retombe bien sur PS_LANG_DEFAULT et applique la règle de blacklist ciblée par langue même sans idLang',
        ];
    } finally {
        $rows = Db::getInstance()->executeS(
            "SELECT id_blacklist FROM " . _DB_PREFIX_ . "neria_blacklist WHERE template = '" . pSQL($template) . "'"
        );
        foreach ((array) $rows as $r) {
            $bl->remove((int) $r['id_blacklist']);
        }
    }
}
