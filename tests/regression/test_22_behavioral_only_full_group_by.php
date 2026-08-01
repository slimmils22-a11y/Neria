<?php
/** Régression : sendReorderReminders()/sendWishlistReminders() ne doivent jamais planter sous sql_mode=ONLY_FULL_GROUP_BY. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $db->execute("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

    // Les deux méthodes retournent tôt (sans exécuter la moindre requête) si
    // leur toggle BO est désactivé — sans forcer l'activation ici, le test
    // "passerait" sans jamais avoir réellement exercé le SQL concerné.
    $prevReorder  = \Configuration::getGlobalValue('NERIA_REORDER_ENABLED');
    $prevWishlist = \Configuration::getGlobalValue('NERIA_WISHLIST_ENABLED');
    \Configuration::updateGlobalValue('NERIA_REORDER_ENABLED', '1');
    \Configuration::updateGlobalValue('NERIA_WISHLIST_ENABLED', '1');

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';
        $mgr = new BehavioralCronManager(neria_test_module());

        $errors = [];

        foreach (['sendReorderReminders', 'sendWishlistReminders'] as $method) {
            $ref = new ReflectionMethod($mgr, $method);
            $ref->setAccessible(true);
            try {
                $ref->invoke($mgr);
            } catch (\Throwable $e) {
                $errors[] = "{$method}(): " . $e->getMessage();
            }
        }

        neria_assert(
            empty($errors),
            "Erreur(s) sous ONLY_FULL_GROUP_BY : " . implode(' | ', $errors) . " — régression du bug GROUP BY non conforme corrigé le 01/08/2026 (commit a422c8f)"
        );

        return ['pass' => true, 'message' => 'sendReorderReminders()/sendWishlistReminders() restent valides sous ONLY_FULL_GROUP_BY'];
    } finally {
        \Configuration::updateGlobalValue('NERIA_REORDER_ENABLED', (string) $prevReorder);
        \Configuration::updateGlobalValue('NERIA_WISHLIST_ENABLED', (string) $prevWishlist);
        $db->execute("SET SESSION sql_mode = DEFAULT");
    }
}
