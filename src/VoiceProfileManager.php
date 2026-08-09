<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — VoiceProfileManager
 *
 * Empreinte vocale de la marque, par langue : mots bannis, mots
 * préférés, notes de ton libre. Sert à repérer les incohérences
 * éditoriales dans les traductions déjà enregistrées ou en cours
 * de saisie — par simple recherche de texte (aucune dépendance IA
 * externe, aucun coût, aucune latence).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VoiceProfileManager
{
    const TABLE = 'neria_voice_profile';

    private \Db    $db;
    private int    $idShop;
    private object $module;

    public function __construct(object $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    /**
     * Retourne le profil vocal brut d'une langue (chaînes multi-lignes
     * telles que saisies) — tableau vide si jamais configuré.
     */
    public function getProfile(string $lang): array
    {
        $lang = $this->sanitizeLang($lang);
        $row  = $this->db->getRow(
            'SELECT `banned_words`, `preferred_words`, `tone_notes`
             FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_shop` = ' . $this->idShop . " AND `lang` = '" . pSQL($lang) . "'"
        );

        if (!$row) {
            return ['banned_words' => '', 'preferred_words' => '', 'tone_notes' => ''];
        }

        return [
            'banned_words'    => (string) $row['banned_words'],
            'preferred_words' => (string) $row['preferred_words'],
            'tone_notes'      => (string) $row['tone_notes'],
        ];
    }

    /**
     * Sauvegarde le profil vocal d'une langue (upsert).
     */
    public function saveProfile(string $lang, string $bannedWords, string $preferredWords, string $toneNotes): bool
    {
        $lang = $this->sanitizeLang($lang);
        if ($lang === '') {
            return false;
        }

        // Round 135 : normalise (déduplique + plafonne) la liste avant
        // écriture — sans ça, aucune borne n'existait contrairement aux
        // autres formulaires du module. Une liste massive ou dupliquée
        // gonfle inutilement le coût O(n) de textContainsWords()/
        // auditTranslations() (des milliers d'entrées de traduction
        // scannées × mots bannis) sans apporter de détection supplémentaire.
        $bannedWords    = $this->normalizeWordListInput($bannedWords);
        $preferredWords = $this->normalizeWordListInput($preferredWords);

        return (bool) $this->db->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`
             (`id_shop`, `lang`, `banned_words`, `preferred_words`, `tone_notes`, `date_upd`)
             VALUES (' . $this->idShop . ", '" . pSQL($lang) . "', '" . pSQL($bannedWords, true) . "',
                     '" . pSQL($preferredWords, true) . "', '" . pSQL($toneNotes, true) . "', NOW())
             ON DUPLICATE KEY UPDATE
               `banned_words`    = VALUES(`banned_words`),
               `preferred_words` = VALUES(`preferred_words`),
               `tone_notes`      = VALUES(`tone_notes`),
               `date_upd`        = NOW()"
        );
    }

    /**
     * Déduplique (insensible à la casse) et plafonne une liste "un mot par
     * ligne" venant du formulaire BO, avant écriture en base — voir
     * saveProfile(). Plafond généreux (500 entrées) : largement suffisant
     * pour un usage éditorial réel, protège seulement contre un collage
     * accidentel massif ou une liste jamais nettoyée.
     */
    private function normalizeWordListInput(string $raw): string
    {
        $words = $this->parseWordList($raw);
        $seen  = [];
        $out   = [];
        foreach ($words as $word) {
            $key = mb_strtolower($word);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $word;
            if (count($out) >= 500) {
                break;
            }
        }
        return implode("\n", $out);
    }

    /**
     * Découpe une liste "un mot/expression par ligne" en tableau, en
     * ignorant les lignes vides.
     */
    public function parseWordList(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $words = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $words[] = $line;
            }
        }
        return $words;
    }

    /**
     * Retourne la liste des mots bannis configurés pour une langue
     * (déjà parsée), pratique pour vérifier plusieurs textes de suite
     * sans relire le profil à chaque fois.
     */
    public function getBannedWords(string $lang): array
    {
        return $this->parseWordList($this->getProfile($lang)['banned_words']);
    }

    /**
     * Recherche, parmi une liste de mots/expressions donnée, ceux
     * présents dans un texte — insensible à la casse, sur des limites
     * de mots compatibles Unicode (accents français, etc.), pas de
     * simple `\b` qui se comporte mal hors ASCII. Ignore les balises
     * HTML du texte source. Retourne les mots trouvés (dédupliqués,
     * dans l'ordre de la liste fournie).
     */
    public static function textContainsWords(string $text, array $words): array
    {
        if (empty($words)) {
            return [];
        }
        $plainText = trim(strip_tags($text));
        if ($plainText === '') {
            return [];
        }

        $found = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }

            // CJK (chinois/japonais/coréen) : pas de séparateur entre les
            // mots d'une phrase, donc un mot banni est presque toujours
            // directement collé à d'autres idéogrammes (eux aussi \p{L}) —
            // les frontières (?<![\p{L}\p{N}])/(?![\p{L}\p{N}]) échouent
            // alors systématiquement et le mot n'est jamais détecté,
            // silencieusement. Correspondance directe par sous-chaîne pour
            // ces scripts, sans notion de frontière de mot.
            if (preg_match('/\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}/u', $word)) {
                // Round 135 : un mot CJK d'un seul caractère (idéogramme
                // isolé) génère en pratique des faux positifs massifs — la
                // sous-chaîne matche n'importe quel mot de plusieurs
                // caractères qui le contient, sans rapport sémantique (ex.
                // bannir « 日 » déclenche une alerte sur « 明日 »/« 日本 »).
                // À partir de 2 caractères, un mot/expression CJK devient
                // suffisamment spécifique pour que la correspondance par
                // sous-chaîne reste exploitable — on ignore silencieusement
                // les entrées d'un seul caractère plutôt que de noyer le
                // marchand sous des alertes inexploitables.
                if (mb_strlen($word) < 2) {
                    continue;
                }
                if (mb_stripos($plainText, $word) !== false) {
                    $found[] = $word;
                }
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/ui';
            if (@preg_match($pattern, $plainText) === 1) {
                $found[] = $word;
            }
        }

        return $found;
    }

    /**
     * Audite TOUTES les traductions déjà enregistrées pour une langue par
     * rapport au profil vocal courant (mots bannis + usage des mots
     * préférés). Ne modifie rien — purement informatif.
     *
     * @return array{
     *   findings: list<array{template:string, key:string, words:list<string>}>,
     *   templates_scanned: int,
     *   entries_scanned: int,
     *   preferred_word_hits: array<string,int>
     * }
     */
    public function auditTranslations(string $lang): array
    {
        $lang    = $this->sanitizeLang($lang);
        $profile = $this->getProfile($lang);
        $banned    = $this->parseWordList($profile['banned_words']);
        $preferred = $this->parseWordList($profile['preferred_words']);

        $result = [
            'findings'            => [],
            'templates_scanned'   => 0,
            'entries_scanned'     => 0,
            'preferred_word_hits' => [],
        ];

        if (empty($banned) && empty($preferred)) {
            return $result;
        }

        $rows = $this->db->executeS(
            "SELECT `template`, `translation_key`, `translation_value`
             FROM `" . _DB_PREFIX_ . "neria_translation`
             WHERE `lang` = '" . pSQL($lang) . "'
               AND `translation_value` != ''"
        );
        if (!is_array($rows)) {
            return $result;
        }

        $templatesSeen = [];
        foreach ($rows as $row) {
            $templatesSeen[$row['template']] = true;
            $result['entries_scanned']++;

            if (!empty($banned)) {
                $hits = self::textContainsWords((string) $row['translation_value'], $banned);
                if ($hits) {
                    $result['findings'][] = [
                        'template' => $row['template'],
                        'key'      => $row['translation_key'],
                        'words'    => $hits,
                    ];
                }
            }

            if (!empty($preferred)) {
                $hits = self::textContainsWords((string) $row['translation_value'], $preferred);
                foreach ($hits as $word) {
                    $result['preferred_word_hits'][$word] = ($result['preferred_word_hits'][$word] ?? 0) + 1;
                }
            }
        }

        $result['templates_scanned'] = count($templatesSeen);

        return $result;
    }

    private function sanitizeLang(string $lang): string
    {
        // Force la casse en sortie (le filtre /i n'agit qu'en entrée) — un
        // appelant passant 'FR' obtenait sinon 'FR' non normalisé, cassant
        // silencieusement les comparaisons strictes ailleurs dans le module
        // (TranslationEngine::SUPPORTED_LANGS toujours en minuscule).
        return mb_strtolower((string) preg_replace('/[^a-z]/i', '', $lang));
    }
}
