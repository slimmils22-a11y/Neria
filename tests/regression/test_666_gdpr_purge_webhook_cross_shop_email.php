<?php
/**
 * Régression : `GdprAuditManager::purgeCustomerData()` purgeait
 * `neria_webhook_queue` par correspondance d'EMAIL SEULE (branche invité
 * `customer_email`, sans `customer_id` — cf.
 * `controllers/front/unsubscribe.php`, événement `unsubscribed` d'un
 * client sans compte PrestaShop), sans AUCUN scoping boutique.
 *
 * Sur une install multi-boutiques, deux clients invités DIFFÉRENTS de deux
 * boutiques distinctes partageant par coïncidence le même email voyaient
 * leur webhook en attente respectif traité comme un SEUL et même
 * enregistrement : la demande d'effacement RGPD de l'un supprimait aussi,
 * silencieusement, le webhook non consentant de l'autre — même famille de
 * bug que `neria_preferences` (round 187), non transposable telle quelle
 * ici car ces lignes n'ont justement aucun `customer_id`.
 *
 * Bug identifié le 09/09/2026 (round 330, audit WebhookManager/
 * GdprAuditManager).
 *
 * Corrigé le 09/09/2026 (round 330, hors round) : nouveau paramètre
 * `$idShop` sur `purgeCustomerData()` (transmis par
 * `neria.php::hookActionDeleteGDPRCustomerImpl()` via `id_shop` du client
 * PS supprimé). La table `neria_webhook_queue` porte sa PROPRE colonne
 * `id_shop` (indépendante du payload JSON) — désormais lue et comparée à
 * `$idShop` quand ce dernier est fourni, avant de purger un webhook matché
 * par email seul. Sans `$idShop` transmis (valeur par défaut 0), le
 * comportement historique (match par email seul, toutes boutiques) est
 * conservé.
 *
 * Test comportemental réel : deux webhooks 'unsubscribed' invités de deux
 * boutiques différentes (id_shop 1 et 2), payload `customer_email`
 * identique. La purge du client de la boutique 1 (avec `$idShop=1`
 * transmis) ne doit affecter QUE le webhook de la boutique 1.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $sharedEmail = 'partage.round330@example.test';
    $ref         = 'REGTEST666_' . uniqid();

    $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE payload LIKE '%{$sharedEmail}%'");

    $insertWebhook = function (int $idShop) use ($db, $prefix, $sharedEmail): int {
        $payload = json_encode(['customer_email' => $sharedEmail, 'event' => 'unsubscribed']);
        $db->execute(
            "INSERT INTO {$prefix}neria_webhook_queue (id_shop, event, payload, status, attempts, date_add)
             VALUES ({$idShop}, 'unsubscribed', '" . pSQL($payload) . "', 'pending', 0, NOW())"
        );
        return (int) $db->Insert_ID();
    };

    try {
        $idWebhookShop1 = $insertWebhook(1);
        $idWebhookShop2 = $insertWebhook(2);

        $existsBefore1 = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idWebhookShop1}");
        $existsBefore2 = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idWebhookShop2}");
        neria_assert($existsBefore1 === 1 && $existsBefore2 === 1, 'jeu de test invalide : INSERT échoué');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        // Demande d'effacement pour un client de la boutique 1 SEULEMENT
        // (id_customer=0 volontaire : simule le cas invité réel, seul le
        // scoping par id_shop peut protéger la boutique 2 ici).
        $mgr->purgeCustomerData(0, $sharedEmail, 1);

        $existsAfter1 = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idWebhookShop1}");
        $existsAfter2 = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idWebhookShop2}");

        neria_assert($existsAfter1 === 0, "le webhook de la boutique 1 n'a pas été purgé — jeu de test invalide");
        neria_assert(
            $existsAfter2 === 1,
            "le webhook 'unsubscribed' de la boutique 2 (id_webhook={$idWebhookShop2}) a été supprimé par erreur suite à la demande d'effacement RGPD d'un client de la boutique 1, sous prétexte qu'ils partagent le même email — régression du bug corrigé le 09/09/2026 (round 330)"
        );

        return [
            'pass'    => true,
            'message' => "GdprAuditManager::purgeCustomerData() ne purge plus neria_webhook_queue par email seul toutes boutiques confondues quand \$idShop est transmis — bug corrigé le 09/09/2026 (round 330)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE payload LIKE '%{$sharedEmail}%'");
    }
}
