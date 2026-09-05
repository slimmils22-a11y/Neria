<?php
/**
 * Régression : GdprAuditManager::auditRetention() appelait Db::getValue()
 * SANS $use_cache=false sur ses 3 requêtes COUNT(*)/MIN() ($total, $oldest,
 * $overdue) — contrairement à $exists (juste au-dessus, déjà en
 * $use_cache=false depuis le round 214) et à purgeTable() (déjà en
 * $use_cache=false), même famille de bug systémique que les rounds
 * 210-223 documentés dans ce fichier et dans WaitlistManager/LoyaltyManager
 * (Db::getValue() met en cache SQL par défaut).
 *
 * $overdue pilote directement $isIssue puis le grade RGPD affiché au
 * marchand dans le rapport exporté (axe légal le plus lourdement pondéré
 * du score) : un marchand qui purge une table (bouton BO "Purger
 * maintenant") puis recharge l'onglet RGPD pouvait se voir resservir
 * l'ancien COUNT(*) non nul par le cache SQL, affichant encore "À PURGER"
 * pour une table qui vient pourtant d'être nettoyée.
 *
 * Corrigé le 05/09/2026 (round 302) : $use_cache=false ajouté aux 3
 * requêtes.
 *
 * Test comportemental réel (même technique que test_440/441/444 : cache en
 * mémoire réellement actif injecté via Cache::setInstanceForTesting(),
 * méthode PUBLIQUE du cœur PrestaShop prévue "Unit testing purpose only") :
 * insère une ligne neria_stat volontairement hors délai de rétention (36
 * mois), appelle auditRetention() une 1re fois (met en cache SQL le COUNT
 * incluant cette ligne), supprime la ligne, puis rappelle auditRetention()
 * — le compteur 'overdue' de neria_stat doit refléter la suppression
 * immédiatement, pas un résultat de cache périmé.
 */
require_once __DIR__ . '/bootstrap.php';

/** Cache minimal en mémoire, pour ce test uniquement (identique à test_440). */
class NeriaTestInMemoryCache566 extends Cache
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
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $table  = $prefix . 'neria_stat';
    $token  = 'regtest566_' . uniqid();
    $idShop = (int) Context::getContext()->shop->id;

    $cleanup = function () use ($db, $table, $token) {
        $db->execute("DELETE FROM `{$table}` WHERE tracking_token = '" . pSQL($token) . "'");
    };
    $cleanup();

    $originalCache = Cache::getInstance();
    Cache::setInstanceForTesting(new NeriaTestInMemoryCache566());
    $db->enableCache();

    try {
        // Ligne délibérément hors délai de rétention (36 mois pour
        // neria_stat) — doit compter dans 'overdue'.
        $db->execute(
            "INSERT INTO `{$table}` (id_shop, template, lang, country_code, id_customer, id_order, ref_scope, tracking_token, event_type, is_mpp, abtest_variant, revenue, ip_address, user_agent, date_add)
             VALUES ({$idShop}, 'order_conf', 'fr', 'FR', 0, 0, '', '" . pSQL($token) . "', 'sent', 0, '', 0, '', '', DATE_SUB(NOW(), INTERVAL 40 MONTH))"
        );

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $ref = new ReflectionMethod(GdprAuditManager::class, 'auditRetention');
        $ref->setAccessible(true);

        $findStatRow = function (array $result): ?array {
            foreach ($result['rows'] as $row) {
                if ($row['table'] === 'neria_stat') {
                    return $row;
                }
            }
            return null;
        };

        // 1er appel : met en cache SQL les 3 requêtes (total/oldest/overdue)
        // pour neria_stat, avec la ligne hors délai présente.
        $before = $findStatRow($ref->invoke($mgr));
        neria_assert($before !== null, "Entrée neria_stat introuvable dans auditRetention() — jeu de test invalide");
        neria_assert(
            (int) $before['overdue'] >= 1,
            "La ligne hors délai insérée n'est pas comptée dans 'overdue' au 1er appel (overdue={$before['overdue']}) — jeu de test invalide"
        );

        // Supprime la ligne — un COUNT(*) rejoué en direct doit désormais
        // être STRICTEMENT inférieur au précédent pour neria_stat.
        $cleanup();

        $after = $findStatRow($ref->invoke($mgr));
        neria_assert($after !== null, "Entrée neria_stat introuvable au 2e appel — jeu de test invalide");

        neria_assert(
            (int) $after['overdue'] === (int) $before['overdue'] - 1,
            "auditRetention() renvoie 'overdue'={$after['overdue']} après suppression de la ligne hors délai (attendu " . ((int) $before['overdue'] - 1) . ") — régression du bug corrigé le 05/09/2026 (round 302) : le cache SQL PrestaShop resservirait le compteur périmé, masquant une purge pourtant réussie au marchand"
        );
        neria_assert(
            (int) $after['total'] === (int) $before['total'] - 1,
            "auditRetention() renvoie 'total'={$after['total']} après suppression (attendu " . ((int) $before['total'] - 1) . ") — même régression que ci-dessus sur le compteur 'total'"
        );

        return [
            'pass'    => true,
            'message' => "GdprAuditManager::auditRetention() reflète bien en temps réel les changements de la table auditée (total/overdue), même avec le cache SQL PrestaShop activé — bug corrigé le 05/09/2026 (round 302)",
        ];
    } finally {
        $cleanup();
        $db->disableCache();
        Cache::setInstanceForTesting($originalCache);
    }
}
