<?php
/**
 * Régression : AbTestManager::getVariantForEmail() utilisait l'email brut
 * (trim() seulement, sans normalisation de casse) comme clé de hash
 * crc32() pour la répartition A/B. Le principe documenté du module est
 * qu'un même destinataire reçoit TOUJOURS la même variante — mais un
 * client dont la casse de l'email change entre deux envois (invité
 * "Jean.Dupont@Gmail.com" puis compte normalisé en minuscules, ou casse
 * corrigée en BO) pouvait obtenir un hash différent et basculer de
 * variante, faussant le suivi et la significativité statistique du test.
 *
 * Corrigé le 17/08/2026 (round 182) : mb_strtolower() ajouté avant le
 * hash.
 *
 * Test comportemental réel : crée un vrai test A/B actif, appelle
 * getVariantForEmail() avec la MÊME adresse dans 3 casses différentes et
 * vérifie que la variante retournée est identique dans les 3 cas.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/AbTestManager.php';

    $db      = neria_test_db();
    $prefix  = neria_test_prefix();
    $module  = neria_test_module();
    $template = 'regtest_374_abtest';

    $mgr = new AbTestManager($module);

    try {
        $db->execute("DELETE FROM {$prefix}neria_abtest WHERE template = '" . pSQL($template) . "'");

        $created = $mgr->createTest($template, 'Variante A', 'Variante B', 50, 'Test round 182');
        neria_assert($created !== false, "createTest() a échoué — jeu de test invalide");

        $activated = $mgr->activateTest($template);
        neria_assert($activated === true, "activateTest() a échoué — jeu de test invalide");

        // Nouvelle instance pour forcer un rechargement propre du cache actif.
        $mgr2 = new AbTestManager($module);

        $emailLower = 'jean.dupont.regtest374@example.com';
        $emailMixed = 'Jean.Dupont.RegTest374@Example.com';
        $emailUpper = 'JEAN.DUPONT.REGTEST374@EXAMPLE.COM';

        $variantLower = $mgr2->getVariantForEmail($template, 0, $emailLower);
        $variantMixed = $mgr2->getVariantForEmail($template, 0, $emailMixed);
        $variantUpper = $mgr2->getVariantForEmail($template, 0, $emailUpper);

        neria_assert(
            $variantLower === $variantMixed && $variantLower === $variantUpper,
            "getVariantForEmail() renvoie des variantes différentes selon la casse de l'email (minuscule={$variantLower}, mixte={$variantMixed}, majuscule={$variantUpper}) — régression du bug corrigé le 17/08/2026 (round 182) : un même destinataire dont la casse de l'email change entre deux envois basculerait de nouveau de variante A/B"
        );
    } finally {
        $mgr->deleteTests($template);
        $db->execute("DELETE FROM {$prefix}neria_abtest WHERE template = '" . pSQL($template) . "'");
    }

    return [
        'pass'    => true,
        'message' => "AbTestManager::getVariantForEmail() renvoie bien la même variante quelle que soit la casse de l'email — bug corrigé le 17/08/2026 (round 182)",
    ];
}
