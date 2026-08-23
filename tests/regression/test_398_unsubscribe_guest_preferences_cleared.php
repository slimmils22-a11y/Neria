<?php
/**
 * Régression : unsubscribe.php n'appelait PreferencesManager::saveByCustomer()
 * QUE si $customerId > 0 (client PrestaShop identifié), alors que
 * PreferencesManager gère explicitement id_customer=0 + email pour les
 * invités (abonnés uniquement via ps_emailsubscription, jamais devenus
 * clients — cf. round 178, clé unique incluant l'email pour ce cas précis).
 *
 * Bug réel identifié le 23/08/2026 (round 188) : un invité cliquant sur le
 * lien "un clic" (RFC 8058) de désabonnement voyait sa demande confirmée
 * (ps_emailsubscription.active mis à 0), mais neria_preferences n'était
 * JAMAIS créée pour son adresse — isAllowed()/isAllowedBatch() restent
 * "true" (opt-in) tant qu'aucune ligne n'existe, donc l'invité continuait
 * de recevoir toutes les autres catégories d'email Neria pour son adresse.
 *
 * Corrigé le 23/08/2026 (round 188) : saveByCustomer($customerId, ...) est
 * désormais appelée inconditionnellement (avec $customerId=0 pour un
 * invité), hors de tout `if ($customerId > 0)`.
 *
 * Test en 2 parties :
 *  1. Structurel : le gate `if ($customerId > 0)` autour de l'appel
 *     saveByCustomer() dans unsubscribe.php a bien disparu.
 *  2. Comportemental réel : PreferencesManager::saveByCustomer(0, email, ...)
 *     — le chemin que ce contrôleur emprunte désormais pour un invité —
 *     crée bien une ligne neria_preferences subscribed=0 par catégorie,
 *     et isAllowed() la respecte.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    // ── Partie structurelle ──────────────────────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/unsubscribe.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/unsubscribe.php');

    $posPrefBlock = strpos($src, "class_exists('PreferencesManager')");
    neria_assert($posPrefBlock !== false, "class_exists('PreferencesManager') introuvable — jeu de test invalide");

    $posSaveCall = strpos($src, '->saveByCustomer(', $posPrefBlock);
    neria_assert($posSaveCall !== false, 'Appel saveByCustomer() introuvable — jeu de test invalide');

    $between = substr($src, $posPrefBlock, $posSaveCall - $posPrefBlock);
    neria_assert(
        strpos($between, 'if ($customerId > 0)') === false,
        "unsubscribe.php gate de nouveau saveByCustomer() derrière \"if (\$customerId > 0)\" — régression du bug corrigé le 23/08/2026 (round 188) : un invité (id_customer=0) ne verrait de nouveau jamais sa ligne neria_preferences créée lors d'un désabonnement"
    );

    // ── Partie comportementale ───────────────────────────────────────
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'invite.round188@example.test';

    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE email = '" . pSQL($email) . "' AND id_customer = 0");

    try {
        $mgr = new PreferencesManager(neria_test_module());
        $mgr->saveByCustomer(0, $email, array_fill_keys(PreferencesManager::CATEGORIES, 0));

        $count = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_preferences WHERE email = '" . pSQL($email) . "' AND id_customer = 0 AND subscribed = 0"
        );
        neria_assert(
            $count >= 1,
            "PreferencesManager::saveByCustomer(0, ...) n'a créé aucune ligne neria_preferences subscribed=0 pour l'invité — le correctif de unsubscribe.php reposerait sur un chemin qui ne fonctionne pas"
        );

        $allowed = $mgr->isAllowed(0, 'newsletter', null, $email);
        neria_assert(
            $allowed === false,
            "PreferencesManager::isAllowed() reste 'true' pour l'invité désabonné (id_customer=0) — le désabonnement 'un clic' resterait sans effet réel sur les futurs envois"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE email = '" . pSQL($email) . "' AND id_customer = 0");
    }

    return [
        'pass'    => true,
        'message' => "unsubscribe.php appelle bien saveByCustomer() sans condition sur \$customerId, et un invité (id_customer=0) est bien désabonné dans neria_preferences — bug corrigé le 23/08/2026 (round 188)",
    ];
}
