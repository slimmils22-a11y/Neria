<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Front controller : pixel de tracking (ouvertures / clics)
 *
 * Cible du pixel 1×1 invisible injecté dans chaque email (EmailRenderer::
 * injectTrackingPixel) et, potentiellement, des liens cliquables suivis.
 *
 * - e=open  : enregistre l'ouverture, renvoie un GIF transparent 1×1.
 * - e=click : enregistre le clic, redirige vers l'URL d'origine (param url).
 *
 * Aucun rendu de page : on répond directement et on coupe l'exécution,
 * comme unsubscribe.php le fait pour le désabonnement « un clic ».
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaTrackModuleFrontController extends ModuleFrontController
{
    /** @var bool Pas de colonnes, pas de thème — réponse brute */
    public $display_column_left  = false;
    public $display_column_right = false;
    public $ssl                  = true;

    public function initContent()
    {
        $token     = (string) Tools::getValue('t');
        $event     = Tools::strtolower((string) Tools::getValue('e', 'open'));
        $url       = (string) Tools::getValue('url', '');
        $signature = (string) Tools::getValue('s', '');

        // Endpoint public, non authentifié, sans throttling jusqu'ici : un
        // bot pouvait le bombarder avec un token connu (ou deviné) autant de
        // fois par seconde qu'il le souhaitait. Le pixel ne peut jamais être
        // compté deux fois (dédup + GET_LOCK dans StatsManager::recordOpen/
        // recordClick), mais CHAQUE requête déclenchait quand même la chaîne
        // complète verrou+SELECT+INSERT — vecteur d'épuisement DB/CPU sans
        // authentification. On ne bloque JAMAIS le pixel/la redirection eux-
        // mêmes (philosophie de ce fichier), on saute uniquement l'écriture
        // en base (la partie coûteuse) au-delà du seuil. Fail-open si APCu
        // n'est pas disponible (best-effort, jamais bloquant).
        $trackingWriteAllowed = true;
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            // Round 164 : la clé de throttling était scopée par IP SEULE,
            // tous tokens/destinataires confondus. Derrière un NAT/proxy
            // partagé (bureau, opérateur mobile), un seul destinataire très
            // actif épuisait le quota de 30 écritures/10s pour TOUS les
            // autres visiteurs légitimes de la même IP — pixels/clics
            // toujours servis (pas de rupture visible), mais leur
            // enregistrement en stats silencieusement sauté pour des
            // destinataires n'ayant rien à voir avec l'abus. Le token (`t`)
            // identifie l'email réellement ouvert/cliqué — combiner IP+token
            // isole le throttling par destinataire réel sans perdre la
            // protection anti-abus (un bot variant le token resterait de
            // toute façon bloqué par la vérification de signature ci-dessous).
            $ip  = (string) (\Tools::getRemoteAddr() ?: '0.0.0.0');
            $key = 'neria_track_rl_' . md5($ip . '|' . $token);
            $hits = (int) apcu_fetch($key, $ok);
            if (!$ok) {
                apcu_store($key, 1, 10);
            } else {
                $hits++;
                apcu_store($key, $hits, 10);
                if ($hits > 30) {
                    $trackingWriteAllowed = false;
                }
            }
        }

        // Autorisation de redirection : liée à un token connu, jamais à l'URL
        // seule — sans ça, ce endpoint public devient un open redirect
        // (n'importe qui peut forger track.php?e=click&url=https://phishing…
        // sans token et faire rediriger via le domaine de confiance de la
        // boutique). En cas d'exception (DB, etc.) on reste permissif SI un
        // token était présent, pour ne jamais casser un email déjà envoyé.
        $redirectAllowed = false;

        // Le tracking (best-effort) ne doit JAMAIS empêcher la redirection du
        // client ou l'affichage du pixel. Une exception ici — DB, token
        // corrompu, disque plein — ne doit jamais se traduire par un lien
        // mort dans un email déjà envoyé, ni par une image cassée visible.
        try {
            if ($token !== '' && class_exists('StatsManager')) {
                $stats = new StatsManager($this->module);

                if ($event === 'click') {
                    // getRefDataByToken() reste appelée même sous throttling :
                    // simple SELECT indexé (idx_token), nécessaire pour
                    // déterminer la redirection en toute sécurité — seule
                    // l'écriture (recordClick, verrou+INSERT) est sautée.
                    $ref = $stats->getRefDataByToken($token);
                    if ($ref) {
                        $redirectAllowed = true;

                        if ($trackingWriteAllowed) {
                            $stats->recordClick($token, $url);

                            // Cookie d'attribution : template:lang:token (24h)
                            // Permet à hookActionOrderStatusPostUpdate d'attribuer la commande.
                            $cookieVal = implode(':', [$ref['template'], $ref['lang'], $token]);
                            setcookie('neria_ref', $cookieVal, [
                                'expires'  => time() + 86400,
                                'path'     => '/',
                                'secure'   => isset($_SERVER['HTTPS']),
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ]);
                        }
                    }

                    // Tracking upsell : détecte neria_ur dans l'URL cible
                    if ($trackingWriteAllowed && $url !== '' && class_exists('UpsellManager')) {
                        $parsed = parse_url($url);
                        if (!empty($parsed['query'])) {
                            parse_str($parsed['query'], $qp);
                            $idUpsell = (int) ($qp['neria_ur'] ?? 0);
                            if ($idUpsell > 0) {
                                (new UpsellManager($this->module))->recordClick($idUpsell);
                            }
                        }
                    }
                } elseif ($trackingWriteAllowed) {
                    $stats->recordOpen($token);
                }
            }
        } catch (\Throwable $e) {
            // Infra en échec (pas un token invalide) : reste permissif si un
            // token était fourni, pour ne pas casser un email déjà envoyé.
            if ($token !== '') {
                $redirectAllowed = true;
            }
            if (class_exists('WatchdogManager')) {
                try {
                    (new WatchdogManager($this->module))->warning(
                        WatchdogManager::i18nMsg('watchdog.track_pixel_exception', ['event' => $event, 'error' => $e->getMessage()]),
                        '', 'TrackController'
                    );
                } catch (\Throwable $ignored) {
                }
            }
        }

        // La redirection (clic) doit se produire même si le tracking a échoué —
        // le client ne doit jamais tomber sur une page cassée à cause de nous —
        // mais uniquement pour un token connu (cf. $redirectAllowed ci-dessus)
        // ET pour l'URL exacte qui a été signée (HMAC) au moment de l'envoi.
        //
        // Sans cette vérification de signature, un token de tracking valide
        // (par ex. reçu légitimement par un attaquant dans un email qui lui
        // est destiné) pourrait être rejoué avec n'importe quelle URL externe
        // (?url=https://phishing...) : le endpoint deviendrait un open
        // redirect exploitable via le domaine de confiance de la boutique,
        // puisque $redirectAllowed n'était lié qu'à la validité du token, pas
        // à l'URL. NeriaTools::verifyTrackingUrl() vérifie que CETTE URL a
        // bien été associée à CE token par EmailRenderer::wrapLinksInFile().
        if (
            $event === 'click'
            && $redirectAllowed
            && $url !== ''
            && Validate::isAbsoluteUrl($url)
            && class_exists('NeriaTools')
            && NeriaTools::verifyTrackingUrl($token, $url, $signature)
        ) {
            header('Location: ' . $url, true, 302);
            exit;
        }

        $this->outputPixel();
    }

    /**
     * Sort un GIF transparent 1×1 — le pixel lui-même. Toujours renvoyé,
     * même si le token est invalide/manquant, pour ne jamais casser le
     * rendu de l'email (image cassée visible par le destinataire).
     */
    private function outputPixel(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($gif));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        echo $gif;
        exit;
    }
}
