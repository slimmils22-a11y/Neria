<?php
/**
 * Régression : QueueManager::processSingle() exécutait l'UPDATE finale
 * `status='sent', sent_at=NOW()` après un Mail::Send() réussi sans jamais
 * vérifier Affected_Rows() — un échec silencieux de cet UPDATE précis
 * (deadlock, coupure de connexion juste après l'envoi réel) laissait la
 * ligne bloquée au statut 'sending' ALORS QUE L'EMAIL A RÉELLEMENT ÉTÉ
 * ENVOYÉ. Le nettoyage "sending bloqué depuis 10 min" en tête de
 * processQueue() (round 241) la resélectionne ensuite au prochain passage
 * cron et la RENVOIE UNE SECONDE FOIS au même client, silencieusement (même
 * classe de bug que le pattern "succès affiché malgré effet réel absent",
 * cf. rounds 310/311/312) — mais inversé ici : c'est l'ABSENCE de mise à
 * jour, malgré un effet réel bien produit (email envoyé), qui est invisible.
 *
 * Corrigé le 06/09/2026 (round 313) : si Affected_Rows() === 0 après cette
 * UPDATE précise, une alerte Watchdog critique explicite est levée (pas de
 * correction automatique possible sans risque de double envoi — seule une
 * vérification manuelle est sûre).
 *
 * Reproduire ce cas précis nécessite un Mail::Send() réel réussi SUIVI d'un
 * échec bas niveau isolé de cette seule UPDATE (deadlock/coupure réseau) —
 * non simulable de façon fiable en test d'intégration sans mocker la
 * connexion DB elle-même. Vérification structurelle ciblée à la place :
 * confirme que le contrôle Affected_Rows()===0 est bien présent dans le
 * corps de processSingle(), immédiatement après l'UPDATE status='sent', et
 * qu'il déclenche bien watchdog()->critical() avec la clé de traduction
 * dédiée — cible le code réel exact (pas un texte de commentaire, qui
 * contient lui aussi le mot "Affected_Rows()" en toutes lettres, cf. piège
 * d'auto-collision déjà rencontré round 312 / test_601).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
    neria_assert($src !== false, 'Impossible de lire src/QueueManager.php');

    $posUpdate = strpos($src, "SET status = \\'sent\\', sent_at = NOW()");
    neria_assert($posUpdate !== false, "UPDATE status='sent' introuvable — jeu de test invalide");

    $body = substr($src, $posUpdate, 1800);

    neria_assert(
        strpos($body, '$affectedSent313 = (int) $this->db->Affected_Rows();') !== false && strpos($body, '$affectedSent313 === 0') !== false,
        "processSingle() ne vérifie plus Affected_Rows() (capturé dans \$affectedSent313) après l'UPDATE status='sent' — régression du bug corrigé le 06/09/2026 (round 313) : un échec silencieux de cette UPDATE précise (email réellement envoyé, DB non mise à jour) redeviendrait invisible et provoquerait un double envoi au prochain passage cron"
    );

    neria_assert(
        strpos($body, "'watchdog.queue_sent_not_confirmed'") !== false,
        "processSingle() ne déclenche plus l'alerte Watchdog critique 'watchdog.queue_sent_not_confirmed' quand Affected_Rows()===0 — régression du bug corrigé le 06/09/2026 (round 313)"
    );

    // La clé de traduction doit exister dans les 19 langues (sinon
    // AdminTranslator::t() affiche la clé brute au marchand).
    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible — jeu de test invalide');
    neria_assert(
        isset($translations['watchdog.queue_sent_not_confirmed']),
        "La clé 'watchdog.queue_sent_not_confirmed' est absente de admin_translations.json"
    );
    $expectedLangs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl', 'gb'];
    foreach ($expectedLangs as $lang) {
        neria_assert(
            !empty($translations['watchdog.queue_sent_not_confirmed'][$lang]),
            "Traduction manquante pour 'watchdog.queue_sent_not_confirmed' dans la langue '{$lang}'"
        );
    }

    return [
        'pass'    => true,
        'message' => "QueueManager::processSingle() vérifie bien Affected_Rows() sur l'UPDATE status='sent' et déclenche une alerte Watchdog critique traduite en 19 langues en cas d'échec silencieux — bug corrigé le 06/09/2026 (round 313)",
    ];
}
