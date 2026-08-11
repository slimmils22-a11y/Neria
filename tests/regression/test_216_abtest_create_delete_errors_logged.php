<?php
/**
 * Régression : AbTestManager::createTest() et deleteTests() doivent
 * journaliser une erreur Watchdog explicite en cas d'échec SQL, et
 * deleteTests() doit répercuter le résultat réel de ses DELETE/UPDATE au
 * lieu de toujours renvoyer true.
 *
 * Bug réel corrigé le 09/08/2026 (round 147) : aucune des deux méthodes
 * n'appelait jamais wd()->error() sur un échec SQL, contrairement à quasiment
 * tous les autres managers du module. deleteTests() ne vérifiait même pas la
 * valeur de retour de ses 3 DELETE/UPDATE et renvoyait toujours true — un
 * échec transitoire (verrou, table verrouillée) pouvait laisser des lignes
 * neria_abtest/neria_abtest_translation orphelines sans qu'aucune alerte ne
 * soit jamais émise, ni que l'appelant ne puisse le détecter via la valeur
 * de retour.
 *
 * Test structurel (même limite d'environnement que test_215 : _PS_DEBUG_SQL_
 * actif sur ce dev fait lever une exception plutôt que retourner false sur
 * une vraie erreur SQL, rendant la reproduction fidèle de l'échec impossible
 * sans modifier le comportement du coeur PrestaShop) : vérifie que le code
 * source journalise bien une erreur sur chaque branche d'échec de
 * createTest(), et que deleteTests() accumule/retourne le résultat réel de
 * ses 3 opérations au lieu d'un `return true;` inconditionnel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/AbTestManager.php');
    neria_assert($src !== false, 'Impossible de lire src/AbTestManager.php');

    // createTest() : les 2 branches d'echec (variante A, variante B) doivent
    // journaliser une erreur avant de retourner false.
    $posCreate = strpos($src, 'public function createTest(');
    $posCreateEnd = strpos($src, 'public function activateTest(', $posCreate);
    neria_assert($posCreate !== false && $posCreateEnd !== false, 'createTest() introuvable — jeu de test invalide');
    $createBody = substr($src, $posCreate, $posCreateEnd - $posCreate);

    $occurrences = substr_count($createBody, 'abtest_create_failed');
    neria_assert(
        $occurrences >= 2,
        "createTest() ne journalise plus d'erreur Watchdog sur ses branches d'echec SQL (trouve {$occurrences}/2 occurrences de 'abtest_create_failed') — regression du bug corrige le 09/08/2026 (round 147)"
    );

    // deleteTests() : doit accumuler $ok et le retourner, pas "return true;" fixe.
    $posDelete = strpos($src, 'public function deleteTests(');
    neria_assert($posDelete !== false, 'deleteTests() introuvable — jeu de test invalide');
    $posDeleteEnd = strpos($src, "\n    // ====", $posDelete);
    $deleteBody = $posDeleteEnd !== false ? substr($src, $posDelete, $posDeleteEnd - $posDelete) : substr($src, $posDelete, 2000);

    neria_assert(
        strpos($deleteBody, '$ok = $this->db->execute(') !== false,
        "deleteTests() n'accumule plus le resultat reel de ses operations SQL dans \$ok — regression du bug corrige le 09/08/2026 (round 147) : la methode redeviendrait aveugle a un echec partiel"
    );
    neria_assert(
        strpos($deleteBody, 'return $ok;') !== false,
        "deleteTests() ne retourne plus \$ok mais probablement 'return true;' fixe — regression du bug corrige le 09/08/2026 (round 147) : un echec SQL redeviendrait indetectable par l'appelant"
    );
    neria_assert(
        strpos($deleteBody, 'abtest_delete_failed') !== false,
        "deleteTests() ne journalise plus d'erreur Watchdog sur un echec — regression du bug corrige le 09/08/2026 (round 147)"
    );
    // Seul le "return true;" de sortie anticipee (aucune ligne a supprimer)
    // est legitime — verifie qu'il n'y en a PAS d'autre apres lui (la fin de
    // methode doit retourner $ok, pas un second "return true;" fixe).
    $posEarlyReturn = strpos($deleteBody, 'if (!$ids) {');
    $afterEarlyReturn = $posEarlyReturn !== false ? substr($deleteBody, $posEarlyReturn + 40) : $deleteBody;
    neria_assert(
        strpos($afterEarlyReturn, 'return true;') === false,
        "deleteTests() contient encore un second 'return true;' inconditionnel apres la sortie anticipee — regression du bug corrige le 09/08/2026 (round 147) : la fin de methode redeviendrait aveugle a un echec SQL"
    );

    return [
        'pass'    => true,
        'message' => "AbTestManager::createTest()/deleteTests() journalisent bien les echecs SQL et deleteTests() repercute son resultat reel",
    ];
}
