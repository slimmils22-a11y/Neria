<?php
/**
 * Régression : TranslationInstaller::importTemplate() retournait `false`
 * silencieusement (fichier introuvable, JSON invalide, template absent du
 * JSON) sans aucun log module->log() ni Watchdog, contrairement à
 * importFromJson() qui journalise chaque cas — un reset de template raté
 * depuis le BO devenait indiagnosticable après coup.
 *
 * Corrigé le 13/08/2026 (round 161) : les 3 mêmes branches d'échec
 * journalisent désormais via Watchdog (watchdog.translation_import_template_source_unreadable).
 *
 * Test réel : appelle importTemplate() avec un chemin de fichier inexistant,
 * vérifie que la méthode retourne bien false (comportement inchangé) ET
 * qu'une ligne Watchdog a été ajoutée à ps_neria_log — pas seulement un
 * test structurel, une vraie invocation de l'API publique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    neria_assert(class_exists('TranslationInstaller'), 'Classe TranslationInstaller introuvable');
    $installer = new TranslationInstaller($module);

    // SUM(occurrence_count) plutôt que COUNT(*) : WatchdogManager consolide
    // les événements identiques (même classe+message) survenus dans la
    // dernière heure en incrémentant occurrence_count au lieu d'insérer une
    // nouvelle ligne — un simple COUNT(*) resterait figé sur une 2e
    // exécution de ce test dans la même heure.
    $sumBefore = (int) $db->getValue(
        "SELECT COALESCE(SUM(occurrence_count), 0) FROM {$prefix}neria_log WHERE class = 'TranslationInstaller'"
    );

    $bogusPath = _PS_MODULE_DIR_ . 'neria/data/translations_does_not_exist_round161.json';
    $result = $installer->importTemplate($bogusPath, 'order_conf');

    neria_assert($result === false, "importTemplate() devrait retourner false sur un fichier source introuvable");

    $sumAfter = (int) $db->getValue(
        "SELECT COALESCE(SUM(occurrence_count), 0) FROM {$prefix}neria_log WHERE class = 'TranslationInstaller'"
    );

    neria_assert(
        $sumAfter > $sumBefore,
        "importTemplate() sur un fichier source introuvable n'a ajouté/incrémenté aucune ligne Watchdog — régression du bug corrigé le 13/08/2026 (round 161) : l'échec redeviendrait silencieux"
    );

    return [
        'pass'    => true,
        'message' => "TranslationInstaller::importTemplate() journalise bien un échec de lecture du fichier source via Watchdog — bug corrigé le 13/08/2026 (round 161)",
    ];
}
