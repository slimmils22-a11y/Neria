<?php
/**
 * Régression : LookCompletionManager::claimSend() écrivait sent_at via
 * date('Y-m-d H:i:s') (horloge PHP) alors que getStats() filtre ensuite
 * "envoyés depuis 30 jours" via `sent_at >= DATE_SUB(NOW(), INTERVAL 30
 * DAY)`, comparaison purement côté MySQL — si le serveur web (PHP) et le
 * serveur MySQL n'ont pas le même fuseau horaire, une ligne écrite avec
 * l'horloge PHP pouvait être incluse/exclue à tort près de la frontière des
 * 30 jours.
 *
 * Corrigé le 07/09/2026 (round 314) : sent_at écrit via NOW() SQL.
 *
 * Test comportemental réel : appelle claimSend() via réflexion pour une
 * commande/client de test, puis compare le sent_at écrit en base à un
 * SELECT NOW() immédiat — l'écart doit être de l'ordre de la seconde
 * (preuve que la valeur vient bien de MySQL, pas d'une chaîne PHP
 * interpolée), complété par une vérification structurelle ciblant le
 * littéral NOW() exact dans le corps de la méthode (pour distinguer un
 * vrai NOW() SQL d'un date() PHP qui donnerait un résultat quasi identique
 * sur une machine de test sans dérive d'horloge réelle).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $orderRow = $db->getRow("SELECT id_order, id_customer FROM {$prefix}orders ORDER BY id_order DESC");
    neria_assert($orderRow !== false, 'Aucune commande trouvée — jeu de test invalide');
    $idOrder    = (int) $orderRow['id_order'];
    $idCustomer = (int) $orderRow['id_customer'];

    try {
        $mgr = new LookCompletionManager(neria_test_module());
        $ref = new ReflectionMethod(LookCompletionManager::class, 'claimSend');
        $ref->setAccessible(true);
        $claimed = $ref->invoke($mgr, $idOrder, $idCustomer);
        neria_assert($claimed === true, 'claimSend() n\'a pas remporté la réservation — jeu de test invalide (ligne déjà présente ?)');

        $sentAt = $db->getValue(
            "SELECT sent_at FROM {$prefix}neria_look_sent WHERE id_order = {$idOrder} AND id_customer = {$idCustomer}"
        );
        neria_assert($sentAt !== false, 'sent_at introuvable après claimSend() — jeu de test invalide');

        $diffSeconds = (int) $db->getValue("SELECT TIMESTAMPDIFF(SECOND, '" . pSQL((string) $sentAt) . "', NOW())");
        neria_assert(
            abs($diffSeconds) <= 5,
            "sent_at écrit par claimSend() diffère de NOW() MySQL de {$diffSeconds}s — jeu de test invalide ou horloge incohérente"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
        neria_assert($src !== false, 'Impossible de lire src/LookCompletionManager.php');
        neria_assert(
            strpos($src, "VALUES ({\$idOrder}, {\$idCustomer}, NOW())") !== false,
            "LookCompletionManager::claimSend() n'écrit plus sent_at via NOW() SQL dans son propre corps — régression du bug corrigé le 07/09/2026 (round 314) : sent_at redeviendrait basé sur l'horloge PHP, source d'incohérence avec le filtre MySQL de getStats()"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_look_sent WHERE id_order = {$idOrder} AND id_customer = {$idCustomer}");
    }

    return [
        'pass'    => true,
        'message' => "LookCompletionManager::claimSend() écrit bien sent_at via NOW() SQL, cohérent avec le filtre MySQL de getStats() — bug corrigé le 07/09/2026 (round 314)",
    ];
}
