<?php
/**
 * Régression : `OrderTriggersManager::checkMilestone()` — quand
 * `Mail::Send('milestone_order', ...)` échoue silencieusement (hook
 * bounce/blacklist/préférences/cooldown), `releaseMilestoneClaim()` est
 * appelée pour permettre un futur re-déclenchement. Mais son propre
 * `WHERE ... AND id_cart_rule = 0` ne libère RIEN si un bon RÉEL a déjà
 * été généré (`generateMilestoneVoucher()` écrit `id_cart_rule` AVANT
 * l'envoi) — ce palier ne redéclenchera donc jamais. Le seul log produit
 * dans ce cas était un `watchdog.send_silent_fail` générique
 * ({template},{email}), sans jamais mentionner qu'un bon CartRule ACTIF
 * existe malgré tout en base : le marchand n'avait aucun moyen de savoir
 * qu'un rattrapage manuel (transmettre le code au client) était possible.
 * Ce comportement de non-libération est un choix délibéré (round 133,
 * éviter un double bon) — le vrai manque était l'absence de signalement.
 *
 * Bug identifié le 11/09/2026 (round 336, audit CssInliner/
 * OrderTriggersManager/PreferencesManager), corrigé le 11/09/2026 hors
 * round (à la demande de l'utilisateur, suite à la clôture du round 336) :
 * nouvelle alerte dédiée `watchdog.milestone_send_fail_voucher_issued`
 * (19 langues, incluant `{voucher_code}`), utilisée à la place du message
 * générique UNIQUEMENT quand un vrai bon a été généré ($voucherCode !== '').
 *
 * Test structurel (checkMilestone() est une méthode privée déclenchée par
 * un vrai changement de statut de commande avec Order/Customer/Address
 * complets — invocation isolée en CLI impraticable proprement, même
 * limite déjà acceptée pour d'autres handlers de ce fichier) + test
 * comportemental réel sur la résolution du message : construit le message
 * encodé exactement comme le code le ferait (WatchdogManager::i18nMsg())
 * et vérifie que WatchdogManager::resolveLogMessage() le décode
 * correctement, avec le code du bon bien visible dans le texte final.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    $posFn = strpos($src, 'private function checkMilestone(\Order $order): void');
    neria_assert($posFn !== false, 'checkMilestone() introuvable — jeu de test invalide');
    $posReleaseCall = strpos($src, '$this->releaseMilestoneClaim($idCustomer, $count, $idShop);', $posFn);
    neria_assert($posReleaseCall !== false, 'Appel à releaseMilestoneClaim() (chemin échec envoi) introuvable — jeu de test invalide');
    $body = substr($src, $posReleaseCall, 1700);

    neria_assert(
        strpos($body, "if (\$voucherCode !== '') {") !== false,
        "checkMilestone() ne distingue plus le cas où un bon réel a été généré avant l'échec d'envoi — régression du correctif du 11/09/2026 (hors round, suite round 336)"
    );
    neria_assert(
        strpos($body, "WatchdogManager::i18nMsg('watchdog.milestone_send_fail_voucher_issued'") !== false
            && strpos($body, "'voucher_code' => \$voucherCode") !== false,
        "checkMilestone() ne journalise plus d'alerte dédiée mentionnant le code du bon déjà émis en cas d'échec d'envoi — régression du correctif du 11/09/2026 (hors round, suite round 336) : le marchand n'aurait de nouveau aucun moyen de savoir qu'un rattrapage manuel est possible"
    );
    neria_assert(
        strpos($body, "WatchdogManager::i18nMsg('watchdog.send_silent_fail'") !== false,
        "checkMilestone() ne conserve plus le repli générique watchdog.send_silent_fail pour le cas où AUCUN bon n'a été généré — régression"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    neria_assert(isset($translations['watchdog.milestone_send_fail_voucher_issued']), "Clé 'watchdog.milestone_send_fail_voucher_issued' absente de admin_translations.json");
    foreach ($locales as $loc) {
        neria_assert(
            !empty($translations['watchdog.milestone_send_fail_voucher_issued'][$loc]),
            "Traduction 'watchdog.milestone_send_fail_voucher_issued' manquante pour la langue '{$loc}'"
        );
        neria_assert(
            strpos($translations['watchdog.milestone_send_fail_voucher_issued'][$loc], '{voucher_code}') !== false,
            "Traduction '{$loc}' de watchdog.milestone_send_fail_voucher_issued ne contient pas le placeholder {voucher_code}"
        );
    }

    // ── Vérification comportementale de la résolution du message ──────
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $encoded = WatchdogManager::i18nMsg('watchdog.milestone_send_fail_voucher_issued', [
        'count'        => 5,
        'email'        => 'client@example.com',
        'voucher_code' => 'NERIA-TIER5-REGTEST691',
    ]);
    $resolved = WatchdogManager::resolveLogMessage($encoded);

    neria_assert(
        strpos($resolved, 'NERIA-TIER5-REGTEST691') !== false,
        "Le message résolu ne contient pas le code du bon — résolution cassée (message résolu : '{$resolved}')"
    );
    neria_assert(
        strpos($resolved, '::i18n::') === false,
        "Le message résolu contient encore la structure brute ::i18n:: — résolution cassée"
    );

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager::checkMilestone() journalise désormais une alerte dédiée mentionnant le code du bon de réduction déjà émis quand l'envoi de milestone_order échoue après génération d'un vrai bon — correctif du 11/09/2026 (hors round, suite round 336)",
    ];
}
