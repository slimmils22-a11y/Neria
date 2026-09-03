<?php
/**
 * Régression : `\Link::getImageLink()` (cœur PrestaShop) n'a aucun
 * paramètre pour forcer HTTPS — elle utilise systématiquement
 * `$this->protocol_content`, déterminé par l'état SSL de la REQUÊTE HTTP
 * courante (`Tools::usingSecureMode()`/`FrontController::$ssl`), PAS par
 * le réglage marchand `PS_SSL_ENABLED` lui-même. Un cron/webhook interne
 * déclenché via une URL `http://` simple (fréquent pour éviter la
 * négociation TLS en boucle locale) produisait alors une URL d'image
 * `http://` même sur une boutique qui force HTTPS pour ses vrais
 * visiteurs — Gmail/Outlook.com traitent ceci comme du contenu mixte et
 * peuvent bloquer l'image dans l'email (panier abandonné, liste
 * d'attente, complétez votre look/collection).
 *
 * `UpsellManager::getProductImageUrl()` contournait déjà ce piège en
 * construisant sa propre URL directe avec `PS_SSL_ENABLED` — mais ce
 * correctif n'avait jamais été répliqué aux 4 AUTRES managers utilisant
 * `getImageLink()` du cœur pour une image produit dans un email.
 *
 * Bug identifié le 03/09/2026 (round 291, audit "cohérence des URLs
 * d'images produit dans les emails").
 *
 * Corrigé le 03/09/2026 (round 291) : nouveau helper
 * `NeriaTools::forceHttpsIfEnabled()`, appliqué autour de l'appel
 * `getImageLink()` dans `CollectionManager::getProductThumbUrl()`,
 * `CollectionManager::getProductImageUrl()` (mode multi-boutique),
 * `LookCompletionManager::buildProductBlocks()`,
 * `WaitlistManager::notifyProduct()`, et
 * `BehavioralCronManager::sendGhostCarts()`.
 *
 * Test comportemental réel sur le helper (fonction pure, testable
 * isolément) + vérification structurelle que les 4 managers l'appliquent
 * bien autour de CHAQUE appel getImageLink() concerné.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $originalSsl = Configuration::get('PS_SSL_ENABLED');

    try {
        Configuration::updateValue('PS_SSL_ENABLED', 1);
        neria_assert(
            NeriaTools::forceHttpsIfEnabled('http://boutique.example/img/p/1/1-home.jpg') === 'https://boutique.example/img/p/1/1-home.jpg',
            "NeriaTools::forceHttpsIfEnabled() ne convertit plus une URL http:// en https:// quand PS_SSL_ENABLED=1 — régression du bug corrigé le 03/09/2026 (round 291)"
        );
        neria_assert(
            NeriaTools::forceHttpsIfEnabled('https://boutique.example/img/p/1/1-home.jpg') === 'https://boutique.example/img/p/1/1-home.jpg',
            "NeriaTools::forceHttpsIfEnabled() altère à tort une URL déjà en https://"
        );
        neria_assert(
            NeriaTools::forceHttpsIfEnabled('') === '',
            "NeriaTools::forceHttpsIfEnabled() ne doit rien produire à partir d'une chaîne vide (produit sans image)"
        );

        Configuration::updateValue('PS_SSL_ENABLED', 0);
        neria_assert(
            NeriaTools::forceHttpsIfEnabled('http://boutique.example/img/p/1/1-home.jpg') === 'http://boutique.example/img/p/1/1-home.jpg',
            "NeriaTools::forceHttpsIfEnabled() convertit à tort une URL http:// alors que PS_SSL_ENABLED=0 — la boutique ne force pas HTTPS, l'image http:// est légitime dans ce cas"
        );
    } finally {
        Configuration::updateValue('PS_SSL_ENABLED', (int) $originalSsl);
    }

    // Vérification structurelle : les 4 managers appliquent bien le helper
    // autour de CHAQUE appel getImageLink() concerné.
    $sites = [
        'src/CollectionManager.php' => 2,
        'src/LookCompletionManager.php' => 1,
        'src/WaitlistManager.php' => 1,
        'src/BehavioralCronManager.php' => 1,
    ];
    foreach ($sites as $file => $expectedCount) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/' . $file);
        neria_assert($src !== false, "Impossible de lire {$file}");
        $actualCount = substr_count($src, 'NeriaTools::forceHttpsIfEnabled(');
        neria_assert(
            $actualCount === $expectedCount,
            "{$file} applique forceHttpsIfEnabled() à {$actualCount} emplacement(s) au lieu de {$expectedCount} attendu(s) — régression du bug corrigé le 03/09/2026 (round 291) : une URL d'image produit redeviendrait exposée au contenu mixte HTTP dans un email envoyé depuis une boutique HTTPS"
        );
    }

    return [
        'pass'    => true,
        'message' => "NeriaTools::forceHttpsIfEnabled() force bien https:// sur les URLs d'image produit quand PS_SSL_ENABLED=1, appliqué dans les 4 managers concernés — bug corrigé le 03/09/2026 (round 291)",
    ];
}
