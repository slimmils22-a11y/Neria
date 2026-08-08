<?php
/**
 * Régression : generateMilestoneVoucher() doit résoudre
 * minimum_amount_currency via Configuration::get('PS_CURRENCY_DEFAULT', ...,
 * $idShop), comme reduction_currency juste en dessous — pas via le
 * contexte statique ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 133) : incohérence mineure trouvée
 * en repassant sur generateMilestoneVoucher() — minimum_amount_currency
 * lisait PS_CURRENCY_DEFAULT sans idShop explicite, alors que
 * reduction_currency (même méthode, quelques lignes plus bas) le faisait
 * déjà correctement. Impact nul aujourd'hui (minimum_amount = 0, jamais
 * exploité), mais latent si ce champ venait à être utilisé.
 *
 * Test structurel : vérifie que les deux résolutions de devise dans
 * generateMilestoneVoucher() passent par le 4e argument $idShop explicite.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    $posMethod = strpos($src, 'private function generateMilestoneVoucher(');
    neria_assert($posMethod !== false, 'generateMilestoneVoucher() introuvable — régression du bug corrigé le 08/08/2026');

    $body = substr($src, $posMethod, 2600);

    neria_assert(
        strpos($body, "\$cartRule->minimum_amount_currency = (int) \\Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$idShop);") !== false,
        "generateMilestoneVoucher() ne résout plus minimum_amount_currency avec l'idShop explicite — régression du bug corrigé le 08/08/2026 (round 133)"
    );
    neria_assert(
        strpos($body, "\$cartRule->reduction_currency = (int) \\Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$idShop);") !== false,
        "generateMilestoneVoucher() ne résout plus reduction_currency avec l'idShop explicite — régression d'un correctif antérieur"
    );

    return [
        'pass'    => true,
        'message' => "generateMilestoneVoucher() résout bien minimum_amount_currency ET reduction_currency via l'idShop explicite de la commande, cohérent partout",
    ];
}
