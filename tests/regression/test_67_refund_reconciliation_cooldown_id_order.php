<?php
/**
 * Régression : BehavioralCronManager::sendRefundReconciliations() doit
 * fournir {id_order} dans templateVars pour les 3 relances
 * refund_reconciliation_1/2/3 — sinon neria.php (hookActionEmailSendBefore)
 * ne peut pas scoper CooldownManager::isDuplicate() à UNE commande précise
 * (même correctif que order_partial_shipped/order_on_hold/refund_processed/
 * return_received, round 63).
 *
 * Bug réel corrigé le 06/08/2026 (round 64) : ces 3 templates ne
 * fournissaient jamais {id_order}/{cooldown_scope}. Un client remboursé sur
 * deux commandes distinctes voyait la relance de la 2e bloquée à tort comme
 * doublon de la 1re si les deux tombaient dans la même fenêtre de cooldown.
 *
 * Test structurel (pas de manipulation de vraies commandes/avoirs, qui
 * aurait des effets de bord sur les données réelles de l'environnement de
 * dev) : vérifie au niveau du code source que les 3 appels à send() passent
 * bien {id_order} via la variable partagée $reconciliationVars.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    neria_assert(
        strpos($src, "'{id_order}'       => \$idOrder,") !== false,
        "sendRefundReconciliations() ne construit plus \$reconciliationVars avec '{id_order}' => \$idOrder — régression du bug corrigé le 06/08/2026 (round 64)"
    );

    $count = preg_match_all('/\$this->send\(\'refund_reconciliation_\d\', \$customer, \$reconciliationVars, \$idOrder\)/', $src);
    neria_assert(
        $count === 3,
        "sendRefundReconciliations() ne passe plus \$reconciliationVars (contenant {id_order}) aux 3 appels attendus (refund_reconciliation_1/2/3) — trouvé {$count}/3. Le Mode Silence pourrait de nouveau bloquer à tort une relance légitime pour une commande différente du même client dans la même fenêtre de cooldown"
    );

    return ['pass' => true, 'message' => "sendRefundReconciliations() fournit bien {id_order} pour les 3 relances refund_reconciliation_1/2/3"];
}
