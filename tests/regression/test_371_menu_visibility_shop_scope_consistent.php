<?php
/**
 * Régression : ConfigManager::resetToDefaults() (via set(), qui écrit
 * NERIA_MENU_HIDDEN_ITEMS en shop-scopé via Configuration::updateValue(...,
 * $this->idShop), comme get() le lit) et toggleMenuItemVisibility()/
 * setAllMenuItemsVisibility() (qui écrivaient via
 * Configuration::updateGlobalValue(), donc uniquement la ligne globale
 * id_shop=0) n'utilisaient pas le même scope shop pour la même clé.
 * Sur une installation multi-boutiques active (Shop::isFeatureActive()),
 * après un clic "Réinitialiser" (pose une ligne shop-scopée '[]'), un
 * masquage de menu ultérieur restait sans effet visible au rechargement :
 * la ligne shop-scopée '[]', prioritaire à la lecture de
 * Configuration::get(..., $idShop), masquait l'écriture globale du toggle.
 *
 * Corrigé le 17/08/2026 (round 181) : toggleMenuItemVisibility() et
 * setAllMenuItemsVisibility() écrivent désormais via
 * Configuration::updateValue(..., $this->idShop), même scope que get()/set().
 *
 * Test hybride (une reproduction multi-shop réelle nécessiterait d'activer
 * PS_MULTISHOP_FEATURE_ACTIVE sur cet environnement de dev — effet de bord
 * trop large pour un test isolé ; le cœur PrestaShop ignore silencieusement
 * le paramètre id_shop de Configuration::updateValue()/get() tant que la
 * fonctionnalité multi-boutique n'est pas active, ce qui rend la
 * reproduction directe impossible sans elle) :
 * 1. Vérification structurelle que les 3 méthodes utilisent le même appel
 *    Configuration::updateValue(..., $this->idShop) — plus d'asymétrie
 *    updateGlobalValue() vs updateValue().
 * 2. Vérification comportementale réelle du round-trip set()/toggle/get()
 *    sur une instance fraîche à chaque étape (couvre la régression de
 *    cache mémoire local, indépendante du multi-boutique).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    // --- 1. Vérification structurelle du scope ---
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ConfigManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ConfigManager.php');

    $posToggle = strpos($src, 'public function toggleMenuItemVisibility(string $key): void');
    neria_assert($posToggle !== false, 'toggleMenuItemVisibility() introuvable — jeu de test invalide');
    $posSetAll = strpos($src, 'public function setAllMenuItemsVisibility(bool $visible): void');
    neria_assert($posSetAll !== false, 'setAllMenuItemsVisibility() introuvable — jeu de test invalide');
    $posNextAfterSetAll = strpos($src, 'public function', $posSetAll + 10);
    neria_assert($posNextAfterSetAll !== false, 'Méthode suivante introuvable pour borner la fenêtre — jeu de test invalide');

    $toggleBody = substr($src, $posToggle, $posSetAll - $posToggle);
    $setAllBody = substr($src, $posSetAll, $posNextAfterSetAll - $posSetAll);

    // Note : recherche positive uniquement (pas de "ne contient pas
    // updateGlobalValue") — le commentaire round 123 de toggleMenuItemVisibility()
    // mentionne "Configuration::updateGlobalValue()" en toutes lettres dans sa
    // prose historique (décrit l'ancien comportement pré-round-181), ce qui
    // ferait échouer à tort une assertion négative sur ce même texte.
    neria_assert(
        strpos($toggleBody, "Configuration::updateValue(self::KEY_MENU_HIDDEN_ITEMS, \$encoded, false, null, \$this->idShop)") !== false,
        "toggleMenuItemVisibility() n'écrit plus dans le même scope shop que get()/set() — régression du bug corrigé le 17/08/2026 (round 181)"
    );
    neria_assert(
        strpos($setAllBody, "Configuration::updateValue(self::KEY_MENU_HIDDEN_ITEMS, \$encoded, false, null, \$this->idShop)") !== false,
        "setAllMenuItemsVisibility() n'écrit plus dans le même scope shop que get()/set() — régression du bug corrigé le 17/08/2026 (round 181)"
    );

    // --- 2. Round-trip comportemental réel (cache mémoire, pas multi-shop) ---
    $module = neria_test_module();
    $key    = ConfigManager::KEY_MENU_HIDDEN_ITEMS;
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $originalGlobal = $db->getValue(
        "SELECT value FROM {$prefix}configuration WHERE name = 'NERIA_MENU_HIDDEN_ITEMS' AND id_shop IS NULL"
    );

    try {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'NERIA_MENU_HIDDEN_ITEMS'");

        $cfgReset = new ConfigManager($module);
        $cfgReset->set($key, '[]');

        $cfgToggle = new ConfigManager($module);
        $cfgToggle->toggleMenuItemVisibility('abtest');

        $cfgRead = new ConfigManager($module);
        $hidden  = $cfgRead->getHiddenMenuItems();

        neria_assert(
            in_array('abtest', $hidden, true),
            "toggleMenuItemVisibility('abtest') n'est pas visible à la relecture par une instance fraîche — régression du bug corrigé le 17/08/2026 (round 181)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'NERIA_MENU_HIDDEN_ITEMS'");
        if ($originalGlobal !== false && $originalGlobal !== null) {
            $db->execute(
                "INSERT INTO {$prefix}configuration (name, value, id_shop, date_add, date_upd) VALUES
                 ('NERIA_MENU_HIDDEN_ITEMS', '" . pSQL($originalGlobal) . "', NULL, NOW(), NOW())"
            );
        }
    }

    return [
        'pass'    => true,
        'message' => "ConfigManager::toggleMenuItemVisibility()/setAllMenuItemsVisibility() écrivent désormais dans le même scope shop que get()/set() — bug corrigé le 17/08/2026 (round 181)",
    ];
}
