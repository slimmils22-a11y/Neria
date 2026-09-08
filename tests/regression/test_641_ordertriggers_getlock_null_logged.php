<?php
/**
 * Régression : OrderTriggersManager — les 4 verrous anti-doublon GET_LOCK()
 * (order_partial_shipped, order_on_hold, handleRefund, handleReturn)
 * traitaient un échec en silence total : `if ((int) $this->db->getValue(
 * "SELECT GET_LOCK(...)", false) !== 1) { return; }` ne distingue pas
 * 0 (verrou déjà détenu — blocage anti-doublon NORMAL, volontairement
 * silencieux) de NULL (erreur MySQL réelle — verrou système indisponible,
 * nombre de locks nommés simultanés dépassé), les deux valant `!== 1`
 * après cast (int). Contrairement à explicitSendBlockReason() (même
 * fichier), qui journalise systématiquement chaque blocage volontaire,
 * aucune trace Watchdog n'existait pour le cas d'échec technique — un
 * email légitime pouvait être perdu (return silencieux) sans qu'aucune
 * ligne de log ne permette de le distinguer d'un doublon réellement
 * bloqué.
 *
 * Corrigé le 08/09/2026 (round 323, traitement différé) : la valeur brute
 * de GET_LOCK() est capturée AVANT le cast — un warning Watchdog est
 * journalisé UNIQUEMENT si elle vaut littéralement NULL (erreur réelle),
 * jamais pour le cas 0 (dédup normale, reste silencieux comme voulu).
 *
 * Test structurel (provoquer une vraie erreur MySQL GET_LOCK() — ex.
 * dépassement du nombre de locks nommés simultanés — nécessiterait de
 * saturer artificiellement les verrous MySQL du serveur de test,
 * risqué pour le reste de la suite) : vérifie la présence du garde-fou
 * dans les 4 emplacements.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    $occurrences = substr_count($src, "GET_LOCK() a échoué (verrou système indisponible) pour");
    neria_assert(
        $occurrences === 4,
        "OrderTriggersManager ne journalise plus (ou plus assez, {$occurrences}/4) l'échec réel de GET_LOCK() via Watchdog — régression du bug corrigé le 08/09/2026 (round 323) : un email légitime pourrait de nouveau être perdu silencieusement sur une panne MySQL réelle, sans aucune trace distincte d'un blocage anti-doublon normal"
    );

    $nullChecks = substr_count($src, '=== null) {');
    neria_assert(
        $nullChecks >= 4,
        "OrderTriggersManager ne distingue plus NULL (erreur réelle) de 0 (dédup normale) avant de logger — régression du bug corrigé le 08/09/2026 (round 323)"
    );

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager journalise bien un warning Watchdog quand GET_LOCK() échoue réellement (NULL), sans spammer le cas normal de dédup (0) — bug corrigé le 08/09/2026 (round 323)",
    ];
}
