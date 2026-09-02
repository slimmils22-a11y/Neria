<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.44
 *
 * Round 261 : enregistre le hook actionObjectOrderDeleteAfter, absent
 * depuis le début. StatsManager::adjustConversionRevenueForOrder() existait
 * déjà (utilisée par OrderTriggersManager::handleRefund() sur un
 * remboursement réel) mais rien ne la déclenchait sur une SUPPRESSION
 * physique de commande (BO > Commandes > Supprimer) — les KPIs de revenu/
 * ROI par campagne (dashboard, tendances, ABTest) restaient surestimés
 * indéfiniment du montant de toute commande supprimée.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_44(Neria $module): bool
{
    // Round 275 : le retour de registerHook() était ignoré — ce script
    // renvoyait toujours true même si l'enregistrement échouait (verrou
    // transitoire sur ps_hook_module, etc.). runUpgradeModule() (cœur
    // PrestaShop) n'avance ps_module.version qu'au retour true du script :
    // un faux "true" committe la version cible sans que needUpgrade() ne
    // redétecte jamais ce script comme "à rejouer" — le hook resterait
    // alors définitivement non enregistré, sans erreur ni log visible, et
    // le bug métier que 1.0.44 corrige (KPIs de revenu surestimés après
    // suppression de commande) persisterait indéfiniment sans rattrapage
    // automatique possible. Chaînage $ok = $ok && ..., même convention que
    // les autres scripts récents (ex. upgrade-1.0.39.php).
    $ok = $module->registerHook('actionObjectOrderDeleteAfter');

    $ok = $ok && Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok;
}
