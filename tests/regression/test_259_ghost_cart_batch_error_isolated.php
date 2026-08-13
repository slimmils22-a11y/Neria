<?php
/**
 * Régression : BehavioralCronManager::sendGhostCarts() n'englobait que les
 * instanciations Product/Link dans un try { … } finally { … } SANS catch,
 * contrairement à TOUTES les autres méthodes send*() de ce fichier, qui ont
 * un try/catch PAR LIGNE autour de tout leur traitement (voir
 * sendFirstAnniversaries(), sendBirthdays(), etc., qui journalisent
 * watchdog.behavioral_send_error et continuent le lot). Une exception dans
 * ces instanciations (produit orphelin référencé dans cart_product,
 * link_rewrite invalide, échec ObjectModel) remontait donc hors de
 * sendGhostCarts() tout entière, abandonnant silencieusement tous les
 * couples produit/client "panier fantôme" suivants du lot pour le reste du
 * cron de ce jour, sans log exploitable par ligne.
 *
 * Corrigé le 09/08/2026 (round 158) en englobant tout le traitement par
 * ligne (Shop::setContext()/Product/Link/send()) dans un try/catch qui
 * journalise watchdog.behavioral_send_error et continue au couple suivant,
 * même pattern que le reste du fichier et que le correctif round 157
 * (CollectionManager/LookCompletionManager).
 *
 * Test structurel (forcer une exception réelle nécessiterait de corrompre
 * des données produit/panier en base, hors périmètre d'un test isolé et
 * risqué pour les autres tests de la suite) : vérifie que le try englobant
 * démarre bien avant Shop::setContext() (pas seulement autour du bloc
 * Product/Link) et se termine par un catch qui journalise
 * watchdog.behavioral_send_error pour ghost_cart.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire BehavioralCronManager.php');

    $posFn = strpos($src, 'private function sendGhostCarts(');
    neria_assert($posFn !== false, 'sendGhostCarts() introuvable — jeu de test invalide');
    $posEnd = strpos($src, 'private function ', $posFn + 30);
    neria_assert($posEnd !== false, 'Méthode suivante introuvable — jeu de test invalide');
    $body = substr($src, $posFn, $posEnd - $posFn);

    $posOriginalShopId = strpos($body, '$originalGhostShopId = \Shop::getContextShopID(true);');
    neria_assert($posOriginalShopId !== false, '$originalGhostShopId introuvable — jeu de test invalide');
    $posTry = strpos($body, 'try {', $posOriginalShopId);
    $posSetContext = strpos($body, '\Shop::setContext(\Shop::CONTEXT_SHOP, $ghostShopId);', $posOriginalShopId);
    neria_assert(
        $posTry !== false && $posTry < $posSetContext,
        "sendGhostCarts() n'a plus de try englobant AVANT Shop::setContext() — régression du bug corrigé le 09/08/2026 (round 158) : le try/finally interne (sans catch) redeviendrait la seule protection, laissant fuiter toute exception hors de la méthode"
    );

    neria_assert(
        substr_count($body, 'catch (\Throwable $e) {') >= 1,
        "sendGhostCarts() n'a plus de bloc catch englobant — régression du bug corrigé le 09/08/2026 (round 158)"
    );
    neria_assert(
        strpos($body, "'template' => 'ghost_cart',") !== false && strpos($body, 'watchdog.behavioral_send_error') !== false,
        "sendGhostCarts() ne journalise plus watchdog.behavioral_send_error pour 'ghost_cart' — régression du bug corrigé le 09/08/2026 (round 158)"
    );

    // Le try englobant doit couvrir jusqu'à l'appel send() inclus (pas
    // seulement le bloc Product/Link) : send() doit apparaître AVANT le
    // catch, à l'intérieur du même try.
    $posSend = strpos($body, "\$this->send(\n                    'ghost_cart',");
    $posCatch = strpos($body, 'catch (\Throwable $e) {');
    neria_assert(
        $posSend !== false && $posCatch !== false && $posSend < $posCatch,
        "l'appel send('ghost_cart', ...) n'est plus à l'intérieur du try englobant — régression du bug corrigé le 09/08/2026 (round 158)"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::sendGhostCarts() englobe bien tout le traitement par ligne dans un try/catch qui journalise et continue le lot — bug corrigé le 09/08/2026 (round 158)",
    ];
}
