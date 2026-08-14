<?php
/**
 * Régression : PropensityScoreManager::recalculateAll() n'assignait même
 * pas le résultat du DELETE de purge à une variable — aucun mécanisme
 * pour signaler un échec au Watchdog, contrairement à
 * ChurnScoreManager::recomputeAll() (qui a au moins $sqlOk). Un
 * verrou/timeout sur ce DELETE en fin de run laissait les scores de
 * clients sortis du périmètre indéfiniment en base, alimentant
 * getAlertCustomers() avec de fausses alertes, sans aucune trace.
 *
 * Corrigé le 14/08/2026 (round 166) : le résultat est désormais capturé
 * dans $purgeOk et une erreur Watchdog est journalisée en cas d'échec.
 *
 * Test structurel : vérifie que le résultat du DELETE de purge est bien
 * capturé et vérifié.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PropensityScoreManager.php');

    $posPurgeSql = strpos($src, 'neria_propensity_score` WHERE id_shop = ');
    neria_assert($posPurgeSql !== false, '$purgeSql introuvable — jeu de test invalide');
    $body = substr($src, $posPurgeSql, 1000);

    neria_assert(
        strpos($body, '$purgeOk = $this->db->execute($purgeSql);') !== false,
        "recalculateAll() ne capture plus le résultat du DELETE de purge — régression du bug corrigé le 14/08/2026 (round 166)"
    );
    neria_assert(
        strpos($body, 'if (!$purgeOk) {') !== false && strpos($body, 'watchdog.propensity_purge_failed') !== false,
        "recalculateAll() ne journalise plus d'erreur Watchdog en cas d'échec de purge — régression du bug corrigé le 14/08/2026 (round 166) : des scores de propension obsolètes pourraient rester en base indéfiniment sans aucune trace"
    );

    return [
        'pass'    => true,
        'message' => "PropensityScoreManager::recalculateAll() capture bien le résultat du DELETE de purge et journalise un échec — bug corrigé le 14/08/2026 (round 166)",
    ];
}
