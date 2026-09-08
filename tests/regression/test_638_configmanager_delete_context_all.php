<?php
/**
 * Régression : ConfigManager::deleteAll()/resetTimeGreetings(null)
 * utilisaient Configuration::deleteFromContext() — le cœur PrestaShop
 * (classes/Configuration.php) fait retourner cette méthode IMMÉDIATEMENT
 * sans rien supprimer dès que Shop::getContext() === Shop::CONTEXT_ALL (le
 * sélecteur de boutique du BO positionné sur "Toutes les boutiques"), quel
 * que soit l'$idShop explicite passé en argument — même piège déjà corrigé
 * pour DomainReputationManager::invalidateCache() (round 314, voir
 * test_606). Un marchand en contexte BO "Toutes les boutiques" au moment
 * de cliquer "Réinitialiser Neria" ou "Réinitialiser les salutations
 * horaires" voyait un succès (true retourné) sans que la config soit
 * réellement supprimée.
 *
 * Corrigé le 08/09/2026 (round 323, traitement différé) :
 * ConfigManager::deleteScoped() résout l'id de ligne via
 * Configuration::getIdByName($key, null, $idShop) (insensible à
 * Shop::getContext(), contrairement à deleteFromContext()) puis supprime
 * par id via Configuration::deleteById().
 *
 * Test comportemental réel : force Shop::setContext(Shop::CONTEXT_ALL)
 * (l'état effectivement rencontré côté BO "Toutes les boutiques" — le
 * bootstrap CLI de ce jeu de tests tourne lui en CONTEXT_SHOP, donc ce
 * cas ne se manifeste pas naturellement ici), pose une ligne de config
 * réelle, appelle deleteAll(), et vérifie qu'elle a bien disparu.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $key    = 'NERIA_CARBON_LINK';
    $idShop = (int) Context::getContext()->shop->id;
    $originalContext = Shop::getContext();

    try {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShop}");
        $db->execute(
            "INSERT INTO {$prefix}configuration (id_shop_group, id_shop, name, value, date_add, date_upd)
             VALUES (NULL, {$idShop}, '{$key}', 'https://own-shop.example/carbon', NOW(), NOW())"
        );

        $existsBefore = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShop}"
        );
        neria_assert($existsBefore === 1, 'Jeu de test invalide : la ligne de config factice ne semble pas posée');

        // Simule le contexte BO "Toutes les boutiques" — c'est ici, et
        // SEULEMENT ici, que deleteFromContext() ne faisait rien.
        Shop::setContext(Shop::CONTEXT_ALL);

        $config = new ConfigManager($module);
        $result = $config->deleteAll();

        Shop::setContext(Shop::CONTEXT_SHOP, $idShop);

        neria_assert($result === true, "deleteAll() n'a pas renvoyé true — jeu de test invalide");

        $existsAfter = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShop}"
        );
        neria_assert(
            $existsAfter === 0,
            "ConfigManager::deleteAll() n'a pas supprimé la ligne sous Shop::CONTEXT_ALL — régression du bug corrigé le 08/09/2026 (round 323) : deleteFromContext() ne fait RIEN sous ce contexte, un marchand en 'Toutes les boutiques' verrait un succès trompeur sans que rien ne soit réellement supprimé"
        );
    } finally {
        Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShop}");
    }

    return [
        'pass'    => true,
        'message' => "ConfigManager::deleteAll() supprime bien la config même sous Shop::CONTEXT_ALL, où deleteFromContext() ne faisait auparavant rien — bug corrigé le 08/09/2026 (round 323)",
    ];
}
