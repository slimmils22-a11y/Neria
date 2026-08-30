<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — SignatureGenerator
 *
 * Generateur de signatures manuscrites pour les emails.
 * Cree une image PNG de signature a partir du nom du fondateur,
 * avec un style calligraphique elegant adapte au positionnement luxe.
 *
 * Fonctionnement :
 * 1. Charge une police TTF manuscrite selon le style choisi
 * 2. Cree une image transparente avec GD
 * 3. Rend le texte avec effets (ombre, inclinaison subtile)
 * 4. Ajoute un paraphe decoratif sous la signature
 * 5. Sauvegarde en PNG dans data/signatures/
 *
 * Polices incluses (libres de droits, licence OFL) :
 * - Dancing Script  : elegante, cursive classique
 * - Great Vibes     : raffinee, style haute couture
 * - Sacramento      : fine et delicate
 * - Pinyon Script   : majestueuse et formelle
 * - Pacifico        : moderne et accessible
 *
 * Prerequis serveur : extension PHP GD (standard sur tous les hosts)
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SignatureGenerator
{
    // ============================================================
    // CONSTANTES
    // ============================================================

    /** Largeur de l'image generee en pixels */
    const IMAGE_WIDTH  = 400;

    /** Hauteur de l'image generee en pixels */
    const IMAGE_HEIGHT = 120;

    /** Taille de la police de signature en points */
    const FONT_SIZE_SIGNATURE = 48;

    /** Taille de la police du titre en points */
    const FONT_SIZE_TITLE = 14;

    /** Styles de signature disponibles */
    const STYLES = [
        'great_vibes'   => 'Great Vibes — Haute couture',
        'dancing_script'=> 'Dancing Script — Classique elegant',
        'sacramento'    => 'Sacramento — Fin et delicat',
        'pinyon_script' => 'Pinyon Script — Majestueux',
        'pacifico'      => 'Pacifico — Moderne',
    ];

    /** Dossier des polices TTF (relatif a la racine du module) */
    const FONTS_DIR = 'data/fonts';

    /** Dossier de sauvegarde des signatures */
    const SIGNATURES_DIR = 'data/signatures';

    // ============================================================
    // PROPRIETES
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var string Chemin absolu vers le dossier des polices */
    private string $fontsPath;

    /** @var string Chemin absolu vers le dossier des signatures */
    private string $signaturesPath;

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    /** @var WatchdogManager|null Instance paresseuse du watchdog */
    private ?WatchdogManager $watchdog = null;

    public function __construct(Neria $module)
    {
        $this->module         = $module;
        $this->fontsPath      = $module->getModulePath(self::FONTS_DIR);
        $this->signaturesPath = $module->getModulePath(self::SIGNATURES_DIR);
    }

    private function watchdog(): WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // GENERATION PRINCIPALE
    // ============================================================

    /**
     * Genere une image de signature et la sauvegarde sur le disque
     *
     * @param string $name      Nom du signataire (ex: "Marie Dupont")
     * @param string $title     Titre (ex: "Fondatrice & Directrice Artistique")
     * @param string $style     Cle du style (ex: 'great_vibes')
     * @param string $color     Couleur hexadecimale (ex: '#b38b59')
     * @param int    $idShop    ID de la boutique
     * @param string|null $resolvedStyle Round 145 : rempli par référence
     *  avec le style RÉELLEMENT utilisé pour le rendu (peut différer de
     *  $style si sa police TTF était absente du disque — voir getFontPath()).
     *  Le nom de fichier ET la colonne BDD font_style doivent refléter ce
     *  style réel, pas celui demandé, sous peine de métadonnée mensongère.
     * @return string|false     Chemin relatif de l'image ou false si erreur
     */
    public function generate(
        string $name,
        string $title  = '',
        string $style  = 'great_vibes',
        string $color  = '#b38b59',
        int    $idShop = 1,
        ?string &$resolvedStyle = null
    ) {
        // Verifie que GD est disponible
        if (!$this->isGdAvailable()) {
            $this->module->log(
                'SignatureGenerator: extension GD indisponible',
                2
            );
            $this->watchdog()->critical(WatchdogManager::i18nMsg('watchdog.signature_gd_missing'), '', 'SignatureGenerator');
            return false;
        }

        // Verifie que le nom n'est pas vide
        $name = trim($name);
        if (empty($name)) {
            return false;
        }

        // Charge la police TTF
        $fontPath = $this->getFontPath($style, $resolvedStyle);
        if (!$fontPath) {
            $this->module->log(
                "SignatureGenerator: police [{$style}] introuvable",
                2
            );
            $this->watchdog()->error(WatchdogManager::i18nMsg('watchdog.signature_font_missing', ['style' => $style]), '', 'SignatureGenerator');
            return false;
        }

        // Cree l'image
        $image = $this->createImage($name, $title, $fontPath, $color);
        if (!$image) {
            return false;
        }

        // Sauvegarde — nom de fichier basé sur le style RÉELLEMENT rendu
        // ($resolvedStyle), pas celui demandé ($style) : voir docblock.
        $filename = $this->buildFilename($idShop, $resolvedStyle ?? $style);
        $fullPath = $this->signaturesPath . '/' . $filename;

        $this->ensureDirectoryExists($this->signaturesPath);

        $saved = imagepng($image, $fullPath, 9);
        imagedestroy($image);

        if (!$saved) {
            $this->module->log(
                "SignatureGenerator: echec sauvegarde [{$fullPath}]",
                3
            );
            // Round 160 : asymétrie avec les 2 autres branches d'échec de
            // cette méthode (GD indisponible, police introuvable), qui
            // alertent déjà le Watchdog — un échec imagepng() (disque
            // plein, permissions) restait invisible du monitoring, visible
            // uniquement en creusant le log fichier PrestaShop brut.
            $this->watchdog()->error(WatchdogManager::i18nMsg('watchdog.signature_save_failed', ['path' => $fullPath]), '', 'SignatureGenerator');
            return false;
        }

        $relativePath = self::SIGNATURES_DIR . '/' . $filename;

        $this->module->log(
            "SignatureGenerator: signature generee [{$relativePath}]",
            1
        );

        return $relativePath;
    }

    // ============================================================
    // CREATION DE L'IMAGE
    // ============================================================

    /**
     * Cree l'image GD de la signature
     *
     * @param string $name     Nom du signataire
     * @param string $title    Titre du signataire
     * @param string $fontPath Chemin absolu vers la police TTF
     * @param string $color    Couleur hexadecimale
     * @return resource|\GdImage|false
     */
    private function createImage(
        string $name,
        string $title,
        string $fontPath,
        string $color
    ) {
        // Parse la couleur accent
        $rgb = $this->hexToRgb($color);

        // Calcule la taille reelle du texte pour adapter la largeur
        $bbox = imagettfbbox(self::FONT_SIZE_SIGNATURE, 0, $fontPath, $name);
        if ($bbox === false) {
            // Round 208 : imagettfbbox() renvoie false sur une police TTF
            // corrompue/tronquée (déploiement interrompu, fichier altéré)
            // même quand file_exists() (déjà vérifié dans getFontPath())
            // est positif — sans ce garde-fou, $bbox[4]/$bbox[0] plus bas
            // déclenchaient un accès sur tableau booléen (warning PHP,
            // valeurs nulles), produisant une signature mal centrée voire
            // hors cadre SANS aucune trace Watchdog, contrairement aux 3
            // autres branches d'échec de generate() (GD manquant, police
            // introuvable, échec de sauvegarde — round 160) qui, elles,
            // alertent déjà systématiquement.
            $this->module->log("SignatureGenerator: police TTF corrompue [{$fontPath}]", 3);
            $this->watchdog()->error(WatchdogManager::i18nMsg('watchdog.signature_font_corrupted', ['path' => $fontPath]), '', 'SignatureGenerator');
            return false;
        }
        $textW   = abs($bbox[4] - $bbox[0]) + 60; // marge laterale
        $width   = max(self::IMAGE_WIDTH, $textW);
        $height  = self::IMAGE_HEIGHT + (!empty($title) ? 30 : 0);

        // Cree l'image avec fond transparent
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        // Fond transparent
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        imagealphablending($image, true);

        // Couleur de la signature
        $signColor = imagecolorallocate(
            $image,
            $rgb['r'],
            $rgb['g'],
            $rgb['b']
        );

        // Couleur de l'ombre (meme teinte, plus claire)
        $shadowColor = imagecolorallocatealpha(
            $image,
            $rgb['r'],
            $rgb['g'],
            $rgb['b'],
            90
        );

        // Position Y de la signature (centree verticalement)
        $signatureY = (int) (self::IMAGE_HEIGHT * 0.70);

        // Position X (centree)
        $bboxFinal = imagettfbbox(self::FONT_SIZE_SIGNATURE, 0, $fontPath, $name);
        $textWidth = abs($bboxFinal[4] - $bboxFinal[0]);
        $signatureX = (int) (($width - $textWidth) / 2);

        // Ombre portee (decalee de 2px)
        imagettftext(
            $image,
            self::FONT_SIZE_SIGNATURE,
            0,              // angle
            $signatureX + 2,
            $signatureY + 2,
            $shadowColor,
            $fontPath,
            $name
        );

        // Texte principal de la signature
        imagettftext(
            $image,
            self::FONT_SIZE_SIGNATURE,
            0,
            $signatureX,
            $signatureY,
            $signColor,
            $fontPath,
            $name
        );

        // Paraphe decoratif sous la signature
        $this->drawParaphe($image, $signatureX, $signatureY, $textWidth, $signColor);

        // Titre du signataire si fourni
        if (!empty($title)) {
            $this->drawTitle($image, $title, $width, $signatureY + 20, $signColor);
        }

        return $image;
    }

    /**
     * Dessine le paraphe decoratif sous la signature
     * Ligne courbe elegante inspiree des signatures de couturiers
     *
     * @param resource $image      Image GD
     * @param int      $x          Position X de debut du nom
     * @param int      $y          Position Y de la ligne de base
     * @param int      $textWidth  Largeur du texte de signature
     * @param int      $color      Couleur GD allouee
     */
    private function drawParaphe(
        $image,
        int $x,
        int $y,
        int $textWidth,
        int $color
    ): void {
        $startX = $x - 10;
        $endX   = $x + $textWidth + 20;
        $lineY  = $y + 8;

        // Ligne principale du paraphe
        imagesetthickness($image, 1);
        imageline($image, $startX, $lineY, $endX, $lineY, $color);

        // Petit trait final vers le bas (flourish)
        imageline(
            $image,
            $endX - 20,
            $lineY,
            $endX + 10,
            $lineY + 8,
            $color
        );

        // Petit trait de debut (flourish gauche)
        imageline(
            $image,
            $startX,
            $lineY,
            $startX - 15,
            $lineY - 6,
            $color
        );
    }

    /**
     * Dessine le titre sous le paraphe avec une police plus petite
     *
     * @param resource $image   Image GD
     * @param string   $title   Texte du titre
     * @param int      $width   Largeur totale de l'image
     * @param int      $y       Position Y de base
     * @param int      $color   Couleur GD
     */
    private function drawTitle(
        $image,
        string $title,
        int    $width,
        int    $y,
        int    $color
    ): void {
        // Police systeme pour le titre (plus lisible a petite taille)
        // On utilise une fonte embarquee simple si disponible
        $titleFontPath = $this->getSystemFontPath();

        if ($titleFontPath) {
            $bbox      = imagettfbbox(self::FONT_SIZE_TITLE, 0, $titleFontPath, $title);
            $textWidth = abs($bbox[4] - $bbox[0]);
            $titleX    = (int) (($width - $textWidth) / 2);
            $titleY    = $y + 28;

            // Couleur du titre : meme teinte mais plus claire (alpha) — dérivée
            // du VRAI index couleur $color (imagecolorsforindex), pas du pixel
            // (0,0) qui est transparent et ne reflète jamais la teinte réelle.
            $rgb = imagecolorsforindex($image, $color);
            $titleColor = imagecolorallocatealpha(
                $image,
                $rgb['red'],
                $rgb['green'],
                $rgb['blue'],
                40
            );

            imagettftext(
                $image,
                self::FONT_SIZE_TITLE,
                0,
                $titleX,
                $titleY,
                $titleColor,
                $titleFontPath,
                $title
            );
        } else {
            // Fallback : fonte systeme integree GD (moins elegante)
            $titleX = (int) (($width - strlen($title) * 6) / 2);
            imagestring($image, 2, $titleX, $y + 20, $title, $color);
        }
    }

    // ============================================================
    // GESTION DES POLICES
    // ============================================================

    /**
     * Retourne le chemin absolu de la police TTF pour un style donne
     *
     * @param string $style Cle du style
     * @return string|null Chemin absolu ou null si introuvable
     */
    /**
     * Round 145 : $resolvedStyle (référence) porte désormais le style
     * RÉELLEMENT utilisé pour le rendu — auparavant, generate() construisait
     * le nom de fichier et la colonne BDD font_style avec le style DEMANDÉ
     * ($style tel que soumis), même quand getFontPath() retombait
     * silencieusement sur un autre style faute de fichier TTF installé
     * (installation de polices partielle, cas géré par checkFonts()). Le
     * marchand voyait alors une signature enregistrée sous un style qu'elle
     * ne montre pas réellement — métadonnée mensongère, incohérence visuelle
     * entre la config affichée et le rendu envoyé aux clients.
     */
    private function getFontPath(string $style, ?string &$resolvedStyle = null): ?string
    {
        $fontFiles = [
            'great_vibes'    => 'GreatVibes-Regular.ttf',
            'dancing_script' => 'DancingScript-Regular.ttf',
            'sacramento'     => 'Sacramento-Regular.ttf',
            'pinyon_script'  => 'PinyonScript-Regular.ttf',
            'pacifico'       => 'Pacifico-Regular.ttf',
        ];

        if (!isset($fontFiles[$style])) {
            // Style inconnu : utilise great_vibes par defaut
            $style = 'great_vibes';
        }

        $path = $this->fontsPath . '/' . $fontFiles[$style];

        if (file_exists($path)) {
            $resolvedStyle = $style;
            return $path;
        }

        // Essaie le premier style disponible comme fallback
        foreach ($fontFiles as $key => $file) {
            $fallback = $this->fontsPath . '/' . $file;
            if (file_exists($fallback)) {
                $this->module->log(
                    "SignatureGenerator: [{$style}] introuvable, "
                    . "fallback vers [{$key}]",
                    2
                );
                $resolvedStyle = $key;
                return $fallback;
            }
        }

        return null;
    }

    /**
     * Retourne le chemin d'une police systeme pour les petits textes
     * Cherche une fonte standard disponible sur le serveur
     *
     * @return string|null
     */
    private function getSystemFontPath(): ?string
    {
        $candidates = [
            // Linux
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            // macOS
            '/Library/Fonts/Arial.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
            // Windows
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/calibri.ttf',
            // Module (fonte embarquee de secours)
            $this->fontsPath . '/DancingScript-Regular.ttf',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    // ============================================================
    // APERCU BACK-OFFICE
    // ============================================================

    /**
     * Genere une signature de preview pour le back-office
     * Retourne le contenu base64 de l'image pour affichage inline
     * sans sauvegarder sur le disque
     *
     * @param string $name  Nom du signataire
     * @param string $title Titre
     * @param string $style Style de police
     * @param string $color Couleur hexadecimale
     * @return string|false Data URI base64 ou false
     */
    public function generatePreview(
        string $name,
        string $title  = '',
        string $style  = 'great_vibes',
        string $color  = '#b38b59'
    ) {
        if (!$this->isGdAvailable()) {
            return false;
        }

        $fontPath = $this->getFontPath($style);
        if (!$fontPath) {
            return false;
        }

        $image = $this->createImage($name, $title, $fontPath, $color);
        if (!$image) {
            return false;
        }

        // Capture en buffer memoire
        ob_start();
        imagepng($image, null, 9);
        $imageData = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($imageData);
    }

    // ============================================================
    // GESTION DES SIGNATURES EXISTANTES
    // ============================================================

    /**
     * Supprime l'image de signature d'une boutique
     *
     * @param int    $idShop      ID boutique
     * @param string $style       Style (pour construire le nom du fichier)
     * @param string $excludePath Round 160 : chemin à NE PAS supprimer (le
     *                            fichier fraîchement généré, quand delete()
     *                            est appelée après generate() pour nettoyer
     *                            les anciens styles) — évite de se
     *                            supprimer lui-même si l'ancien et le
     *                            nouveau style partagent le même nom.
     * @return bool
     */
    public function delete(int $idShop, string $style = '', string $excludePath = ''): bool
    {
        if ($style) {
            $path = $this->signaturesPath . '/' . $this->buildFilename($idShop, $style);
            if ($excludePath !== '' && realpath($path) === realpath($excludePath)) {
                return true;
            }
            if (file_exists($path)) {
                return @unlink($path);
            }
            return true;
        }

        // Supprime toutes les signatures de cette boutique
        // Round 244 : @ -- fichier potentiellement déjà supprimé entre le
        // glob() et cet unlink() (résiduel même sous le GET_LOCK ajouté
        // côté appelant : un nettoyage disque externe, ou un appel direct à
        // delete() hors du chemin verrouillé de neria.php, reste possible).
        $pattern = $this->signaturesPath . "/signature_{$idShop}_*.png";
        $excludeReal = $excludePath !== '' ? realpath($excludePath) : false;
        foreach (glob($pattern) ?: [] as $file) {
            if ($excludeReal !== false && realpath($file) === $excludeReal) {
                continue;
            }
            @unlink($file);
        }

        return true;
    }

    /**
     * Retourne la liste des signatures existantes pour une boutique
     *
     * @param int $idShop ID boutique
     * @return array [['style' => '...', 'path' => '...', 'url' => '...'], ...]
     */
    public function getExistingSignatures(int $idShop): array
    {
        $pattern    = $this->signaturesPath . "/signature_{$idShop}_*.png";
        $signatures = [];

        foreach (glob($pattern) ?: [] as $file) {
            $filename = basename($file);
            // Le style peut lui-meme contenir des underscores (ex: "great_vibes",
            // "dancing_script", "pinyon_script") : un simple explode('_') coupe
            // le style en plusieurs morceaux et ne recupere que le premier
            // fragment ("great" au lieu de "great_vibes"). On retire uniquement
            // le prefixe "signature_{idShop}_" connu et l'extension .png pour
            // isoler le style complet.
            $prefix = "signature_{$idShop}_";
            $base   = str_replace('.png', '', $filename);
            $style  = str_starts_with($base, $prefix) ? substr($base, strlen($prefix)) : 'unknown';
            if ($style === '') {
                $style = 'unknown';
            }

            $signatures[] = [
                'style'    => $style,
                'filename' => $filename,
                'path'     => self::SIGNATURES_DIR . '/' . $filename,
                'url'      => $this->module->getModuleUrl(
                    self::SIGNATURES_DIR . '/' . $filename
                ),
                'size'     => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        return $signatures;
    }

    // ============================================================
    // INSTALLATION DES POLICES
    // ============================================================

    /**
     * Verifie si les polices TTF sont installees dans data/fonts/
     * Affiche un avertissement dans le back-office si manquantes
     *
     * @return array ['installed' => [...], 'missing' => [...]]
     */
    public function checkFonts(): array
    {
        $fontFiles = [
            'great_vibes'    => 'GreatVibes-Regular.ttf',
            'dancing_script' => 'DancingScript-Regular.ttf',
            'sacramento'     => 'Sacramento-Regular.ttf',
            'pinyon_script'  => 'PinyonScript-Regular.ttf',
            'pacifico'       => 'Pacifico-Regular.ttf',
        ];

        $installed = [];
        $missing   = [];

        foreach ($fontFiles as $style => $file) {
            $path = $this->fontsPath . '/' . $file;
            if (file_exists($path)) {
                $installed[$style] = $file;
            } else {
                $missing[$style] = $file;
            }
        }

        return [
            'installed'    => $installed,
            'missing'      => $missing,
            'fonts_dir'    => $this->fontsPath,
            'gd_available' => $this->isGdAvailable(),
        ];
    }

    /**
     * Retourne les URLs de telechargement des polices manquantes
     * Affiche dans le back-office pour guider le marchand
     *
     * @return array ['style' => ['name' => '...', 'url' => '...'], ...]
     */
    public function getFontDownloadUrls(): array
    {
        return [
            'great_vibes'    => [
                'name' => 'Great Vibes',
                'url'  => 'https://fonts.google.com/specimen/Great+Vibes',
                'file' => 'GreatVibes-Regular.ttf',
            ],
            'dancing_script' => [
                'name' => 'Dancing Script',
                'url'  => 'https://fonts.google.com/specimen/Dancing+Script',
                'file' => 'DancingScript-Regular.ttf',
            ],
            'sacramento'     => [
                'name' => 'Sacramento',
                'url'  => 'https://fonts.google.com/specimen/Sacramento',
                'file' => 'Sacramento-Regular.ttf',
            ],
            'pinyon_script'  => [
                'name' => 'Pinyon Script',
                'url'  => 'https://fonts.google.com/specimen/Pinyon+Script',
                'file' => 'PinyonScript-Regular.ttf',
            ],
            'pacifico'       => [
                'name' => 'Pacifico',
                'url'  => 'https://fonts.google.com/specimen/Pacifico',
                'file' => 'Pacifico-Regular.ttf',
            ],
        ];
    }

    // ============================================================
    // UTILITAIRES PRIVES
    // ============================================================

    /**
     * Verifie que l'extension GD est disponible et fonctionnelle
     *
     * @return bool
     */
    private function isGdAvailable(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagettftext');
    }

    /**
     * Convertit une couleur hexadecimale en composantes RGB
     *
     * @param string $hex Couleur hex (ex: '#b38b59' ou 'b38b59')
     * @return array ['r' => int, 'g' => int, 'b' => int]
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        // Normalise le format court (#abc → #aabbcc)
        if (strlen($hex) === 3) {
            $hex = str_repeat($hex[0], 2)
                 . str_repeat($hex[1], 2)
                 . str_repeat($hex[2], 2);
        }

        // Round 208 : les 2 seuls appelants réels (neria.php) passent déjà
        // $color par NeriaTools::sanitizeColor() avant generate(), donc ce
        // filet n'est jamais exercé aujourd'hui — mais contrairement à
        // ConfigManager (qui utilise systématiquement sanitizeColor() avec
        // un défaut de marque), rien ici n'empêche un futur appelant direct
        // de cette classe de passer une valeur hors format. hexdec() sur
        // une chaîne trop courte/non-hex ignore silencieusement les
        // caractères invalides et produit une teinte imprévisible plutôt
        // que la couleur de marque attendue (#b38b59).
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            $hex = 'b38b59';
        }

        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Construit le nom de fichier pour une signature
     *
     * @param int    $idShop ID boutique
     * @param string $style  Style de police
     * @return string Nom du fichier (ex: signature_1_great_vibes.png)
     */
    private function buildFilename(int $idShop, string $style): string
    {
        return sprintf(
            'signature_%d_%s.png',
            $idShop,
            preg_replace('/[^a-z0-9_]/', '', strtolower($style))
        );
    }

    /**
     * Cree le dossier de destination s'il n'existe pas
     *
     * @param string $path Chemin absolu du dossier
     */
    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            // Round 244 : @ + revérification is_dir() -- sans le GET_LOCK
            // par boutique ajouté côté appelant (neria.php), deux requêtes
            // concurrentes passant is_dir() à false simultanément faisaient
            // échouer le mkdir() de la seconde avec un warning PHP non
            // suppressé ("File exists"). Le verrou ferme la fenêtre de
            // course réelle ; @ + revérification reste une défense en
            // profondeur bon marché (ex: dossier créé entre-temps par un
            // autre process hors du périmètre du verrou).
            @mkdir($path, 0755, true);
            if (!is_dir($path)) {
                return;
            }

            // Securite : ajoute un index.php vide
            $indexFile = $path . '/index.php';
            if (!file_exists($indexFile)) {
                file_put_contents($indexFile, '<?php' . PHP_EOL . 'header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");' . PHP_EOL . 'header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");' . PHP_EOL . 'header("Cache-Control: no-store, no-cache, must-revalidate");' . PHP_EOL . 'header("Cache-Control: post-check=0, pre-check=0", false);' . PHP_EOL . 'header("Pragma: no-cache");' . PHP_EOL . 'header("Location: ../");' . PHP_EOL . 'exit;');
            }
        }
    }
}