<?php
/**
 * NERIA — MultiClientPreviewManager
 *
 * Prévisualisation multi-client email :
 * — Simulation CSS côté serveur (5 clients, sans API)
 * — Intégration optionnelle Litmus / Email on Acid (clé API marchand)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MultiClientPreviewManager
{
    const CONFIG_LITMUS_KEY = 'NERIA_LITMUS_KEY';
    const CONFIG_EOA_KEY    = 'NERIA_EOA_KEY';

    const CLIENTS = [
        'apple_mail' => [
            'name'    => 'Apple Mail',
            'icon'    => '🍎',
            'color'   => '#555555',
            'support' => 'Rendu natif WebKit — référence complète',
        ],
        'gmail' => [
            'name'    => 'Gmail',
            'icon'    => 'G',
            'color'   => '#EA4335',
            'support' => 'Supprime les balises style — CSS inline uniquement',
        ],
        'outlook' => [
            'name'    => 'Outlook',
            'icon'    => 'O',
            'color'   => '#0078D4',
            'support' => 'Moteur Word — background-image, border-radius, flex supprimés',
        ],
        'orange' => [
            'name'    => 'Orange Mail',
            'icon'    => '●',
            'color'   => '#FF6600',
            'support' => 'Webmail basique — blocs de styles supprimés',
        ],
        'yahoo' => [
            'name'    => 'Yahoo Mail',
            'icon'    => 'Y!',
            'color'   => '#6001D2',
            'support' => 'Supprime les media queries',
        ],
        'hotmail' => [
            'name'    => 'Hotmail / Outlook.com',
            'icon'    => 'H',
            'color'   => '#0072C6',
            'support' => 'Webmail Microsoft — balises link supprimées, styles partiels',
        ],
    ];

    // ============================================================
    // SIMULATION CSS PAR CLIENT
    // ============================================================

    /**
     * Transforme le HTML source pour simuler le rendu d'un client email.
     */
    public function transformForClient(string $html, string $client): string
    {
        switch ($client) {
            case 'gmail':
                return $this->transformGmail($html);
            case 'outlook':
                return $this->transformOutlook($html);
            case 'orange':
                return $this->transformOrange($html);
            case 'yahoo':
                return $this->transformYahoo($html);
            case 'hotmail':
                return $this->transformHotmail($html);
            case 'apple_mail':
            default:
                return $this->addBanner($html, 'apple_mail');
        }
    }

    private function transformGmail(string $html): string
    {
        // Gmail supprime tous les blocs <style> et les <link> CSS
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<link\b[^>]+rel=["\']stylesheet["\'][^>]*\/?>/i', '', $html);
        return $this->addBanner($html, 'gmail');
    }

    private function transformOutlook(string $html): string
    {
        // Supprime background-image dans les attributs style
        $html = preg_replace('/background-image\s*:[^;"\'}]+[;"\'}]/i', '', $html);
        // Supprime border-radius
        $html = preg_replace('/border-radius\s*:[^;"\'}]+[;"\'}]/i', '', $html);
        // Supprime display:flex
        $html = preg_replace('/display\s*:\s*flex[^;"\'}]*[;"\'}]/i', 'display:block;', $html);
        // Supprime gap
        $html = preg_replace('/\bgap\s*:[^;"\'}]+[;"\'}]/i', '', $html);

        // Transforme aussi les blocs <style>
        $html = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/si', function ($m) {
            $css = $m[1];
            $css = preg_replace('/background-image\s*:[^;{}]+;?/i', '', $css);
            $css = preg_replace('/border-radius\s*:[^;{}]+;?/i', '', $css);
            $css = preg_replace('/display\s*:\s*flex[^;{}]*;?/i', 'display:block;', $css);
            $css = preg_replace('/\bgap\s*:[^;{}]+;?/i', '', $css);
            return '<style>' . $css . '</style>';
        }, $html);

        return $this->addBanner($html, 'outlook');
    }

    private function transformOrange(string $html): string
    {
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<link\b[^>]+rel=["\']stylesheet["\'][^>]*\/?>/i', '', $html);
        return $this->addBanner($html, 'orange');
    }

    private function transformYahoo(string $html): string
    {
        // Yahoo conserve les styles mais supprime les @media queries
        $html = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/si', function ($m) {
            $css = preg_replace('/@media\b[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/si', '', $m[1]);
            return '<style>' . $css . '</style>';
        }, $html);
        return $this->addBanner($html, 'yahoo');
    }

    private function transformHotmail(string $html): string
    {
        // Hotmail/Outlook.com supprime les <link> CSS externes
        $html = preg_replace('/<link\b[^>]+rel=["\']stylesheet["\'][^>]*\/?>/i', '', $html);
        // Supprime text-shadow et box-shadow (non supportés)
        $html = preg_replace('/(?:text|box)-shadow\s*:[^;"\'}]+[;"\'}]/i', '', $html);
        return $this->addBanner($html, 'hotmail');
    }

    private function addBanner(string $html, string $client): string
    {
        $info   = self::CLIENTS[$client];
        $banner = sprintf(
            '<div style="background:%s;padding:7px 12px;font-family:Arial,sans-serif;'
            . 'font-size:11px;color:#fff;border-bottom:2px solid rgba(0,0,0,.15);">'
            . '<strong>%s</strong> — %s</div>',
            htmlspecialchars($info['color']),
            htmlspecialchars($info['name']),
            htmlspecialchars($info['support'])
        );

        // Insère le bandeau juste après <body> si présent, sinon en tête
        if (stripos($html, '<body') !== false) {
            return preg_replace('/(<body\b[^>]*>)/i', '$1' . $banner, $html, 1);
        }
        return $banner . $html;
    }

    // ============================================================
    // LITMUS API
    // ============================================================

    public function hasLitmusKey(): bool
    {
        return trim((string) \Configuration::get(self::CONFIG_LITMUS_KEY)) !== '';
    }

    /**
     * Soumet le HTML à Litmus et retourne ['id' => string] ou ['error' => string].
     */
    public function submitToLitmus(string $html): array
    {
        $key = trim((string) \Configuration::get(self::CONFIG_LITMUS_KEY));
        if (!$key) {
            return ['error' => 'Clé API Litmus non configurée'];
        }
        if (!function_exists('curl_init')) {
            return ['error' => 'cURL non disponible sur ce serveur'];
        }

        $payload = json_encode([
            'email_source' => ['html_text' => $html],
            'applications' => [
                ['application' => 'gmailnew'],
                ['application' => 'ol2019'],
                ['application' => 'appmail14'],
                ['application' => 'yahoo_mail_'],
            ],
        ]);

        $ch = curl_init('https://api.litmus.com/v1/tests');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($key . ':'),
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['error' => 'Litmus HTTP ' . $httpCode . ' — ' . mb_substr((string) $response, 0, 200)];
        }

        $data = json_decode((string) $response, true);
        return ['id' => $data['id'] ?? null, 'share_url' => $data['share_url'] ?? null];
    }

    /**
     * Interroge Litmus pour les screenshots d'un test existant.
     */
    public function pollLitmus(string $testId): array
    {
        $key = trim((string) \Configuration::get(self::CONFIG_LITMUS_KEY));

        $ch = curl_init("https://api.litmus.com/v1/tests/{$testId}/results");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($key . ':'),
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data   = json_decode((string) $response, true);
        $result = [];
        foreach ($data['previews'] ?? [] as $p) {
            $result[] = [
                'client'  => $p['application'] ?? '',
                'image'   => $p['full_screenshot_url'] ?? '',
                'ready'   => !empty($p['full_screenshot_url']),
            ];
        }
        return $result;
    }

    // ============================================================
    // EMAIL ON ACID API
    // ============================================================

    public function hasEoaKey(): bool
    {
        return trim((string) \Configuration::get(self::CONFIG_EOA_KEY)) !== '';
    }

    /**
     * Soumet à Email on Acid. La clé doit être au format "account_id:api_password".
     */
    public function submitToEmailOnAcid(string $html): array
    {
        $key = trim((string) \Configuration::get(self::CONFIG_EOA_KEY));
        if (!$key) {
            return ['error' => 'Clé API Email on Acid non configurée'];
        }
        if (!function_exists('curl_init')) {
            return ['error' => 'cURL non disponible sur ce serveur'];
        }

        $payload = json_encode([
            'subject' => 'Neria Preview',
            'html'    => $html,
        ]);

        $ch = curl_init('https://api.emailonacid.com/v6/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($key),
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['error' => 'Email on Acid HTTP ' . $httpCode . ' — ' . mb_substr((string) $response, 0, 200)];
        }

        $data = json_decode((string) $response, true);
        return ['id' => $data['id'] ?? null];
    }

    /**
     * Interroge Email on Acid pour les screenshots d'un test existant.
     */
    public function pollEmailOnAcid(string $testId): array
    {
        $key = trim((string) \Configuration::get(self::CONFIG_EOA_KEY));

        $ch = curl_init("https://api.emailonacid.com/v6/emails/{$testId}/results");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($key),
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data   = json_decode((string) $response, true);
        $result = [];
        foreach ($data['results'] ?? [] as $r) {
            $result[] = [
                'client' => $r['client_id'] ?? '',
                'image'  => $r['image'] ?? '',
                'ready'  => !empty($r['image']),
            ];
        }
        return $result;
    }
}
