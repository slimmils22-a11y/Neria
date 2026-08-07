<?php
/**
 * Régression : OrderTriggersManager doit passer {id_order} dans
 * templateVars pour milestone_order — même correctif que
 * order_partial_shipped/order_on_hold/refund_processed/return_received
 * (round 63, test_66), jamais étendu à milestone_order jusqu'à ce round.
 *
 * Bug réel corrigé le 07/08/2026 (round 97) : sans {id_order}, neria.php
 * (hookActionEmailSendBefore) ne peut pas scoper CooldownManager::
 * isDuplicate() à UNE commande précise pour ce template — le Mode Silence
 * retombe alors sur (template, client, fenêtre) seul. Un client atteignant
 * légitimement DEUX paliers différents (5, 10, 25...) dans la même fenêtre
 * de cooldown (import en masse, corrections de statut groupées faisant
 * repasser plusieurs commandes à valid=1 d'un coup) voyait le second email
 * milestone_order bloqué à tort comme "doublon" du premier — alors qu'un
 * vrai bon de réduction distinct avait déjà été généré et attribué pour ce
 * second palier, jamais notifié au client.
 *
 * Test structurel (pas de manipulation de vraies commandes, qui aurait des
 * effets de bord sur les données réelles de l'environnement de dev) —
 * même approche que test_66 pour les 4 autres templates.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    // Isole le bloc milestone_order (entre son commentaire d'ouverture et le
    // Mail::Send() suivant) pour vérifier spécifiquement CE tableau, pas
    // n'importe laquelle des 4 autres occurrences déjà couvertes par
    // test_66.
    $posMilestone = strpos($src, "if (in_array(\$count, self::MILESTONES, true)) {");
    neria_assert($posMilestone !== false, "Bloc milestone_order introuvable dans OrderTriggersManager.php — jeu de test invalide");

    $block = substr($src, $posMilestone, 2600);
    neria_assert(
        strpos($block, "'{id_order}'               => (int) \$order->id,") !== false,
        "OrderTriggersManager ne fournit plus '{id_order}' pour milestone_order — régression du bug corrigé le 07/08/2026 (round 97) : le Mode Silence pourrait de nouveau bloquer à tort un second email milestone_order légitime (palier différent) déclenché dans la même fenêtre de cooldown que le précédent"
    );

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager fournit bien {id_order} pour milestone_order, scopant correctement le Mode Silence par commande",
    ];
}
