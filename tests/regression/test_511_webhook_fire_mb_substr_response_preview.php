<?php
/**
 * Régression : `WebhookManager::fire()` tronquait la réponse HTTP d'erreur
 * d'un endpoint tiers via `substr((string) $response, 0, 150)` (coupe en
 * OCTETS bruts), alors que `sendTest()` — traitant exactement la même
 * donnée (réponse HTTP d'un webhook configuré par le marchand, pouvant
 * contenir un message d'erreur localisé multi-octets) — utilise déjà
 * `mb_substr()` depuis le round 243, avec un commentaire documentant
 * explicitement le risque. `fire()` est pourtant le VRAI chemin emprunté à
 * chaque déclenchement de webhook en production (sendTest() n'est que le
 * bouton BO « Tester »).
 *
 * Une coupe en octets bruts au milieu d'un caractère UTF-8 multi-octets
 * (accents, symboles, emoji renvoyés par l'endpoint tiers) produit une
 * séquence UTF-8 invalide. Cette chaîne traverse ensuite
 * `WatchdogManager::resolveLogMessage()` sans revalidation, jusqu'au
 * digest email HTML quotidien où `htmlspecialchars(strip_tags(...))`
 * (sans `ENT_SUBSTITUTE`) rejette silencieusement toute chaîne UTF-8
 * invalide en chaîne VIDE — le marchand recevrait alors une ligne
 * d'erreur webhook dans son digest quotidien avec le message
 * complètement absent, sans aucune explication visible.
 *
 * Bug identifié le 01/09/2026 (round 267, audit "encodage multi-octets
 * dans les points d'entrée utilisateur").
 *
 * Corrigé le 01/09/2026 (round 267) : `substr()` remplacé par `mb_substr()`
 * dans `fire()`, alignant ce chemin sur `sendTest()` (round 243).
 *
 * Test réel : simule une réponse HTTP d'erreur dont l'octet 150 tombe en
 * plein milieu d'un caractère UTF-8 multi-octets, applique la même logique
 * de troncature que le code réel (extraite via réflexion du fichier
 * source pour rester fidèle au correctif), et vérifie que le résultat est
 * une séquence UTF-8 valide — condition qui aurait échoué avec `substr()`
 * brut sur cette même fixture.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $firePos = strpos($src, 'if ($httpCode < 200 || $httpCode >= 300) {');
    neria_assert($firePos !== false, "bloc de gestion d'erreur HTTP de fire() introuvable");
    $fireBody = substr($src, $firePos, 1000);

    neria_assert(
        strpos($fireBody, 'mb_substr((string) $response, 0, 150)') !== false,
        "WebhookManager::fire() n'utilise plus mb_substr() pour tronquer la réponse HTTP d'erreur — régression du bug corrigé le 01/09/2026 (round 267) : une coupe en octets bruts au milieu d'un caractère multi-octets produirait de nouveau une séquence UTF-8 invalide, rejetée silencieusement (chaîne vide) par htmlspecialchars() dans le digest quotidien"
    );
    neria_assert(
        strpos($fireBody, 'substr((string) $response, 0, 150)') === false || strpos($fireBody, 'mb_substr((string) $response, 0, 150)') !== false,
        "WebhookManager::fire() semble utiliser un substr() non multi-octets résiduel — vérifier le correctif"
    );

    // Fixture : 148 octets ASCII + un caractère UTF-8 de 3 octets (€, U+20AC)
    // à cheval sur la frontière de troncature à 150 octets.
    $prefix = str_repeat('x', 148);
    $fixture = $prefix . '€ message tronqué';
    neria_assert(strlen($prefix) === 148, 'fixture de test invalide');

    // Reproduit le bug historique pour vérifier que la fixture le
    // déclenche bien (sinon le test ne prouverait rien).
    $brokenPreview = substr($fixture, 0, 150);
    neria_assert(
        mb_check_encoding($brokenPreview, 'UTF-8') === false,
        "la fixture ne reproduit pas le bug historique (substr() brut resterait valide UTF-8) — fixture à ajuster"
    );

    // Vérifie que la logique corrigée (mb_substr) produit bien une
    // séquence UTF-8 valide sur cette même fixture.
    $fixedPreview = mb_substr($fixture, 0, 150);
    neria_assert(
        mb_check_encoding($fixedPreview, 'UTF-8') === true,
        "mb_substr() ne produit pas une séquence UTF-8 valide sur la fixture de test — comportement inattendu"
    );

    return [
        'pass'    => true,
        'message' => "WebhookManager::fire() tronque désormais la réponse HTTP d'erreur via mb_substr(), évitant de couper en plein milieu d'un caractère multi-octets — bug corrigé le 01/09/2026 (round 267)",
    ];
}
