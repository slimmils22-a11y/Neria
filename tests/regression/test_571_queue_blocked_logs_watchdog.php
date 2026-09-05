<?php
/**
 * Régression : QueueManager::markQueueFailed() (appelée pour bounce,
 * blacklist, préférences, cooldown, produit indisponible, panier déjà
 * converti) mettait à jour la ligne en base (status='failed',
 * error='blocked_by_...') sans JAMAIS appeler watchdog()->warning()/
 * error() — contrairement à absolument tous les autres chemins d'échec du
 * même fichier (markFailedOrRetry(), et le principe explicitement établi
 * au round 268 juste au-dessus dans processSingle() : "un chemin d'échec
 * qui n'appelle aucun watchdog()->warning()/error() est invisible — ni
 * alerte immédiate, ni entrée dans le digest quotidien").
 *
 * Scénario concret : un marchand planifie un envoi manuel
 * (ManualSendManager::scheduleManual(), qui répond "programmé avec
 * succès"). Au moment du traitement réel par le cron, la ligne est bloquée
 * par le Mode Silence (cooldown) ou les préférences du client — elle passe
 * silencieusement en status='failed' en base, sans qu'aucune trace
 * Watchdog n'existe : le marchand n'a alors AUCUN moyen de savoir que
 * l'email n'est jamais parti, ni pourquoi.
 *
 * Corrigé le 05/09/2026 (round 303) : markQueueFailed() appelle désormais
 * watchdog()->warning() avec la raison du blocage.
 *
 * Test comportemental réel : appelle markQueueFailed() via reflection et
 * vérifie qu'une nouvelle ligne apparaît bien dans neria_log avec la
 * classe 'QueueManager' et le niveau 'warning'.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $idShop = (int) Context::getContext()->shop->id;

    $fakeQueueId = 888555; // id de ligne fictif — la méthode ne vérifie pas son existence avant l'UPDATE
    $cleanup = function () use ($db, $prefix, $fakeQueueId): void {
        $db->execute("DELETE FROM {$prefix}neria_log WHERE class = 'QueueManager' AND message LIKE '%{$fakeQueueId}%'");
    };
    $cleanup();

    try {
        // Baseline : nombre de lignes neria_log 'warning'/QueueManager avant l'appel.
        $before = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE class = 'QueueManager' AND level = 'warning' AND id_shop = {$idShop}"
        );

        $mgr = new QueueManager(neria_test_module());
        $ref = new ReflectionMethod(QueueManager::class, 'markQueueFailed');
        $ref->setAccessible(true);
        $result = $ref->invoke($mgr, $fakeQueueId, 'cooldown');

        neria_assert($result === false, "markQueueFailed() ne renvoie plus false — changement de contrat inattendu");

        $after = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE class = 'QueueManager' AND level = 'warning' AND id_shop = {$idShop}"
        );

        neria_assert(
            $after > $before,
            "markQueueFailed() n'a créé aucune nouvelle ligne neria_log (level='warning', class='QueueManager') — régression du bug corrigé le 05/09/2026 (round 303) : un envoi bloqué (bounce/blacklist/préférences/cooldown/produit indisponible/panier déjà converti) redeviendrait totalement invisible, sans alerte ni entrée dans le digest quotidien"
        );

        // Vérification structurelle complémentaire : l'appel watchdog() est
        // bien présent dans le corps de la méthode elle-même (pas juste un
        // effet de bord d'un autre appel concurrent sur la même fenêtre).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
        neria_assert($src !== false, 'Impossible de lire src/QueueManager.php');
        $posFn = strpos($src, 'private function markQueueFailed(int $id, string $reason): bool');
        neria_assert($posFn !== false, 'markQueueFailed() introuvable — jeu de test invalide');
        $body = substr($src, $posFn, 700);
        neria_assert(
            strpos($body, 'watchdog()->warning(') !== false,
            "markQueueFailed() n'appelle plus watchdog()->warning() dans son propre corps — régression du bug corrigé le 05/09/2026 (round 303)"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager::markQueueFailed() journalise désormais un warning Watchdog à chaque blocage (bounce/blacklist/préférences/cooldown/produit indisponible/panier déjà converti) — bug corrigé le 05/09/2026 (round 303)",
        ];
    } finally {
        $cleanup();
    }
}
