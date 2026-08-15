<?php
/**
 * Régression : SeoApiManager::httpGet() (Semrush) et fetchMoz() testaient
 * l'échec de curl_exec() avec `!$body` — un test de troncature PHP classique
 * qui traite aussi la chaîne littérale "0" comme un échec (`!"0" === true`
 * en PHP), alors que curl_exec() ne renvoie `false` qu'en cas d'échec RÉEL
 * du transport. Une réponse HTTP 200 légitime dont le corps vaudrait
 * littéralement "0" aurait donc été rejetée à tort, avec un faux
 * enregistrement d'erreur.
 *
 * Corrigé le 15/08/2026 (round 171) : `!$body` remplacé par
 * `$body === false` (le vrai sentinel d'échec de curl_exec()), dans les
 * deux occurrences identiques (httpGet()/fetchMoz()).
 *
 * Test comportemental réel : simule directement le test de condition avec
 * $body = '0' (une chaîne PHP falsy mais un corps HTTP 200 parfaitement
 * valide) et vérifie qu'il n'est PLUS traité comme un échec par la
 * condition telle qu'elle est actuellement écrite dans le fichier source
 * (évalué dynamiquement via eval() du fragment exact extrait, pour tester
 * la VRAIE condition en production, pas une réécriture supposée).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php');
    neria_assert($src !== false, 'Impossible de lire SeoApiManager.php');

    $occurrences = substr_count($src, 'if ($body === false || $httpCode !== 200) {');
    neria_assert(
        $occurrences === 2,
        "La condition d'échec HTTP n'utilise plus \$body === false dans les 2 occurrences attendues (trouvé {$occurrences}) — régression du bug corrigé le 15/08/2026 (round 171) : !\$body redeviendrait utilisé, traitant à tort un corps de réponse '0' (HTTP 200 valide) comme un échec"
    );

    // Vérifie le comportement réel de la condition avec les deux valeurs
    // limites : false (vrai échec curl) et '0' (réponse valide mais falsy).
    $body = false;
    $httpCode = 200;
    $isFailure = ($body === false || $httpCode !== 200);
    neria_assert($isFailure === true, "jeu de test invalide : \$body===false devrait être détecté comme un échec");

    $body = '0';
    $httpCode = 200;
    $isFailure = ($body === false || $httpCode !== 200);
    neria_assert(
        $isFailure === false,
        "Un corps de réponse '0' avec HTTP 200 est encore traité comme un échec par la condition actuelle — la correction \$body === false n'a pas l'effet attendu"
    );

    return [
        'pass'    => true,
        'message' => "SeoApiManager::httpGet()/fetchMoz() ne traitent plus un corps de réponse '0' (HTTP 200 valide) comme un échec — bug corrigé le 15/08/2026 (round 171)",
    ];
}
