<?php
/**
 * Régression : CooldownManager::resolveCustomerId() doit commuter le
 * contexte boutique STATIQUE (Shop::setContext()) autour de
 * \Customer::customerExists(), pas se contenter de l'id_shop passé en
 * paramètre ou de Context::getContext()->shop->id.
 *
 * Bug réel corrigé le 08/08/2026 (round 129) : \Customer::customerExists()
 * filtre en interne via Shop::addSqlRestriction(), qui s'appuie sur
 * Shop::$context_id_shop (statique) — jamais mis à jour par la simple
 * réaffectation Context::getContext()->shop = new Shop($idShop) utilisée
 * dans la boucle multi-boutique du cron comportemental. Le client était
 * résolu par rapport à la boutique "ambiante" figée au bootstrap du
 * process, désactivant silencieusement le Mode Silence (anti-doublon) pour
 * toutes les boutiques suivantes de la boucle.
 *
 * Test comportemental réel : vérifie (1) que la résolution reste correcte
 * pour un client réel, et (2) que le contexte Shop statique est bien
 * restauré après l'appel (pas de fuite d'état affectant les appels
 * suivants du même process cron).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $idCustomer = neria_test_any_customer_id();
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $row = $db->getRow("SELECT email FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    $email = $row['email'];
    $realShop = (int) Context::getContext()->shop->id;

    $module = neria_test_module();
    require_once _PS_MODULE_DIR_ . 'neria/src/CooldownManager.php';
    $mgr = new CooldownManager($module);

    $ref = new ReflectionMethod($mgr, 'resolveCustomerId');
    $ref->setAccessible(true);

    $contextBefore = Shop::getContextShopID();
    $resolved = $ref->invoke($mgr, $email, $realShop);
    $contextAfter = Shop::getContextShopID();

    neria_assert(
        $resolved === $idCustomer,
        "resolveCustomerId() a résolu {$resolved} au lieu de {$idCustomer} — régression du bug corrigé le 08/08/2026 (round 129)"
    );
    neria_assert(
        (int) $contextAfter === (int) $contextBefore,
        "Le contexte Shop statique n'a pas été restauré après resolveCustomerId() (avant={$contextBefore}, après={$contextAfter}) — fuite d'état pouvant affecter les appels suivants du même process cron"
    );

    return [
        'pass'    => true,
        'message' => "CooldownManager::resolveCustomerId() commute toujours le contexte Shop statique correctement, sans fuite d'état",
    ];
}
