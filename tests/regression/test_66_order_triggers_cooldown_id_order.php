<?php
/**
 * Régression : OrderTriggersManager doit passer {id_order} dans
 * templateVars pour order_partial_shipped, order_on_hold, refund_processed
 * et return_received — sinon neria.php (hookActionEmailSendBefore) ne peut
 * pas scoper CooldownManager::isDuplicate() à UNE commande précise, comme
 * il le fait déjà pour tous les autres templates liés à une commande
 * (LookCompletionManager notamment).
 *
 * Bug réel corrigé le 06/08/2026 (round 63) : ces 4 templates ne
 * fournissaient jamais {id_order}/{cooldown_scope}. Le Mode Silence
 * retombait alors sur (template, client, fenêtre) seul — un client avec
 * deux commandes distinctes déclenchant le même type d'email dans la même
 * fenêtre de cooldown voyait le second email légitime bloqué à tort comme
 * doublon du premier.
 *
 * Test structurel (pas de manipulation de vraies commandes/statuts, qui
 * aurait des effets de bord sur les données réelles de l'environnement de
 * dev) : vérifie au niveau du code source que chacun des tableaux de
 * variables contient bien '{id_order}' => (int) $order->id — le tableau
 * $common de handleStatusChange() couvre à la fois order_partial_shipped
 * ET order_on_hold (partagé), d'où 3 occurrences pour ces 4 templates.
 *
 * Passé à 4 occurrences au round 97 (2026-08-07) : milestone_order avait le
 * même défaut (jamais corrigé au round 63, qui ne couvrait que les 4
 * templates listés ci-dessus) — voir test_101 dédié à ce 5e template.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    $count = preg_match_all('/\'\{id_order\}\'\s*=>\s*\(int\)\s*\$order->id/', $src);

    neria_assert(
        $count === 4,
        "OrderTriggersManager ne fournit plus '{id_order}' => (int) \$order->id dans les 4 emplacements attendus (\$common partagé order_partial_shipped/order_on_hold, refund_processed, return_received, milestone_order) — trouvé {$count}/4. Régression du bug corrigé le 06/08/2026 (round 63) et le 07/08/2026 (round 97) : le Mode Silence pourrait de nouveau bloquer à tort un email légitime pour une commande différente du même client dans la même fenêtre de cooldown"
    );

    return ['pass' => true, 'message' => "OrderTriggersManager fournit bien {id_order} pour les 4 templates liés à une commande (order_partial_shipped, order_on_hold, refund_processed, return_received)"];
}
