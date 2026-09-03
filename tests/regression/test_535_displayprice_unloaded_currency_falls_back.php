<?php
/**
 * Régression : `NeriaTools::displayPrice()` ne vérifiait jamais
 * `Validate::isLoadedObject($currency)` avant utilisation. Plusieurs
 * appelants instancient `new \Currency($idCurrency)` directement à
 * partir d'un `id_currency` STOCKÉ EN BASE (commande, devis B2B) —
 * `OrderTriggersManager::handleRefund()` (email refund_processed),
 * `BehavioralCronManager::sendQuoteEmail()` (relance de devis B2B) — un
 * id référençant une devise que le marchand a supprimée depuis produit
 * un objet Currency NON CHARGÉ (`iso_code`/`sign` vides). Le montant
 * affiché dans l'email perdait alors son symbole/code monétaire, ou
 * produisait un formatage cassé selon le chemin de formatage emprunté.
 *
 * Bug identifié le 02/09/2026 (round 285, audit "null-safety objets
 * cœur PrestaShop en scénarios limites").
 *
 * Corrigé le 02/09/2026 (round 285) : `displayPrice()` détecte
 * désormais un `$currency` non chargé et se replie sur la devise par
 * défaut de la boutique (`Currency::getDefaultCurrency()`) plutôt que
 * de propager une devise vide.
 *
 * Test comportemental réel : construit un objet Currency avec un ID
 * inexistant (jamais chargé depuis la base), appelle displayPrice() et
 * vérifie que le résultat contient bien un symbole/code monétaire
 * (celui de la devise par défaut), pas une chaîne de prix nue.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $idNonExistent = 9999000 + random_int(1, 999);
    $unloadedCurrency = new Currency($idNonExistent);
    neria_assert(
        !Validate::isLoadedObject($unloadedCurrency),
        "jeu de test invalide : l'id de devise {$idNonExistent} correspond à une devise réelle"
    );

    // Même technique que test_336 (round 173) : force un idLang EXPLICITE
    // différent du contexte pour emprunter le chemin formatPriceWithIntl()
    // plutôt que \Tools::displayPrice() natif, qui nécessite le kernel
    // Symfony indisponible hors bootstrap HTTP complet — sans rapport avec
    // le correctif testé ici (repli sur la devise par défaut).
    $context = Context::getContext();
    $originalLanguage = $context->language;
    $context->language = null;

    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
    try {
        $result = NeriaTools::displayPrice(42.5, $unloadedCurrency, $idLang);
    } finally {
        $context->language = $originalLanguage;
    }

    $defaultCurrency = Currency::getDefaultCurrency();
    neria_assert($defaultCurrency instanceof Currency && Validate::isLoadedObject($defaultCurrency), 'jeu de test invalide : pas de devise par défaut configurée');

    // NumberFormatter (formatPriceWithIntl) peut rendre le symbole OU le
    // code ISO selon la locale — les deux sont des marqueurs valides
    // prouvant que la devise par défaut a bien été utilisée.
    $matchesSign = $defaultCurrency->sign !== '' && strpos($result, $defaultCurrency->sign) !== false;
    $matchesIso  = $defaultCurrency->iso_code !== '' && strpos($result, $defaultCurrency->iso_code) !== false;
    neria_assert(
        $matchesSign || $matchesIso,
        "NeriaTools::displayPrice() avec une devise non chargée ne se replie plus sur la devise par défaut de la boutique (résultat='{$result}', attendu contenant le signe '{$defaultCurrency->sign}' ou le code '{$defaultCurrency->iso_code}') — régression du bug corrigé le 02/09/2026 (round 285) : un id_currency stocké en base référençant une devise supprimée redonnerait un prix sans symbole/code monétaire dans refund_processed/les relances de devis B2B"
    );

    return [
        'pass'    => true,
        'message' => "NeriaTools::displayPrice() se replie bien sur la devise par défaut quand \$currency n'est pas chargé (devise supprimée depuis) — bug corrigé le 02/09/2026 (round 285)",
    ];
}
