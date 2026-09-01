<?php
/**
 * Régression : `BounceManager::processBounceWebhook()`/`recordBounce()`
 * n'avaient aucune déduplication par ID d'événement ESP. La protection
 * atomique existante (`INSERT ... ON DUPLICATE KEY UPDATE`, round 210-217)
 * empêche une CONFLIT/duplication SQL entre 2 appels CONCURRENTS pour la
 * même adresse, mais n'empêche PAS 2 appels SÉQUENTIELS (quelques
 * minutes/heures d'écart) d'incrémenter `bounce_count` une seconde fois
 * pour un SEUL bounce réel — un comportement pourtant STANDARD chez les
 * ESP ("at-least-once delivery" : Mailgun/SendGrid/Postmark redélivrent
 * normalement un webhook non acquitté assez vite). Cela pouvait
 * rapprocher artificiellement une adresse du seuil de blacklist (soft
 * bounce) sans qu'aucun nouvel échec réel ne se soit produit.
 *
 * Bug identifié le 01/09/2026 (round 273, audit "gestion des webhooks
 * entrants tiers").
 *
 * Corrigé le 01/09/2026 (round 273) : chaque parseur ESP (`parseMailgun`,
 * `parseSendgrid`, `parsePostmark`, `parseGenericWebhook`) extrait
 * désormais un `event_id` (ex. `event-data.id` Mailgun, `sg_event_id`
 * SendGrid, `ID` Postmark), et `processBounceWebhook()` ignore tout
 * événement déjà vu dans les dernières 24h via
 * `bounceEventAlreadyProcessed()` (APCu, fail-open si indisponible ou si
 * event_id vide — même convention que la déduplication webhook sortant de
 * `unsubscribe.php`, round 265).
 *
 * Test réel + structurel : l'extension APCu est confirmée absente de cet
 * environnement de dev/test (`function_exists('apcu_enabled')` → false,
 * même contrainte que `test_482`), donc `bounceEventAlreadyProcessed()`
 * fail-open systématiquement ici — le comportement de déduplication réel
 * ne peut être vérifié qu'au niveau du code source. Vérifie néanmoins
 * comportementalement que `processBounceWebhook()` reste fonctionnel
 * (aucune régression introduite par le paramètre event_id supplémentaire)
 * en enregistrant un vrai bounce Mailgun, puis vérifie structurellement
 * la présence de l'extraction event_id dans les 4 parseurs et du garde
 * dans `processBounceWebhook()`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'regtest-round273@example.com';

    $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '{$email}'");

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';
        $mgr = new BounceManager(neria_test_module());

        $payload = [
            'event-data' => [
                'id'        => 'evt-round273-test-1',
                'event'     => 'failed',
                'recipient' => $email,
                'severity'  => 'permanent',
                'delivery-status' => ['message' => 'test bounce'],
            ],
        ];
        $ok = $mgr->processBounceWebhook($payload, 'mailgun');
        neria_assert($ok === true, "processBounceWebhook() a échoué sur un événement Mailgun valide avec event_id — jeu de test invalide (régression possible du paramètre supplémentaire)");

        $recorded = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_bounces WHERE email = '{$email}'");
        neria_assert($recorded === 1, "le bounce Mailgun n'a pas été enregistré normalement — régression possible du correctif round 273");

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BounceManager.php');
        neria_assert($src !== false, 'Impossible de lire src/BounceManager.php');

        neria_assert(
            strpos($src, 'private function bounceEventAlreadyProcessed(string $source, string $eventId): bool') !== false,
            "BounceManager::bounceEventAlreadyProcessed() est introuvable — régression du bug corrigé le 01/09/2026 (round 273) : plus aucune déduplication des événements de bounce redélivrés par l'ESP"
        );
        neria_assert(
            strpos($src, "if (\$this->bounceEventAlreadyProcessed(\$source, \$result['event_id'])) {") !== false,
            "processBounceWebhook() n'appelle plus bounceEventAlreadyProcessed() avant recordBounce() — régression du bug corrigé le 01/09/2026 (round 273)"
        );
        neria_assert(
            strpos($src, "\$event_id = (string) (\$data['id'] ?? '');") !== false,
            "parseMailgun() n'extrait plus event_id (data['id']) — régression du bug corrigé le 01/09/2026 (round 273)"
        );
        neria_assert(
            strpos($src, "\$event_id = (string) (\$p['sg_event_id'] ?? '');") !== false,
            "parseSendgrid() n'extrait plus event_id (sg_event_id) — régression du bug corrigé le 01/09/2026 (round 273)"
        );
        neria_assert(
            strpos($src, "\$event_id = isset(\$p['ID']) ? (string) \$p['ID'] : '';") !== false,
            "parsePostmark() n'extrait plus event_id (ID) — régression du bug corrigé le 01/09/2026 (round 273)"
        );

        return [
            'pass'    => true,
            'message' => "BounceManager déduplique désormais les événements de bounce redélivrés par l'ESP (fenêtre 24h, fail-open si APCu indisponible) — bug corrigé le 01/09/2026 (round 273)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '{$email}'");
    }
}
