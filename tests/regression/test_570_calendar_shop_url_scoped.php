<?php
/**
 * Régression : CalendarManager::sendCalendarEmail() résolvait {shop_url}
 * via \Tools::getShopDomainSsl(true, true) — cette fonction dépend du
 * contexte d'exécution courant (Context::getContext()->shop), pas de
 * $this->idShop (la boutique réelle du destinataire, déjà utilisée juste
 * au-dessus pour {shop_name}/{shop_email}, et pour Mail::Send() plus bas
 * dans la même méthode).
 *
 * Même piège déjà corrigé ailleurs dans le module (SegmentManager/
 * LoyaltyManager/SeasonalCampaignManager/ManualSendManager) : {shop_url}
 * pointait vers le domaine de la boutique du CONTEXTE d'exécution courant,
 * pas celle du client réellement ciblé. checkAndSendDailyEvents() est
 * explicitement appelé dans une boucle multi-boutique (runBackgroundJobs()),
 * et ce fichier documente lui-même (rounds 114/159) ce même risque pour
 * d'autres champs — mais {shop_url} ne l'avait jamais reçu.
 *
 * Scénario concret : install multi-boutiques, cron déclenché avec
 * Context::getContext()->shop resté sur la Boutique A (simple
 * réassignation, jamais Shop::setContext(), cf. commentaire round 138
 * ailleurs dans ce module) ; un email calendaire (Noël, Fête des Mères...)
 * envoyé à un client de la Boutique B contient un lien {shop_url} pointant
 * vers le domaine de la Boutique A.
 *
 * Corrigé le 05/09/2026 (round 303) : \Context::getContext()->link->
 * getBaseLink($this->idShop), même pattern que SegmentManager::
 * sendToSegment().
 *
 * Test structurel (une fixture calendaire complète — événement actif,
 * client éligible, invocation réelle du cron journalier — serait lourde et
 * fragile pour ce seul champ, cf. contrainte similaire des tests
 * shop_url/shop_name jumeaux ailleurs dans ce module) : vérifie la
 * présence exacte du correctif et l'absence de l'ancien appel non scopé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CalendarManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CalendarManager.php');

    neria_assert(
        strpos($src, "\\Tools::getShopDomainSsl(true, true)") === false,
        "CalendarManager utilise encore \\Tools::getShopDomainSsl(true, true) pour {shop_url} — régression du bug corrigé le 05/09/2026 (round 303) : {shop_url} pointerait de nouveau vers le domaine du contexte d'exécution courant, pas celle de la boutique réelle du client"
    );
    neria_assert(
        strpos($src, "'{shop_url}'    => \\Context::getContext()->link->getBaseLink(\$this->idShop),") !== false,
        "CalendarManager::sendCalendarEmail() ne résout plus {shop_url} via getBaseLink(\$this->idShop) — régression du bug corrigé le 05/09/2026 (round 303)"
    );

    return [
        'pass'    => true,
        'message' => "CalendarManager::sendCalendarEmail() résout bien {shop_url} via getBaseLink(\$this->idShop) — la boutique réelle du client, pas celle du contexte d'exécution courant — bug corrigé le 05/09/2026 (round 303)",
    ];
}
