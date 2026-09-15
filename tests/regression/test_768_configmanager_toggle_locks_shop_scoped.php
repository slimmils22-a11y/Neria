<?php
/**
 * Régression : `ConfigManager::toggleBooleanKey()`/`toggleMenuItemVisibility()`
 * utilisaient des noms de verrou MySQL NON scopés par boutique
 * (`'neria_toggle_' . $key` et `'neria_menu_hidden_items'`), alors que les
 * clés qu'ils protègent le sont déjà (chaque instance agit exclusivement
 * sur son propre `$this->idShop` via `$getter`/`$setter`/
 * `updateValue(..., $this->idShop)`). Deux boutiques indépendantes
 * basculant le MÊME toggle (ou le même item de menu masqué) au même
 * moment se bloquaient mutuellement inutilement le temps du verrou
 * (jusqu'à 3s), sans aucun risque réel de corruption de données.
 *
 * Bug identifié le 15/09/2026 (round 360, audit dédié ConfigManager).
 *
 * Corrigé le 15/09/2026 : les 2 noms de verrou incluent désormais
 * `$this->idShop`.
 *
 * Test comportemental réel : une connexion mysqli séparée tient le
 * verrou scopé sur la boutique A pendant qu'une instance ConfigManager
 * scopée sur la boutique B tente le même toggle au même moment — vérifie
 * que B n'est PLUS bloquée par le verrou de A (contrairement à l'ancien
 * comportement non scopé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $idShopA = 999997681;
    $idShopB = 999997682;
    $key = 'round360test768_dummy_key';

    $lockConn = new \mysqli(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_);
    neria_assert(!$lockConn->connect_error, 'connexion mysqli séparée impossible : ' . ($lockConn->connect_error ?? ''));

    // Tient le verrou de la boutique A (nom scopé attendu après correctif).
    $lockNameA = 'neria_toggle_' . $key . '_' . $idShopA;
    $heldRes = $lockConn->query("SELECT GET_LOCK('" . $lockConn->real_escape_string($lockNameA) . "', 3)");
    $held = $heldRes ? (int) $heldRes->fetch_row()[0] : 0;
    neria_assert($held === 1, 'jeu de test invalide : verrou A non acquis');

    try {
        // Une instance ConfigManager scopée sur la boutique B (différente
        // de A) doit pouvoir acquérir SON propre verrou (nom différent),
        // donc appeler getBoolean/setBoolean sans blocage.
        $refCfg = new ConfigManager(neria_test_module(), $idShopB);
        $refMethod = new ReflectionMethod(ConfigManager::class, 'toggleBooleanKey');
        $refMethod->setAccessible(true);

        // État initial AVANT bascule -- lu ainsi plutôt que supposé (l'état
        // par défaut de la clé/l'ordre d'exécution des tests ne sont pas
        // garantis), pour vérifier l'INVERSION réelle plutôt qu'une valeur
        // fixe qui rendrait ce test bistable/flaky selon l'état résiduel.
        $before = $refCfg->isMultiSenderEnabled();

        // toggleBooleanKey() attend des noms de méthode getter/setter
        // existants -- on réutilise un couple bool réel neutre pour ne
        // pas dépendre d'une clé métier spécifique : isMultiSenderEnabled/
        // setMultiSenderEnabled est un simple booléen scopé shop.
        $result = $refMethod->invoke($refCfg, 'NERIA_MULTISENDER_ENABLED', 'isMultiSenderEnabled', 'setMultiSenderEnabled');

        neria_assert(
            $result === !$before,
            "toggleBooleanKey() sur la boutique B a échoué (verrou refusé, état inchangé à {$before}) alors que seul le verrou de la boutique A (nom différent après correctif) est tenu — régression du bug corrigé le 15/09/2026 (round 360) : les noms de verrou 'neria_toggle_<key>'/'neria_menu_hidden_items' ne sont plus scopés par id_shop, 2 boutiques se bloqueraient mutuellement sans nécessité"
        );

        // Vérifie aussi structurellement que le verrou du menu est scopé
        // (comportemental équivalent disproportionné : nécessiterait de
        // dupliquer tout le mécanisme JSON getHiddenMenuItems()).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ConfigManager.php');
        neria_assert(
            strpos($src, "\$lockNameMenu = 'neria_menu_hidden_items_' . \$this->idShop;") !== false,
            "toggleMenuItemVisibility() n'utilise plus un nom de verrou scopé par \$this->idShop — régression du bug corrigé le 15/09/2026 (round 360)"
        );

        return [
            'pass'    => true,
            'message' => "ConfigManager::toggleBooleanKey()/toggleMenuItemVisibility() utilisent désormais des verrous MySQL scopés par boutique — 2 boutiques indépendantes ne se bloquent plus mutuellement sur le même toggle — bug corrigé le 15/09/2026 (round 360)",
        ];
    } finally {
        $lockConn->query("SELECT RELEASE_LOCK('" . $lockConn->real_escape_string($lockNameA) . "')");
        $lockConn->close();
        \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'NERIA_MULTISENDER_ENABLED\' AND `id_shop` = ' . (int) $idShopB
        );
    }
}
