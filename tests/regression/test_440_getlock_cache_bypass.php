<?php
/**
 * Régression : Db::getValue()/getRow() de PrestaShop met en cache SQL le
 * résultat d'une requête par défaut ($use_cache=true implicite). Quand le
 * cache SQL PrestaShop est actif (réglage BO Performances, réellement
 * utilisé en production sur des hébergements chargés — Memcache(d)/APC/
 * Xcache), un GET_LOCK() émis via getValue() SANS passer explicitement
 * $use_cache=false peut renvoyer un résultat mémorisé ("1" = verrou
 * acquis) sans jamais réexécuter la requête sur MySQL — neutralisant
 * silencieusement TOUT mécanisme d'exclusion mutuelle du module basé sur
 * ce pattern.
 *
 * Bug réel identifié le 25/08/2026 (round 210), présent dans 25 appels
 * répartis sur 16 fichiers (StatsManager, QueueManager, WebhookManager,
 * WaitlistManager, OrderTriggersManager, MonthlyReportManager,
 * CalendarManager, CssInliner, ConfigManager, DomainReputationManager,
 * PostmasterManager, SearchConsoleManager, TranslationHistoryManager,
 * WatchdogManager, LicenseManager, HealthCheckManager) — un mécanisme
 * d'exclusion mutuelle explicitement ajouté rounds après rounds pour
 * empêcher doubles crédits/doubles envois/races devenait contournable
 * silencieusement, précisément sous forte concurrence.
 *
 * Corrigé le 25/08/2026 (round 210) : $use_cache=false ajouté explicitement
 * à chaque appel getValue() de GET_LOCK, et à StatsManager::eventExists()
 * (le "check" appairé à ce lock).
 *
 * Test comportemental réel : le backend de cache par défaut de cet
 * environnement (Memcache) n'étant pas disponible en CLI de test, on
 * injecte un cache en mémoire minimal via Cache::setInstanceForTesting()
 * (méthode PUBLIQUE du cœur PrestaShop prévue explicitement "Unit testing
 * purpose only") pour rendre le cache RÉELLEMENT actif et fonctionnel le
 * temps du test. Un premier appel getValue("SELECT GET_LOCK(...)") cache
 * le résultat "1". Une seconde connexion MySQL brute acquiert ensuite
 * RÉELLEMENT le même verrou nommé. Un appel IDENTIQUE (même texte SQL)
 * avec use_cache=true (défaut, comportement pré-correctif) renvoie alors
 * À TORT "1" depuis le cache, alors qu'un GET_LOCK() réel renverrait 0
 * (verrou déjà tenu ailleurs) — prouvant la neutralisation. Le même appel
 * avec use_cache=false (le correctif) renvoie bien 0, le comportement
 * correct.
 */
require_once __DIR__ . '/bootstrap.php';

/** Cache minimal en mémoire, pour ce test uniquement. */
class NeriaTestInMemoryCache extends Cache
{
    private array $store = [];
    protected function _set($key, $value, $ttl = 0)
    {
        $this->store[$key] = $value;
        return true;
    }
    protected function _get($key)
    {
        return $this->store[$key] ?? false;
    }
    protected function _exists($key)
    {
        return isset($this->store[$key]);
    }
    protected function _delete($key)
    {
        unset($this->store[$key]);
        return true;
    }
    protected function _writeKeys()
    {
        return true;
    }
    public function flush()
    {
        $this->store = [];
        return true;
    }
}

function run_test(): array
{
    $db = Db::getInstance();
    $lockName = 'neria_test_round210_' . uniqid();
    $sql = "SELECT GET_LOCK('" . pSQL($lockName) . "', 0)";

    // Seconde connexion MySQL BRUTE (indépendante de Db::getInstance()) —
    // simule un 2e process Neria qui tient réellement le verrou pendant
    // qu'on interroge le cache via la connexion principale.
    $host = defined('_DB_SERVER_') ? _DB_SERVER_ : '127.0.0.1';
    $user = defined('_DB_USER_') ? _DB_USER_ : 'root';
    $pass = defined('_DB_PASSWD_') ? _DB_PASSWD_ : '';
    $name = defined('_DB_NAME_') ? _DB_NAME_ : 'shop';
    $port = 3306;
    if (strpos($host, ':') !== false) {
        [$host, $portStr] = explode(':', $host, 2);
        $port = (int) $portStr ?: 3306;
    }

    $raw = @mysqli_connect($host, $user, $pass, $name, $port);
    neria_assert($raw !== false, "Impossible d'ouvrir une 2e connexion MySQL brute — jeu de test invalide (environnement)");

    // Injecte un cache en mémoire réellement fonctionnel (le backend par
    // défaut de cet environnement, Memcache, n'est pas disponible en CLI).
    $originalCache = Cache::getInstance();
    Cache::setInstanceForTesting(new NeriaTestInMemoryCache());
    $db->enableCache();

    try {
        // 1) Premier appel via le chemin BUGUÉ (use_cache par défaut) :
        // le verrou n'est tenu par personne, donc il doit réussir (1) —
        // et ce résultat "1" est mis en cache sous ce texte SQL exact.
        $first = (int) $db->getValue($sql);
        neria_assert(
            $first === 1,
            "Le premier GET_LOCK (verrou libre) ne renvoie pas 1 — jeu de test invalide"
        );
        // Relâche immédiatement côté connexion principale — le verrou
        // MySQL réel est donc de nouveau libre, mais le CACHE, lui,
        // continue de dire "1" (aucune invalidation : GET_LOCK ne touche
        // aucune table suivie par le cache SQL de PrestaShop).
        $db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");

        // 2) Une AUTRE connexion (2e process Neria simulé) acquiert
        // RÉELLEMENT le même verrou nommé — il est maintenant tenu ailleurs.
        $rawLocked = mysqli_query($raw, "SELECT GET_LOCK('" . mysqli_real_escape_string($raw, $lockName) . "', 5)");
        $rawRow = mysqli_fetch_row($rawLocked);
        neria_assert(
            (int) $rawRow[0] === 1,
            "La 2e connexion MySQL brute n'a pas pu acquérir le verrou — jeu de test invalide"
        );

        // 3) Appel IDENTIQUE (même texte SQL) SANS $use_cache=false —
        // comportement pré-correctif. Un vrai GET_LOCK() renverrait 0
        // (verrou tenu par la connexion brute), mais le cache PrestaShop
        // renvoie encore "1" du 1er appel : c'est EXACTEMENT le
        // contournement démontré.
        $cachedResult = (int) $db->getValue($sql);
        neria_assert(
            $cachedResult === 1,
            "Le cache SQL ne rejoue pas le résultat périmé comme attendu — jeu de test invalide (le mécanisme de cache PrestaShop a peut-être changé de comportement)"
        );

        // 4) Le MÊME appel avec use_cache=false (le correctif appliqué
        // partout dans le code réel) doit lui renvoyer 0 — comportement
        // correct, prouvant que le correctif referme bien la faille.
        $realResult = (int) $db->getValue($sql, false);
        neria_assert(
            $realResult === 0,
            "getValue(\$sql, false) renvoie {$realResult} au lieu de 0 — régression du bug corrigé le 25/08/2026 (round 210) : \$use_cache=false ne force plus une exécution réelle de GET_LOCK(), le verrou resterait contournable par le cache SQL"
        );
    } finally {
        mysqli_query($raw, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($raw, $lockName) . "')");
        mysqli_close($raw);
        $db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        $db->disableCache();
        Cache::setInstanceForTesting($originalCache);
    }

    return [
        'pass'    => true,
        'message' => "Db::getValue(\$sql, false) force bien une exécution réelle de GET_LOCK() même quand le cache SQL PrestaShop est actif, contrairement à l'appel sans ce paramètre (démontré avec un verrou réellement tenu par une 2e connexion MySQL) — bug corrigé le 25/08/2026 (round 210)",
    ];
}
