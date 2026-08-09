<?php
/**
 * Régression : VoiceProfileManager::saveProfile() doit dédupliquer
 * (insensible à la casse) et plafonner à 500 entrées la liste de mots
 * avant écriture en base.
 *
 * Bug réel corrigé le 08/08/2026 (round 135) : la liste de mots
 * bannis/préférés venant du formulaire BO était enregistrée telle quelle,
 * sans aucune borne — un collage massif ou une liste jamais nettoyée
 * gonflait inutilement le coût O(n) de textContainsWords()/
 * auditTranslations() (des milliers d'entrées de traduction scannées ×
 * mots bannis) sans apporter de détection supplémentaire.
 *
 * Test comportemental réel : sauvegarde une liste avec des doublons (casse
 * différente) et une liste dépassant 500 lignes, vérifie que la liste
 * effectivement stockée en base est bien dédupliquée et plafonnée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/VoiceProfileManager.php';

    $mgr = new VoiceProfileManager(neria_test_module());
    $testLang = 'fr';
    $original = $mgr->getProfile($testLang);

    try {
        // Cas 1 : dédup insensible à la casse.
        $withDupes = "Promo\npromo\nPROMO\nSoldes";
        $ok = $mgr->saveProfile($testLang, $withDupes, '', '');
        neria_assert($ok, "saveProfile() a échoué sur le jeu de test (doublons)");

        $stored = $mgr->getBannedWords($testLang);
        neria_assert(
            count($stored) === 2,
            "saveProfile() ne déduplique plus la liste de mots (insensible à la casse) — régression du bug corrigé le 08/08/2026 (round 135) : " . count($stored) . " entrées stockées au lieu de 2 attendues (Promo, Soldes)"
        );

        // Cas 2 : plafond à 500 entrées.
        $massiveList = implode("\n", array_map(fn ($i) => "mot_test_{$i}", range(1, 600)));
        $ok2 = $mgr->saveProfile($testLang, $massiveList, '', '');
        neria_assert($ok2, "saveProfile() a échoué sur le jeu de test (liste massive)");

        $stored2 = $mgr->getBannedWords($testLang);
        neria_assert(
            count($stored2) === 500,
            "saveProfile() ne plafonne plus la liste de mots à 500 entrées — régression du bug corrigé le 08/08/2026 (round 135) : " . count($stored2) . " entrées stockées au lieu de 500 attendues"
        );
    } finally {
        // Restaure le profil d'origine (getProfile() renvoie déjà les
        // chaînes brutes attendues par saveProfile()).
        $mgr->saveProfile(
            $testLang,
            $original['banned_words'],
            $original['preferred_words'],
            $original['tone_notes']
        );
    }

    return [
        'pass'    => true,
        'message' => "VoiceProfileManager::saveProfile() déduplique (insensible à la casse) et plafonne bien la liste de mots à 500 entrées avant écriture en base",
    ];
}
