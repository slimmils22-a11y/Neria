<?php
/**
 * Régression : BounceManager::isBounced() doit consulter à la fois la
 * ligne globale (id_shop=0, filet de sécurité qui bloque TOUTES les
 * boutiques) et la ligne scopée à une boutique donnée — jamais l'une
 * sans l'autre.
 *
 * Correctif du 15/09/2026 (bloc d de la feuille de route Addons, 2e
 * arbitrage produit — ce module étant vendu à de multiples commerçants,
 * un bounce détecté sur une boutique d'une installation multi-boutiques
 * ne doit plus nécessairement bloquer TOUTES les autres boutiques par
 * défaut). Avant ce correctif, `neria_bounces` n'avait aucune colonne
 * `id_shop` : un bounce enregistré via n'importe quel canal bloquait
 * l'envoi sur TOUTES les boutiques d'une installation multi-boutiques,
 * même totalement indépendantes commercialement.
 *
 * Test comportemental réel : insère directement 2 lignes distinctes
 * (une globale id_shop=0, une scopée à une boutique fictive) et vérifie
 * qu'isBounced() les traite bien différemment selon la boutique
 * interrogée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $emailGlobal = 'regtest771-global-' . uniqid() . '@example.com';
    $emailScoped = 'regtest771-scoped-' . uniqid() . '@example.com';
    $shopA = 999997711;
    $shopB = 999997712;

    $cleanup = function () use ($db, $prefix, $emailGlobal, $emailScoped) {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email IN ('" . pSQL($emailGlobal) . "', '" . pSQL($emailScoped) . "')");
    };
    $cleanup();

    try {
        // Ligne GLOBALE (id_shop=0, hard) : doit bloquer sur N'IMPORTE
        // QUELLE boutique interrogée — filet de sécurité, jamais moins
        // protecteur qu'avant ce correctif.
        $db->execute(
            "INSERT INTO {$prefix}neria_bounces (email, id_shop, type, bounce_count, last_bounce_at, status, date_add)
             VALUES ('" . pSQL($emailGlobal) . "', 0, 'hard', 1, NOW(), 'active', NOW())"
        );
        neria_assert(
            BounceManager::isBounced($emailGlobal, $shopA) === true,
            "isBounced() ne bloque plus une adresse en bounce GLOBAL (id_shop=0) sur la boutique {$shopA} — régression du correctif du 15/09/2026 : le filet de sécurité global serait cassé"
        );
        neria_assert(
            BounceManager::isBounced($emailGlobal, $shopB) === true,
            "isBounced() ne bloque plus une adresse en bounce GLOBAL (id_shop=0) sur la boutique {$shopB} — régression du correctif du 15/09/2026 : le filet de sécurité global serait cassé"
        );

        // Ligne SCOPÉE à la boutique A (hard) : ne doit bloquer QUE la
        // boutique A, pas la boutique B.
        $db->execute(
            "INSERT INTO {$prefix}neria_bounces (email, id_shop, type, bounce_count, last_bounce_at, status, date_add)
             VALUES ('" . pSQL($emailScoped) . "', {$shopA}, 'hard', 1, NOW(), 'active', NOW())"
        );
        neria_assert(
            BounceManager::isBounced($emailScoped, $shopA) === true,
            "isBounced() ne bloque plus une adresse en bounce SCOPÉ sur la boutique concernée ({$shopA}) — régression du correctif du 15/09/2026"
        );
        neria_assert(
            BounceManager::isBounced($emailScoped, $shopB) === false,
            "isBounced() bloque à tort une adresse en bounce SCOPÉ à la boutique {$shopA} sur une AUTRE boutique ({$shopB}) — régression du correctif du 15/09/2026 : sur une install multi-boutiques, un bounce d'une boutique bloquerait à tort une boutique indépendante"
        );

        return [
            'pass'    => true,
            'message' => "BounceManager::isBounced() isole bien les bounces scopés par boutique tout en conservant le filet de sécurité global (id_shop=0) — correctif du 15/09/2026 (bloc d)",
        ];
    } finally {
        $cleanup();
    }
}
