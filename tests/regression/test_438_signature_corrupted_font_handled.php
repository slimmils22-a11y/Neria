<?php
/**
 * Régression : SignatureGenerator::createImage() ne vérifiait pas le
 * retour de imagettfbbox() — sur une police TTF corrompue/tronquée
 * (déploiement interrompu, fichier altéré), imagettfbbox() renvoie false
 * même quand file_exists() (déjà vérifié par getFontPath()) est positif.
 *
 * Bug réel identifié le 25/08/2026 (round 208) : sans garde, $bbox[4]/
 * $bbox[0] déclenchaient un accès sur tableau booléen (warning PHP),
 * produisant une signature mal centrée voire hors cadre SANS aucune trace
 * Watchdog — contrairement aux 3 autres branches d'échec de generate()
 * (GD manquant, police introuvable, échec de sauvegarde, round 160) qui,
 * elles, alertent déjà systématiquement.
 *
 * Corrigé le 25/08/2026 (round 208) : vérification explicite de
 * imagettfbbox() === false, retour false + alerte Watchdog.
 *
 * Test comportemental réel : écrit un fichier .ttf réellement corrompu
 * (octets arbitraires, pas une vraie police) dans le scratchpad, appelle
 * createImage() via réflexion avec ce chemin, et vérifie qu'elle retourne
 * false proprement (pas d'exception/warning fatal) tout en journalisant
 * l'échec via Watchdog.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SignatureGenerator.php';

    $module = neria_test_module();
    $gen = new SignatureGenerator($module);
    $ref = new ReflectionMethod('SignatureGenerator', 'createImage');
    $ref->setAccessible(true);

    $idShop = (int) Context::getContext()->shop->id;
    $markerMsg = 'ROUND208_CORRUPT_FONT_TEST';
    $tmpFont = sys_get_temp_dir() . '/neria_test_corrupt_' . uniqid() . '.ttf';
    file_put_contents($tmpFont, "PAS UNE POLICE TTF VALIDE\x00\x01\x02\xFF\xFE");

    $db = Db::getInstance();

    try {
        // imagettfbbox() sur ce fichier corrompu doit renvoyer false —
        // condition préalable du bug, pas juste une supposition.
        $realBbox = @imagettfbbox(48, 0, $tmpFont, 'Test');
        neria_assert(
            $realBbox === false,
            "imagettfbbox() ne renvoie pas false sur ce fichier corrompu — jeu de test invalide, GD ne reproduit pas les conditions du bug ici"
        );

        $result = $ref->invoke($gen, 'Test Round208', '', $tmpFont, '#b38b59');
        neria_assert(
            $result === false,
            "SignatureGenerator::createImage() ne retourne plus false proprement sur une police TTF corrompue — régression du bug corrigé le 25/08/2026 (round 208)"
        );

        // Vérifie qu'un log Watchdog réel a bien été posé pour ce chemin
        // précis (pas seulement un retour false silencieux).
        $count = (int) $db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_log`
             WHERE `class` = 'SignatureGenerator'
               AND `message` LIKE '%" . pSQL(basename($tmpFont)) . "%'
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        neria_assert(
            $count > 0,
            "SignatureGenerator::createImage() ne journalise plus l'échec via Watchdog sur une police corrompue — régression du bug corrigé le 25/08/2026 (round 208) : l'échec redeviendrait invisible du monitoring"
        );
    } finally {
        @unlink($tmpFont);
        $db->execute("DELETE FROM `" . _DB_PREFIX_ . "neria_log` WHERE `class` = 'SignatureGenerator' AND `message` LIKE '%" . pSQL(basename($tmpFont)) . "%'");
    }

    return [
        'pass'    => true,
        'message' => "SignatureGenerator::createImage() gère bien une police TTF corrompue (retour false + alerte Watchdog) au lieu d'un warning PHP silencieux — bug corrigé le 25/08/2026 (round 208)",
    ];
}
