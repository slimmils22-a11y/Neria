<?php
/**
 * Régression : WaitlistManager::notifyProductLocked() ne vérifiait jamais
 * le statut actif du produit avant d'envoyer l'email "de retour en
 * stock" — contrairement à LookCompletionManager::buildProductBlocks(),
 * qui vérifie bien `!$product->active`. Un produit désactivé/retiré du
 * catalogue entre l'inscription sur liste d'attente et le réassort
 * (ex. stock résiduel non nettoyé avant la désactivation) pouvait
 * recevoir une notification pointant vers une page produit indisponible.
 *
 * Corrigé le 18/08/2026 (round 184) : `!$product->active` ajouté à la
 * même condition que `!Validate::isLoadedObject($product)`.
 *
 * Test structurel (une fixture catalogue complète — produit réel
 * désactivable, stock, inscription liste d'attente, invocation complète
 * de notifyProduct() qui appelle NeriaTools::displayPrice()/
 * Tools::displayPrice() nécessitant un contexte HTTP complet, cf.
 * test_107 — serait lourde et fragile pour ce seul contrôle) : vérifie
 * la présence exacte de la condition dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WaitlistManager.php');

    $pos = strpos($src, '$product = new \Product($idProduct, false, $idLang, $idShop);');
    neria_assert($pos !== false, "Instanciation du produit introuvable — jeu de test invalide");
    $body = substr($src, $pos, 300);

    neria_assert(
        strpos($body, "if (!\Validate::isLoadedObject(\$product) || !\$product->active) continue;") !== false,
        "WaitlistManager::notifyProductLocked() ne vérifie plus \$product->active — régression du bug corrigé le 18/08/2026 (round 184) : un produit désactivé entre l'inscription et le réassort recevrait de nouveau une notification 'de retour en stock' vers une page indisponible"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager::notifyProductLocked() exclut bien les produits désactivés, cohérent avec LookCompletionManager — bug corrigé le 18/08/2026 (round 184)",
    ];
}
