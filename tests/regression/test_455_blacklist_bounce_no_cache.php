<?php
/**
 * Régression : BlacklistManager::loadAll() et BounceManager::isBounced()
 * lisaient sans $use_cache=false, alors que ces deux méthodes gatent
 * directement chaque Mail::Send() (garde anti-envoi vers un template
 * blacklisté / une adresse en hard bounce). Même famille de bug
 * systémique que les rounds 210-217 : sous cache SQL périmé, un blocage
 * fraîchement enregistré (blacklist ajoutée, bounce reçu via webhook)
 * pouvait ne pas être vu immédiatement.
 *
 * Corrigé le 26/08/2026 (round 218) : $use_cache=false explicite.
 *
 * Test structurel + comportemental réel : une règle blacklistée / un
 * email en hard bounce seedés en base sont toujours détectés
 * correctement après l'ajout du paramètre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $base = _PS_MODULE_DIR_ . 'neria/';

    $bl = file_get_contents($base . 'src/BlacklistManager.php');
    neria_assert($bl !== false, 'Impossible de lire src/BlacklistManager.php');
    neria_assert(
        strpos($bl, "ORDER BY `template`, `lang`',\n            true,\n            false\n        );") !== false,
        "BlacklistManager::loadAll() n'a plus \$use_cache=false — régression du bug corrigé le 26/08/2026 (round 218)"
    );

    $bo = file_get_contents($base . 'src/BounceManager.php');
    neria_assert($bo !== false, 'Impossible de lire src/BounceManager.php');
    neria_assert(
        strpos($bo, "WHERE `email` = \\'' . pSQL(\$email) . '\\'',\n            false\n        );") !== false,
        "BounceManager::isBounced() n'a plus \$use_cache=false — régression du bug corrigé le 26/08/2026 (round 218)"
    );

    require_once $base . 'src/BlacklistManager.php';
    require_once $base . 'src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    // BlacklistManager : comportement nominal
    $db->execute("DELETE FROM {$prefix}neria_blacklist WHERE id_shop = {$idShop} AND template = 'round218_test'");
    try {
        $bmgr = new BlacklistManager($idShop);
        neria_assert(
            $bmgr->isBlacklisted('round218_test', 'fr') === false,
            "isBlacklisted() détecte à tort un template non blacklisté — jeu de test invalide"
        );
        $bmgr->add('round218_test', 'fr');
        $bmgr2 = new BlacklistManager($idShop);
        neria_assert(
            $bmgr2->isBlacklisted('round218_test', 'fr') === true,
            "BlacklistManager::isBlacklisted() ne détecte plus un template fraîchement blacklisté — régression du bug corrigé le 26/08/2026 (round 218)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_blacklist WHERE id_shop = {$idShop} AND template = 'round218_test'");
    }

    // BounceManager : comportement nominal
    $email = 'round218.bounce.test@example.test';
    $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");
    try {
        neria_assert(
            BounceManager::isBounced($email) === false,
            "isBounced() détecte à tort un email sans historique de bounce — jeu de test invalide"
        );
        $db->execute(
            "INSERT INTO {$prefix}neria_bounces (email, type, bounce_count, status, last_bounce_at, date_add)
             VALUES ('" . pSQL($email) . "', 'hard', 1, 'active', NOW(), NOW())"
        );
        neria_assert(
            BounceManager::isBounced($email) === true,
            "BounceManager::isBounced() ne détecte plus un hard bounce fraîchement enregistré — régression du bug corrigé le 26/08/2026 (round 218)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");
    }

    return [
        'pass'    => true,
        'message' => "BlacklistManager::loadAll() et BounceManager::isBounced() lisent bien avec \$use_cache=false et détectent toujours correctement les blocages — bug corrigé le 26/08/2026 (round 218)",
    ];
}
