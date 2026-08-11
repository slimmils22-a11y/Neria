<?php
/**
 * Régression : AbTestManager::copyVariantBToDefault() et archiveTest()
 * doivent conditionner leur log Watchdog de succès au résultat réel de
 * leur INSERT SQL — même correctif que activateTest()/createTest()/
 * deleteTests() (round 147, même fichier), oublié sur ces deux méthodes
 * lors de ce précédent correctif.
 *
 * Bug réel corrigé le 09/08/2026 (round 148) : les deux méthodes
 * journalisaient un succès Watchdog inconditionnellement après leur
 * execute(), sans jamais vérifier le résultat — exactement le pattern déjà
 * identifié et corrigé partout ailleurs dans ce même fichier au round 147.
 *
 * Test structurel (même limite d'environnement _PS_DEBUG_SQL_ que les
 * autres tests de ce round) : vérifie que les deux méthodes conditionnent
 * bien leur log au résultat de l'INSERT, avec un log d'erreur dédié sur
 * échec.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/AbTestManager.php');
    neria_assert($src !== false, 'Impossible de lire src/AbTestManager.php');

    // copyVariantBToDefault()
    $posCopy = strpos($src, 'private function copyVariantBToDefault(string $template): void');
    neria_assert($posCopy !== false, 'copyVariantBToDefault() introuvable — jeu de test invalide');
    $posCopyEnd = strpos($src, "\n    // ====", $posCopy);
    $copyBody = $posCopyEnd !== false ? substr($src, $posCopy, $posCopyEnd - $posCopy) : substr($src, $posCopy, 1500);

    neria_assert(
        strpos($copyBody, '$result = $this->db->execute(') !== false,
        "copyVariantBToDefault() ne capture plus le resultat reel de l'INSERT — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($copyBody, 'if ($result !== false) {') !== false && strpos($copyBody, 'abtest_variant_b_promote_failed') !== false,
        "copyVariantBToDefault() ne conditionne plus son log au resultat reel de l'INSERT — regression du bug corrige le 09/08/2026 (round 148)"
    );

    // archiveTest()
    $posArchive = strpos($src, 'public function archiveTest(string $template, array $report, string $winner, int $confidence, bool $applied = false): void');
    neria_assert($posArchive !== false, 'archiveTest() introuvable — jeu de test invalide');
    $archiveBody = substr($src, $posArchive, 4000);

    neria_assert(
        strpos($archiveBody, '$result = $this->db->execute($sql);') !== false,
        "archiveTest() ne capture plus le resultat reel de l'INSERT — regression du bug corrige le 09/08/2026 (round 148)"
    );
    neria_assert(
        strpos($archiveBody, 'if ($result !== false) {') !== false && strpos($archiveBody, 'abtest_archive_failed') !== false,
        "archiveTest() ne conditionne plus son log au resultat reel de l'INSERT — regression du bug corrige le 09/08/2026 (round 148)"
    );

    return [
        'pass'    => true,
        'message' => "AbTestManager::copyVariantBToDefault()/archiveTest() conditionnent bien leur log Watchdog au resultat reel de l'INSERT SQL",
    ];
}
