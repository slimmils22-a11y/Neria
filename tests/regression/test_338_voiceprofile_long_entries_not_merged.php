<?php
/**
 * Régression : VoiceProfileManager::normalizeWordListInput() calculait la
 * clé de dédoublonnage APRÈS avoir tronqué chaque entrée à MAX_WORD_LENGTH
 * (100 caractères) — deux entrées distinctes de plus de 100 caractères
 * partageant le même préfixe produisaient donc la même clé et fusionnaient
 * silencieusement, la seconde disparaissant de la liste sans aucune erreur
 * ni avertissement au BO.
 *
 * Bug réel corrigé le 15/08/2026 (round 174) : la clé se calcule désormais
 * sur le mot ORIGINAL (avant troncature) — seules deux entrées réellement
 * identiques fusionnent, la troncature à l'affichage/stockage reste
 * appliquée après coup.
 *
 * Test comportemental réel : sauvegarde deux mots bannis de plus de 100
 * caractères partageant le même préfixe de 100 caractères mais différant
 * ensuite, relit le profil, vérifie que les DEUX entrées sont présentes
 * (tronquées à 100 caractères chacune, mais bien 2 lignes distinctes).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php';

    $module = neria_test_module();
    $mgr    = new VoiceProfileManager($module);

    $lang = 'fr';
    $original = $mgr->getProfile($lang);

    $prefix = str_repeat('a', 100);
    $wordA  = $prefix . '-suffixe-A-jamais-affiche-car-tronque';
    $wordB  = $prefix . '-suffixe-B-different-jamais-affiche';

    try {
        $ok = $mgr->saveProfile($lang, $wordA . "\n" . $wordB, '', '');
        neria_assert($ok === true, "saveProfile() a échoué — jeu de test invalide");

        $profile = $mgr->getProfile($lang);
        $lines = array_values(array_filter(explode("\n", $profile['banned_words']), static fn($l) => $l !== ''));

        neria_assert(
            count($lines) === 2,
            "VoiceProfileManager::normalizeWordListInput() fusionne à tort 2 entrées distinctes de plus de 100 caractères partageant le même préfixe — régression du bug corrigé le 15/08/2026 (round 174) : obtenu " . count($lines) . " ligne(s) au lieu de 2 (" . implode(' | ', $lines) . ")"
        );

        foreach ($lines as $line) {
            neria_assert(
                mb_strlen($line) <= 100,
                "Une entrée dépasse toujours MAX_WORD_LENGTH (100) après normalisation : " . mb_strlen($line) . " caractères — la troncature n'est plus appliquée"
            );
        }
    } finally {
        $mgr->saveProfile(
            $lang,
            $original['banned_words'],
            $original['preferred_words'],
            $original['tone_notes']
        );
    }

    return [
        'pass'    => true,
        'message' => "VoiceProfileManager::normalizeWordListInput() ne fusionne plus 2 entrées distinctes de plus de 100 caractères partageant le même préfixe — bug corrigé le 15/08/2026 (round 174)",
    ];
}
