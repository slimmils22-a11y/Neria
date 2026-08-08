<?php
/**
 * Régression : WatchdogManager::sendImmediateAlert()/sendDailyDigest()/
 * getAlertEmail() doivent résoudre {shop_name}/PS_SHOP_EMAIL via
 * Configuration::get(..., null, null, $this->idShop), pas le contexte
 * d'exécution ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 115) : même piège que rounds
 * 111-114. WatchdogManager est instancié à la volée par des managers qui
 * tournent dans une boucle multi-boutique (WebhookManager, CalendarManager,
 * etc.) après réassignation de Context::getContext()->shop = new
 * \Shop($idShop) — ce qui met bien à jour $this->idShop (propriété d'objet
 * lue depuis Context::getContext()->shop->id au constructeur, comme le
 * prouvent les nombreuses requêtes SQL WHERE id_shop = $this->idShop dans
 * cette même classe), mais PAS Shop::$context_id_shop, dont dépend
 * Configuration::get() sans idShop explicite (seul Shop::setContext() la
 * modifie). Un marchand multi-boutiques recevait une alerte/digest Watchdog
 * (ex. "secret webhook illisible") dont le sujet et l'en-tête From
 * affichaient le nom/email de la boutique ambiante réelle (celle du visiteur
 * front ayant déclenché le hook), pas celle réellement en défaut —
 * confusion de diagnostic pour l'admin.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WatchdogManager.php');

    $needle = "null, null, \$this->idShop)";
    $countShopName  = substr_count($src, "\\Configuration::get('PS_SHOP_NAME', " . $needle);
    $countShopEmail = substr_count($src, "\\Configuration::get('PS_SHOP_EMAIL', " . $needle);

    neria_assert(
        $countShopName === 2,
        "WatchdogManager résout {shop_name} via \$this->idShop à seulement {$countShopName} emplacement(s) au lieu de 2 (sendImmediateAlert() + sendDailyDigest()) — régression du bug corrigé le 08/08/2026 (round 115)"
    );
    neria_assert(
        $countShopEmail === 3,
        "WatchdogManager résout PS_SHOP_EMAIL via \$this->idShop à seulement {$countShopEmail} emplacement(s) au lieu de 3 (sendImmediateAlert() + sendDailyDigest() + getAlertEmail()) — régression du bug corrigé le 08/08/2026 (round 115)"
    );

    return [
        'pass'    => true,
        'message' => "WatchdogManager résout bien {shop_name}/PS_SHOP_EMAIL via \$this->idShop dans ses 5 emplacements (sendImmediateAlert x2, sendDailyDigest x2, getAlertEmail x1), pas le contexte d'exécution ambiant",
    ];
}
