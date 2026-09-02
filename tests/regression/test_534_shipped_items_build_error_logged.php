<?php
/**
 * Régression : `OrderTriggersManager::buildShippedItemsVars()` avalait
 * silencieusement toute exception (`$order->getProducts()` corrompu,
 * requête `order_carrier` en échec) — renvoyait `{shipped_items}`/
 * `{shipped_items_txt}` vides SANS journaliser l'échec via Watchdog.
 * `order_partial_shipped` partait quand même au client (variables vides,
 * pas d'erreur remontée à `Mail::Send()`), mais sans la liste des
 * articles expédiés ni le numéro de suivi transporteur, et sans aucune
 * trace permettant au marchand de savoir qu'un email dégradé venait
 * d'être envoyé.
 *
 * Bug identifié le 02/09/2026 (round 283, audit "exceptions avalées
 * silencieusement sans Watchdog").
 *
 * Corrigé le 02/09/2026 (round 283) : le catch journalise désormais un
 * `$this->watchdog()->warning(...)` (clé `watchdog.shipped_items_build_error`,
 * 19 locales) avant de renvoyer le contenu vide de repli — comportement
 * fonctionnel inchangé (l'email part toujours, dégradé), seule la trace
 * Watchdog est nouvelle.
 *
 * Test structurel : forcer une VRAIE exception dans `getProducts()`/la
 * requête `order_carrier` nécessiterait de corrompre un état partagé
 * (connexion DB, ligne produit) au risque d'effets de bord sur le reste
 * de la suite — vérifie la présence du log Watchdog dans le bloc catch
 * et de la clé de traduction dans les 19 locales.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    $posFn = strpos($src, 'private function buildShippedItemsVars(\Order $order): array');
    neria_assert($posFn !== false, 'buildShippedItemsVars() introuvable — jeu de test invalide');

    $posCatch = strpos($src, 'catch (\Throwable $e) {', $posFn);
    neria_assert($posCatch !== false && $posCatch - $posFn < 3000, 'jeu de test invalide : catch introuvable dans buildShippedItemsVars()');

    $body = substr($src, $posCatch, 900);
    neria_assert(
        strpos($body, '$this->watchdog()->warning(') !== false
            && strpos($body, 'watchdog.shipped_items_build_error') !== false,
        "OrderTriggersManager::buildShippedItemsVars() n'a plus le log Watchdog dans son catch — régression du bug corrigé le 02/09/2026 (round 283) : une exception lors de la construction de {shipped_items}/{shipped_items_txt} redeviendrait totalement invisible, un email order_partial_shipped dégradé (sans articles ni suivi) partirait sans laisser aucune trace"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    foreach ($locales as $l) {
        neria_assert(
            isset($translations['watchdog.shipped_items_build_error'][$l]) && $translations['watchdog.shipped_items_build_error'][$l] !== '',
            "la clé watchdog.shipped_items_build_error est absente ou vide pour la locale '{$l}' dans admin_translations.json"
        );
    }

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager::buildShippedItemsVars() journalise désormais tout échec de construction via Watchdog au lieu de l'avaler silencieusement — bug corrigé le 02/09/2026 (round 283)",
    ];
}
