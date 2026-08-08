<?php
/**
 * Régression : PreferencesManager::getPreferencesUrl() doit accepter un
 * $idShop et l'utiliser pour résoudre Link::getBaseLink($idShop), au lieu de
 * Context::getContext()->link->getBaseLink() (contexte du process qui ENVOIE
 * l'email — cron/admin BO — pas celui de la boutique du CLIENT destinataire).
 *
 * Bug réel corrigé le 08/08/2026 (round 110) : getPreferencesUrl() n'avait
 * pas de paramètre $idShop du tout, contrairement aux autres méthodes de
 * cette même classe (isAllowed()/setPreferences()) qui scopent déjà leurs
 * requêtes par boutique. EmailRenderer::resolveCustomerId() calculait
 * pourtant l'id_shop correct du destinataire (un commentaire mentionnait
 * explicitement getPreferencesUrl() comme concerné) mais ne pouvait pas le
 * transmettre faute de paramètre. Sur une installation multi-boutique, le
 * lien "Gérer mes préférences" d'un email envoyé à un client de la boutique
 * B pointait vers le domaine de la boutique A (celle du contexte
 * d'exécution courant) — mauvais catalogue/branding, voire 404 si les
 * boutiques ont des domaines strictement séparés.
 *
 * Test structurel : vérifie la signature de getPreferencesUrl() (paramètre
 * $idShop), son usage dans getBaseLink($idShop ?: null), et que les deux
 * appels client (EmailRenderer, rendu normal + secours) transmettent bien
 * resolveShopId($params) plutôt que d'omettre le paramètre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $pmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php');
    neria_assert($pmSrc !== false, 'Impossible de lire src/PreferencesManager.php');

    neria_assert(
        strpos($pmSrc, 'function getPreferencesUrl(string $email, int $idCustomer, string $lang = \'fr\', int $idShop = 0)') !== false,
        "PreferencesManager::getPreferencesUrl() n'a plus de paramètre \$idShop — régression du bug corrigé le 08/08/2026 (round 110)"
    );
    neria_assert(
        strpos($pmSrc, 'getBaseLink($idShop ?: null)') !== false,
        "getPreferencesUrl() ne transmet plus \$idShop à Link::getBaseLink() — le lien retomberait de nouveau sur le contexte d'exécution courant plutôt que la boutique du client"
    );

    $erSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
    neria_assert($erSrc !== false, 'Impossible de lire src/EmailRenderer.php');

    neria_assert(
        strpos($erSrc, 'private function resolveShopId(array $params): int') !== false,
        "EmailRenderer::resolveShopId() introuvable — helper de résolution de l'id_shop du destinataire manquant"
    );

    $countCalls = substr_count($erSrc, 'getPreferencesUrl(');
    $countScoped = substr_count($erSrc, '$this->resolveShopId($params)');
    neria_assert(
        $countScoped >= 2,
        "Moins de 2 appels à getPreferencesUrl() transmettent resolveShopId(\$params) — régression du bug corrigé le 08/08/2026 (round 110) : au moins le rendu normal ET le rendu de secours doivent scoper {preferences_url} par la boutique du client"
    );

    return [
        'pass'    => true,
        'message' => "PreferencesManager::getPreferencesUrl() résout bien {preferences_url} via la boutique du client (\$idShop), pas celle du contexte d'exécution courant",
    ];
}
