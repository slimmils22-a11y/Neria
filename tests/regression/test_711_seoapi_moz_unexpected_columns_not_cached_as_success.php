<?php
/**
 * Régression : `SeoApiManager::fetchMoz()` fabriquait un rapport SEO à
 * ZÉRO PARTOUT quand la réponse JSON Moz ne contenait AUCUNE des clés
 * attendues ('domain_authority', 'page_authority', 'spam_score',
 * 'links_to_root_domain', 'root_domains_to_root_domain') et le retournait
 * comme un résultat VALIDE : `runCheck()` mettait alors ce rapport fabriqué
 * en cache 24h et appelait `clearError()`, effaçant `getLastError()`. Un
 * simple warning Watchdog interne était bien loggué, mais rien n'empêchait
 * la donnée fabriquée d'écraser silencieusement le dernier rapport correct
 * pendant 24h, sans aucune alerte visible pour le marchand dans le BO.
 *
 * Bug identifié le 12/09/2026 (round 342, audit SeoApiManager/
 * TranslationInstaller/TranslationEngine) — même classe de bug que
 * fetchSemrush() (round 335), jamais répliquée à fetchMoz() jusqu'ici.
 *
 * Corrigé le 12/09/2026 (round 342) : quand aucune clé attendue n'est
 * trouvée, `fetchMoz()` appelle désormais `recordError()` (avec la
 * nouvelle clé `msg.moz_unexpected_columns`, 19 langues) et retourne
 * `null` au lieu de fabriquer un résultat.
 *
 * Test structurel (comme test_682 pour fetchSemrush() — httpGet() fait un
 * vrai appel réseau vers l'API Moz, impraticable à invoquer isolément en
 * CLI) : vérifie que le chemin "aucune clé attendue" appelle bien
 * recordError() et retourne null AVANT la construction de $result, et que
 * la nouvelle clé de traduction existe dans les 19 langues.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeoApiManager.php');

    $posFn = strpos($src, 'private function fetchMoz(string $domain): ?array');
    neria_assert($posFn !== false, 'fetchMoz() introuvable — jeu de test invalide');

    $posGuard = strpos($src, "count(array_intersect(\$expectedKeys, array_keys(\$r))) === 0", $posFn);
    neria_assert($posGuard !== false, 'Garde "clés inattendues" introuvable — jeu de test invalide');

    $posResultBuild = strpos($src, "\$result = [\n            'domain'", $posFn);
    neria_assert($posResultBuild !== false, "Construction de \$result introuvable — jeu de test invalide");
    neria_assert(
        $posGuard < $posResultBuild,
        "La garde 'clés inattendues' n'est plus AVANT la construction de \$result — le fichier a peut-être été restructuré, ce test doit être revu"
    );

    $guardBody = substr($src, $posGuard, $posResultBuild - $posGuard);
    neria_assert(
        strpos($guardBody, "\$this->recordError(\\AdminTranslator::t('msg.moz_unexpected_columns'))") !== false,
        "fetchMoz() n'appelle plus recordError('msg.moz_unexpected_columns') quand aucune clé attendue n'est trouvée — régression du bug corrigé le 12/09/2026 (round 342) : getLastError() ne serait plus renseigné"
    );
    neria_assert(
        strpos($guardBody, 'return null;') !== false,
        "fetchMoz() ne retourne plus null quand aucune clé attendue n'est trouvée — régression du bug corrigé le 12/09/2026 (round 342) : un rapport fabriqué à 0 partout serait de nouveau mis en cache 24h comme un résultat valide via runCheck(), effaçant getLastError()"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    foreach (['msg.moz_unexpected_columns', 'watchdog.moz_unexpected_columns'] as $transKey) {
        neria_assert(
            isset($translations[$transKey]),
            "Clé '{$transKey}' absente de admin_translations.json"
        );
        foreach ($locales as $loc) {
            neria_assert(
                !empty($translations[$transKey][$loc]),
                "Traduction '{$transKey}' manquante pour la langue '{$loc}'"
            );
        }
    }

    return [
        'pass'    => true,
        'message' => "SeoApiManager::fetchMoz() ne fabrique plus un rapport SEO à 0 partout quand la réponse JSON Moz ne contient aucune clé attendue — retourne null et appelle recordError() au lieu de mettre en cache une donnée fabriquée comme un succès (round 342)",
    ];
}
