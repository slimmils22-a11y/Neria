<?php
/**
 * Régression : `neria.php::hookActionDeleteGDPRCustomerImpl()` réinjectait
 * l'email en clair du client dans `neria_log` JUSTE APRÈS que
 * `GdprAuditManager::purgeCustomerData()` venait de scanner/supprimer
 * toutes les lignes `neria_log` contenant cet email (round 270).
 * `WatchdogManager::info()` → `record()` persiste le `message` (encodé
 * par `i18nMsg()`, qui sérialise ses `$vars` — dont 'email' — en JSON
 * dans la chaîne stockée) tel quel dans `neria_log`. Cette table n'est
 * ensuite JAMAIS repurgée par `id_customer` (seulement par ancienneté à
 * 12 mois via `WatchdogManager::MAX_LOG_AGE_DAYS`), donc l'email d'un
 * client venant d'exercer son droit à l'effacement pouvait survivre
 * jusqu'à 12 mois dans la table même que la purge venait de nettoyer.
 *
 * Bug identifié le 02/09/2026 (round 278, audit "purge RGPD vs entités
 * dérivées orphelines").
 *
 * Corrigé le 02/09/2026 (round 278) : le log de confirmation ne transmet
 * plus que `customer` (id, déjà supprimé, non identifiant seul) et `n`
 * (compteur) — plus `email`. Traduction `watchdog.gdpr_customer_purged`
 * mise à jour dans les 19 locales pour retirer le placeholder `{email}`.
 *
 * Test structurel (le hook `actionDeleteGDPRCustomer` est un hook natif
 * PrestaShop déclenché par le BO, hors de portée raisonnable d'une
 * reproduction complète en CLI) : vérifie que le tableau `vars` passé à
 * `i18nMsg()` dans ce hook ne contient plus la clé 'email', et que la
 * traduction ne contient plus le placeholder {email} dans aucune des 19
 * locales.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posPurge = strpos($src, '->purgeCustomerData($idCustomer, $email);');
    neria_assert($posPurge !== false, 'Appel purgeCustomerData() introuvable — jeu de test invalide');

    $body = substr($src, $posPurge, 1000);
    neria_assert(
        strpos($body, "'email'    => \$email,") === false,
        "neria.php::hookActionDeleteGDPRCustomerImpl() repasse de nouveau 'email' dans les vars du log watchdog.gdpr_customer_purged — régression du bug corrigé le 02/09/2026 (round 278) : l'email d'un client venant d'exercer son droit à l'effacement serait réinjecté en clair dans neria_log juste après que purgeCustomerData() l'en ait retiré, et n'y serait plus jamais repurgé avant 12 mois"
    );
    neria_assert(
        strpos($body, "'customer' => \$idCustomer,") !== false && strpos($body, "'n'        => \$purged,") !== false,
        "neria.php::hookActionDeleteGDPRCustomerImpl() ne transmet plus 'customer'/'n' au log watchdog.gdpr_customer_purged — jeu de test invalide ou régression structurelle plus large"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    foreach ($locales as $l) {
        $text = $translations['watchdog.gdpr_customer_purged'][$l] ?? '';
        neria_assert(
            $text !== '' && strpos($text, '{email}') === false,
            "la traduction watchdog.gdpr_customer_purged contient de nouveau le placeholder {email} pour la locale '{$l}' — régression du bug corrigé le 02/09/2026 (round 278)"
        );
    }

    return [
        'pass'    => true,
        'message' => "neria.php ne réinjecte plus l'email du client dans neria_log via le log de confirmation de purge RGPD — bug corrigé le 02/09/2026 (round 278)",
    ];
}
