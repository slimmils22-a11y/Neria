<?php
/**
 * Régression : ClvManager::getTopCustomers() doit protéger ses 2 divisions
 * par o.conversion_rate contre une valeur 0 (IF(conversion_rate = 0, 1, ...)),
 * comme le fait déjà computeClv() en PHP et l'agrégat de remboursements de
 * cette même méthode.
 *
 * Bug réel corrigé le 06/08/2026 (round 59) : sans ce garde-fou, une seule
 * commande à conversion_rate=0 (donnée legacy/import) chez un client rend
 * le SUM() de TOUTES ses commandes NULL en SQL (division par zéro) —
 * classé en dernier dans le pré-tri ORDER BY (risque d'exclusion du pool
 * des 200 candidats) et total_revenue écrasé à 0 dans le calcul final du
 * Top 20 CLV, alors que la fiche client individuelle (getCustomerClv(),
 * protégée en PHP) reste correcte pour ce même client — incohérence directe
 * entre les deux vues.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ClvManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ClvManager.php');

    neria_assert(
        strpos($src, "ORDER BY SUM(o.`total_paid_tax_incl` / IF(o.`conversion_rate` = 0, 1, o.`conversion_rate`)) DESC") !== false,
        "getTopCustomers() ne protège plus contre conversion_rate=0 dans son ORDER BY de pré-sélection — régression du bug corrigé le 06/08/2026 (round 59) : un client à forte valeur avec une commande à conversion_rate=0 pourrait de nouveau être exclu du pool des 200 candidats"
    );

    neria_assert(
        strpos($src, "SUM(o.`total_paid_tax_incl` / IF(o.`conversion_rate` = 0, 1, o.`conversion_rate`)) AS total_revenue") !== false,
        "getTopCustomers() ne protège plus contre conversion_rate=0 dans son agrégat total_revenue — régression du bug corrigé le 06/08/2026 (round 59) : le CA d'un client avec une commande à conversion_rate=0 serait de nouveau écrasé à 0 (NULL en SQL) dans le Top 20 CLV"
    );

    return ['pass' => true, 'message' => "getTopCustomers() reste protégé contre conversion_rate=0 dans ses 2 divisions SQL (ORDER BY + total_revenue)"];
}
