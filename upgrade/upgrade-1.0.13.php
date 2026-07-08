<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Upgrade 1.0.12 → 1.0.13
 * Visibilité boutique : PageSpeed Insights + Google Search Console + SEO API payante.
 * Pas de nouvelles tables SQL (tout en Configuration).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_13(Neria $module): bool
{
    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);
    return true;
}
