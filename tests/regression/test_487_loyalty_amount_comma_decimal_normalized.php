<?php
/**
 * Régression round 252 (31/08/2026) : l'action BO `save_loyalty_tiers`
 * (neria.php) castait `loyalty_amount_{bronze,silver,gold}` directement en
 * `(float)` sans nettoyer une éventuelle virgule décimale (format
 * français), contrairement aux 3 autres montants BO du même groupe
 * fonctionnel (`neria_voucher_fixed_cap`, `birthday_voucher_amount`,
 * `milestone_voucher_amount`), qui appliquent tous
 * `str_replace(',', '.', ...)` avant le cast avec un commentaire dédié
 * expliquant le risque de faute de saisie.
 *
 * `(float) "12,50"` retourne `12.0` en PHP (tronqué à la virgule), pas
 * `12.5` — un marchand saisissant un montant au format français (via une
 * requête POST directe, un script d'automatisation, ou un proxy/extension
 * réinjectant une virgule malgré le `type="number"` HTML) verrait son
 * palier de fidélité enregistré à un montant erroné, silencieusement, sans
 * aucune erreur visible.
 *
 * Corrigé le 31/08/2026 (round 252) : str_replace(',', '.', ...) ajouté
 * avant le cast, par cohérence avec les 3 champs sœurs.
 *
 * Test réel (partie A) : démontre sur les vraies valeurs PHP que
 * `(float) "12,50"` tronque à 12.0 (le défaut visé) alors que
 * `(float) str_replace(',', '.', "12,50")` donne bien 12.5 (le correctif).
 *
 * Test structurel (partie B) : vérifie la présence exacte du correctif
 * dans neria.php.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Partie A : démonstration réelle du défaut et du correctif ──
    $frenchInput = '12,50';

    neria_assert(
        (float) $frenchInput === 12.0,
        "jeu de test invalide : (float) '12,50' ne tronque plus à 12.0 sur cette version de PHP — le scénario de démonstration ne reproduit plus le défaut visé par le round 252"
    );
    neria_assert(
        (float) str_replace(',', '.', $frenchInput) === 12.5,
        "str_replace(',', '.', ...) avant le cast (float) ne produit pas la valeur attendue 12.5 — comportement inattendu"
    );

    // ── Partie B : vérification structurelle du correctif ──
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');
    $src = str_replace("\r", '', $src);

    neria_assert(
        strpos($src, "max(0.01, (float) str_replace(',', '.', (string) Tools::getValue('loyalty_amount_' . \$k, 5)))") !== false,
        "save_loyalty_tiers ne nettoie plus la virgule décimale de loyalty_amount_* avant le cast (float) — régression du bug corrigé le 31/08/2026 (round 252) : une saisie au format français serait de nouveau tronquée à la virgule, enregistrant silencieusement un palier de fidélité à un montant erroné"
    );

    return [
        'pass'    => true,
        'message' => "save_loyalty_tiers nettoie bien la virgule décimale de loyalty_amount_* avant le cast (float), par cohérence avec les 3 champs de montant BO sœurs — démontré que (float) '12,50' tronquerait sinon à 12.0 au lieu de 12.5 — bug corrigé le 31/08/2026 (round 252)",
    ];
}
