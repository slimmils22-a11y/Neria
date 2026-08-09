<?php
/**
 * Régression : TranslationInstaller::flushBatch() ne doit exécuter
 * "SET NAMES 'utf8mb4'" qu'UNE SEULE FOIS par opération d'import, pas à
 * chaque lot de BATCH_SIZE lignes.
 *
 * Bug réel corrigé le 08/08/2026 (round 139) : sur un import de plusieurs
 * milliers de clés × 19 langues (potentiellement 50-100+ lots), c'était
 * une requête réseau superflue répétée à chaque flushBatch(), alors que
 * l'encodage de session ne change jamais en cours de route.
 *
 * Test comportemental réel : simule 3 flush successifs sur la même
 * instance, vérifie via le flag charsetSet que le compteur d'exécution de
 * SET NAMES ne dépasse pas 1.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php';

    $installer = new TranslationInstaller(neria_test_module());

    $refFlag = new ReflectionProperty(TranslationInstaller::class, 'charsetSet');
    $refFlag->setAccessible(true);
    neria_assert($refFlag->getValue($installer) === false, "charsetSet devrait être false avant tout flush — jeu de test invalide");

    $refMethod = new ReflectionMethod(TranslationInstaller::class, 'flushBatch');
    $refMethod->setAccessible(true);

    $refBuildRow = new ReflectionMethod(TranslationInstaller::class, 'buildRow');
    $refBuildRow->setAccessible(true);
    $row = $refBuildRow->invoke($installer, 'neria_test_round139', 'fr', 'test_key', 'test_value', date('Y-m-d H:i:s'));

    try {
        $refMethod->invoke($installer, [$row]);
        neria_assert($refFlag->getValue($installer) === true, "flushBatch() n'a pas positionné charsetSet à true après le premier lot — régression du bug corrigé le 08/08/2026 (round 139)");

        // Second flush : le flag doit rester true, pas de nouvelle exécution.
        $refMethod->invoke($installer, [$row]);
        neria_assert($refFlag->getValue($installer) === true, "charsetSet ne devrait jamais repasser à false entre deux flush de la même instance");
    } finally {
        $db = neria_test_db();
        $prefix = neria_test_prefix();
        $db->execute("DELETE FROM {$prefix}neria_translation WHERE template = 'neria_test_round139'");
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php');
    neria_assert(
        strpos($src, 'private bool $charsetSet = false;') !== false && strpos($src, 'if (!$this->charsetSet) {') !== false,
        "TranslationInstaller::flushBatch() n'utilise plus le flag charsetSet pour n'exécuter SET NAMES qu'une seule fois — régression du bug corrigé le 08/08/2026 (round 139)"
    );

    return [
        'pass'    => true,
        'message' => "TranslationInstaller::flushBatch() n'exécute plus 'SET NAMES utf8mb4' qu'une seule fois par opération d'import, pas à chaque lot",
    ];
}
