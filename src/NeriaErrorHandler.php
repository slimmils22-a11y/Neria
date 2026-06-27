<?php
/**
 * NERIA — NeriaErrorHandler
 *
 * Filet de sécurité global pour les erreurs PHP non catchées dans le module.
 *
 * Deux mécanismes complémentaires :
 *
 * 1. register_shutdown_function — capture les E_ERROR / E_PARSE / E_CORE_ERROR
 *    qui ne sont pas rattrapables par try/catch. Écrit directement en DB
 *    (WatchdogManager peut ne plus être chargeable en phase de shutdown).
 *    Ne log que les erreurs dont le fichier contient "neria" pour ne pas
 *    polluer le watchdog avec des erreurs PS ou PHP core.
 *
 * 2. Wrapper getContent() — remplace la page d'erreur PrestaShop par un
 *    message d'alerte dans le BO et logue au watchdog en même temps.
 *
 * Enregistrement : appelé une fois dans le constructeur de Neria.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaErrorHandler
{
    private static bool $registered = false;

    /**
     * Enregistre le shutdown handler une seule fois par requête.
     * À appeler depuis Neria::__construct().
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        register_shutdown_function(static function (): void {
            $err = error_get_last();

            // Seulement les fatals (E_ERROR=1, E_PARSE=4, E_CORE_ERROR=16, E_COMPILE_ERROR=64)
            if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            // Seulement si l'erreur est dans un fichier Neria
            if (!str_contains(strtolower((string) ($err['file'] ?? '')), 'neria')) {
                return;
            }

            $message = sprintf(
                'Fatal PHP [%s] : %s  in %s:%d',
                self::errorTypeName($err['type']),
                $err['message'],
                basename($err['file']),
                $err['line']
            );

            // Écriture directe en DB — WatchdogManager peut ne plus être chargeable ici.
            try {
                $db  = \Db::getInstance();
                $msg = pSQL(substr($message, 0, 2000));
                $db->execute(
                    "INSERT INTO `" . _DB_PREFIX_ . "neria_log`
                     (id_shop, level, template, class, message, date_add)
                     VALUES (1, 'critical', '', 'NeriaErrorHandler', '$msg', NOW())"
                );
            } catch (\Throwable $t) {
                // Ne jamais laisser le handler lui-même planter — silence total.
            }
        });
    }

    /**
     * Exécute $callback dans un filet try/catch.
     * En cas d'exception non rattrapée :
     *   - log CRITICAL dans le watchdog
     *   - retourne un message d'alerte HTML pour le BO (au lieu d'une page d'erreur PS)
     *
     * @param callable(): string $callback
     * @param \Neria             $module
     */
    public static function wrapGetContent(callable $callback, \Neria $module): string
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            // Loguer au watchdog
            try {
                (new \WatchdogManager($module))->critical(
                    sprintf(
                        'Crash dans getContent() : %s  in %s:%d — Le panneau de configuration n\'a pas pu s\'afficher.',
                        $e->getMessage(),
                        basename($e->getFile()),
                        $e->getLine()
                    ),
                    '',
                    'NeriaErrorHandler'
                );
            } catch (\Throwable $t) {
                // Si même le watchdog plante, écriture directe en DB
                try {
                    $db  = \Db::getInstance();
                    $msg = pSQL(substr($e->getMessage(), 0, 500));
                    $db->execute(
                        "INSERT INTO `" . _DB_PREFIX_ . "neria_log`
                         (id_shop, level, template, class, message, date_add)
                         VALUES (1, 'critical', '', 'NeriaErrorHandler', '$msg', NOW())"
                    );
                } catch (\Throwable $ignored) {}
            }

            // Afficher un message lisible dans le BO au lieu de la page d'erreur PS
            return '
<div style="margin:20px; padding:20px 24px; background:#fef2f2;
            border-left:4px solid #dc2626; border-radius:6px; font-family:sans-serif;">
  <div style="font-size:15px; font-weight:700; color:#991b1b; margin-bottom:8px;">
    ⚠ Neria — Erreur inattendue dans le panneau de configuration
  </div>
  <div style="font-size:13px; color:#7f1d1d; line-height:1.6;">
    Une erreur a été détectée et enregistrée dans le <strong>Watchdog</strong>
    (onglet <strong>Aide → Journal</strong>).<br>
    Consultez-le pour le détail complet et contactez le support si le problème persiste.
  </div>
  <div style="margin-top:12px; font-size:11px; color:#991b1b; opacity:.7; font-family:monospace;">
    ' . htmlspecialchars($e->getMessage()) . '
    in ' . htmlspecialchars(basename($e->getFile())) . ':' . (int) $e->getLine() . '
  </div>
</div>';
        }
    }

    private static function errorTypeName(int $type): string
    {
        return match ($type) {
            E_ERROR         => 'E_ERROR',
            E_PARSE         => 'E_PARSE',
            E_CORE_ERROR    => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            default         => "E_UNKNOWN($type)",
        };
    }
}
