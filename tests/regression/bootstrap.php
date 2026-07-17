<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Suite de tests de régression — bootstrap partagé.
 *
 * Ces tests ne remplacent pas un audit de code : ils protègent contre la
 * RÉCIDIVE des bugs réels trouvés et corrigés lors de l'audit exhaustif du
 * 17/07/2026 (64 bugs), pas contre l'apparition de nouveaux bugs inconnus.
 * Chaque test seed des données réelles dans la base de dev, exécute le vrai
 * code du module, vérifie le résultat attendu, puis nettoie systématiquement
 * — même en cas d'échec (finally).
 *
 * Usage : php run_all.php
 * Chaque fichier test_*.php doit définir une fonction run_test(): array
 * retournant ['pass' => bool, 'message' => string].
 */

if (!defined('_PS_ROOT_DIR_')) {
    require_once dirname(__DIR__, 4) . '/config/config.inc.php';
}

function neria_test_db(): Db
{
    return Db::getInstance();
}

function neria_test_module(): Module
{
    static $module = null;
    if ($module === null) {
        $module = Module::getInstanceByName('neria');
    }
    return $module;
}

function neria_test_prefix(): string
{
    return _DB_PREFIX_;
}

/** Un client réel actif quelconque, pour les tests qui ont juste besoin d'un id_customer valide. */
function neria_test_any_customer_id(): int
{
    static $id = null;
    if ($id === null) {
        $id = (int) neria_test_db()->getValue(
            "SELECT id_customer FROM " . neria_test_prefix() . "customer WHERE active=1 AND deleted=0"
        );
    }
    return $id;
}

function neria_assert(bool $cond, string $failMessage): void
{
    if (!$cond) {
        throw new RuntimeException($failMessage);
    }
}
