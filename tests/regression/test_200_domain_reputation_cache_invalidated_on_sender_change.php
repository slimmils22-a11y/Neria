<?php
/**
 * Régression : changer l'expéditeur (neria.php, action save_senders) doit
 * invalider le cache de réputation domaine via
 * DomainReputationManager::invalidateCache().
 *
 * Bug réel corrigé le 09/08/2026 (round 144) : rien n'appelait jamais cette
 * invalidation quand le marchand changeait son expéditeur transactionnel.
 * Le tableau de bord continuait d'afficher jusqu'à 24h le score/grade de
 * l'ANCIEN domaine — au moment précis où le risque (nouveau domaine
 * fraîchement configuré, sans SPF/DKIM/DMARC en place) est le plus élevé.
 *
 * Test structurel assumé explicitement : Configuration::get() du cœur PS
 * ignore silencieusement tout idShop explicite quand
 * Shop::isFeatureActive() === false (installation mono-boutique, cas de
 * cet environnement de dev — même limite que test_188/round 142, confirmée
 * ici aussi en LECTURE, pas seulement en écriture) : impossible de faire
 * lire à getCachedReport() une ligne shop-scopée précise dans cet
 * environnement, même via un INSERT SQL brut préalable — Configuration::get()
 * retombe sur Shop::getContextShopID(true) (NULL en mono-boutique) avant
 * même de tenter la lecture shop-scopée. Vérifie donc que invalidateCache()
 * appelle bien deleteFromContext() sur les 2 clés de cache, et que
 * neria.php::save_senders l'appelle bien après avoir sauvegardé l'expéditeur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
    neria_assert($src !== false, 'Impossible de lire src/DomainReputationManager.php');

    $posMethod = strpos($src, 'public static function invalidateCache(int $idShop): void');
    neria_assert($posMethod !== false, "DomainReputationManager::invalidateCache() introuvable — régression du bug corrigé le 09/08/2026 (round 144)");

    $body = substr($src, $posMethod, 400);
    neria_assert(
        strpos($body, "\Configuration::deleteFromContext(self::CONFIG_LAST_CHECK, null, \$idShop);") !== false,
        "invalidateCache() n'efface plus CONFIG_LAST_CHECK — régression du bug corrigé le 09/08/2026 (round 144)"
    );
    neria_assert(
        strpos($body, "\Configuration::deleteFromContext(self::CONFIG_CACHE, null, \$idShop);") !== false,
        "invalidateCache() n'efface plus CONFIG_CACHE — régression du bug corrigé le 09/08/2026 (round 144)"
    );

    $mainSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    $posSave = strpos($mainSrc, "Tools::getValue('neria_action') === 'save_senders'");
    neria_assert($posSave !== false, "action save_senders introuvable dans neria.php — jeu de test invalide");
    $saveBody = substr($mainSrc, $posSave, 1900);
    neria_assert(
        strpos($saveBody, 'DomainReputationManager::invalidateCache((int) $this->context->shop->id)') !== false,
        "neria.php::save_senders n'appelle plus DomainReputationManager::invalidateCache() — régression du bug corrigé le 09/08/2026 (round 144) : le tableau de bord afficherait de nouveau jusqu'à 24h le score de l'ancien expéditeur après un changement"
    );

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::invalidateCache() efface bien les 2 clés de cache, et neria.php::save_senders l'appelle bien après un changement d'expéditeur",
    ];
}
