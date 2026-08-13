<?php
/**
 * Régression : navigation.tpl affichait {$ls.expires_at} (date d'expiration
 * de licence, provenant du serveur de licence distant neriasoftware.com/admin)
 * sans |escape:'html', incohérent avec le reste du fichier qui échappe
 * systématiquement ses variables dynamiques. Une compromission ou un MITM
 * sur ce service tiers pourrait injecter du HTML dans cette bannière BO.
 *
 * Corrigé le 13/08/2026 (round 164) : |escape:'html' ajouté.
 *
 * Test structurel : vérifie la présence de l'échappement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $tpl = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/navigation.tpl');
    neria_assert($tpl !== false, 'Impossible de lire navigation.tpl');

    neria_assert(
        strpos($tpl, "{\$ls.expires_at}") === false,
        "navigation.tpl affiche de nouveau {\$ls.expires_at} sans échappement — régression du bug corrigé le 13/08/2026 (round 164)"
    );
    neria_assert(
        strpos($tpl, "{\$ls.expires_at|escape:'html'}") !== false,
        "navigation.tpl n'échappe plus \$ls.expires_at — régression du bug corrigé le 13/08/2026 (round 164) : une donnée du serveur de licence distant redeviendrait injectable dans la bannière BO"
    );

    return [
        'pass'    => true,
        'message' => "navigation.tpl échappe bien \$ls.expires_at — bug corrigé le 13/08/2026 (round 164)",
    ];
}
