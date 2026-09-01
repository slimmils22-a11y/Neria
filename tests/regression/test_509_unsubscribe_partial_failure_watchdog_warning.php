<?php
/**
 * Régression : `unsubscribe.php::processUnsubscribe()` touche 3 sources de
 * vérité indépendantes (ps_customer.newsletter, ps_neria_preferences via
 * PreferencesManager, ps_emailsubscription), chacune dans son propre
 * try/catch, avec un `$ok` global mis à `true` dès qu'UN SEUL des 3 canaux
 * réussit. Si `ps_customer.newsletter` réussissait mais que
 * `PreferencesManager::saveByCustomer()` levait une exception (table
 * verrouillée, erreur de contrainte, etc.), le client voyait quand même la
 * page de confirmation "vous êtes désabonné" — alors que
 * `ps_neria_preferences` (la SEULE table consultée par
 * `PreferencesManager::isAllowed()` avant tout envoi Neria) n'avait jamais
 * été mise à jour : le client continuait à recevoir tous les emails
 * comportementaux/fidélité/saisonniers/B2B malgré la confirmation
 * affichée, sans aucune trace exploitable par le marchand pour détecter
 * le problème.
 *
 * Bug identifié le 01/09/2026 (round 266, audit "opérations multi-requêtes
 * non atomiques"). Le design "best-effort, jamais bloquant" du rendu de
 * page reste volontairement inchangé (cf. round 247, exigé par le POST
 * one-click RFC 8058) — le correctif ne touche pas au comportement visible
 * pour le client, seulement à la détectabilité côté marchand.
 *
 * Corrigé le 01/09/2026 (round 266) : nouvelle variable `$prefsOk`,
 * distincte du `$ok` global, qui trace spécifiquement le succès du canal
 * `PreferencesManager`. Si `$ok` est vrai (au moins un canal a réussi) mais
 * que `$prefsOk` est faux (le canal préférences a échoué alors qu'il a été
 * tenté), un warning Watchdog explicite est désormais journalisé.
 *
 * Test structurel : le comportement dépend d'une exception provoquée dans
 * `PreferencesManager::saveByCustomer()` pendant qu'un autre canal réussit
 * — hors périmètre sûr d'une reproduction comportementale réelle sans
 * simuler une panne partielle de la base (même contrainte que d'autres
 * tests de cette série sur des chemins d'erreur best-effort). Vérifie que
 * le code source distingue bien `$prefsOk` de `$ok`, et que le warning
 * Watchdog est conditionné par `$ok && !$prefsOk`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/unsubscribe.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/unsubscribe.php');

    neria_assert(
        strpos($src, '$prefsOk = false;') !== false,
        "controllers/front/unsubscribe.php ne trace plus \$prefsOk séparément de \$ok — régression du bug corrigé le 01/09/2026 (round 266)"
    );

    neria_assert(
        strpos($src, '$ok      = true;') !== false && strpos($src, '$prefsOk = true;') !== false,
        "controllers/front/unsubscribe.php ne positionne plus \$prefsOk = true en cas de succès de PreferencesManager::saveByCustomer() — régression du bug corrigé le 01/09/2026 (round 266)"
    );

    neria_assert(
        strpos($src, "if (\$ok && !\$prefsOk && class_exists('PreferencesManager') && class_exists('WatchdogManager'))") !== false,
        "controllers/front/unsubscribe.php ne journalise plus de warning Watchdog quand un canal réussit mais que PreferencesManager a échoué — régression du bug corrigé le 01/09/2026 (round 266) : un désabonnement partiel redeviendrait totalement invisible pour le marchand"
    );

    neria_assert(
        strpos($src, "WatchdogManager::i18nMsg('watchdog.unsubscribe_preferences_channel_failed', ['email' => \$email])") !== false,
        "controllers/front/unsubscribe.php n'utilise plus la clé de traduction watchdog.unsubscribe_preferences_channel_failed avec le bon vecteur de variables — régression du bug corrigé le 01/09/2026 (round 266)"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    neria_assert(
        isset($translations['watchdog.unsubscribe_preferences_channel_failed']['fr'])
        && isset($translations['watchdog.unsubscribe_preferences_channel_failed']['en']),
        "La clé de traduction watchdog.unsubscribe_preferences_channel_failed est absente ou incomplète dans admin_translations.json — régression du bug corrigé le 01/09/2026 (round 266)"
    );

    return [
        'pass'    => true,
        'message' => "controllers/front/unsubscribe.php journalise désormais un warning Watchdog détectable quand le canal PreferencesManager échoue alors qu'un autre canal a réussi — bug corrigé le 01/09/2026 (round 266)",
    ];
}
