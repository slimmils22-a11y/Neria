<?php
/**
 * NERIA — Mise à niveau vers la version 1.0.5
 *
 * Ajoute le template « Anniversaire de la relation client » :
 *   - relationship_anniversary (mails + traductions)
 *   Aucune nouvelle table — déduplication via ps_neria_behavioral_sent (ref_id = année)
 *
 * @author Neria
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_5($module)
{
    // Importer les nouvelles clés de traduction
    if (method_exists($module, 'importTranslations')) {
        $module->importTranslations();
    }

    return true;
}
