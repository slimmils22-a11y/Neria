<?php
/**
 * Régression : SeoApiManager::httpGet() (utilisée par fetchSemrush()) doit
 * journaliser une alerte Watchdog sur un échec réseau/HTTP, comme sa
 * jumelle fetchMoz() (même fichier) le fait déjà.
 *
 * Bug réel corrigé le 09/08/2026 (round 152) : httpGet() capturait bien
 * curl_error() via recordError() (visible en creusant la page SEO BO),
 * mais n'appelait jamais wd()->warning() — contrairement à fetchMoz() et
 * aux 3 autres managers SEO/API déjà corrigés au round 150
 * (PageSpeedManager, SearchConsoleManager, PostmasterManager). Une panne
 * réseau prolongée sur l'API Semrush (abonnement payant) pouvait passer
 * totalement inaperçue du flux de notifications/monitoring standard du
 * module.
 *
 * Test structurel : vérifie que httpGet() appelle bien wd()->warning() sur
 * son échec réseau/HTTP, avec la clé de traduction dédiée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeoApiManager.php');

    $posFn = strpos($src, 'private function httpGet(string $url): ?string');
    neria_assert($posFn !== false, 'httpGet() introuvable — jeu de test invalide');
    $posEnd = strpos($src, "\n    }\n}", $posFn);
    $body = $posEnd !== false ? substr($src, $posFn, $posEnd - $posFn) : substr($src, $posFn, 800);

    neria_assert(
        strpos($body, '$this->recordError(') !== false,
        "httpGet() n'appelle plus recordError() — jeu de test invalide ou regression distincte"
    );
    neria_assert(
        strpos($body, "\$this->wd()->warning(\\WatchdogManager::i18nMsg('watchdog.semrush_http_error'") !== false,
        "httpGet() ne journalise plus d'alerte Watchdog sur son echec reseau/HTTP — regression du bug corrige le 09/08/2026 (round 152) : une panne Semrush prolongee redeviendrait invisible du flux de notifications standard"
    );

    return [
        'pass'    => true,
        'message' => "SeoApiManager::httpGet() journalise bien une alerte Watchdog sur un echec reseau/HTTP, comme sa jumelle fetchMoz()",
    ];
}
