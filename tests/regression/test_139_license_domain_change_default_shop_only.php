<?php
/**
 * Régression : LicenseManager::checkDomainChange() ne doit comparer le
 * domaine courant au domaine du jeton de licence que sur la boutique par
 * défaut de l'installation — jamais sur une boutique secondaire.
 *
 * Bug réel corrigé le 08/08/2026 : la licence est globale (un seul jeton
 * pour toute l'installation, aucun scoping id_shop), mais currentDomain()
 * reflétait le domaine de la boutique visitée par le visiteur front. Sur
 * une installation multi-boutiques avec domaines différents par boutique,
 * chaque page vue d'une boutique secondaire (dont le domaine diffère
 * naturellement de celui enregistré à l'activation) déclenchait à tort un
 * "changement de domaine" : validateLicense(true) contournait le cache 24h
 * à chaque hit, déclenchant un appel réseau en rafale vers le serveur de
 * licence.
 *
 * Test structurel (déclencher un vrai appel réseau vers neriasoftware.com
 * serait trop invasif) : vérifie que checkDomainChange() court-circuite
 * bien AVANT toute comparaison de domaine lorsque la boutique courante
 * n'est pas la boutique par défaut de l'installation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LicenseManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LicenseManager.php');

    $posMethod = strpos($src, 'public function checkDomainChange(): void');
    neria_assert($posMethod !== false, 'checkDomainChange() introuvable — régression du bug corrigé le 08/08/2026');

    $body = substr($src, $posMethod, 1400);

    $posFeatureCheck = strpos($body, 'Shop::isFeatureActive()');
    neria_assert(
        $posFeatureCheck !== false,
        "checkDomainChange() ne vérifie plus si le multi-boutique est actif — régression du bug corrigé le 08/08/2026 : le contrôle de domaine redeviendrait déclenché sur chaque boutique secondaire"
    );

    $posDefaultShop = strpos($body, "PS_SHOP_DEFAULT");
    neria_assert(
        $posDefaultShop !== false && $posDefaultShop > $posFeatureCheck,
        "checkDomainChange() ne restreint plus la comparaison à la boutique par défaut — régression du bug corrigé le 08/08/2026"
    );

    $posReturn = strpos($body, 'return;', $posDefaultShop);
    $posCachedDomain = strpos($body, '$cachedDomain');
    neria_assert(
        $posReturn !== false && $posCachedDomain !== false && $posReturn < $posCachedDomain,
        "checkDomainChange() : le court-circuit sur boutique secondaire ne précède plus la comparaison de domaine — régression du bug corrigé le 08/08/2026"
    );

    return [
        'pass'    => true,
        'message' => "LicenseManager::checkDomainChange() restreint bien la comparaison de domaine à la boutique par défaut de l'installation, évitant les faux positifs en multi-boutiques multi-domaines",
    ];
}
