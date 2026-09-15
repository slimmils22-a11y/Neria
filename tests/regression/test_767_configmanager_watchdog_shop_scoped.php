<?php
/**
 * Régression : `ConfigManager::watchdog()` instanciait son
 * `WatchdogManager` interne SANS transmettre `$this->idShop`
 * (`new \WatchdogManager($this->module)`) — un ConfigManager construit
 * sur une boutique DIFFÉRENTE du contexte ambiant (pattern déjà utilisé
 * par `EmailRenderer::resolveShopId()` pour le multi-sender, round 351)
 * journalisait donc tout avertissement (`toggleBooleanKey()`,
 * `toggleMenuItemVisibility()`, `uploadLogo()`) sous l'id_shop AMBIANT,
 * pas celui réellement traité par cette instance.
 *
 * Bug identifié le 15/09/2026 (round 360, audit dédié ConfigManager).
 *
 * Corrigé le 15/09/2026 : `WatchdogManager` accepte désormais un 2e
 * paramètre optionnel `?int $idShop` (même pattern que ConfigManager/
 * TranslationEngine/DomainReputationManager, rounds 351/357/358), et
 * `ConfigManager::watchdog()` transmet explicitement `$this->idShop`.
 *
 * Test comportemental réel : construit un ConfigManager avec un $idShop
 * NON ambiant, provoque un verrou GET_LOCK() déjà tenu (donc un
 * ->warning() réel via toggleMenuItemVisibility()), et vérifie que la
 * ligne neria_log résultante porte bien l'id_shop de l'INSTANCE, pas celui
 * du contexte ambiant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $ambientIdShop  = (int) \Context::getContext()->shop->id;
    $targetIdShop   = 999997671;
    neria_assert($targetIdShop !== $ambientIdShop, 'jeu de test invalide : id_shop cible identique au contexte ambiant');

    $db->execute("DELETE FROM {$prefix}neria_log WHERE message LIKE '%round360test767%'");

    // Tient le verrou MySQL depuis une AUTRE connexion pour forcer
    // toggleMenuItemVisibility() à échouer immédiatement (comme test_182).
    $lockName = 'neria_menu_hidden_items_' . $targetIdShop;
    $lockConn = new \mysqli(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_);
    neria_assert(!$lockConn->connect_error, 'connexion mysqli séparée impossible : ' . ($lockConn->connect_error ?? ''));
    $heldRes = $lockConn->query("SELECT GET_LOCK('" . $lockConn->real_escape_string($lockName) . "', 3)");
    $held = $heldRes ? (int) $heldRes->fetch_row()[0] : 0;
    neria_assert($held === 1, 'jeu de test invalide : verrou concurrent non acquis');

    try {
        $cfg = new ConfigManager(neria_test_module(), $targetIdShop);
        $cfg->toggleMenuItemVisibility('round360test767_dummy_key');

        $row = $db->getRow(
            "SELECT `id_shop` FROM {$prefix}neria_log
             WHERE class = 'ConfigManager' AND message LIKE '%round360test767_dummy_key%'
             ORDER BY id_log DESC",
            false
        );

        neria_assert(is_array($row), "Aucune ligne neria_log trouvée pour le warning toggleMenuItemVisibility() — jeu de test invalide (verrou pas réellement contesté ?)");

        neria_assert(
            (int) $row['id_shop'] === $targetIdShop,
            "Le warning ConfigManager::toggleMenuItemVisibility() a été journalisé sous id_shop={$row['id_shop']} au lieu de {$targetIdShop} (boutique de l'instance) — régression du bug corrigé le 15/09/2026 (round 360) : ConfigManager::watchdog() ne transmet plus \$this->idShop à WatchdogManager, le log retomberait à tort sur le contexte ambiant ({$ambientIdShop})"
        );

        return [
            'pass'    => true,
            'message' => "ConfigManager::watchdog() transmet désormais bien \$this->idShop à son WatchdogManager interne — un log émis par une instance scopée sur une autre boutique porte le bon id_shop — bug corrigé le 15/09/2026 (round 360)",
        ];
    } finally {
        $lockConn->query("SELECT RELEASE_LOCK('" . $lockConn->real_escape_string($lockName) . "')");
        $lockConn->close();
        $db->execute("DELETE FROM {$prefix}neria_log WHERE message LIKE '%round360test767%'");
    }
}
