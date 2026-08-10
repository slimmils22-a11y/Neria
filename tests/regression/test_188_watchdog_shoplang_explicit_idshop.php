<?php
/**
 * Régression : WatchdogManager::shopLang() doit accepter un $idShop
 * explicite et le transmettre à Configuration::get(), et ses 7 appelants
 * externes doivent le lui fournir plutôt que de compter sur le contexte
 * global.
 *
 * Bug réel corrigé le 09/08/2026 (round 142) : shopLang() (méthode
 * statique) appelait Configuration::get('PS_LANG_DEFAULT') sans idShop —
 * contrairement au reste de WatchdogManager qui capture scrupuleusement
 * $this->idShop au constructeur. Pendant une boucle multi-boutique
 * (BehavioralCronManager::run(), simple réassignation de Context->shop,
 * jamais Shop::setContext()), les appelants externes composaient leurs
 * messages Watchdog dans la langue par défaut de la boutique d'ORIGINE du
 * cron, pas celle réellement en cours de traitement.
 *
 * Test structurel assumé explicitement : Configuration::get()/updateValue()
 * du cœur PrestaShop ignorent silencieusement tout $idShop explicite quand
 * Shop::isFeatureActive() === false (installation mono-boutique, cas de cet
 * environnement de dev — cf. leçon round 141, même piège que
 * Configuration::updateValue()) : impossible de distinguer par le
 * comportement, dans CET environnement, un appel avec idShop explicite
 * d'un appel sans, puisque le cœur PS retombe de toute façon sur le
 * contexte global dans les deux cas. Un vrai test comportemental
 * nécessiterait une installation multi-boutique active. Vérifie donc que
 * le paramètre existe et est bien transmis à Configuration::get(), et que
 * chacun des 7 appelants externes connus lui fournit un idShop explicite
 * plutôt que d'appeler shopLang() à vide.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    // 1. shopLang() accepte bien un paramètre idShop et le transmet
    $ref = new ReflectionMethod(WatchdogManager::class, 'shopLang');
    $params = $ref->getParameters();
    neria_assert(
        count($params) === 1 && $params[0]->getName() === 'idShop' && $params[0]->allowsNull(),
        "WatchdogManager::shopLang() n'accepte plus de paramètre \$idShop optionnel — régression du bug corrigé le 09/08/2026 (round 142)"
    );

    $wdSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php');
    $posShopLang = strpos($wdSrc, 'public static function shopLang(?int $idShop = null): string');
    neria_assert($posShopLang !== false, "signature de shopLang() modifiée de façon inattendue — jeu de test invalide");
    $body = substr($wdSrc, $posShopLang, 400);
    neria_assert(
        strpos($body, "Configuration::get('PS_LANG_DEFAULT', null, null, \$idShop)") !== false,
        "shopLang() ne transmet plus \$idShop à Configuration::get() — régression du bug corrigé le 09/08/2026 (round 142)"
    );
    neria_assert(
        strpos($wdSrc, 'return self::shopLang($this->idShop);') !== false,
        "getShopLang() (instance) ne transmet plus \$this->idShop à shopLang() — régression du bug corrigé le 09/08/2026 (round 142)"
    );

    // 2. Les 7 appelants externes connus passent bien un idShop explicite
    $callers = [
        'ABTestManager.php'       => '\WatchdogManager::shopLang($this->idShop)',
        'EmailRenderer.php'       => 'WatchdogManager::shopLang((int) \Context::getContext()->shop->id)',
        'PostmasterManager.php'   => '\WatchdogManager::shopLang((int) \Context::getContext()->shop->id)',
        'SearchConsoleManager.php' => '\WatchdogManager::shopLang((int) \Context::getContext()->shop->id)',
    ];
    foreach ($callers as $file => $expected) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/' . $file);
        neria_assert(
            strpos($src, $expected) !== false,
            "{$file} n'appelle plus shopLang() avec un idShop explicite — régression du bug corrigé le 09/08/2026 (round 142) : les messages Watchdog composés depuis ce fichier pendant une boucle multi-boutique redeviendraient dans la mauvaise langue"
        );
        neria_assert(
            strpos($src, '\WatchdogManager::shopLang()') === false && strpos($src, 'WatchdogManager::shopLang()') === false,
            "{$file} contient encore un appel à shopLang() SANS argument — régression partielle du bug corrigé le 09/08/2026 (round 142)"
        );
    }

    // PageSpeedManager et SeoApiManager ont 2 appels chacun
    foreach (['PageSpeedManager.php', 'SeoApiManager.php'] as $file) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/' . $file);
        $count = substr_count($src, 'WatchdogManager::shopLang((int) \Context::getContext()->shop->id)');
        neria_assert(
            $count === 2,
            "{$file} : {$count}/2 appels à shopLang() passent un idShop explicite — régression du bug corrigé le 09/08/2026 (round 142)"
        );
        neria_assert(
            strpos($src, 'WatchdogManager::shopLang())') === false,
            "{$file} contient encore un appel à shopLang() SANS argument — régression partielle du bug corrigé le 09/08/2026 (round 142)"
        );
    }

    return [
        'pass'    => true,
        'message' => "WatchdogManager::shopLang() accepte bien un idShop explicite, transmis par ses 7 appelants externes connus (ABTestManager, EmailRenderer, PageSpeedManager×2, PostmasterManager, SearchConsoleManager, SeoApiManager×2)",
    ];
}
