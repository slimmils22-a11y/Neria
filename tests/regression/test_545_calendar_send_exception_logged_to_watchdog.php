<?php
/**
 * Régression : `CalendarManager::sendCalendarEmail()` était le SEUL chemin
 * d'envoi du module à dégrader une exception réelle (échec SMTP/transport
 * levé par `Mail::Send()`) en simple `$this->module->log()` natif
 * (`ps_log`, jamais consulté par le tableau de bord Watchdog ni par le
 * digest quotidien) — contrairement aux 8+ autres managers d'envoi
 * (OrderTriggersManager, WebhookManager, SeasonalCampaignManager, etc.)
 * qui journalient systématiquement via `watchdog()->error()` avec le
 * message d'exception inclus.
 *
 * Conséquence concrète avant correctif : une panne SMTP récurrente
 * touchant spécifiquement les emails calendrier (anniversaires, Noël,
 * fête des mères...) restait invisible du marchand — aucune alerte, aucun
 * détail actionnable dans le BO, seulement une ligne générique
 * "watchdog.calendar_send_fail_customer" côté appelant qui ne distingue
 * pas un échec normal (`Mail::Send()` retourne `false`) d'une exception
 * réelle (transport cassé).
 *
 * Bug identifié et corrigé le 03/09/2026 (round 293, audit "gestion des
 * erreurs et exceptions PHP dans les managers d'envoi").
 *
 * Test structurel (simuler une exception réelle de `Mail::Send()` — appel
 * cœur PrestaShop/Symfony Mailer — nécessiterait un mock hors périmètre
 * sûr d'un test d'intégration isolé, cf. convention de cette série pour
 * les chemins d'exception SMTP) : vérifie que le `catch` journalise bien
 * via `watchdog()->error()` avec la clé de traduction dédiée, et que
 * cette clé existe dans les 19 langues du module.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CalendarManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CalendarManager.php');

    neria_assert(
        strpos($src, "WatchdogManager::i18nMsg('watchdog.calendar_send_exception'") !== false,
        "CalendarManager::sendCalendarEmail() ne journalise plus les exceptions SMTP via watchdog()->error() — régression du bug corrigé le 03/09/2026 (round 293) : une panne SMTP récurrente sur les emails calendrier redeviendrait invisible du tableau Watchdog"
    );

    // Le catch doit toujours appeler ->error() (pas ->warning(), qui ne
    // déclenche jamais sendImmediateAlert() — pattern déjà bien établi
    // dans cette série, cf. rounds 268/276/287).
    $catchPos = strpos($src, 'catch (\Throwable $e) {');
    neria_assert($catchPos !== false, "Le catch(\\Throwable \$e) de sendCalendarEmail() a disparu");
    $catchBlock = substr($src, $catchPos, 600);
    neria_assert(
        strpos($catchBlock, '$this->watchdog()->error(') !== false,
        "Le catch de CalendarManager::sendCalendarEmail() n'appelle plus watchdog()->error() — régression du bug corrigé le 03/09/2026 (round 293)"
    );

    $translations = json_decode(
        (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'),
        true
    );
    neria_assert(is_array($translations), 'Impossible de décoder data/admin_translations.json');
    neria_assert(
        isset($translations['watchdog.calendar_send_exception']),
        "La clé de traduction 'watchdog.calendar_send_exception' a disparu de data/admin_translations.json"
    );
    $expectedLangs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl', 'gb'];
    foreach ($expectedLangs as $lang) {
        neria_assert(
            !empty($translations['watchdog.calendar_send_exception'][$lang]),
            "La traduction '{$lang}' de 'watchdog.calendar_send_exception' est manquante ou vide"
        );
    }

    return [
        'pass'    => true,
        'message' => "CalendarManager::sendCalendarEmail() journalise désormais les exceptions SMTP via watchdog()->error() (19 langues) — bug corrigé le 03/09/2026 (round 293)",
    ];
}
