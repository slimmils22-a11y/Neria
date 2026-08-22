<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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

        // Round 162 : contrairement aux hooks classiques (que PrestaShop ne
        // déclenche plus pour un module désactivé), ce contrôleur front
        // reste accessible par URL directe même si le marchand désactive
        // Neria depuis le BO — désactiver le module ne purge pas
        // NERIA_CRON_TOKEN/NERIA_CRON_ENABLED. Si le vrai cron serveur
        // (crontab) reste actif en dehors de PrestaShop, les tâches de
        // fond (queue d'envoi, licence, health checks) continuaient de
        // tourner et d'écrire en base pendant toute la période où le
        // marchand pense le module éteint — contradiction directe avec son
        // intention explicite.
        if (!Module::isEnabled('neria')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'module_disabled']);
            exit;
        }

        $token = (string) Tools::getValue('token', '');
        $expected = (string) Configuration::getGlobalValue('NERIA_CRON_TOKEN');

        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
            // Round 186 : le contrôleur était atteint (l'hébergeur a bien
            // déclenché le cron) mais rejeté silencieusement — indiscernable
            // pour le Watchdog d'une vraie panne cron côté hébergeur. On
            // trace l'horodatage du rejet (jamais le jeton reçu) pour que
            // checkActiveCron() puisse donner un diagnostic précis.
            Configuration::updateGlobalValue('NERIA_CRON_LAST_REJECTED', date('Y-m-d H:i:s'));

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
            // Round 141 : déclenchement serveur réel (jeton vérifié
            // ci-dessus, aucun visiteur n'attend cette réponse) — les scans
            // lourds (known_regressions_guard) sont donc autorisés ici,
            // contrairement à l'appel passif depuis hookDisplayHeader().
            $ran = $this->module->runBackgroundJobs(true);
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
