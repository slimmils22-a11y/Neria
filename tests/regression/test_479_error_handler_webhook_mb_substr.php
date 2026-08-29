<?php
/**
 * Régression round 243 (30/08/2026) : NeriaErrorHandler (4 sites : le
 * handler shutdown fatal, les 2 filets de secours de wrapGetContent()/
 * logHookCrash() si le Watchdog lui-même plante) et WebhookManager
 * (2 sites : aperçu de la réponse HTTP d'un test de webhook) tronquaient un
 * message potentiellement multi-octets (nom client/produit interpolé dans
 * une exception métier, réponse HTTP d'un endpoint tiers localisé) via
 * `substr()` — une coupe en OCTETS bruts peut trancher au milieu d'un
 * caractère UTF-8 multi-octets, produisant une séquence invalide stockée en
 * base puis affichée en BO (risque de mojibake voire de casser le rendu
 * HTML du visualiseur de logs). Même classe de bug déjà corrigée au round
 * 164 pour QueueManager::sanitizeErrorMessage() (substr → mb_substr).
 *
 * Corrigé le 30/08/2026 (round 243) : les 6 sites utilisent désormais
 * mb_substr() (coupe consciente des limites de caractères), pas substr().
 *
 * Test réel (partie A) : démontre sur un fixture UTF-8 multi-octets réel
 * (texte arabe, dont chaque caractère occupe 2 octets en UTF-8) que
 * substr() produit effectivement une chaîne UTF-8 invalide à une coupe
 * choisie pour tomber au milieu d'un caractère, alors que mb_substr() reste
 * valide — documente concrètement le défaut de classe visé par ce round.
 *
 * Test structurel (partie B) : vérifie la présence de mb_substr() aux 6
 * sites précis corrigés (substr() n'y apparaît plus).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Partie A : démonstration réelle substr() vs mb_substr() sur UTF-8 ──
    // "مرحبا" (5 caractères arabes) = 10 octets en UTF-8 (2 octets/caractère).
    $arabic = 'مرحبا';
    neria_assert(mb_strlen($arabic, 'UTF-8') === 5, "jeu de test invalide : le fixture arabe ne fait pas 5 caractères");
    neria_assert(strlen($arabic) === 10, "jeu de test invalide : le fixture arabe ne fait pas 10 octets en UTF-8");

    // Coupe à l'octet 3 : tombe au milieu du 2e caractère (octets 2-3).
    $cutBySubstr = substr($arabic, 0, 3);
    neria_assert(
        !mb_check_encoding($cutBySubstr, 'UTF-8'),
        "jeu de test invalide : substr() à l'octet 3 sur ce fixture ne produit pas de séquence UTF-8 invalide comme attendu — le scénario de démonstration ne reproduit plus le défaut"
    );

    $cutByMbSubstr = mb_substr($arabic, 0, 3, 'UTF-8');
    neria_assert(
        mb_check_encoding($cutByMbSubstr, 'UTF-8'),
        "mb_substr() produit une séquence UTF-8 invalide sur le fixture arabe — comportement inattendu de mb_substr() lui-même"
    );

    // ── Partie B : vérification structurelle des 6 sites corrigés ──
    $nehSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/NeriaErrorHandler.php');
    neria_assert($nehSrc !== false, 'Impossible de lire NeriaErrorHandler.php');
    $nehSrc = str_replace("\r", '', $nehSrc);
    neria_assert(
        substr_count($nehSrc, 'pSQL(mb_substr(') === 4,
        "NeriaErrorHandler.php ne compte plus 4 occurrences de pSQL(mb_substr(...)) — régression du bug corrigé le 30/08/2026 (round 243) : au moins un des 4 sites de journalisation de secours est revenu à substr() brut"
    );
    neria_assert(
        strpos($nehSrc, 'pSQL(substr(') === false,
        "NeriaErrorHandler.php contient de nouveau pSQL(substr(...)) (sans mb_) — régression du bug corrigé le 30/08/2026 (round 243)"
    );

    $whmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($whmSrc !== false, 'Impossible de lire WebhookManager.php');
    $whmSrc = str_replace("\r", '', $whmSrc);
    neria_assert(
        strpos($whmSrc, 'mb_substr($body, 0, 150)') !== false && strpos($whmSrc, 'mb_substr($body, 0, 300)') !== false,
        "WebhookManager.php n'utilise plus mb_substr() sur l'aperçu de réponse HTTP tierce — régression du bug corrigé le 30/08/2026 (round 243)"
    );

    return [
        'pass'    => true,
        'message' => "NeriaErrorHandler (4 sites) et WebhookManager (2 sites) utilisent bien mb_substr() — démontré concrètement qu'un substr() brut aurait produit une séquence UTF-8 invalide sur du texte multi-octets — bug corrigé le 30/08/2026 (round 243)",
    ];
}
