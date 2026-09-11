<?php
/**
 * Régression : `WebhookManager::processQueue()` exécutait l'UPDATE finale
 * `status='done'` après un `fire()` réussi (webhook réellement livré au
 * système tiers du marchand — CRM, Zapier...) sans jamais vérifier
 * `Affected_Rows()` — un échec silencieux de cet UPDATE précis (deadlock,
 * coupure de connexion juste après la livraison réelle) laissait la ligne
 * bloquée au statut 'sending' ALORS QUE LE WEBHOOK A RÉELLEMENT ÉTÉ LIVRÉ.
 * Le nettoyage "sending bloqué depuis 10 min" en tête de `processQueue()`
 * (round 241) la resélectionne ensuite au prochain passage et la RENVOIE
 * UNE SECONDE FOIS au même endpoint externe, silencieusement — exactement
 * le risque de double livraison que la réservation atomique du round 241
 * visait à éliminer, mais côté "avant envoi" seulement, jamais côté "après
 * envoi réussi" (même classe de bug que `QueueManager::processSingle()`,
 * déjà corrigée round 313, cf. test_603).
 *
 * Bug identifié le 12/09/2026 (round 339, audit EmailRenderer/
 * WebhookManager).
 *
 * Corrigé le 12/09/2026 (round 339) : si `Affected_Rows() === 0` après
 * cette UPDATE précise, une alerte Watchdog critique explicite est levée
 * (pas de correction automatique possible sans risque de double
 * notification — seule une vérification manuelle est sûre).
 *
 * Reproduire ce cas précis nécessite un `fire()` réel réussi (appel HTTP
 * sortant vers un tiers) SUIVI d'un échec bas niveau isolé de cette seule
 * UPDATE — non simulable de façon fiable en test d'intégration sans mocker
 * la connexion DB elle-même (même limite déjà acceptée pour test_603).
 * Vérification structurelle ciblée à la place : confirme que le contrôle
 * `Affected_Rows()===0` est bien présent dans le corps de `processQueue()`,
 * immédiatement après l'UPDATE `status='done'`, et qu'il déclenche bien
 * `watchdog()->critical()` avec la clé de traduction dédiée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $posUpdate = strpos($src, "UPDATE `{\$table}` SET `status` = 'done' WHERE `id_webhook` = {\$id}");
    neria_assert($posUpdate !== false, "UPDATE status='done' introuvable — jeu de test invalide");

    $body = substr($src, $posUpdate, 2800);

    neria_assert(
        strpos($body, '$affectedDone339 = (int) $this->db->Affected_Rows();') !== false
            && strpos($body, '$affectedDone339 === 0') !== false,
        "processQueue() ne vérifie plus Affected_Rows() après l'UPDATE status='done' — régression du bug corrigé le 12/09/2026 (round 339) : un échec silencieux de cette UPDATE précise (webhook réellement livré, DB non mise à jour) redeviendrait invisible et provoquerait une double notification au système tiers au prochain passage"
    );
    neria_assert(
        strpos($body, "'watchdog.webhook_done_not_confirmed'") !== false,
        "processQueue() ne déclenche plus l'alerte Watchdog critique 'watchdog.webhook_done_not_confirmed' quand Affected_Rows()===0 — régression du bug corrigé le 12/09/2026 (round 339)"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible — jeu de test invalide');
    neria_assert(
        isset($translations['watchdog.webhook_done_not_confirmed']),
        "La clé 'watchdog.webhook_done_not_confirmed' est absente de admin_translations.json"
    );
    $expectedLangs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl', 'gb'];
    foreach ($expectedLangs as $lang) {
        neria_assert(
            !empty($translations['watchdog.webhook_done_not_confirmed'][$lang]),
            "Traduction manquante pour 'watchdog.webhook_done_not_confirmed' dans la langue '{$lang}'"
        );
    }

    return [
        'pass'    => true,
        'message' => "WebhookManager::processQueue() vérifie bien Affected_Rows() sur l'UPDATE status='done' et déclenche une alerte Watchdog critique traduite en 19 langues en cas d'échec silencieux — bug corrigé le 12/09/2026 (round 339)",
    ];
}
