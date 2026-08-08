<?php
/**
 * Régression : LookCompletionManager::getStats() doit scoper sent/sent30
 * par boutique (via un JOIN sur orders.id_shop, la table neria_look_sent
 * n'ayant pas de colonne id_shop propre) — comme CollectionManager::
 * getStats() (round 119), UpsellManager::getStats() et
 * WaitlistManager::getStats().
 *
 * Bug réel corrigé le 08/08/2026 (round 127) : sent/sent30 comptaient
 * TOUTES les lignes de neria_look_sent, toutes boutiques confondues,
 * contrairement à CollectionManager::getStats() (scopé depuis le round
 * 119), affiché juste à côté sur le même écran BO — deux KPI
 * manifestement incohérents pour un admin sur une installation
 * multi-boutiques (ex. "Complétez votre look" affichant le total de
 * l'installation entière pendant que "Complétion de collection" affiche
 * bien le total de SA boutique).
 *
 * Test fonctionnel réel : insère une ligne neria_look_sent pointant vers un
 * id_order INEXISTANT (donc appartenant, par construction, à aucune
 * boutique valide) et vérifie qu'elle n'est PAS comptée dans sent/sent30 —
 * preuve que le comptage passe bien par le JOIN sur orders (pas un simple
 * COUNT(*) global qui l'aurait comptée aveuglément).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $mgr = new LookCompletionManager(neria_test_module());
    $statsBefore = $mgr->getStats();

    // id_order volontairement inexistant (très grand, hors de toute
    // séquence AUTO_INCREMENT réaliste) — simule une ligne qui, avec l'ancien
    // COUNT(*) sans jointure, aurait été comptée aveuglément.
    $fakeOrderId = 999999999;
    $db->execute(
        "INSERT INTO {$prefix}neria_look_sent (id_order, id_customer, sent_at)
         VALUES ({$fakeOrderId}, " . neria_test_any_customer_id() . ", NOW())"
    );

    try {
        $statsAfter = $mgr->getStats();

        neria_assert(
            $statsAfter['sent'] === $statsBefore['sent'],
            "getStats()['sent'] est passé de {$statsBefore['sent']} à {$statsAfter['sent']} après l'insertion d'une ligne pointant vers un id_order inexistant — régression du bug corrigé le 08/08/2026 (round 127) : sent/sent30 ne sont plus scopés via JOIN sur orders.id_shop"
        );
        neria_assert(
            $statsAfter['sent30'] === $statsBefore['sent30'],
            "getStats()['sent30'] est passé de {$statsBefore['sent30']} à {$statsAfter['sent30']} après l'insertion d'une ligne pointant vers un id_order inexistant — régression du bug corrigé le 08/08/2026 (round 127)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_look_sent WHERE id_order = {$fakeOrderId}");
    }

    return [
        'pass'    => true,
        'message' => "LookCompletionManager::getStats() scope bien sent/sent30 via JOIN sur orders.id_shop, isolant correctement les KPIs entre boutiques",
    ];
}
