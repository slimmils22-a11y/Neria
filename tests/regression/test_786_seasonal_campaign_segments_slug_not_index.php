<?php
/**
 * Bloc 4 (18/09/2026) : le formulaire des campagnes saisonnières envoyait
 * l'INDEX du segment (0..4, clé du tableau SegmentManager::getAllSegments())
 * au lieu de son slug. array_filter() supprimait le "0" (Ambassadeur) et
 * getEligibleCustomers() comparait seg.segment IN ('1','2','3','4') à des
 * slugs ('ambassador'...) : toute campagne créée avec le formulaire par
 * défaut (tous segments cochés) ne ciblait AUCUN client. Trouvé en créant
 * une vraie campagne sur ps-test.
 *
 * Test : (1) le template envoie le slug ; (2) normalizeSegments() gère les
 * nouvelles valeurs (slugs), les anciennes lignes numériques déjà stockées
 * ("1,2,3,4" = défaut => aucun filtre ; indices isolés => slugs) et rejette
 * toute valeur inconnue.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $dir = _PS_MODULE_DIR_ . 'neria/';
    require_once $dir . 'src/SegmentManager.php';
    require_once $dir . 'src/SeasonalCampaignManager.php';

    $tpl = (string) file_get_contents($dir . 'views/templates/admin/seasonal.tpl');
    neria_assert(
        strpos($tpl, "name=\"seasonal_segments[]\" value=\"{\$segLabel|escape:'html'}\"") !== false,
        "seasonal.tpl n'envoie plus le slug du segment (value={\$segLabel})"
    );

    $all = SegmentManager::getAllSegments();
    $n   = 'SeasonalCampaignManager::normalizeSegments';

    neria_assert($n('') === [], "CSV vide doit rester vide (aucun filtre)");
    neria_assert($n(implode(',', $all)) === $all, "les 5 slugs doivent être conservés tels quels");
    neria_assert($n('ambassador') === ['ambassador'], "un slug seul doit être conservé (Ambassadeur ne doit pas disparaître)");
    neria_assert($n('1,2,3,4') === [], "ancienne ligne '1,2,3,4' (formulaire par défaut) doit signifier tous les segments");
    neria_assert($n('0,2') === [$all[0], $all[2]], "anciens indices isolés doivent devenir les slugs correspondants");
    neria_assert($n('loyal,bogus,9') === ['loyal'], "valeurs inconnues doivent être rejetées");

    return ['pass' => true, 'message' => "les campagnes saisonnières stockent/lisent des slugs de segment (et migrent à la lecture les anciens index) — bloc 4 (18/09/2026)"];
}
