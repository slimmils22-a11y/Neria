<?php
/**
 * Régression : `BounceManager::recordBounce()` — point d'entrée central
 * appelé à la fois par la vérification IMAP manuelle et par les 2 canaux
 * webhook ESP — n'a jamais vérifié le retour de son
 * `INSERT ... ON DUPLICATE KEY UPDATE`. Un échec SQL transitoire (connexion
 * coupée, verrou) journalisait quand même un `watchdog.bounce_recorded` de
 * succès, alors que l'adresse n'était en réalité pas protégée contre un
 * futur envoi — même pattern "succès affiché sans vérifier l'effet réel"
 * déjà corrigé dans ce même fichier pour `ignoreBounce()`/
 * `reactivateBounce()`/`deleteBounce()` (round 315), jamais porté à ce
 * point d'entrée pourtant central.
 *
 * Bug identifié le 11/09/2026 (round 336, audit BounceManager/
 * LoyaltyManager).
 *
 * Corrigé le 11/09/2026 (round 336) : retour de `Db::execute()` capturé ;
 * `watchdog.bounce_recorded` (warning) journalisé sur succès, nouvelle
 * alerte `watchdog.bounce_record_failed` (critical, 19 langues) journalisée
 * sur échec.
 *
 * Test structurel (simuler un échec SQL réel dans un test CLI isolé
 * nécessiterait de casser la connexion DB en plein milieu de l'appel,
 * impraticable proprement) : vérifie que le retour est bien capturé et
 * conditionne le choix entre les deux clés Watchdog. Test comportemental
 * réel sur le chemin NOMINAL : enregistre un vrai bounce et vérifie qu'il
 * est bien persisté, garantissant que l'ajout de la vérification n'a rien
 * cassé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BounceManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BounceManager.php');

    $posFn = strpos($src, 'public function recordBounce(string $email, string $type, string $reason, string $source = \'imap\'): void');
    neria_assert($posFn !== false, 'recordBounce() introuvable — jeu de test invalide');
    $posEnd = strpos($src, "\n    }\n\n    // ", $posFn);
    $body = $posEnd !== false ? substr($src, $posFn, $posEnd - $posFn) : substr($src, $posFn, 3000);

    neria_assert(
        strpos($body, '$inserted336 = $db->execute(') !== false,
        "recordBounce() ne capture plus le retour de Db::execute() dans une variable — régression du bug corrigé le 11/09/2026 (round 336) : le succès de l'écriture en base ne serait de nouveau jamais vérifié"
    );
    neria_assert(
        strpos($body, 'if ($inserted336) {') !== false
            && strpos($body, "WatchdogManager::i18nMsg('watchdog.bounce_recorded'") !== false,
        "recordBounce() ne conditionne plus watchdog.bounce_recorded au succès réel de l'INSERT — régression du bug corrigé le 11/09/2026 (round 336)"
    );
    neria_assert(
        strpos($body, "WatchdogManager::i18nMsg('watchdog.bounce_record_failed'") !== false,
        "recordBounce() ne journalise plus d'alerte dédiée (watchdog.bounce_record_failed) sur échec de l'INSERT — régression du bug corrigé le 11/09/2026 (round 336) : un échec SQL transitoire redeviendrait invisible, l'adresse ne serait pas protégée sans aucune alerte"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    neria_assert(isset($translations['watchdog.bounce_record_failed']), "Clé 'watchdog.bounce_record_failed' absente de admin_translations.json");
    foreach ($locales as $loc) {
        neria_assert(
            !empty($translations['watchdog.bounce_record_failed'][$loc]),
            "Traduction 'watchdog.bounce_record_failed' manquante pour la langue '{$loc}'"
        );
    }

    // ── Vérification comportementale du chemin nominal ────────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $email  = 'regtest689_' . uniqid() . '@example.com';

    $db->execute("DELETE FROM {$prefix}" . BounceManager::TABLE . " WHERE `email` = '" . pSQL($email) . "'");

    try {
        $mgr = new BounceManager($module);
        $mgr->recordBounce($email, 'soft', 'Regtest689 : boîte pleine', 'manual');

        $row = $db->getRow(
            "SELECT * FROM {$prefix}" . BounceManager::TABLE . " WHERE `email` = '" . pSQL($email) . "'"
        );
        neria_assert(
            $row !== false && $row !== null && (int) $row['bounce_count'] === 1,
            "recordBounce() n'a pas persisté le bounce comme attendu sur le chemin nominal — comportement nominal cassé par l'ajout de la vérification du retour d'INSERT"
        );

        return [
            'pass'    => true,
            'message' => "BounceManager::recordBounce() vérifie désormais le retour de son INSERT (succès → watchdog.bounce_recorded, échec → watchdog.bounce_record_failed), comportement nominal préservé — bug corrigé le 11/09/2026 (round 336)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}" . BounceManager::TABLE . " WHERE `email` = '" . pSQL($email) . "'");
    }
}
