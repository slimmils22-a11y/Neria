<?php
/**
 * Régression : `SeoApiManager::fetchSemrush()` fabriquait un rapport SEO à
 * ZÉRO PARTOUT quand l'export CSV Semrush ne contenait AUCUNE des colonnes
 * attendues ('Rk','Or','Ot','Oc','Ad','At' — cas déjà rencontré une 1re fois
 * historiquement quand le code lisait les mauvais libellés de colonnes) et
 * le retournait comme un résultat VALIDE : `runCheck()` mettait alors ce
 * rapport fabriqué en cache 24h et appelait `clearError()`, effaçant
 * `getLastError()`. Un simple warning Watchdog interne était bien loggué,
 * mais rien n'empêchait la donnée fabriquée d'écraser silencieusement le
 * dernier rapport correct pendant 24h, sans aucune alerte visible pour le
 * marchand dans le BO (widget Stats SEO affichant "0 partout" présenté
 * comme à jour).
 *
 * Bug identifié le 10/09/2026 (round 335, audit TranslationEngine/
 * TranslationInstaller/SeoApiManager).
 *
 * Corrigé le 10/09/2026 (round 335) : quand aucune colonne attendue n'est
 * trouvée, `fetchSemrush()` appelle désormais `recordError()` (avec la
 * nouvelle clé `msg.semrush_unexpected_columns`, 19 langues) et retourne
 * `null` au lieu de fabriquer un résultat — `runCheck()` ne met alors rien
 * en cache et ne vide pas `getLastError()`, `getReport()` retombe sur le
 * repli "dernier rapport connu" déjà en place (round 171).
 *
 * Test structurel (comme tous les tests existants de ce fichier — httpGet()
 * fait un vrai appel réseau vers l'API Semrush, impraticable à invoquer
 * isolément en CLI, cf. test_240/test_326/test_327/test_528) : vérifie que
 * le chemin "aucune colonne attendue" appelle bien recordError() et
 * retourne null AVANT la construction de $result, et que la nouvelle clé
 * de traduction existe dans les 19 langues.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeoApiManager.php');

    $posFn = strpos($src, 'private function fetchSemrush(string $domain): ?array');
    neria_assert($posFn !== false, 'fetchSemrush() introuvable — jeu de test invalide');

    $posGuard = strpos($src, "count(array_intersect(\$expectedKeys, array_keys(\$row))) === 0", $posFn);
    neria_assert($posGuard !== false, 'Garde "colonnes inattendues" introuvable — jeu de test invalide');

    $posResultBuild = strpos($src, "\$result = [\n            'domain'", $posFn);
    neria_assert($posResultBuild !== false, "Construction de \$result introuvable — jeu de test invalide");
    neria_assert(
        $posGuard < $posResultBuild,
        "La garde 'colonnes inattendues' n'est plus AVANT la construction de \$result — le fichier a peut-être été restructuré, ce test doit être revu"
    );

    $guardBody = substr($src, $posGuard, $posResultBuild - $posGuard);
    neria_assert(
        strpos($guardBody, "\$this->recordError(\\AdminTranslator::t('msg.semrush_unexpected_columns'))") !== false,
        "fetchSemrush() n'appelle plus recordError('msg.semrush_unexpected_columns') quand aucune colonne attendue n'est trouvée — régression du bug corrigé le 10/09/2026 (round 335) : le widget BO Stats SEO redeviendrait aveugle à ce cas, getLastError() ne serait plus renseigné"
    );
    neria_assert(
        strpos($guardBody, 'return null;') !== false,
        "fetchSemrush() ne retourne plus null quand aucune colonne attendue n'est trouvée — régression du bug corrigé le 10/09/2026 (round 335) : un rapport fabriqué à 0 partout serait de nouveau mis en cache 24h comme un résultat valide via runCheck(), effaçant getLastError()"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    neria_assert(
        isset($translations['msg.semrush_unexpected_columns']),
        "Clé 'msg.semrush_unexpected_columns' absente de admin_translations.json"
    );
    foreach ($locales as $loc) {
        neria_assert(
            !empty($translations['msg.semrush_unexpected_columns'][$loc]),
            "Traduction 'msg.semrush_unexpected_columns' manquante pour la langue '{$loc}'"
        );
    }

    return [
        'pass'    => true,
        'message' => "SeoApiManager::fetchSemrush() ne fabrique plus un rapport SEO à 0 partout quand l'export CSV Semrush ne contient aucune colonne attendue — retourne null et appelle recordError() au lieu de mettre en cache une donnée fabriquée comme un succès (round 335)",
    ];
}
