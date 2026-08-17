<?php
/**
 * Régression : EmailRenderer::resolveSignature() doit ajouter un paramètre
 * de cache-busting (?v=filemtime) à l'URL de l'image de signature.
 *
 * Bug réel corrigé le 09/08/2026 (round 145) : buildFilename() produit un
 * nom de fichier déterministe (signature_{idShop}_{style}.png), sans hash
 * de contenu. Régénérer avec le MÊME style mais une couleur/un nom
 * différents écrase le fichier sur disque à une URL strictement
 * identique — le cache navigateur/client email (proxy d'images Gmail,
 * Outlook…) continuait d'afficher l'ancienne version tant que son cache
 * HTTP n'expirait pas, malgré un changement réel côté marchand.
 *
 * Test comportemental réel : pose une ligne neria_signature active pointant
 * vers un fichier réel sur disque, appelle resolveSignature() (via
 * Reflection, privée) et vérifie que l'URL renvoyée contient bien
 * "?v=<mtime du fichier>". Modifie ensuite le fichier (touch, mtime
 * différent) et vérifie que l'URL change en conséquence.
 *
 * clearstatcache() (round 181) : nécessaire ici car ce test appelle
 * resolveSignature() deux fois dans le MÊME process PHP après un touch()
 * intermédiaire — sans elle, le cache de stat interne de PHP (filemtime())
 * renvoie l'ancien mtime au 2e appel, un faux échec du test. Scénario
 * artificiel au test uniquement : en production chaque requête PrestaShop
 * est un process PHP neuf, sans cache de stat persistant entre deux
 * régénérations réelles de la signature — le code source n'a pas besoin
 * de clearstatcache().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = 999995;

    $relPath  = 'data/signatures/signature_' . $idShop . '_great_vibes.png';
    $fullPath = _PS_MODULE_DIR_ . 'neria/' . $relPath;

    @mkdir(dirname($fullPath), 0755, true);
    file_put_contents($fullPath, 'fake-png-content-round145');
    touch($fullPath, 1700000000); // mtime connu et contrôlé

    $db->execute("DELETE FROM {$prefix}neria_signature WHERE id_shop = {$idShop}");
    $db->execute(
        "INSERT INTO {$prefix}neria_signature (id_shop, signer_name, signer_title, font_style, color, image_path, is_active, date_add, date_upd)
         VALUES ({$idShop}, 'Regtest', 'Testeur', 'great_vibes', '#b38b59', '" . pSQL($relPath) . "', 1, NOW(), NOW())"
    );

    try {
        $renderer = new EmailRenderer(neria_test_module());
        $ref = new ReflectionMethod(EmailRenderer::class, 'resolveSignature');
        $ref->setAccessible(true);

        $result = $ref->invoke($renderer, $idShop);
        neria_assert(is_array($result) && !empty($result['url']), "resolveSignature() n'a pas renvoyé d'URL — jeu de test invalide");

        neria_assert(
            strpos($result['url'], '?v=1700000000') !== false,
            "l'URL de signature ('{$result['url']}') ne contient pas '?v=1700000000' (mtime du fichier) — régression du bug corrigé le 09/08/2026 (round 145) : le cache navigateur/client email afficherait de nouveau une version périmée après régénération"
        );

        // Change le mtime (simule une régénération) — l'URL doit suivre
        touch($fullPath, 1800000000);
        clearstatcache(true, $fullPath);
        $result2 = $ref->invoke($renderer, $idShop);
        neria_assert(
            strpos($result2['url'], '?v=1800000000') !== false,
            "l'URL ne reflète pas le nouveau mtime après modification du fichier — le cache-busting ne suivrait pas une régénération réelle"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_signature WHERE id_shop = {$idShop}");
        @unlink($fullPath);
    }

    return [
        'pass'    => true,
        'message' => "EmailRenderer::resolveSignature() ajoute bien un paramètre de cache-busting (?v=mtime) à l'URL de signature, qui suit toute régénération réelle du fichier",
    ];
}
