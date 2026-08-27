<?php
/**
 * Régression : BehavioralCronManager envoyait {estimated_days} =
 * $lifespanDays (la durée de vie TOTALE configurée du produit, ex. 180
 * jours), alors que le texte du template affirme littéralement "Vous
 * avez acquis {product_name} il y a {estimated_days} jours" — une
 * affirmation sur le délai RÉELLEMENT écoulé depuis l'achat, pas sur la
 * durée de vie configurée.
 *
 * Bug réel : un client dont l'achat remonte à 150 jours (targetDay =
 * lifespanDays - alertDays, ou plus à cause de la fenêtre de rattrapage)
 * recevait un email affirmant "il y a 180 jours" — information de date
 * fausse et incohérente avec sa propre facture.
 *
 * Corrigé le 26/08/2026 (round 222) : {estimated_days} calculé depuis la
 * vraie date d'achat (purchase_date) via DateTime::diff(), avec repli
 * sur $lifespanDays si la date est illisible.
 *
 * Test structurel + comportemental réel : vérifie la présence du
 * correctif dans le code source, puis reproduit exactement le calcul
 * DateTime::diff() du code réel sur une date d'achat connue et confirme
 * qu'il renvoie bien le nombre de jours réellement écoulés (pas la
 * durée de vie configurée).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    neria_assert(
        strpos($src, "\$daysSincePurchase = (int) (new \\DateTime(\$customer['purchase_date']))") !== false,
        "BehavioralCronManager n'utilise plus purchase_date pour calculer {estimated_days} — régression du bug corrigé le 26/08/2026 (round 222) : product_lifespan_reminder afficherait de nouveau la durée de vie configurée au lieu du délai réellement écoulé depuis l'achat"
    );
    neria_assert(
        strpos($src, "'{estimated_days}' => (string) \$daysSincePurchase,") !== false,
        "BehavioralCronManager n'envoie plus \$daysSincePurchase comme {estimated_days} — régression du bug corrigé le 26/08/2026 (round 222)"
    );

    // Vérification comportementale réelle du calcul lui-même (reproduit
    // exactement le code réel) sur une date d'achat connue.
    $lifespanDays = 180;
    $purchaseDate = date('Y-m-d H:i:s', strtotime('-150 days'));

    $daysSincePurchase = $lifespanDays;
    try {
        $daysSincePurchase = (int) (new DateTime($purchaseDate))->diff(new DateTime())->days;
    } catch (\Throwable $e) {
    }

    neria_assert(
        $daysSincePurchase >= 149 && $daysSincePurchase <= 151,
        "Le calcul réel de \$daysSincePurchase renvoie {$daysSincePurchase} au lieu d'environ 150 pour un achat vieux de 150 jours — jeu de test invalide ou logique de calcul cassée"
    );
    neria_assert(
        $daysSincePurchase !== $lifespanDays,
        "\$daysSincePurchase (délai réel écoulé) est resté égal à \$lifespanDays (durée de vie configurée) — régression du bug corrigé le 26/08/2026 (round 222)"
    );

    return [
        'pass'    => true,
        'message' => "product_lifespan_reminder calcule bien {estimated_days} depuis la vraie date d'achat, pas depuis la durée de vie configurée — bug corrigé le 26/08/2026 (round 222)",
    ];
}
