<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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
            'name'    => 'Outlook (Desktop)',
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
            'name'    => 'Outlook.com (Web) — inclut Hotmail, Live, MSN',
            'icon'    => 'H',
            'color'   => '#0072C6',
            'support' => 'Webmail Microsoft — balises link supprimées, styles partiels',
        ],
        'qq_mail' => [
            'name'    => 'QQ Mail (163.com)',
            'icon'    => 'QQ',
            'color'   => '#12B7F5',
            'support' => 'Webmail chinois — balises style et link supprimées, CSS externe bloqué',
        ],
        'mailru' => [
            'name'    => 'Mail.ru',
            'icon'    => 'M',
            'color'   => '#005FF9',
            'support' => 'Webmail russe — balises style supprimées, background-image bloqué',
        ],
        'samsung_email' => [
            'name'    => 'Samsung Email',
            'icon'    => 'S',
            'color'   => '#1428A0',
            'support' => 'Client Android natif — media queries et flexbox ignorés',
        ],
        'gmx' => [
            'name'    => 'GMX / Web.de',
            'icon'    => 'GX',
            'color'   => '#1C449B',
            'support' => 'Webmail allemand — CSS externe et shadows supprimés',
        ],
        'naver' => [
            'name'    => 'Naver Mail',
            'icon'    => 'N',
            'color'   => '#03C75A',
            'support' => 'Webmail coréen — balises style et link supprimées',
        ],
        'yandex' => [
            'name'    => 'Yandex Mail',
            'icon'    => 'Y',
            'color'   => '#FC3F1D',
            'support' => 'Webmail russe — balises style supprimées, background-image bloqué',
        ],
        'aol' => [
            'name'    => 'AOL Mail',
            'icon'    => 'AOL',
            'color'   => '#3D007A',
            'support' => 'Webmail historique US — media queries et styles supprimés',
        ],
        'protonmail' => [
            'name'    => 'ProtonMail',
            'icon'    => 'P',
            'color'   => '#6D4AFF',
            'support' => 'Sécurité/vie privée — CSS strict, border-radius et shadows bloqués',
        ],
        'jp_carrier' => [
            'name'    => 'Mail opérateur japonais (docomo/au/SoftBank)',
            'icon'    => '携',
            'color'   => '#E60012',
            'support' => 'Le plus restrictif au monde — tout le CSS est supprimé, rendu texte brut',
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
            case 'qq_mail':
                return $this->transformQqMail($html);
            case 'mailru':
                return $this->transformMailru($html);
            case 'samsung_email':
                return $this->transformSamsungEmail($html);
            case 'gmx':
                return $this->transformGmx($html);
            case 'naver':
                return $this->transformNaver($html);
            case 'yandex':
                return $this->transformYandex($html);
            case 'aol':
                return $this->transformAol($html);
            case 'protonmail':
                return $this->transformProtonMail($html);
            case 'jp_carrier':
                return $this->transformJpCarrier($html);
            case 'apple_mail':
            default:
                return $this->addBanner($html, 'apple_mail');
        }
    }

    /**
     * Supprime les blocs <style> et les <link> CSS externes — comportement
     * partagé par Gmail/Orange/QQ Mail/Naver, qui utilisent tous le même
     * modèle strict "pas de CSS externe/interne" côté webmail. Auparavant
     * dupliqué à l'identique dans 4 méthodes séparées (transformOrange,
     * transformQqMail, transformNaver) sous un nom différent à chaque fois,
     * laissant croire à une simulation propre à chaque client alors que le
     * rendu produit était rigoureusement identique — un marchand qui
     * choisissait "Orange" pour vérifier un rendu spécifique voyait en
     * réalité le même résultat que "Gmail" sans le savoir. Centralisé ici
     * comme un choix explicite et documenté, pas une coïncidence de code.
     */
    private function stripStyleAndLinkTags(string $html): string
    {
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<link\b[^>]+rel=["\']stylesheet["\'][^>]*\/?>/i', '', $html);
        return $html;
    }

    /**
     * Applique une ou plusieurs règles de suppression/remplacement de
     * propriété CSS UNIQUEMENT à l'intérieur des attributs style="..." —
     * pas sur le HTML entier. Les regex ci-dessous (ex. /background-image
     * :[^;"'}]+;?/i) appliquées directement sur tout $html matchaient aussi
     * du texte VISIBLE mentionnant littéralement une déclaration CSS (ex.
     * un article/guide technique inclus dans l'email), le tronquant dans
     * l'aperçu. Impact limité (outil de prévisualisation uniquement, ne
     * touche jamais l'email réellement envoyé) mais corrigé pour rester
     * fidèle au HTML source.
     *
     * @param array<string,string> $patterns [regex => remplacement]
     */
    private function replaceInInlineStyles(string $html, array $patterns): string
    {
        return preg_replace_callback(
            '/(\sstyle\s*=\s*)(["\'])(.*?)\2/is',
            function (array $m) use ($patterns): string {
                $css = $m[3];
                foreach ($patterns as $pattern => $replacement) {
                    $css = preg_replace($pattern, $replacement, $css);
                }
                return $m[1] . $m[2] . $css . $m[2];
            },
            $html
        ) ?? $html;
    }

    private function transformGmail(string $html): string
    {
        return $this->addBanner($this->stripStyleAndLinkTags($html), 'gmail');
    }

    private function transformOutlook(string $html): string
    {
        // Supprime background-image/border-radius/gap et neutralise
        // display:flex, uniquement dans les attributs style="..."
        $html = $this->replaceInInlineStyles($html, [
            '/background-image\s*:[^;"\'}]+;?/i' => '',
            '/border-radius\s*:[^;"\'}]+;?/i'     => '',
            '/display\s*:\s*flex[^;"\'}]*;?/i'    => 'display:block;',
            '/\bgap\s*:[^;"\'}]+;?/i'             => '',
        ]);

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
        return $this->addBanner($this->stripStyleAndLinkTags($html), 'orange');
    }

    /**
     * Supprime uniquement les @media queries en conservant le reste du CSS
     * — comportement partagé par Yahoo/AOL Mail (même groupe Verizon Media/
     * Yahoo historiquement, moteur de rendu apparenté).
     */
    private function stripMediaQueries(string $html): string
    {
        return preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/si', function ($m) {
            $css = preg_replace('/@media\b[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/si', '', $m[1]);
            return '<style>' . $css . '</style>';
        }, $html);
    }

    private function transformYahoo(string $html): string
    {
        return $this->addBanner($this->stripMediaQueries($html), 'yahoo');
    }

    /**
     * Supprime les <link> CSS externes + text-shadow/box-shadow — comportement
     * partagé par Hotmail/Outlook.com et GMX/Web.de (webmails européens au
     * support CSS restreint similaire).
     */
    private function stripLinkTagsAndShadows(string $html): string
    {
        $html = preg_replace('/<link\b[^>]+rel=["\']stylesheet["\'][^>]*\/?>/i', '', $html);
        $html = $this->replaceInInlineStyles($html, [
            '/(?:text|box)-shadow\s*:[^;"\'}]+;?/i' => '',
        ]);
        return $html;
    }

    private function transformHotmail(string $html): string
    {
        return $this->addBanner($this->stripLinkTagsAndShadows($html), 'hotmail');
    }

    private function transformQqMail(string $html): string
    {
        return $this->addBanner($this->stripStyleAndLinkTags($html), 'qq_mail');
    }

    /**
     * Supprime les blocs <style> et le background-image — comportement
     * partagé par Mail.ru et Yandex Mail (webmails russes au même moteur).
     */
    private function stripStyleAndBackgroundImage(string $html): string
    {
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $html = $this->replaceInInlineStyles($html, [
            '/background-image\s*:[^;"\'}]+;?/i' => '',
        ]);
        return $html;
    }

    private function transformMailru(string $html): string
    {
        return $this->addBanner($this->stripStyleAndBackgroundImage($html), 'mailru');
    }

    private function transformSamsungEmail(string $html): string
    {
        // Samsung Email (Android) ignore les @media queries et le flexbox
        $html = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/si', function ($m) {
            $css = preg_replace('/@media\b[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/si', '', $m[1]);
            $css = preg_replace('/display\s*:\s*flex[^;{}]*;?/i', 'display:block;', $css);
            return '<style>' . $css . '</style>';
        }, $html);
        return $this->addBanner($html, 'samsung_email');
    }

    private function transformGmx(string $html): string
    {
        return $this->addBanner($this->stripLinkTagsAndShadows($html), 'gmx');
    }

    private function transformNaver(string $html): string
    {
        return $this->addBanner($this->stripStyleAndLinkTags($html), 'naver');
    }

    private function transformYandex(string $html): string
    {
        return $this->addBanner($this->stripStyleAndBackgroundImage($html), 'yandex');
    }

    private function transformAol(string $html): string
    {
        return $this->addBanner($this->stripMediaQueries($html), 'aol');
    }

    private function transformProtonMail(string $html): string
    {
        // ProtonMail (sécurité) : supprime border-radius, shadows et position (anti-tracking)
        $html = $this->replaceInInlineStyles($html, [
            '/border-radius\s*:[^;"\'}]+;?/i'       => '',
            '/(?:text|box)-shadow\s*:[^;"\'}]+;?/i' => '',
            '/\bposition\s*:[^;"\'}]+;?/i'          => '',
        ]);
        $html = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/si', function ($m) {
            $css = preg_replace('/border-radius\s*:[^;{}]+;?/i', '', $m[1]);
            $css = preg_replace('/(?:text|box)-shadow\s*:[^;{}]+;?/i', '', $css);
            $css = preg_replace('/\bposition\s*:[^;{}]+;?/i', '', $css);
            return '<style>' . $css . '</style>';
        }, $html);
        return $this->addBanner($html, 'protonmail');
    }

    private function transformJpCarrier(string $html): string
    {
        // Mail opérateur japonais : le plus restrictif — tout le CSS est supprimé
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<link\b[^>]+rel=["\']stylesheet["\'][^>]*\/?>/i', '', $html);
        $html = preg_replace('/\sstyle\s*=\s*(["\']).*?\1/i', '', $html);
        return $this->addBanner($html, 'jp_carrier');
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
        $key = \CryptoManager::decrypt(trim((string) \Configuration::get(self::CONFIG_LITMUS_KEY)));
        if (!$key) {
            return ['error' => AdminTranslator::t('msg.litmus_key_missing')];
        }
        if (!function_exists('curl_init')) {
            return ['error' => AdminTranslator::t('msg.curl_unavailable')];
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
        $key = \CryptoManager::decrypt(trim((string) \Configuration::get(self::CONFIG_LITMUS_KEY)));
        if (!$key) {
            return [];
        }

        $ch = curl_init("https://api.litmus.com/v1/tests/{$testId}/results");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($key . ':'),
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // Contrairement à submitToLitmus() ci-dessus, cette méthode ignorait
        // totalement le code HTTP et les erreurs cURL — une API indisponible,
        // une clé expirée en cours de route, ou un timeout réseau pendant le
        // sondage (interrogé toutes les 4s pendant 60s côté BO) retournait
        // silencieusement [], indiscernable d'un test simplement "pas encore
        // prêt". Le marchand ne voyait que "délai dépassé" sans jamais
        // savoir que le service avait répondu une erreur. On journalise
        // désormais l'échec (diagnostic Watchdog) — la structure de retour
        // (tableau vide) reste inchangée pour ne pas casser le contrat JS
        // existant (multipreview.tpl itère un tableau de previews).
        if ($curlErr !== '' || $httpCode < 200 || $httpCode >= 300) {
            if (class_exists('WatchdogManager') && class_exists('Module')) {
                $module = \Module::getInstanceByName('neria');
                if ($module) {
                    (new \WatchdogManager($module))->warning(
                        \WatchdogManager::i18nMsg('watchdog.multipreview_poll_failed', [
                            'provider' => 'Litmus',
                            'code'     => $httpCode,
                            'error'    => $curlErr !== '' ? $curlErr : mb_substr((string) $response, 0, 200),
                        ]),
                        '', 'MultiClientPreviewManager'
                    );
                }
            }
            return [];
        }

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
        $key = \CryptoManager::decrypt(trim((string) \Configuration::get(self::CONFIG_EOA_KEY)));
        if (!$key) {
            return ['error' => AdminTranslator::t('msg.eoa_key_missing')];
        }
        if (!function_exists('curl_init')) {
            return ['error' => AdminTranslator::t('msg.curl_unavailable')];
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
        $key = \CryptoManager::decrypt(trim((string) \Configuration::get(self::CONFIG_EOA_KEY)));
        if (!$key) {
            return [];
        }

        $ch = curl_init("https://api.emailonacid.com/v6/emails/{$testId}/results");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($key),
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // Même correctif que pollLitmus() ci-dessus.
        if ($curlErr !== '' || $httpCode < 200 || $httpCode >= 300) {
            if (class_exists('WatchdogManager') && class_exists('Module')) {
                $module = \Module::getInstanceByName('neria');
                if ($module) {
                    (new \WatchdogManager($module))->warning(
                        \WatchdogManager::i18nMsg('watchdog.multipreview_poll_failed', [
                            'provider' => 'Email on Acid',
                            'code'     => $httpCode,
                            'error'    => $curlErr !== '' ? $curlErr : mb_substr((string) $response, 0, 200),
                        ]),
                        '', 'MultiClientPreviewManager'
                    );
                }
            }
            return [];
        }

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
