<?php
/**
 * Régression : QueueManager::enqueue()/enqueueAt() appelaient
 * `json_encode($extraVars, JSON_UNESCAPED_UNICODE)` SANS jamais vérifier un
 * retour `false` — pourtant possible et documenté en PHP dès que
 * `$extraVars` contient une séquence d'octets UTF-8 invalide (données
 * produit/client mal saisies ou importées depuis un autre système). Sans ce
 * contrôle, `pSQL(false)` était castée en chaîne vide et stockée telle
 * quelle dans `vars_json` : l'email partait quand même via processQueue(),
 * mais TOUTES les variables dynamiques (bloc upsell, montant, nom de
 * produit...) disparaissaient silencieusement, sans une seule ligne de log
 * Watchdog — contrairement au même risque déjà géré ailleurs dans le module
 * (WebhookManager::trigger(), StatsManager::buildSnapshot()).
 *
 * Bug identifié le 31/08/2026 (round 259, audit "encodage/échappement JSON
 * dans payloads webhook et rendered_vars"). Corrigé le 31/08/2026
 * (round 259) : repli explicite sur `'{}'` avec journalisation Watchdog
 * (niveau Erreur) au lieu d'une perte silencieuse, dans enqueue() ET
 * enqueueAt().
 *
 * Test comportemental réel : appelle le VRAI enqueue() avec une valeur
 * contenant une séquence d'octets UTF-8 invalide dans $extraVars (provoque
 * un VRAI échec de json_encode(), pas un mock), vérifie que la ligne
 * insérée en base a bien `vars_json = '{}'` (pas une chaîne vide), et
 * qu'une entrée Watchdog explicite a bien été journalisée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $template   = 'regtest499';
    $refId      = random_int(100000, 999999);

    // Séquence d'octets UTF-8 invalide (0xB1 seul, sans byte de poursuite
    // valide) — provoque un VRAI échec de json_encode() en PHP.
    $invalidUtf8 = "valeur \xB1 invalide";
    neria_assert(
        json_encode(['x' => $invalidUtf8]) === false,
        "jeu de test invalide : la séquence choisie ne fait plus échouer json_encode() sur cet environnement PHP"
    );

    $db->execute("DELETE FROM {$prefix}neria_queue WHERE template = '" . pSQL($template) . "' AND ref_id = {$refId}");
    // WatchdogManager::record() consolide un message+class identique dans
    // la dernière heure (occurrence_count incrémenté au lieu d'un nouvel
    // INSERT) — un simple COUNT(*) avant/après serait donc faux-négatif si
    // ce test (ou un autre appel réel identique) a déjà tourné récemment.
    // On repart d'un état propre plutôt que de comparer un delta.
    $db->execute("DELETE FROM {$prefix}neria_log WHERE class = 'QueueManager' AND message LIKE '%queue_vars_encode_failed%'");

    $mgr = new QueueManager(neria_test_module());

    try {
        $mgr->enqueue(
            $template,
            [
                'id_customer' => $idCustomer,
                'id_shop'     => $idShop,
                'id_lang'     => 1,
                'email'       => 'regtest499@example.test',
                'firstname'   => 'Regtest',
                'lastname'    => '499',
            ],
            ['upsell_block' => $invalidUtf8],
            $refId,
            10
        );

        $row = $db->getRow(
            "SELECT vars_json FROM {$prefix}neria_queue WHERE template = '" . pSQL($template) . "' AND ref_id = {$refId}"
        );
        neria_assert($row !== false, "jeu de test invalide : la ligne n'a pas été insérée en file (echec inattendu de l'INSERT)");

        neria_assert(
            $row['vars_json'] === '{}',
            "QueueManager::enqueue() n'utilise plus de repli '{}' explicite sur un échec de json_encode() — régression du bug corrigé le 31/08/2026 (round 259) : vars_json obtenu = " . var_export($row['vars_json'], true)
        );

        $countLogsAfter = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE class = 'QueueManager' AND message LIKE '%queue_vars_encode_failed%'"
        );
        neria_assert(
            $countLogsAfter > 0,
            "QueueManager::enqueue() n'a pas journalisé l'échec de json_encode() dans Watchdog — régression du bug corrigé le 31/08/2026 (round 259) : la perte de variables resterait silencieuse"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager::enqueue() gère désormais explicitement un échec de json_encode() (repli '{}' + log Watchdog), au lieu de perdre silencieusement les variables dynamiques de l'email — bug corrigé le 31/08/2026 (round 259)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE template = '" . pSQL($template) . "' AND ref_id = {$refId}");
        $db->execute("DELETE FROM {$prefix}neria_log WHERE class = 'QueueManager' AND message LIKE '%queue_vars_encode_failed%'");
    }
}
