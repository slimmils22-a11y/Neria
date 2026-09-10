<?php
/**
 * Régression : `CustomerEmailHistoryManager::getEmails()` et
 * `getEmailById()` lisaient `neria_stat` sans jamais passer
 * `$use_cache=false` — même famille de bug que `getShopAverageOpenRate()`
 * (round 313, même fichier, même table) : le texte SQL est identique
 * d'un appel à l'autre pour le même client, donc sans ce paramètre, une
 * ouverture qui vient de se produire (pixel de tracking) ou un email
 * fraîchement envoyé n'apparaissait pas immédiatement dans la timeline,
 * le badge d'engagement, les alertes ou l'export CSV de la fiche client —
 * tant que le cache SQL PrestaShop de cette requête n'expire pas.
 *
 * Bug identifié le 10/09/2026 (round 334, audit ConfigManager/
 * CustomerEmailHistoryManager).
 *
 * Corrigé le 10/09/2026 (round 334) : `executeS($sql, true, false)` dans
 * `getEmails()` (piège de signature round 326 : le 2e argument
 * positionnel contrôle le mode tableau, pas le cache) et
 * `getRow($sql, false)` dans `getEmailById()`.
 *
 * Test structurel (comme test_665/round 330-331, pour la même raison :
 * `Db::$is_cache_enabled` est vide dans cet environnement de dev, le cache
 * SQL n'y a donc aucun effet observable localement) + comportemental sur
 * le fonctionnement NOMINAL : insère un vrai email envoyé, vérifie qu'il
 * apparaît bien dans getEmails() et est bien retrouvable via
 * getEmailById(), garantissant que l'ajout des paramètres n'a pas cassé
 * le comportement normal.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle des 2 correctifs ──────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CustomerEmailHistoryManager.php');

    $posGetEmails = strpos($src, 'public function getEmails(int $idCustomer): array');
    neria_assert($posGetEmails !== false, 'getEmails() introuvable — jeu de test invalide');
    $bodyGetEmails = substr($src, $posGetEmails, 1600);
    neria_assert(
        strpos($bodyGetEmails, '$rows = $this->db->executeS($sql, true, false);') !== false,
        "CustomerEmailHistoryManager::getEmails() n'a plus \$use_cache=false sur son executeS() — régression du bug corrigé le 10/09/2026 (round 334) : un email fraîchement envoyé/ouvert n'apparaîtrait de nouveau pas immédiatement dans la timeline/le badge/les alertes de la fiche client"
    );

    $posGetById = strpos($src, 'public function getEmailById(int $idStat, int $idCustomer): ?array');
    neria_assert($posGetById !== false, 'getEmailById() introuvable — jeu de test invalide');
    $bodyGetById = substr($src, $posGetById, 900);
    neria_assert(
        strpos($bodyGetById, "AND event_type = 'sent'\",\n            false\n        );") !== false,
        "CustomerEmailHistoryManager::getEmailById() n'a plus \$use_cache=false sur son getRow() — régression du bug corrigé le 10/09/2026 (round 334)"
    );

    // ── Vérification comportementale du chemin nominal ──────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $table  = $prefix . StatsManager::TABLE;
    $token  = 'regtest681_' . uniqid();

    $db->execute(
        "INSERT INTO {$table} (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
         VALUES ({$idShop}, {$idCustomer}, 'regtest681_template', 'fr', 'sent', '" . pSQL($token) . "', NOW())"
    );
    $idStat = (int) $db->Insert_ID();

    try {
        $mgr = new CustomerEmailHistoryManager(neria_test_module());

        $emails = $mgr->getEmails($idCustomer);
        $found = null;
        foreach ($emails as $e) {
            if ((int) $e['id_stat'] === $idStat) {
                $found = $e;
                break;
            }
        }
        neria_assert(
            $found !== null,
            "getEmails() ne retrouve plus l'email fraîchement inséré (id_stat={$idStat}) — comportement nominal cassé par l'ajout de \$use_cache=false"
        );

        $byId = $mgr->getEmailById($idStat, $idCustomer);
        neria_assert(
            $byId !== null && (int) $byId['id_stat'] === $idStat,
            "getEmailById() ne retrouve plus l'email fraîchement inséré (id_stat={$idStat}) — comportement nominal cassé par l'ajout de \$use_cache=false"
        );

        return [
            'pass'    => true,
            'message' => "CustomerEmailHistoryManager::getEmails()/getEmailById() bypassent désormais le cache SQL (\$use_cache=false), comportement nominal préservé — bug corrigé le 10/09/2026 (round 334)",
        ];
    } finally {
        $db->execute("DELETE FROM {$table} WHERE id_stat = {$idStat}");
    }
}
