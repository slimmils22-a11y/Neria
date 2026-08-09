<?php
/**
 * Régression : BlacklistManager::isBlacklisted() doit comparer le nom du
 * template de façon insensible à la casse, et add() doit normaliser en
 * minuscules à l'écriture — cohérent avec EmailRenderer::resolveTemplate()
 * qui force systématiquement strtolower() avant d'appeler isBlacklisted().
 *
 * Bug réel corrigé le 08/08/2026 (round 136) : add() n'avait jamais
 * normalisé la casse (juste trim()+pSQL()) — non exploitable aujourd'hui
 * via le <select> BO (déjà en minuscules), mais silencieusement cassé dès
 * qu'un template en casse mixte serait enregistré par un autre chemin.
 *
 * Test comportemental réel : ajoute une règle avec un nom de template en
 * casse mixte, vérifie que isBlacklisted() la détecte bien avec une
 * variante en minuscules ET en majuscules.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BlacklistManager.php';

    $idShop = (int) Context::getContext()->shop->id;
    $mgr = new BlacklistManager($idShop);
    $testTemplate = 'Neria_Test_Case_Round136';

    try {
        $mgr->add($testTemplate, '');

        neria_assert(
            $mgr->isBlacklisted('neria_test_case_round136', '') === true,
            "isBlacklisted() ne détecte plus une règle enregistrée en casse mixte via une variante minuscule — régression du bug corrigé le 08/08/2026 (round 136)"
        );
        neria_assert(
            $mgr->isBlacklisted('NERIA_TEST_CASE_ROUND136', '') === true,
            "isBlacklisted() ne détecte plus une règle enregistrée en casse mixte via une variante majuscule — régression du bug corrigé le 08/08/2026 (round 136)"
        );
    } finally {
        $rules = $mgr->getAll();
        foreach ($rules as $rule) {
            if (strtolower($rule['template']) === strtolower($testTemplate)) {
                $mgr->remove((int) $rule['id_blacklist']);
            }
        }
    }

    return [
        'pass'    => true,
        'message' => "BlacklistManager::isBlacklisted() compare bien le nom du template de façon insensible à la casse, cohérent avec add() qui normalise désormais en minuscules à l'écriture",
    ];
}
