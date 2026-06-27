<?php
/**
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
        $token = (string) Tools::getValue('t');
        $event = Tools::strtolower((string) Tools::getValue('e', 'open'));

        if ($token !== '' && class_exists('StatsManager')) {
            $stats = new StatsManager($this->module);

            if ($event === 'click') {
                $url = (string) Tools::getValue('url', '');
                $stats->recordClick($token, $url);

                // Cookie d'attribution : template:lang:token (24h)
                // Permet à hookActionOrderStatusPostUpdate d'attribuer la commande.
                $ref = $stats->getRefDataByToken($token);
                if ($ref) {
                    $cookieVal = implode(':', [$ref['template'], $ref['lang'], $token]);
                    setcookie('neria_ref', $cookieVal, [
                        'expires'  => time() + 86400,
                        'path'     => '/',
                        'secure'   => isset($_SERVER['HTTPS']),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }

                // Tracking upsell : détecte neria_ur dans l'URL cible
                if ($url !== '' && class_exists('UpsellManager')) {
                    $parsed = parse_url($url);
                    if (!empty($parsed['query'])) {
                        parse_str($parsed['query'], $qp);
                        $idUpsell = (int) ($qp['neria_ur'] ?? 0);
                        if ($idUpsell > 0) {
                            (new UpsellManager($this->module))->recordClick($idUpsell);
                        }
                    }
                }

                if ($url !== '' && Validate::isAbsoluteUrl($url)) {
                    header('Location: ' . $url, true, 302);
                    exit;
                }
            } else {
                $stats->recordOpen($token);
            }
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
