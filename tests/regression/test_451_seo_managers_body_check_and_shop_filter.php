<?php
/**
 * Régression round 215 (26/08/2026) — 2 correctifs distincts :
 *
 * 1. PostmasterManager::fetchAndCache() — le filtre de domaine
 *    `$shopHost !== '' && !domainsMatch(...)` était fail-OPEN : un
 *    $shopHost vide (PS_SHOP_DOMAIN_SSL mal configuré) rendait la
 *    condition toujours fausse, donc AUCUN domaine n'était filtré — TOUS
 *    les domaines du compte Google Postmaster Tools du marchand
 *    (potentiellement plusieurs boutiques) se retrouvaient mélangés.
 *    Incohérent avec SearchConsoleManager::matchesShopHost(), qui échoue
 *    déjà (fail-CLOSED) sur un host vide. Corrigé en inversant la
 *    condition en fail-closed.
 *
 * 2. PageSpeedManager::fetchStrategy() et SearchConsoleManager::httpPost()
 *    utilisaient `!$body` au lieu de `$body === false` sur un retour
 *    curl_exec() — un corps de réponse littéral "0" aurait été à tort
 *    traité comme un échec réseau. Déjà corrigé ailleurs dans le projet
 *    (SeoApiManager, PostmasterManager::httpPost()) ; le commentaire
 *    round 135 de ce dernier affirmait à tort que SearchConsoleManager::
 *    httpPost() avait déjà ce correctif.
 *
 * Test structurel : vérifie la présence de chaque garde-fou dans le code
 * source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $base = _PS_MODULE_DIR_ . 'neria/';

    $pm = str_replace("\r", '', (string) file_get_contents($base . 'src/PostmasterManager.php'));
    neria_assert($pm !== '', 'Impossible de lire src/PostmasterManager.php');
    neria_assert(
        strpos($pm, "if (\$shopHost === '' || !self::domainsMatch(\$shopHost, \$domainName)) {") !== false,
        "PostmasterManager::fetchAndCache() n'est plus fail-closed sur son filtre de domaine — régression du bug corrigé le 26/08/2026 (round 215) : un \$shopHost vide mélangerait de nouveau tous les domaines du compte Google Postmaster Tools"
    );

    $psm = str_replace("\r", '', (string) file_get_contents($base . 'src/PageSpeedManager.php'));
    neria_assert($psm !== '', 'Impossible de lire src/PageSpeedManager.php');
    neria_assert(
        strpos($psm, 'if ($body === false) {') !== false,
        "PageSpeedManager::fetchStrategy() utilise de nouveau !\$body au lieu de \$body === false — régression du bug corrigé le 26/08/2026 (round 215)"
    );

    $scm = str_replace("\r", '', (string) file_get_contents($base . 'src/SearchConsoleManager.php'));
    neria_assert($scm !== '', 'Impossible de lire src/SearchConsoleManager.php');
    neria_assert(
        substr_count($scm, 'if ($body === false) {') >= 1,
        "SearchConsoleManager::httpPost() utilise de nouveau !\$body au lieu de \$body === false — régression du bug corrigé le 26/08/2026 (round 215)"
    );
    neria_assert(
        strpos($scm, "if (\$body === false) {\n            return [];\n        }") !== false,
        "SearchConsoleManager::httpPost() ne retourne plus [] via \$body === false — régression du bug corrigé le 26/08/2026 (round 215)"
    );

    return [
        'pass'    => true,
        'message' => 'Round 215 : PostmasterManager fail-closed sur le filtre de domaine, PageSpeedManager/SearchConsoleManager utilisent $body === false',
    ];
}
