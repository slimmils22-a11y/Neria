<?php
/**
 * Régression : le handler `reset_abtest`/création de test A/B dans
 * neria.php doit vérifier le retour de ABTestManager::deleteTests() avant
 * de poursuivre avec createTest()/activateTest().
 *
 * Bug identifié le 13/09/2026 (round 349, audit multi-agents, angle A/B
 * testing) : deleteTests() peut échouer partiellement (ex. connexion perdue
 * en plein DELETE) sans que son retour ne soit vérifié — le code
 * poursuivait alors avec createTest()+activateTest(), qui active TOUTES
 * les lignes du template (ABTestManager::activateTest() ne filtre que par
 * `template`, pas par `id_abtest`), y compris d'éventuelles lignes
 * orphelines d'un ancien cycle jamais supprimées. getAllActiveTests()/
 * archiveTest() (SELECT ... variant='B' AND is_active=1 SANS ORDER BY)
 * pouvaient alors remonter un id_abtest arbitraire — risque de voir
 * "appliquer le gagnant" sur le contenu d'un ancien test B jamais
 * réellement mesuré dans le cycle courant.
 *
 * Test structurel (simuler une vraie perte de connexion MySQL en plein
 * DELETE, de façon isolée et reproductible en CLI, est impraticable) :
 * vérifie que le retour de deleteTests() est bien vérifié avant
 * createTest()/activateTest() dans le handler neria.php.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posHandler = strpos($src, "if (Tools::getValue('neria_action') === 'deactivate_abtest'");
    neria_assert($posHandler !== false, "jeu de test invalide : handler deactivate_abtest introuvable, impossible de borner la fenêtre de recherche");

    $posDelete = strrpos(substr($src, 0, $posHandler), 'if (!$ab->deleteTests($tplKey)) {');
    neria_assert(
        $posDelete !== false,
        "Le retour de deleteTests() n'est plus vérifié avant de poursuivre — régression du bug corrigé le 13/09/2026 (round 349) : un échec partiel du DELETE laisserait de nouveau des lignes A/B orphelines réactivées par le cycle suivant"
    );

    $window = substr($src, $posDelete, 500);
    neria_assert(
        strpos($window, '$idA = $ab->createTest(') !== false,
        "createTest() n'est plus appelé dans la branche succès de la vérification — jeu de test invalide ou régression"
    );

    return [
        'pass'    => true,
        'message' => "Le handler A/B testing de neria.php vérifie bien le retour de deleteTests() avant de poursuivre avec createTest()/activateTest() — bug corrigé le 13/09/2026 (round 349)",
    ];
}
