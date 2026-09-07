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
 * Mis à jour le 07/09/2026 (round 314) : invalidateCache() n'utilise plus
 * Configuration::deleteFromContext() — le cœur PrestaShop fait retourner
 * cette méthode IMMÉDIATEMENT sans rien supprimer dès que
 * Shop::getContext() === Shop::CONTEXT_ALL (l'état naturel de cet
 * environnement de test CLI, confirmé par test_290), quel que soit le
 * $idShop explicite passé en argument — le correctif round 144 ne
 * fonctionnait donc en réalité JAMAIS dans ce contexte précis. Remplacé
 * par Configuration::updateValue($key, '', ...), qui écrit bien sur la
 * boutique explicitement demandée quel que soit le contexte BO ambiant
 * (voir test_606 pour le test comportemental dédié à ce correctif).
 *
 * Test structurel : vérifie que invalidateCache() appelle bien
 * updateValue() (pas deleteFromContext()) sur les 2 clés de cache, et que
 * neria.php::save_senders l'appelle bien après avoir sauvegardé
 * l'expéditeur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
    neria_assert($src !== false, 'Impossible de lire src/DomainReputationManager.php');

    $posMethod = strpos($src, 'public static function invalidateCache(int $idShop): void');
    neria_assert($posMethod !== false, "DomainReputationManager::invalidateCache() introuvable — régression du bug corrigé le 09/08/2026 (round 144)");

    $body = substr($src, $posMethod, 1800);
    neria_assert(
        strpos($body, "\Configuration::updateValue(self::CONFIG_LAST_CHECK, '', false, null, \$idShop);") !== false,
        "invalidateCache() n'efface plus CONFIG_LAST_CHECK via updateValue() — régression du bug corrigé le 07/09/2026 (round 314) : sous Shop::CONTEXT_ALL, deleteFromContext() ne ferait de nouveau RIEN"
    );
    neria_assert(
        strpos($body, "\Configuration::updateValue(self::CONFIG_CACHE, '', false, null, \$idShop);") !== false,
        "invalidateCache() n'efface plus CONFIG_CACHE via updateValue() — régression du bug corrigé le 07/09/2026 (round 314)"
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
