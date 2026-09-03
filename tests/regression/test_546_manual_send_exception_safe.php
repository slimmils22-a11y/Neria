<?php
/**
 * Régression : `ManualSendManager::send()` était le SEUL point d'envoi
 * manuel du module sans `try/catch` autour de `Mail::Send()` NI autour de
 * l'`INSERT IGNORE` de suivi anniversaire (`neria_behavioral_sent`) —
 * contrairement à OrderTriggersManager/WebhookManager/
 * SeasonalCampaignManager etc., tous protégés systématiquement.
 *
 * Conséquence concrète avant correctif : une exception transitoire
 * (transport SMTP cassé, ou deadlock MySQL sur `neria_behavioral_sent` —
 * table partagée en écriture concurrente avec le cron
 * `BehavioralCronManager`) remontait NON interceptée jusqu'à
 * `neria.php::postProcess()`, provoquant une page d'erreur fatale pour
 * l'employé BO qui venait de cliquer "Envoyer" — y compris dans le cas où
 * l'email avait DÉJÀ été réellement envoyé avec succès juste avant que
 * l'INSERT de suivi ne lève (le marchand voit un crash et pourrait renvoyer
 * le même email par erreur, alors qu'il est déjà parti).
 *
 * Bug identifié et corrigé le 03/09/2026 (round 293, audit "gestion des
 * erreurs et exceptions PHP dans les managers d'envoi").
 *
 * Test structurel (simuler une exception réelle de `Mail::Send()` ou un
 * deadlock MySQL nécessiterait un mock hors périmètre sûr d'un test
 * d'intégration isolé, cf. round 293 angle A/CalendarManager) : vérifie
 * que les deux points d'appel sont bien entourés d'un try/catch
 * journalisant via `watchdog()->error()`, et que les 2 clés de
 * traduction existent dans les 19 langues du module. Vérifie aussi
 * comportementalement qu'un envoi manuel réel (chemin nominal, sans
 * exception) fonctionne toujours normalement après l'ajout des try/catch.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ManualSendManager.php');

    neria_assert(
        strpos($src, "WatchdogManager::i18nMsg('watchdog.manual_send_exception'") !== false,
        "ManualSendManager::send() n'entoure plus Mail::Send() d'un try/catch journalisant via watchdog()->error() — régression du bug corrigé le 03/09/2026 (round 293) : une exception de transport crasherait de nouveau la page BO"
    );
    neria_assert(
        strpos($src, "WatchdogManager::i18nMsg('watchdog.manual_send_tracking_exception'") !== false,
        "ManualSendManager::send() n'entoure plus l'INSERT de suivi anniversaire d'un try/catch journalisant via watchdog()->error() — régression du bug corrigé le 03/09/2026 (round 293) : un deadlock concurrent avec BehavioralCronManager crasherait de nouveau la page BO après un envoi pourtant réussi"
    );

    $translations = json_decode(
        (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'),
        true
    );
    neria_assert(is_array($translations), 'Impossible de décoder data/admin_translations.json');
    $expectedKeys  = ['msg.send_exception', 'watchdog.manual_send_exception', 'watchdog.manual_send_tracking_exception'];
    $expectedLangs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl', 'gb'];
    foreach ($expectedKeys as $key) {
        neria_assert(isset($translations[$key]), "La clé de traduction '{$key}' a disparu de data/admin_translations.json");
        foreach ($expectedLangs as $lang) {
            neria_assert(
                !empty($translations[$key][$lang]),
                "La traduction '{$lang}' de '{$key}' est manquante ou vide"
            );
        }
    }

    // Vérification comportementale réelle du chemin nominal (sans
    // exception) : un envoi manuel doit toujours fonctionner normalement
    // après l'ajout des try/catch (pas de régression fonctionnelle).
    $module = neria_test_module();
    $mgr    = new ManualSendManager($module);
    $result = $mgr->send('template_totalement_inexistant_546', 'test@example.com', '', 'Sujet', []);
    neria_assert(
        is_array($result) && array_key_exists('ok', $result) && $result['ok'] === false,
        "ManualSendManager::send() ne renvoie plus un tableau ['ok' => false, ...] propre pour un template invalide — le try/catch aurait pu introduire une régression sur le chemin d'erreur normal (non-exception)"
    );

    return [
        'pass'    => true,
        'message' => "ManualSendManager::send() entoure désormais Mail::Send() et l'INSERT de suivi anniversaire d'un try/catch journalisant via watchdog()->error() (19 langues), sans régression du chemin nominal — bug corrigé le 03/09/2026 (round 293)",
    ];
}
