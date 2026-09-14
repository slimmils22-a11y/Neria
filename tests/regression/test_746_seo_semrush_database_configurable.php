<?php
/**
 * Régression : SeoApiManager::fetchSemrush() ne doit plus coder en dur
 * 'database' => 'fr' dans ses 2 appels API Semrush — le module est vendu à
 * des commerçants du monde entier (PrestaShop Addons), pas seulement des
 * marchands francophones. Un marchand hors zone francophone recevait un
 * rapport SEO quasi vide/faussé (trafic organique proche de 0 sur la base
 * FR de Semrush), silencieusement, sans aucune indication que la cause
 * était un mauvais périmètre géographique côté appel API.
 *
 * Bug identifié le 14/09/2026 (round 354, audit multi-agents, décision
 * produit confirmée — même raisonnement que les findings loyalty/collection
 * : ne pas juger uniquement sur l'usage propre francophone de l'auteur).
 *
 * Corrigé le 14/09/2026 : nouvelle constante NERIA_SEMRUSH_DATABASE
 * (Configuration globale), lue avec repli sur 'us' (base Semrush la plus
 * large, choix neutre par défaut) si non configurée — au lieu du 'fr' figé
 * précédent.
 *
 * Test structurel (aucune vraie clé Semrush disponible dans cet
 * environnement de test pour un appel API réel) : vérifie que le code
 * source ne contient plus le littéral 'database' => 'fr' figé, et que les
 * 2 appels utilisent bien la variable résolue depuis la configuration.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeoApiManager.php');

    neria_assert(
        strpos($src, "'database'       => 'fr',") === false,
        "SeoApiManager::fetchSemrush() code encore en dur 'database' => 'fr' — régression du bug corrigé le 14/09/2026 (round 354) : tout marchand hors zone francophone recevrait de nouveau un rapport SEO quasi vide/faussé sans indication de la cause"
    );

    neria_assert(
        strpos($src, "const CONFIG_SEMRUSH_DATABASE = 'NERIA_SEMRUSH_DATABASE';") !== false,
        "SeoApiManager n'a plus la constante CONFIG_SEMRUSH_DATABASE — régression du bug corrigé le 14/09/2026 (round 354)"
    );

    neria_assert(
        strpos($src, "\$semrushDb = (string) \\Configuration::get(self::CONFIG_SEMRUSH_DATABASE) ?: 'us';") !== false,
        "SeoApiManager::fetchSemrush() ne résout plus la base Semrush via la configuration (repli 'us') — régression du bug corrigé le 14/09/2026 (round 354)"
    );

    $usageCount = substr_count($src, "'database'       => \$semrushDb,");
    neria_assert(
        $usageCount === 2,
        "SeoApiManager::fetchSemrush() n'utilise plus \$semrushDb dans les 2 appels API Semrush (domain_ranks ET domain_organic) — régression du bug corrigé le 14/09/2026 (round 354), trouvé {$usageCount} occurrence(s)"
    );

    return [
        'pass'    => true,
        'message' => "SeoApiManager::fetchSemrush() résout désormais la base Semrush via NERIA_SEMRUSH_DATABASE (repli 'us') au lieu du 'fr' figé — bug corrigé le 14/09/2026 (round 354)",
    ];
}
