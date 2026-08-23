<?php
/**
 * Régression : BehavioralCronManager::send() résolvait {shop_name} via
 * Configuration::get('PS_SHOP_NAME') SANS $idShop, alors que cette même
 * méthode résout déjà $idShop correctement pour historyUrl()/Mail::Send()
 * juste à côté, et que QueueManager::processSingle() le fait pour cette
 * MÊME variable dans le même flux d'envoi (email comportemental en file,
 * envoyé à l'heure préférée du client).
 *
 * Bug réel identifié le 23/08/2026 (round 187) : sur une install
 * multi-boutiques avec un PS_SHOP_NAME distinct par boutique, tout email
 * envoyé directement par send() (pas via la file) affichait le nom de la
 * boutique du contexte Configuration COURANT au moment de l'exécution du
 * cron — pas forcément celle du client réellement destinataire.
 *
 * Corrigé le 23/08/2026 (round 187) : Configuration::get('PS_SHOP_NAME', null,
 * null, $idShop).
 *
 * Test structurel (send() est privée, appelée en interne par ~15 méthodes
 * d'envoi comportemental différentes — un test comportemental complet
 * nécessiterait de simuler tout le flux d'un cron, hors de portée d'un test
 * isolé) : vérifie par lecture directe du source que la résolution de
 * {shop_name} dans send() passe bien $idShop à Configuration::get().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    $posSend = strpos($src, 'private function send(');
    neria_assert($posSend !== false, 'BehavioralCronManager::send() introuvable — jeu de test invalide');

    $posShopName = strpos($src, "'{shop_name}'", $posSend);
    neria_assert($posShopName !== false, "'{shop_name}' introuvable dans send() — jeu de test invalide");

    $line = substr($src, $posShopName, 120);
    neria_assert(
        strpos($line, "Configuration::get('PS_SHOP_NAME', null, null, \$idShop)") !== false,
        "BehavioralCronManager::send() résout de nouveau {shop_name} sans passer \$idShop à Configuration::get() — régression du bug corrigé le 23/08/2026 (round 187) : un email comportemental multi-boutique afficherait de nouveau le nom de la mauvaise boutique"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::send() résout {shop_name} avec le bon \$idShop — bug corrigé le 23/08/2026 (round 187)",
    ];
}
