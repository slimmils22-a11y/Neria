<?php
/**
 * NERIA — Endpoint webhook pour les notifications de bounce ESP
 *
 * URL : https://votredomaine.com/module/neria/bounce
 * À configurer dans votre ESP (Mailgun, SendGrid, Postmark…) comme
 * URL de callback pour les événements de type "bounce" / "failed".
 *
 * Sécurité : chaque requête doit inclure le header
 *   X-Neria-Signature: <hmac-sha256 du body avec le secret configuré>
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NeriaBounceModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        // Accepte uniquement les POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
            return;
        }

        $rawBody  = file_get_contents('php://input');
        $payload  = json_decode($rawBody, true);

        if (!is_array($payload)) {
            $this->jsonResponse(['error' => 'Invalid JSON payload'], 400);
            return;
        }

        if (!class_exists('BounceManager')) {
            $this->jsonResponse(['error' => 'BounceManager not available'], 500);
            return;
        }

        // Best-effort : une exception ici ne doit jamais renvoyer une page
        // d'erreur brute à l'ESP — plusieurs (Mailgun...) désactivent un
        // webhook après des échecs HTTP répétés, cassant durablement et
        // silencieusement tout le pipeline de bounces.
        try {
            $mgr = new BounceManager($this->module);

            // Vérification de la signature HMAC
            $signature = $_SERVER['HTTP_X_NERIA_SIGNATURE'] ?? '';
            if ($signature !== '' && !$mgr->verifyWebhookSignature($rawBody, $signature)) {
                $this->jsonResponse(['error' => 'Invalid signature'], 401);
                return;
            }

            // Détection automatique de la source ESP
            $source = $this->detectSource($payload, $_SERVER);

            $recorded = $mgr->processBounceWebhook($payload, $source);

            $this->jsonResponse([
                'ok'       => $recorded,
                'source'   => $source,
                'recorded' => $recorded,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('WatchdogManager')) {
                try {
                    (new WatchdogManager($this->module))->error(
                        'Webhook bounce — exception : ' . $e->getMessage(),
                        '', 'BounceController'
                    );
                } catch (\Throwable $ignored) {
                }
            }
            // 200 volontaire : évite que l'ESP désactive le webhook après
            // plusieurs échecs HTTP consécutifs (comportement Mailgun notamment).
            $this->jsonResponse(['ok' => false, 'error' => 'internal_error_logged'], 200);
        }
    }

    /**
     * Identifie l'ESP d'origine depuis la structure du payload ou les headers.
     */
    private function detectSource(array $payload, array $server): string
    {
        $ua = mb_strtolower($server['HTTP_USER_AGENT'] ?? '');

        if (str_contains($ua, 'mailgun')) {
            return 'mailgun';
        }
        if (str_contains($ua, 'sendgrid')) {
            return 'sendgrid';
        }
        if (str_contains($ua, 'postmark') || isset($payload['RecordType'])) {
            return 'postmark';
        }
        // Mailgun : contient event-data
        if (isset($payload['event-data'])) {
            return 'mailgun';
        }
        // SendGrid : tableau d'objets avec champ "event"
        if (isset($payload[0]['event'])) {
            return 'sendgrid';
        }
        // Postmark : champ "Type" avec "Bounce"
        if (isset($payload['Type']) && str_contains($payload['Type'], 'Bounce')) {
            return 'postmark';
        }

        return 'generic';
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($data));
    }
}
