<?php
/**
 * Amélioration : `controllers/front/unsubscribe.php` transmettait
 * l'email du client EN CLAIR dans le payload du webhook sortant
 * `unsubscribed` (`'customer_email' => $email`), contrairement aux 4
 * autres événements webhook du module (`email_sent`, `email_opened`,
 * `ab_winner`, `conversion` — `StatsManager.php`) qui transmettent tous
 * `customer_id` (entier interne à la boutique), jamais l'email en clair.
 * Ce n'était pas une fuite au sens strict (le marchand connaît déjà
 * cette adresse — c'est lui qui a configuré l'URL webhook), mais restait
 * le champ le plus directement identifiant de tout le module, envoyé
 * vers une URL potentiellement moins protégée que la boutique elle-même
 * (endpoint CRM/plateforme tierce, possible ancien webhook mal nettoyé).
 *
 * Constat fait le 03/09/2026 (round 290, audit "exposition de données
 * dans les payloads webhook sortants" — jugé non bloquant par l'audit,
 * traité ensuite le même jour sur demande explicite de l'utilisateur).
 *
 * Corrigé le 03/09/2026 : `customer_id` transmis en priorité (aligné sur
 * les 4 autres événements) quand l'adresse correspond à un vrai compte
 * client de CETTE boutique ; repli sur `customer_email` UNIQUEMENT pour
 * un invité (id_customer=0, jamais devenu client PrestaShop — cas
 * newsletter/newsletter_voucher via ps_emailsubscription, round 188) où
 * aucun ID interne n'existe et où un récepteur externe ne pourrait de
 * toute façon identifier cette adresse que par email.
 *
 * Test structurel : instancier le contrôleur front réel (ModuleFrontController)
 * nécessite un bootstrap HTTP front complet, hors de portée d'un test
 * CLI isolé (aucun test existant sur ce fichier n'instancie la classe —
 * tous structurels, cf. test_507/test_509/test_513) — vérifie que
 * $customerId est bien résolu AVANT le déclenchement du webhook et que
 * le payload bascule correctement selon sa valeur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/unsubscribe.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/unsubscribe.php');

    $posPrefsBlock = strpos($src, "\$prefsOk = false;");
    neria_assert($posPrefsBlock !== false, 'jeu de test invalide : bloc PreferencesManager introuvable');
    $posCustomerIdInit = strpos($src, '$customerId = 0;', $posPrefsBlock);
    neria_assert(
        $posCustomerIdInit !== false && $posCustomerIdInit - $posPrefsBlock < 600,
        "controllers/front/unsubscribe.php n'initialise plus \$customerId=0 avant sa résolution — régression de la correction du 03/09/2026 (round 290)"
    );

    $posTrigger = strpos($src, "class_exists('WebhookManager')");
    neria_assert($posTrigger !== false, 'jeu de test invalide : bloc de déclenchement webhook introuvable');
    neria_assert(
        $posTrigger > $posCustomerIdInit,
        "controllers/front/unsubscribe.php : \$customerId n'est plus résolu AVANT le déclenchement du webhook — régression du 03/09/2026 (round 290)"
    );

    $triggerBody = substr($src, $posTrigger, 1000);
    neria_assert(
        strpos($triggerBody, "\$customerId > 0") !== false
            && strpos($triggerBody, "'customer_id' => \$customerId") !== false
            && strpos($triggerBody, "'customer_email' => \$email") !== false,
        "controllers/front/unsubscribe.php ne bascule plus le payload webhook 'unsubscribed' entre customer_id (client réel) et customer_email (repli invité) — régression du 03/09/2026 (round 290) : le webhook redeviendrait l'unique événement du module à transmettre systématiquement l'email en clair"
    );

    return [
        'pass'    => true,
        'message' => "controllers/front/unsubscribe.php transmet désormais customer_id au webhook 'unsubscribed' pour un vrai client (repli sur customer_email pour un invité), aligné sur les 4 autres événements webhook du module — amélioration du 03/09/2026 (round 290)",
    ];
}
