<?php
/**
 * Régression : WatchdogManager consolide les messages identiques (même
 * niveau+classe+message dans la dernière heure) en incrémentant
 * occurrence_count, mais ne rafraîchissait JAMAIS date_add — alors que le
 * commentaire du code affirme explicitement que la fenêtre de consolidation
 * "glisse".
 *
 * Bug réel identifié le 23/08/2026 (round 189) : sendImmediateAlert()
 * (burst count depuis $lastSent) et sendDailyDigestIfDueLocked() (fenêtre
 * 24h) filtrent tous deux par date_add. Une ligne créée juste AVANT le début
 * d'une fenêtre mais dont l'occurrence_count grimpait ensuite (occurrences
 * consolidées APRÈS le début de la fenêtre) restait invisible à ces deux
 * comptages — sous-évaluant un incident en cours dans le mécanisme même
 * censé le signaler au marchand.
 *
 * Corrigé le 23/08/2026 (round 189) : `date_add = NOW()` ajouté à l'UPDATE
 * de consolidation.
 *
 * Test comportemental réel : logue un message, recule artificiellement son
 * date_add de 10 minutes (simulant une ligne créée avant le début d'une
 * fenêtre d'alerte), puis logue le MÊME message une seconde fois (consolidation)
 * et vérifie que date_add est bien remonté proche de maintenant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $marker = 'test_401_round189_' . uniqid();
    $wd = new WatchdogManager($module);

    $db->execute("DELETE FROM {$prefix}neria_log WHERE message = '" . pSQL($marker) . "'");

    try {
        $wd->error($marker, '', 'Test401Class');

        $idLog = (int) $db->getValue(
            "SELECT id_log FROM {$prefix}neria_log WHERE message = '" . pSQL($marker) . "'"
        );
        neria_assert($idLog > 0, 'jeu de test invalide : le premier log() n\'a créé aucune ligne');

        // Recule artificiellement date_add de 10 minutes, comme si cette
        // ligne avait été créée avant le début d'une fenêtre d'alerte de
        // quelques minutes.
        $db->execute(
            "UPDATE {$prefix}neria_log SET date_add = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id_log = {$idLog}"
        );
        $dateAddBefore = (string) $db->getValue("SELECT date_add FROM {$prefix}neria_log WHERE id_log = {$idLog}");

        // Consolidation : même message+classe dans l'heure.
        $wd->error($marker, '', 'Test401Class');

        $row = $db->getRow(
            "SELECT id_log, occurrence_count, date_add FROM {$prefix}neria_log WHERE message = '" . pSQL($marker) . "'"
        );
        neria_assert($row !== false, 'jeu de test invalide : la ligne a disparu après consolidation');
        neria_assert(
            (int) $row['id_log'] === $idLog,
            'jeu de test invalide : la consolidation a créé une nouvelle ligne au lieu de réutiliser l\'existante (hors de portée de ce test — fenêtre de consolidation 1h dépassée ?)'
        );
        neria_assert(
            (int) $row['occurrence_count'] >= 2,
            "occurrence_count n'a pas été incrémenté par la consolidation (valeur : {$row['occurrence_count']})"
        );

        $secondsSinceDateAdd = time() - strtotime($row['date_add']);
        neria_assert(
            $secondsSinceDateAdd < 60,
            "date_add n'a pas été rafraîchi par la consolidation (encore à {$row['date_add']}, reculé artificiellement à {$dateAddBefore}) — régression du bug corrigé le 23/08/2026 (round 189) : une ligne active resterait invisible aux fenêtres d'alerte (burst count, digest) filtrant par date_add"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_log WHERE message = '" . pSQL($marker) . "'");
    }

    return [
        'pass'    => true,
        'message' => "WatchdogManager rafraîchit bien date_add à chaque occurrence consolidée — bug corrigé le 23/08/2026 (round 189)",
    ];
}
