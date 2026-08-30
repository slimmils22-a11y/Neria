<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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
    /**
     * Enregistre le shutdown handler une seule fois par requête.
     * À appeler depuis Neria::__construct().
     *
     * Round 250 : une propriété `private static bool $registered` était
     * utilisée pour ce garde d'idempotence -- mais une propriété statique
     * de CLASSE survit pour toute la durée de vie du WORKER PHP-FPM, pas
     * seulement de la requête courante (contrairement à
     * register_shutdown_function() lui-même, dont la liste est bien
     * réinitialisée par PHP à chaque nouvelle requête). Sur un
     * hébergement mutualisé/PHP-FPM (le cas réel de production), le
     * handler s'enregistrait à la 1ère requête traitée par un worker,
     * puis register() devenait un no-op PERMANENT pour ce worker : le
     * filet de sécurité contre les erreurs fatales PHP (E_ERROR/E_PARSE/
     * E_CORE_ERROR) ne fonctionnait plus du tout pour toutes les requêtes
     * suivantes traitées par ce même worker (des milliers, selon
     * pm.max_requests), sans qu'aucune alerte ne le signale. $GLOBALS
     * est, lui, garanti frais à chaque requête (contrairement à une
     * propriété statique de classe) tout en restant partagé au sein
     * d'UNE MÊME requête si register() y était appelé plusieurs fois.
     */
    public static function register(): void
    {
        if (!empty($GLOBALS['__neria_error_handler_registered'])) {
            return;
        }
        $GLOBALS['__neria_error_handler_registered'] = true;

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
                // Round 243 : mb_substr (pas substr) -- $message peut
                // interpoler du texte multi-octets (nom client/produit
                // levé dans une exception métier) ; une coupe en octets
                // brutes risque de trancher au milieu d'un caractère,
                // produisant une séquence UTF-8 invalide en base (même
                // correction déjà appliquée à QueueManager::
                // sanitizeErrorMessage() au round 164).
                $msg = pSQL(mb_substr($message, 0, 2000));
                $idShop = self::currentShopId();
                $db->execute(
                    "INSERT INTO `" . _DB_PREFIX_ . "neria_log`
                     (id_shop, level, template, class, message, date_add)
                     VALUES ($idShop, 'critical', '', 'NeriaErrorHandler', '$msg', NOW())"
                );
            } catch (\Throwable $t) {
                // Ne jamais laisser le handler lui-même planter — silence total.
            }
        });
    }

    /**
     * Résout l'id_shop courant pour les écritures de secours en DB
     * (quand WatchdogManager, qui scope normalement ses logs par boutique,
     * n'est lui-même plus chargeable). Retombe sur 1 seulement si le
     * contexte boutique est indisponible (ex: shutdown très tardif).
     */
    private static function currentShopId(): int
    {
        try {
            $context = \Context::getContext();
            if ($context !== null && isset($context->shop->id) && (int) $context->shop->id > 0) {
                return (int) $context->shop->id;
            }
        } catch (\Throwable $t) {
            // Contexte indisponible — repli ci-dessous.
        }

        return 1;
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
                    \WatchdogManager::i18nMsg('watchdog.crash_get_content', [
                        'error' => $e->getMessage(),
                        'file'  => basename($e->getFile()),
                        'line'  => $e->getLine(),
                    ]),
                    '',
                    'NeriaErrorHandler'
                );
            } catch (\Throwable $t) {
                // Si même le watchdog plante, écriture directe en DB
                try {
                    $db  = \Db::getInstance();
                    // Round 243 : mb_substr (voir justification ligne ~66).
                    $msg = pSQL(mb_substr($e->getMessage(), 0, 500));
                    $idShop = self::currentShopId();
                    $db->execute(
                        "INSERT INTO `" . _DB_PREFIX_ . "neria_log`
                         (id_shop, level, template, class, message, date_add)
                         VALUES ($idShop, 'critical', '', 'NeriaErrorHandler', '$msg', NOW())"
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

    /**
     * Filet de sécurité pour hookDisplayHeader() — exécuté sur CHAQUE page
     * front-office. Une exception non rattrapée ici casserait la boutique
     * entière pour tout visiteur, pas seulement une fonctionnalité Neria.
     * Contrairement à wrapGetContent(), ne retourne/affiche jamais rien :
     * le hook doit rester totalement silencieux en cas d'échec.
     */
    public static function wrapDisplayHeader(callable $callback, \Neria $module): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            try {
                (new \WatchdogManager($module))->critical(
                    \WatchdogManager::i18nMsg('watchdog.crash_display_header', [
                        'error' => $e->getMessage(),
                        'file'  => basename($e->getFile()),
                        'line'  => $e->getLine(),
                    ]),
                    '',
                    'NeriaErrorHandler'
                );
            } catch (\Throwable $t) {
                try {
                    $db  = \Db::getInstance();
                    // Round 243 : mb_substr (voir justification ligne ~66).
                    $msg = pSQL(mb_substr($e->getMessage(), 0, 500));
                    $idShop = self::currentShopId();
                    $db->execute(
                        "INSERT INTO `" . _DB_PREFIX_ . "neria_log`
                         (id_shop, level, template, class, message, date_add)
                         VALUES ($idShop, 'critical', '', 'NeriaErrorHandler', '$msg', NOW())"
                    );
                } catch (\Throwable $ignored) {}
            }
            // Aucun retour, aucun affichage — le hook s'arrête proprement, la page continue.
        }
    }

    /**
     * Filet générique pour les hooks PrestaShop à retour void (actionXxx,
     * displayBackOfficeHeader…). Utilisé pour tous les hooks qui ne rendent
     * pas de HTML — une exception y est journalisée puis absorbée.
     */
    public static function wrapHookVoid(string $hookName, callable $callback, \Neria $module): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            self::logHookCrash($hookName, $e, $module);
        }
    }

    /**
     * Filet générique pour les hooks PrestaShop qui retournent du HTML
     * (displayXxx). Retourne une chaîne vide en cas d'exception — le bloc
     * Neria disparaît silencieusement plutôt que de casser toute la page
     * (fiche commande, fiche client, page produit…).
     */
    public static function wrapHookString(string $hookName, callable $callback, \Neria $module): string
    {
        try {
            return (string) $callback();
        } catch (\Throwable $e) {
            self::logHookCrash($hookName, $e, $module);
            return '';
        }
    }

    private static function logHookCrash(string $hookName, \Throwable $e, \Neria $module): void
    {
        try {
            (new \WatchdogManager($module))->critical(
                \WatchdogManager::i18nMsg('watchdog.crash_hook_generic', [
                    'hook'  => $hookName,
                    'error' => $e->getMessage(),
                    'file'  => basename($e->getFile()),
                    'line'  => $e->getLine(),
                ]),
                '',
                'NeriaErrorHandler'
            );
        } catch (\Throwable $t) {
            try {
                $db  = \Db::getInstance();
                // Round 243 : mb_substr (voir justification ligne ~66).
                $msg = pSQL(mb_substr($hookName . ' : ' . $e->getMessage(), 0, 500));
                $idShop = self::currentShopId();
                $db->execute(
                    "INSERT INTO `" . _DB_PREFIX_ . "neria_log`
                     (id_shop, level, template, class, message, date_add)
                     VALUES ($idShop, 'critical', '', 'NeriaErrorHandler', '$msg', NOW())"
                );
            } catch (\Throwable $ignored) {
            }
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
