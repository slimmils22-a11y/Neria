<?php
/**
 * Régression : DomainReputationManager::invalidateCache() utilisait
 * Configuration::deleteFromContext() — le cœur PrestaShop
 * (classes/Configuration.php) fait retourner cette méthode IMMÉDIATEMENT
 * sans rien supprimer dès que Shop::getContext() === Shop::CONTEXT_ALL (le
 * sélecteur de boutique du BO positionné sur "Toutes les boutiques"), quel
 * que soit le $idShop explicite passé en argument — même piège déjà
 * rencontré et documenté aux rounds 290/300 sur ce même noyau PrestaShop
 * (cf. test_290). save_senders (neria.php) appelle pourtant toujours cette
 * méthode avec un $idShop concret : le cache de réputation domaine
 * (score/grade SPF/DKIM/DMARC/RBL) restait affiché jusqu'à 24h après un
 * changement d'expéditeur transactionnel dès que l'admin BO avait "Toutes
 * les boutiques" sélectionné au moment du changement.
 *
 * Corrigé le 07/09/2026 (round 314) : Configuration::updateValue($key, '',
 * ...) au lieu de deleteFromContext() — écrit bien sur la boutique
 * explicitement demandée quel que soit le contexte BO ambiant.
 *
 * Test comportemental réel : l'environnement CLI de test est naturellement
 * en Shop::CONTEXT_ALL (confirmé par test_290) — pas besoin de le forcer.
 * Pose un cache factice via updateValue() (seul chemin qui invalide
 * correctement le cache statique PrestaShop, cf. test_290), appelle
 * invalidateCache(), et vérifie que le cache est bien retombé à vide —
 * précisément le scénario où l'ancien code (deleteFromContext) ne faisait
 * RIEN.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $idShop = (int) Context::getContext()->shop->id;
    $originalCache     = Configuration::get(DomainReputationManager::CONFIG_CACHE, null, null, $idShop);
    $originalLastCheck = Configuration::get(DomainReputationManager::CONFIG_LAST_CHECK, null, null, $idShop);

    try {
        // Pose un cache factice "valide" — date_add récent + JSON non vide.
        Configuration::updateValue(DomainReputationManager::CONFIG_LAST_CHECK, (string) time(), false, null, $idShop);
        Configuration::updateValue(DomainReputationManager::CONFIG_CACHE, json_encode(['score' => 99]), false, null, $idShop);

        $lastCheckBefore = (int) Configuration::get(DomainReputationManager::CONFIG_LAST_CHECK, null, null, $idShop);
        neria_assert($lastCheckBefore > 0, 'Jeu de test invalide : le cache factice ne semble pas posé');

        DomainReputationManager::invalidateCache($idShop);

        $lastCheckAfter = (int) Configuration::get(DomainReputationManager::CONFIG_LAST_CHECK, null, null, $idShop);
        $cacheAfter     = (string) Configuration::get(DomainReputationManager::CONFIG_CACHE, null, null, $idShop);

        neria_assert(
            $lastCheckAfter === 0 && $cacheAfter === '',
            "DomainReputationManager::invalidateCache() n'a pas invalidé le cache (last_check={$lastCheckAfter}, cache=" . var_export($cacheAfter, true) . ") — régression du bug corrigé le 07/09/2026 (round 314) : deleteFromContext() ne fait RIEN sous Shop::getContext()===CONTEXT_ALL (l'état naturel de cet environnement de test CLI), le score/grade affiché resterait périmé jusqu'à 24h après un changement d'expéditeur"
        );
    } finally {
        if ($originalCache !== false && $originalCache !== '') {
            Configuration::updateValue(DomainReputationManager::CONFIG_CACHE, $originalCache, false, null, $idShop);
        } else {
            Configuration::updateValue(DomainReputationManager::CONFIG_CACHE, '', false, null, $idShop);
        }
        if ($originalLastCheck !== false && $originalLastCheck !== '') {
            Configuration::updateValue(DomainReputationManager::CONFIG_LAST_CHECK, $originalLastCheck, false, null, $idShop);
        } else {
            Configuration::updateValue(DomainReputationManager::CONFIG_LAST_CHECK, '', false, null, $idShop);
        }
    }

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::invalidateCache() invalide bien le cache via updateValue() même sous Shop::CONTEXT_ALL, où deleteFromContext() ne faisait auparavant rien — bug corrigé le 07/09/2026 (round 314)",
    ];
}
