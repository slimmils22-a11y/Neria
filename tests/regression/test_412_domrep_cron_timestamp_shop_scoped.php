<?php
/**
 * Régression : DomainReputationManager::runFullCheckLocked() écrivait
 * NERIA_CRON_LAST_DOMREP sans idShop, alors que toutes les autres clés de
 * ce même fichier sont scrupuleusement scopées par boutique.
 * HealthCheckManager::checkCronsHealth() lisait cette même clé sans idShop
 * non plus.
 *
 * Bug réel identifié le 23/08/2026 (round 193) : le cron multi-boutique
 * (neria.php) appelle runFullCheckLocked() indépendamment pour chaque
 * boutique (échecs individuels avalés). Si la Boutique A réussit mais la
 * Boutique B échoue systématiquement (résolveur DNS en panne, config
 * d'expéditeur malformée sur B uniquement), ce timestamp GLOBAL était quand
 * même rafraîchi grâce au seul succès de A — un admin consultant le
 * Diagnostic depuis le contexte de la Boutique B voyait "OK, exécuté
 * récemment" alors que SA vérification échoue silencieusement depuis des
 * jours.
 *
 * Corrigé le 23/08/2026 (round 193) : $idShop transmis explicitement en
 * écriture ET en lecture.
 *
 * Test structurel (Shop::isFeatureActive() de PrestaShop lui-même ignore
 * tout $idShop explicite passé à Configuration::get()/updateValue() sur une
 * install à une seule boutique — cf. classes/Shop.php — rendant le
 * comportement multi-boutique non observable sur cet environnement de dev
 * mono-boutique, même avec un fix correct) : vérifie par lecture directe du
 * source que les 2 sites d'appel passent bien idShop.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $drmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
    neria_assert($drmSrc !== false, 'Impossible de lire src/DomainReputationManager.php');
    neria_assert(
        strpos($drmSrc, "\\Configuration::updateValue(\\HealthCheckManager::CRON_LAST_DOMREP, date('Y-m-d H:i:s'), false, null, \$this->idShop);") !== false,
        "DomainReputationManager::runFullCheckLocked() n'écrit plus CRON_LAST_DOMREP avec \$this->idShop — régression du bug corrigé le 23/08/2026 (round 193) : sur une install multi-boutiques, une exécution réussie sur une boutique masquerait de nouveau une panne réelle sur une autre"
    );

    $hcmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
    neria_assert($hcmSrc !== false, 'Impossible de lire src/HealthCheckManager.php');
    neria_assert(
        strpos($hcmSrc, "\$lastDomrep  = (string) \\Configuration::get(self::CRON_LAST_DOMREP, null, null, \$this->idShop);") !== false,
        "HealthCheckManager::checkCronsHealth() ne lit plus CRON_LAST_DOMREP avec \$this->idShop — régression du bug corrigé le 23/08/2026 (round 193)"
    );

    return [
        'pass'    => true,
        'message' => "NERIA_CRON_LAST_DOMREP est bien scopé par boutique en écriture ET en lecture — bug corrigé le 23/08/2026 (round 193)",
    ];
}
