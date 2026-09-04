<?php
/**
 * Régression : DomainReputationManager::runFullCheck() — quand GET_LOCK
 * échouait immédiatement (timeout 0) ET qu'aucun cache n'existait encore
 * (tout premier check à froid d'une boutique), le code exécutait quand
 * même runFullCheckLocked() SANS verrou. C'était précisément le scénario
 * que ce verrou (round 154) visait à corriger : deux déclenchements
 * concurrents au tout premier lancement d'une boutique pouvaient chacun
 * dupliquer la résolution DNS/RBL complète, réintroduisant le
 * double-envoi d'alerte que le verrou devait éliminer.
 *
 * Corrigé le 13/08/2026 (round 165) : en l'absence de cache, le code
 * attend désormais réellement le verrou (jusqu'à 6s, sous le budget DNS
 * de 8s) au lieu de le contourner immédiatement.
 *
 * Test comportemental réel (même technique que test_255 : une seconde
 * connexion MySQL brute détient le verrou pendant l'appel) : supprime le
 * cache existant, une seconde connexion tient le VRAI verrou que le code
 * va utiliser (round 299 : basé sur le domaine expéditeur via lockName(),
 * plus id_shop directement — récupéré via réflexion pour rester fidèle au
 * comportement réel), appelle runFullCheck() et vérifie qu'il attend
 * réellement (élapsed proche de 6s) plutôt que de retourner quasi
 * instantanément comme avant le correctif.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $idShop = (int) Context::getContext()->shop->id;
    $originalCache     = Configuration::get(DomainReputationManager::CONFIG_CACHE, null, null, $idShop);
    $originalLastCheck = Configuration::get(DomainReputationManager::CONFIG_LAST_CHECK, null, null, $idShop);

    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'Impossible d\'ouvrir une seconde connexion MySQL pour simuler un processus concurrent — jeu de test invalide');

    try {
        // Pas de cache : force le chemin "cold start" testé par ce bug.
        // Configuration::deleteFromContext() ne fait RIEN si Shop::getContext()
        // === CONTEXT_ALL (le cas dans cet environnement CLI de test) — un
        // no-op silencieux découvert en debuggant ce test. Un DELETE SQL
        // direct ne suffit pas non plus : Configuration::get() lit un cache
        // STATIQUE déjà peuplé en mémoire par le bootstrap PS (config.inc.php),
        // jamais invalidé par une suppression SQL brute faite après coup.
        // Configuration::updateValue() est le seul chemin qui invalide
        // correctement ce cache statique en plus d'écrire en base — on
        // écrase donc les 2 clés avec des valeurs qui échouent leurs
        // vérifications de fraîcheur/présence (CONFIG_LAST_CHECK='0' ne
        // passe pas le test TTL, CONFIG_CACHE='' est falsy).
        Configuration::updateValue(DomainReputationManager::CONFIG_LAST_CHECK, '0', false, null, $idShop);
        Configuration::updateValue(DomainReputationManager::CONFIG_CACHE, '', false, null, $idShop);

        $mgr = new DomainReputationManager(neria_test_module());
        $refDomain = new ReflectionMethod(DomainReputationManager::class, 'getSenderDomain');
        $refDomain->setAccessible(true);
        $domain = (string) $refDomain->invoke($mgr);
        $refLock = new ReflectionMethod(DomainReputationManager::class, 'lockName');
        $refLock->setAccessible(true);
        $lockName = (string) $refLock->invoke($mgr, $domain);

        $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "', 2)");
        $row = mysqli_fetch_row($res);
        neria_assert((int) $row[0] === 1, 'La seconde connexion MySQL n\'a pas pu obtenir le verrou — jeu de test invalide');

        $start = microtime(true);
        $mgr->runFullCheck();
        $elapsed = microtime(true) - $start;

        neria_assert(
            $elapsed >= 5.0,
            "runFullCheck() en cold start (pas de cache) a retourné en {$elapsed}s alors que le verrou était détenu par un processus concurrent — régression du bug corrigé le 13/08/2026 (round 165) : le verrou serait de nouveau contourné immédiatement au lieu d'attendre, réintroduisant le risque de double vérification DNS/RBL et de double alerte au premier check d'une boutique"
        );
        neria_assert(
            $elapsed < 10.0,
            "runFullCheck() a mis {$elapsed}s à retourner — devrait rester borné par l'attente de verrou (6s) + une éventuelle vérification de repli, pas bloquer indéfiniment"
        );
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($mysqli, $lockName ?? ('neria_domain_reputation_' . $idShop)) . "')");
        mysqli_close($mysqli);
        if ($originalCache !== false && $originalCache !== '') {
            Configuration::updateValue(DomainReputationManager::CONFIG_CACHE, $originalCache, false, null, $idShop);
        }
        if ($originalLastCheck !== false && $originalLastCheck !== '') {
            Configuration::updateValue(DomainReputationManager::CONFIG_LAST_CHECK, $originalLastCheck, false, null, $idShop);
        }
    }

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::runFullCheck() attend bien réellement le verrou lors d'un cold start (pas de cache), au lieu de le contourner immédiatement — bug corrigé le 13/08/2026 (round 165)",
    ];
}
