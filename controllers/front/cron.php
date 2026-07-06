<?php
/**
 * NERIA — Front controller : déclencheur cron externe
 *
 * Point d'entrée destiné à être appelé par un vrai cron serveur
 * (crontab), pour que la surveillance et les tâches de fond de Neria
 * (queue d'envoi, crons comportementaux, rapports, digest Watchdog…)
 * tournent à heure fixe, indépendamment du trafic visiteurs.
 *
 * Le fallback existant (hookDisplayHeader, déclenché sur chaque page
 * front-office) continue de fonctionner sans configuration — ce
 * contrôleur est une amélioration recommandée, pas un prérequis.
 *
 * URL : /index.php?fc=module&module=neria&controller=cron&token=VOTRE_TOKEN
 * Le token est affiché dans Neria → Aide → section Diagnostic.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaCronModuleFrontController extends ModuleFrontController
{
    public $display_column_left  = false;
    public $display_column_right = false;
    public $ssl                  = true;

    public function initContent()
    {
        header('Content-Type: application/json; charset=utf-8');

        $token = (string) Tools::getValue('token', '');
        $expected = (string) Configuration::getGlobalValue('NERIA_CRON_TOKEN');

        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'invalid_token']);
            exit;
        }

        if (!Configuration::getGlobalValue('NERIA_CRON_ENABLED')) {
            // Désactivé volontairement par le marchand (onglet Aide) — pas une
            // erreur : on répond 200 pour ne pas déclencher de fausse alerte
            // côté outil de supervision cron de l'hébergeur.
            echo json_encode(['ok' => true, 'disabled' => true]);
            exit;
        }

        $ran = [];
        try {
            $ran = $this->module->runBackgroundJobs();
            if (class_exists('WatchdogManager')) {
                (new WatchdogManager($this->module))->cronHeartbeat('cron_endpoint', 'ok');
            }
        } catch (\Throwable $e) {
            if (class_exists('WatchdogManager')) {
                try {
                    (new WatchdogManager($this->module))->error(
                        WatchdogManager::i18nMsg('watchdog.cron_endpoint_exception', ['error' => $e->getMessage()]),
                        '', 'CronController'
                    );
                } catch (\Throwable $ignored) {
                }
            }
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'exception']);
            exit;
        }

        echo json_encode(['ok' => true, 'ran' => $ran, 'timestamp' => date('c')]);
        exit;
    }
}
