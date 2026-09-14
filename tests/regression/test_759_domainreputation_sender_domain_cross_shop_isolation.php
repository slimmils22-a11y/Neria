<?php
/**
 * Régression : DomainReputationManager::getSenderDomain() — le repli n°3
 * (`\Tools::getShopDomainSsl()`) délègue à `ShopUrl::getMainShopDomainSSL()`
 * SANS jamais transmettre l'id_shop réel — cette API du cœur PrestaShop met
 * en cache le domaine résolu dans un cache STATIQUE de process, indexé par
 * `(int) $id_shop`, et comme aucun id_shop n'est jamais passé, la clé est
 * TOUJOURS `(int) null === 0`, quelle que soit la boutique réellement
 * ambiante. Dans la boucle multi-boutique de neria.php (un seul process
 * PHP), la première boutique sans sender Neria ni PS_SHOP_EMAIL configuré
 * remplissait ce cache clé 0 avec SON domaine ; toute boutique suivante
 * dans la MÊME boucle, elle aussi sans sender/PS_SHOP_EMAIL, recevait ce
 * même domaine — fuite cross-boutique dans le rapport SPF/DKIM/DMARC/RBL,
 * exactement le défaut que le round 193 (mêmes replis n°1/n°2) visait à
 * éliminer, réintroduit par une API du cœur que ce fichier ne contrôle pas.
 *
 * Bug identifié le 14/09/2026 (round 358, audit dédié DomainReputationManager).
 *
 * Corrigé le 14/09/2026 : lecture SQL directe de `shop_url` scopée par
 * `$this->idShop` (WHERE id_shop = ... AND main = 1 AND active = 1), sans
 * jamais passer par le cache statique du cœur.
 *
 * Test comportemental réel : 2 boutiques fictives avec des domaines
 * DIFFÉRENTS dans shop_url, aucun sender Neria ni PS_SHOP_EMAIL configuré
 * pour forcer le repli n°3 — vérifie que getSenderDomain() résout bien LE
 * BON domaine pour chaque boutique, dans le MÊME process PHP (reproduisant
 * exactement la boucle multi-boutique de neria.php).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $idShopA = 999997101;
    $idShopB = 999997102;
    $domainA = 'regtest759a.invalid';
    $domainB = 'regtest759b.invalid';

    $originalShopEmail = (string) Configuration::get('PS_SHOP_EMAIL');

    $cleanup = function () use ($db, $prefix, $idShopA, $idShopB) {
        $db->execute("DELETE FROM {$prefix}shop_url WHERE id_shop IN ({$idShopA}, {$idShopB})");
        Configuration::deleteByName('NERIA_SENDERS_JSON');
    };

    $cleanup();

    try {
        // Neutralise temporairement les 2 premiers replis (PS_SHOP_EMAIL,
        // repli n°2) pour forcer getSenderDomain() à atteindre le repli
        // n°3 testé ici — restauré dans le finally.
        Configuration::updateGlobalValue('PS_SHOP_EMAIL', '');
        $db->execute("INSERT INTO {$prefix}shop_url (id_shop, domain, domain_ssl, physical_uri, virtual_uri, main, active)
                       VALUES ({$idShopA}, '" . pSQL($domainA) . "', '" . pSQL($domainA) . "', '/', '', 1, 1)");
        $db->execute("INSERT INTO {$prefix}shop_url (id_shop, domain, domain_ssl, physical_uri, virtual_uri, main, active)
                       VALUES ({$idShopB}, '" . pSQL($domainB) . "', '" . pSQL($domainB) . "', '/', '', 1, 1)");

        $module = neria_test_module();
        $mgrA = new DomainReputationManager($module);
        $mgrB = new DomainReputationManager($module);

        $refIdShop = new ReflectionProperty(DomainReputationManager::class, 'idShop');
        $refIdShop->setAccessible(true);
        $refIdShop->setValue($mgrA, $idShopA);
        $refIdShop->setValue($mgrB, $idShopB);

        $refMethod = new ReflectionMethod(DomainReputationManager::class, 'getSenderDomain');
        $refMethod->setAccessible(true);

        // Ordre A puis B — reproduit la boucle multi-boutique de neria.php
        // dans le MÊME process PHP.
        $resolvedA = $refMethod->invoke($mgrA);
        $resolvedB = $refMethod->invoke($mgrB);

        neria_assert(
            $resolvedA === $domainA,
            "getSenderDomain() pour la boutique A retourne '{$resolvedA}' au lieu de '{$domainA}' — jeu de test invalide ou régression"
        );
        neria_assert(
            $resolvedB === $domainB,
            "getSenderDomain() pour la boutique B (appelée APRÈS A, même process PHP) retourne '{$resolvedB}' au lieu de '{$domainB}' — régression du bug corrigé le 14/09/2026 (round 358) : le domaine de A fuiterait vers le rapport de réputation de B via le cache statique de ShopUrl::\$main_domain_ssl[0]"
        );

        return [
            'pass'    => true,
            'message' => "DomainReputationManager::getSenderDomain() résout bien le domaine RÉEL de chaque boutique (lecture SQL scopée), sans fuite cross-boutique via le cache statique du cœur — bug corrigé le 14/09/2026 (round 358)",
        ];
    } finally {
        Configuration::updateGlobalValue('PS_SHOP_EMAIL', $originalShopEmail);
        $cleanup();
    }
}
