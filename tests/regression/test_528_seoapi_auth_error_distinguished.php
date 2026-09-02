<?php
/**
 * Régression : `SeoApiManager::httpGet()` (Semrush) et `fetchMoz()`
 * traitaient tout code HTTP ≠ 200 de façon identique (`wd()->warning()`
 * générique, message "HTTP {code}"), sans distinguer un 401/403 (clé
 * API révoquée/invalide/crédits épuisés) d'une panne réseau transitoire
 * (timeout/500/DNS). `warning()` ne déclenche jamais
 * `sendImmediateAlert()` (contrairement à `error()`/`critical()`) — un
 * marchand dont la clé Semrush/Moz expirait ne recevait donc aucune
 * alerte proactive, et le message générique "HTTP 403" ne l'orientait
 * pas vers la vraie cause. `PageSpeedManager::fetchStrategy()` (même
 * module) distingue déjà correctement 403 -> `error()` depuis le round
 * 171 — jamais répliqué ici.
 *
 * Bug identifié le 01/09/2026 (round 276, audit "expiration silencieuse
 * de clés API tierces").
 *
 * Corrigé le 01/09/2026 (round 276) : 401/403 escaladés en `error()` avec
 * un message explicite ("clé invalide/révoquée/crédits épuisés"), le
 * reste (réseau/500/DNS/429) restant en `warning()` (comportement
 * inchangé).
 *
 * Test réel + structurel : les identifiants de test ne sont pas
 * configurés dans cet environnement de dev (fetchSemrush()/fetchMoz()
 * retournent null avant d'atteindre httpGet(), pas de vraie requête HTTP
 * possible sans clé réelle) — vérifie structurellement la distinction
 * 401/403 vs autres codes dans les 2 méthodes, et la présence des 2
 * nouvelles clés de traduction dans les 19 locales.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeoApiManager.php');

    $posHttpGet = strpos($src, 'private function httpGet(string $url): ?string');
    neria_assert($posHttpGet !== false, 'httpGet() introuvable — jeu de test invalide');
    $httpGetBody = substr($src, $posHttpGet, 2600);
    neria_assert(
        strpos($httpGetBody, "if (\$httpCode === 401 || \$httpCode === 403) {") !== false
            && strpos($httpGetBody, "watchdog.semrush_http_auth_error") !== false,
        "SeoApiManager::httpGet() ne distingue plus 401/403 des autres codes HTTP — régression du bug corrigé le 01/09/2026 (round 276) : une clé Semrush invalide/révoquée redeviendrait noyée dans le même warning() générique qu'une panne réseau transitoire, sans alerte immédiate ni message explicite"
    );

    $posFetchMoz = strpos($src, 'private function fetchMoz(string $domain): ?array');
    neria_assert($posFetchMoz !== false, 'fetchMoz() introuvable — jeu de test invalide');
    $fetchMozBody = substr($src, $posFetchMoz, 3800);
    neria_assert(
        strpos($fetchMozBody, "if (\$httpCode === 401 || \$httpCode === 403) {") !== false
            && strpos($fetchMozBody, "watchdog.moz_http_auth_error") !== false,
        "SeoApiManager::fetchMoz() ne distingue plus 401/403 des autres codes HTTP — régression du bug corrigé le 01/09/2026 (round 276)"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    foreach (['watchdog.semrush_http_auth_error', 'watchdog.moz_http_auth_error'] as $key) {
        foreach ($locales as $l) {
            neria_assert(
                isset($translations[$key][$l]) && $translations[$key][$l] !== '',
                "la clé {$key} est absente ou vide pour la locale '{$l}' dans admin_translations.json"
            );
        }
    }

    return [
        'pass'    => true,
        'message' => "SeoApiManager distingue désormais une clé API Semrush/Moz invalide (401/403 -> error(), alerte immédiate) d'une panne réseau générique (warning(), inchangé) — bug corrigé le 01/09/2026 (round 276)",
    ];
}
