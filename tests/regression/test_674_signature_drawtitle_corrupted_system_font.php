<?php
/**
 * Régression : `SignatureGenerator::drawTitle()` ne vérifiait pas le
 * retour de `imagettfbbox()` sur la police SYSTÈME du titre
 * (`getSystemFontPath()`) — même piège que `createImage()` (round 208,
 * police TTF du MODULE), jamais étendu ici. `imagettfbbox()` peut
 * renvoyer `false` sur une police système corrompue/tronquée même quand
 * le chemin a bien été trouvé par `getSystemFontPath()`, provoquant un
 * accès sur tableau booléen (`$bbox[4]`/`$bbox[0]`, warning PHP, largeur
 * incohérente) sans jamais retomber sur le fallback GD embarqué
 * (`imagestring()`) pourtant disponible juste en dessous dans le code.
 *
 * Bug identifié le 10/09/2026 (round 333, audit CryptoManager/
 * SignatureGenerator via ChecklistManager/AcademyProgressManager).
 *
 * Corrigé le 10/09/2026 (round 333) : `$bbox !== false` ajouté à la
 * condition d'entrée dans la branche police TTF — un `$bbox` faux fait
 * désormais retomber proprement sur le fallback GD.
 *
 * Test structurel (`getSystemFontPath()` résout une VRAIE police système
 * du poste, non falsifiable en conditions réelles sans corrompre un
 * binaire système — même limite acceptée pour d'autres correctifs de ce
 * fichier nécessitant une fixture lourde, cf. test_380/test_423) : vérifie
 * la présence du garde-fou `$bbox !== false` dans le corps de
 * `drawTitle()`, avant tout accès à `$bbox[4]`/`$bbox[0]`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SignatureGenerator.php');
    neria_assert($src !== false, 'Impossible de lire src/SignatureGenerator.php');

    $posFn = strpos($src, 'private function drawTitle(');
    neria_assert($posFn !== false, 'drawTitle() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1200);

    $posBbox = strpos($body, 'imagettfbbox(self::FONT_SIZE_TITLE');
    neria_assert($posBbox !== false, "L'appel à imagettfbbox() dans drawTitle() est introuvable — jeu de test invalide");

    // Le garde-fou doit apparaître AVANT tout accès $bbox[...] (donc après
    // l'appel imagettfbbox(), mais avant la ligne qui en fait usage).
    $posAccess = strpos($body, '$bbox[4]', $posBbox);
    $posGuard  = strpos($body, '$bbox !== false', $posBbox);
    neria_assert(
        $posGuard !== false && $posAccess !== false && $posGuard < $posAccess,
        "drawTitle() n'a plus de vérification \$bbox !== false AVANT l'accès à \$bbox[4]/\$bbox[0] — régression du bug corrigé le 10/09/2026 (round 333) : une police système corrompue provoquerait de nouveau un accès sur tableau booléen sans repli sur le fallback GD embarqué"
    );

    // Vérification comportementale complémentaire (chemin nominal, police
    // système réelle du poste ou absente) : drawTitle() ne doit jamais
    // lever d'exception ni de warning fatal, que la police système soit
    // trouvée ou non.
    require_once _PS_MODULE_DIR_ . 'neria/src/SignatureGenerator.php';
    $module = neria_test_module();
    $gen = new SignatureGenerator($module);
    $ref = new ReflectionMethod('SignatureGenerator', 'drawTitle');
    $ref->setAccessible(true);

    $image = imagecreatetruecolor(400, 150);
    imagesavealpha($image, true);
    $color = imagecolorallocate($image, 179, 139, 89);

    try {
        $ref->invoke($gen, $image, 'Titre de test', 400, 60, $color);
    } catch (\Throwable $e) {
        neria_assert(false, "drawTitle() a levé " . get_class($e) . " : " . $e->getMessage() . " — jeu de test invalide ou régression");
    } finally {
        imagedestroy($image);
    }

    return [
        'pass'    => true,
        'message' => "SignatureGenerator::drawTitle() vérifie désormais imagettfbbox() !== false avant d'accéder au tableau \$bbox, avec repli sur le fallback GD embarqué — bug corrigé le 10/09/2026 (round 333)",
    ];
}
