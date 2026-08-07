<?php
/**
 * Régression : SegmentManager::sendToSegment() doit résoudre {shop_url} via
 * $this->idShop (getBaseLink()), pas \Tools::getShopDomainSsl() non scopé
 * (résout via Context::getContext()->shop, le contexte d'exécution
 * courant) — même correctif déjà appliqué à LoyaltyManager/
 * SeasonalCampaignManager/ManualSendManager pour ce même piège.
 *
 * Bug réel corrigé le 07/08/2026 (round 102) : {history_url} (ligne
 * juste au-dessus, dans le même tableau $vars) était déjà correctement
 * scopé par $this->idShop, mais {shop_url} — un placeholder du même
 * template, généré dans la même méthode, une ligne plus bas — utilisait
 * \Tools::getShopDomainSsl(true), qui résout toujours via le contexte
 * d'exécution courant. Sur une installation multi-boutiques, un client de
 * la boutique B recevait un lien {shop_url} pointant vers le domaine
 * d'une AUTRE boutique si le contexte d'exécution divergeait de la
 * boutique réellement ciblée (getCustomersBySegment() filtre déjà bien
 * $this->idShop pour la sélection des destinataires).
 *
 * Test structurel (pas d'invocation de sendToSegment(), qui déclencherait
 * un envoi email réel via Mail::Send() dans une boucle sur de vrais
 * clients) : vérifie au niveau du code source que {shop_url} utilise bien
 * getBaseLink($this->idShop).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SegmentManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SegmentManager.php');

    neria_assert(
        strpos($src, 'getBaseLink($this->idShop)') !== false,
        "SegmentManager::sendToSegment() n'utilise plus \$this->idShop pour {shop_url} (getBaseLink()) — régression du bug corrigé le 07/08/2026 (round 102) : un client d'une boutique pourrait de nouveau recevoir un lien {shop_url} pointant vers le domaine d'une AUTRE boutique"
    );
    neria_assert(
        strpos($src, "getPageLink('history', true, \$idLang, null, false, \$this->idShop)") !== false,
        "SegmentManager::sendToSegment() n'utilise plus \$this->idShop pour {history_url} — régression déjà couverte par un garde-fou existant, revérifiée ici"
    );

    return [
        'pass'    => true,
        'message' => "SegmentManager::sendToSegment() résout bien {shop_url} ET {history_url} via \$this->idShop, pas le contexte d'exécution courant",
    ];
}
