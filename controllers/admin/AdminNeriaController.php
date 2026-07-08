<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — AdminNeriaController
 *
 * Contrôleur BO minimal : redirige vers la page de configuration Neria.
 * Son seul rôle est d'exister pour que le tab PS soit valide,
 * puis de rediriger immédiatement vers getContent().
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminNeriaController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function init(): void
    {
        parent::init();

        \Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=neria'
        );
    }
}
