<?php
/**
 * Bloc 5 (19/09/2026) : ABTestManager::getEligibleTemplates() (liste
 * proposée dans l'onglet A/B testing) contenait 'back_in_stock' et
 * 'referral_invitation' — aucun des deux n'existe comme template
 * (mails/themes/neria_global/core/). Affichés en slug brut dans le BO ;
 * un test A/B créé dessus ne pouvait jamais rien mesurer. Trouvé en
 * parcourant l'onglet sur ps-test.
 *
 * Test comportemental réel : chaque template proposé existe (.html et .txt)
 * ET possède un libellé lisible (différent du slug brut).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';
    $core = _PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/';

    $eligible = (new ABTestManager(neria_test_module()))->getEligibleTemplates();
    neria_assert(count($eligible) >= 20, "liste éligible anormalement courte (" . count($eligible) . ")");

    $missing = [];
    $unlabeled = [];
    foreach ($eligible as $key => $label) {
        if (!is_file($core . $key . '.html') || !is_file($core . $key . '.txt')) {
            $missing[] = $key;
        }
        if ($label === $key) {
            $unlabeled[] = $key;
        }
    }
    neria_assert(empty($missing), "templates éligibles A/B inexistants : " . implode(', ', $missing));
    neria_assert(empty($unlabeled), "templates éligibles A/B sans libellé lisible (slug brut affiché) : " . implode(', ', $unlabeled));
    neria_assert(isset($eligible['waitlist_available']), "waitlist_available (email de retour en stock) doit être testable en A/B");

    return ['pass' => true, 'message' => "les " . count($eligible) . " templates proposés en A/B existent tous et ont un libellé lisible — bloc 5 (19/09/2026)"];
}
