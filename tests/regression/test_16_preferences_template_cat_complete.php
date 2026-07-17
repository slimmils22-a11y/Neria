<?php
/** Régression : tous les templates réellement envoyés par BehavioralCronManager doivent être dans TEMPLATE_CAT. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    preg_match_all("/this->send\(\s*\n?\s*'([a-z_0-9]+)'/", $src, $m);
    $sentTemplates = array_unique($m[1]);

    neria_assert(count($sentTemplates) > 10, "extraction des templates envoyés a échoué (" . count($sentTemplates) . " trouvés)");

    $missing = [];
    foreach ($sentTemplates as $tpl) {
        if (!isset(PreferencesManager::TEMPLATE_CAT[$tpl])) {
            $missing[] = $tpl;
        }
    }

    neria_assert(
        empty($missing),
        "template(s) envoyé(s) par BehavioralCronManager mais absent(s) de TEMPLATE_CAT : " . implode(', ', $missing) . " — régression du bug order_shipped_delay corrigé le 17/07/2026 (commit bcb3bf7), les préférences ne s'appliqueraient plus à ces templates"
    );

    return ['pass' => true, 'message' => 'TEMPLATE_CAT couvre toujours tous les templates réellement envoyés (' . count($sentTemplates) . ' vérifiés)'];
}
