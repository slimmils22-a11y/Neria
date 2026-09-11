<?php
/**
 * Régression : `SeasonalCampaignManager::isStillActive()` — helper de
 * "relecture peu coûteuse" (round 256) censé permettre à `runDueCampaigns()`
 * de revérifier périodiquement (tous les 20 clients) qu'une campagne n'a
 * pas été désactivée en BO en cours d'envoi — n'avait jamais
 * `$use_cache=false`, contrairement aux 2 AUTRES relectures de fraîcheur du
 * même fichier (`update()` ligne ~189, et la relecture `customerRow` dans
 * `runDueCampaigns()`, toutes deux déjà corrigées avec `false` explicite).
 * Sous cache SQL PrestaShop actif, le premier appel dans une boucle
 * mettait en cache le résultat sous une clé dérivée du SQL (identique pour
 * un même id_campaign/id_shop) — TOUS les appels suivants de la même
 * boucle recevaient ce résultat périmé, exactement l'inverse de l'objectif
 * documenté : un marchand cliquant sur `toggle()` en BO pour arrêter en
 * urgence une campagne en cours d'envoi (ciblage large) voyait le cron
 * continuer d'envoyer jusqu'au bout du lot.
 *
 * Bug identifié le 11/09/2026 (round 338, audit SeasonalCampaignManager/
 * SearchConsoleManager).
 *
 * Corrigé le 11/09/2026 (round 338) : `$use_cache=false` ajouté.
 *
 * Test structurel (comme test_665/round 330 — `Db::$is_cache_enabled` est
 * vide dans cet environnement de dev, le cache SQL n'y a donc aucun effet
 * observable localement) + comportemental sur le fonctionnement NOMINAL :
 * crée une vraie campagne active, vérifie qu'`isStillActive()` (privée,
 * via réflexion) la détecte active, la désactive via `toggle()`, et
 * vérifie qu'`isStillActive()` détecte bien IMMÉDIATEMENT la désactivation
 * — garantissant que l'ajout de `$use_cache=false` n'a pas cassé le
 * comportement normal.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');
    $posFn = strpos($src, 'private function isStillActive(int $idCampaign): bool');
    neria_assert($posFn !== false, 'isStillActive() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1700);
    neria_assert(
        strpos($body, 'AND id_shop = " . (int) $this->idShop,') !== false
            && strpos($body, "\n            false\n        );") !== false,
        "SeasonalCampaignManager::isStillActive() n'a plus \$use_cache=false sur son getValue() — régression du bug corrigé le 11/09/2026 (round 338) : le cron pourrait de nouveau continuer d'envoyer une campagne désactivée en urgence jusqu'à la fin du lot en cours"
    );

    // ── Vérification comportementale du chemin nominal ────────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $module = neria_test_module();
    $table  = $prefix . SeasonalCampaignManager::TABLE;
    $name   = 'Regtest698 ' . uniqid();

    $db->execute(
        "INSERT INTO {$table} (id_shop, name, template, annual_date, days_before, is_active, target_segment, date_add, date_upd)
         VALUES ({$idShop}, '" . pSQL($name) . "', 'newsletter', '12-25', 7, 1, 'all', NOW(), NOW())"
    );
    $idCampaign = (int) $db->Insert_ID();
    neria_assert($idCampaign > 0, "Insert_ID() n'a pas renvoyé d'id valide — jeu de test invalide");

    try {
        $mgr = new SeasonalCampaignManager($module);
        $ref = new ReflectionMethod(SeasonalCampaignManager::class, 'isStillActive');
        $ref->setAccessible(true);

        neria_assert(
            $ref->invoke($mgr, $idCampaign) === true,
            "isStillActive() ne détecte pas la campagne fraîchement créée comme active — comportement nominal cassé par l'ajout de \$use_cache=false"
        );

        $mgr->toggle($idCampaign);

        neria_assert(
            $ref->invoke($mgr, $idCampaign) === false,
            "isStillActive() ne détecte pas IMMÉDIATEMENT la désactivation via toggle() — comportement nominal cassé, ou régression du bug corrigé le 11/09/2026 (round 338)"
        );

        return [
            'pass'    => true,
            'message' => "SeasonalCampaignManager::isStillActive() bypasse désormais le cache SQL (\$use_cache=false), détecte immédiatement une désactivation en cours d'envoi — bug corrigé le 11/09/2026 (round 338)",
        ];
    } finally {
        $db->execute("DELETE FROM {$table} WHERE id_campaign = {$idCampaign}");
    }
}
