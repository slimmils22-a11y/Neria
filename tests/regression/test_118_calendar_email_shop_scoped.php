<?php
/**
 * Régression : CalendarManager::sendCalendarEmail() doit résoudre
 * {shop_name} et l'adresse d'expédition (Mail::Send from-email/from-name)
 * via Configuration::get('PS_SHOP_NAME'/'PS_SHOP_EMAIL', null, null,
 * $this->idShop), pas le contexte d'exécution ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 114) : neria.php boucle sur toutes
 * les boutiques actives et réassigne Context::getContext()->shop = new
 * \Shop($idShopCalendar) avant d'instancier CalendarManager — ce qui met
 * bien à jour $this->idShop (propriété d'objet lue depuis
 * Context::getContext()->shop->id au constructeur, donc correctement scopée,
 * comme le prouvent buildSentKey()/getEligibleCustomers() dans le même
 * fichier), mais PAS Shop::$context_id_shop, la variable statique interne
 * dont dépend Configuration::get() sans $idShop explicite (seul
 * Shop::setContext() la modifie). Même piège déjà corrigé pour PS_SHOP_NAME
 * dans 8 managers au round 106, puis dans MonthlyReportManager (round 111),
 * BehavioralCronManager (round 112), SeasonalCampaignManager (round 113).
 * Sur une install multi-boutiques, un client d'une boutique B recevait un
 * email calendaire (Noël, Nouvel An, etc.) affichant le nom de la boutique
 * ambiante réelle dans {shop_name} ET expédié depuis l'adresse from de
 * cette même mauvaise boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CalendarManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CalendarManager.php');

    neria_assert(
        strpos($src, "\\Configuration::get('PS_SHOP_NAME', null, null, \$this->idShop)") !== false,
        "CalendarManager::sendCalendarEmail() ne résout plus {shop_name} via \$this->idShop — régression du bug corrigé le 08/08/2026 (round 114)"
    );
    neria_assert(
        strpos($src, "\\Configuration::get('PS_SHOP_EMAIL', null, null, \$this->idShop)") !== false,
        "CalendarManager::sendCalendarEmail() ne résout plus l'adresse d'expédition via \$this->idShop — régression du bug corrigé le 08/08/2026 (round 114)"
    );

    return [
        'pass'    => true,
        'message' => "CalendarManager::sendCalendarEmail() résout bien {shop_name}/{shop_email} via \$this->idShop, pas le contexte d'exécution ambiant",
    ];
}
