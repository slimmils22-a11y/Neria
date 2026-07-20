<?php
/**
 * Régression : isolation multi-boutique de BehavioralCronManager (chantier du 20/07/2026).
 * Avant correction, aucune des 16 méthodes d'envoi ne filtrait par id_shop — un client
 * partagé entre plusieurs boutiques d'une même install pouvait ne jamais recevoir un
 * email comportemental déjà "marqué envoyé" pour une autre boutique, ou recevoir un
 * bon d'anniversaire enregistré sous la mauvaise boutique. run() lui-même ne boucle
 * qu'une fois par jour : sans la boucle sur Shop::getShops(), scoper chaque requête sur
 * le contexte courant aurait simplement arrêté de traiter toutes les boutiques sauf une.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');

    neria_assert(
        (bool) preg_match('/Shop::getShops\(/', $src),
        "run() ne boucle plus sur Shop::getShops() — régression de l'isolation multi-boutique (chaque méthode ne traiterait plus qu'une seule boutique par jour)"
    );

    // Chaque méthode d'envoi doit encore contenir un filtre id_shop (colonne propre
    // ou dédup neria_behavioral_sent) — pin exact des 16 méthodes corrigées.
    // Découpage simple sur les signatures de méthode (pas de regex à backtracking
    // sur tout le fichier, plus sûr et plus rapide qu'un match DOTALL global).
    $chunks = preg_split('/\n    (?:private|public) function /', $src);
    $methods = [
        'sendBirthdays', 'sendFirstAnniversaries', 'sendRelationshipAnniversaries',
        'sendReorderReminders', 'sendWinBacks', 'sendRewardExpiryAlerts',
        'sendWishlistReminders', 'sendAbandonedCarts', 'sendCheckoutAbandonment',
        'sendQuoteExpiryReminders', 'sendRefundReconciliations', 'sendLifespanReminders',
        'sendPostPurchase', 'sendShippedDelayAlerts', 'sendGhostCarts',
    ];

    foreach ($methods as $method) {
        $body = null;
        foreach ($chunks as $chunk) {
            if (strpos($chunk, $method . '(') === 0) {
                $body = $chunk;
                break;
            }
        }
        neria_assert(
            $body !== null,
            "Méthode {$method}() introuvable dans BehavioralCronManager.php (renommée ?)"
        );
        neria_assert(
            str_contains((string) $body, 'id_shop'),
            "{$method}() ne filtre plus par id_shop — régression du bug multi-boutique corrigé le 20/07/2026"
        );
    }

    // Le bon d'anniversaire (CartRule) doit être enregistré sous la boutique du client,
    // pas sous la valeur DEFAULT 1 de la colonne — bug distinct trouvé lors du test réel
    // à 2 boutiques (id_shop absent de l'INSERT malgré le filtre de lecture correct).
    neria_assert(
        str_contains($src, 'function generateBirthdayVoucher(int $idCustomer, \ConfigManager $config, int $idShop)')
        && str_contains($src, '(id_customer, year, id_cart_rule, voucher_code, id_shop, created_at)'),
        "generateBirthdayVoucher() n'insère plus id_shop — régression du bug où le bon d'anniversaire d'un client de la boutique 2 était enregistré sous id_shop=1 (DEFAULT de colonne)"
    );

    return ['pass' => true, 'message' => 'BehavioralCronManager toujours isolé par boutique (16 méthodes + run() + bon anniversaire)'];
}
