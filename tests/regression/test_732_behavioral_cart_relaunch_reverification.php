<?php
/**
 * Régression : sendAbandonedCarts()/sendCheckoutAbandonment()
 * (BehavioralCronManager) doivent, pour CHAQUE panier, revérifier juste
 * avant l'envoi (1) qu'aucune commande n'a été passée depuis le SELECT
 * initial, et (2) que le panier contient encore au moins un article — sans
 * se contenter du SELECT en début de traitement, qui peut dater de
 * plusieurs minutes pour un panier en fin de lot (MAX_BATCH_PER_RUN=500).
 *
 * Bugs identifiés le 13/09/2026 (round 350, audit multi-agents, angle
 * checkout abandonment) :
 * 1. Un client dont le panier est traité en fin de lot peut finaliser sa
 *    commande entre le SELECT (T0) et son tour d'envoi (T0+plusieurs
 *    minutes) — sans revérification, il recevait quand même la relance
 *    "panier oublié" pour une commande déjà payée. Même risque déjà
 *    identifié et corrigé côté file d'attente (QueueManager::
 *    processSingle(), round 294), jamais porté à ce chemin d'envoi direct.
 * 2. Si le panier est vidé/modifié entre le SELECT et l'envoi,
 *    buildCartProducts() renvoie '' silencieusement — l'email partait
 *    quand même, sans aucun article listé.
 *
 * Test structurel (simuler une vraie fenêtre de course — commande passée
 * PENDANT le traitement d'un lot de 500 paniers — est impraticable de
 * façon isolée et déterministe en CLI) : vérifie que les 2 garde-fous sont
 * bien présents dans les 2 méthodes concernées.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    foreach (['sendAbandonedCarts', 'sendCheckoutAbandonment'] as $method) {
        $posMethod = strpos($src, 'function ' . $method . '(');
        neria_assert($posMethod !== false, "{$method}() introuvable");

        $posNextMethod = strpos($src, "\n    private function ", $posMethod + 20);
        $body = $posNextMethod !== false
            ? substr($src, $posMethod, $posNextMethod - $posMethod)
            : substr($src, $posMethod, 4000);

        neria_assert(
            strpos($body, "SELECT 1 FROM `' . \$this->prefix . 'orders` WHERE id_cart = ' . \$idCart") !== false,
            "{$method}() ne revérifie plus l'absence de commande juste avant l'envoi — régression du bug corrigé le 13/09/2026 (round 350) : un client en fin de lot recevrait de nouveau une relance pour un panier déjà payé"
        );
        neria_assert(
            strpos($body, 'if ($alreadyOrdered) {') !== false,
            "{$method}() : le garde-fou anti-course \$alreadyOrdered a disparu — régression du bug corrigé le 13/09/2026 (round 350)"
        );
        neria_assert(
            strpos($body, "if (\$products === '') {") !== false,
            "{$method}() ne détecte plus un panier vidé/modifié (produits vides) avant l'envoi — régression du bug corrigé le 13/09/2026 (round 350) : un email 'panier oublié' sans aucun article listé pourrait de nouveau partir"
        );
        neria_assert(
            strpos($body, "'watchdog.behavioral_send_cancelled_empty_cart'") !== false,
            "{$method}() n'alerte plus Watchdog quand l'envoi est annulé pour panier vide — régression du bug corrigé le 13/09/2026 (round 350)"
        );
    }

    return [
        'pass'    => true,
        'message' => "sendAbandonedCarts()/sendCheckoutAbandonment() revérifient bien l'absence de commande ET la présence d'articles juste avant l'envoi, fermant la fenêtre de course du lot de 500 paniers — bugs corrigés le 13/09/2026 (round 350)",
    ];
}
