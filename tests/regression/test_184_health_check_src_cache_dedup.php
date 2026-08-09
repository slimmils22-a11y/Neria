<?php
/**
 * Régression : HealthCheckManager::readModuleSrc() doit mettre en cache le
 * contenu lu par chemin de fichier, pour éviter les lectures redondantes de
 * checkKnownRegressionsGuard() (jusqu'à 9x le même fichier dans une seule
 * exécution avant correctif).
 *
 * Bug réel corrigé le 09/08/2026 (round 141) : ~150 sites d'appel
 * file_get_contents() sans cache, certains fichiers relus jusqu'à 9 fois
 * dans la même invocation — remplacés par un appel unique à
 * readModuleSrc(), qui met en cache par chemin de fichier ($srcCache).
 *
 * Test comportemental réel (via Reflection, la méthode est privée) : lit un
 * fichier temporaire une première fois via readModuleSrc(), modifie le
 * fichier sur disque, relit via readModuleSrc() — le second appel doit
 * renvoyer le contenu ORIGINAL mis en cache, pas le contenu modifié, ce qui
 * prouve qu'aucune seconde lecture disque n'a eu lieu.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $module = neria_test_module();
    $health = new HealthCheckManager($module);

    $tmpFile = sys_get_temp_dir() . '/neria_test_round141_srccache_' . uniqid() . '.php';
    file_put_contents($tmpFile, '<?php // version 1');

    $ref = new ReflectionMethod(HealthCheckManager::class, 'readModuleSrc');
    $ref->setAccessible(true);

    try {
        $first = $ref->invoke($health, $tmpFile);
        neria_assert($first === '<?php // version 1', "readModuleSrc() n'a pas lu correctement le fichier temporaire au premier appel — jeu de test invalide");

        file_put_contents($tmpFile, '<?php // version 2 (modifiée après le premier appel)');

        $second = $ref->invoke($health, $tmpFile);
        neria_assert(
            $second === '<?php // version 1',
            "readModuleSrc() a relu le fichier sur disque au lieu de réutiliser le cache (obtenu : '{$second}') — régression du bug corrigé le 09/08/2026 (round 141) : checkKnownRegressionsGuard() relirait de nouveau les mêmes fichiers plusieurs fois par exécution"
        );

        $refCache = new ReflectionProperty(HealthCheckManager::class, 'srcCache');
        $refCache->setAccessible(true);
        $cache = $refCache->getValue($health);
        neria_assert(
            isset($cache[$tmpFile]) && $cache[$tmpFile] === '<?php // version 1',
            "le cache interne \$srcCache ne contient pas l'entrée attendue pour ce fichier"
        );
    } finally {
        @unlink($tmpFile);
    }

    return [
        'pass'    => true,
        'message' => "HealthCheckManager::readModuleSrc() met bien en cache le contenu lu par chemin de fichier, évitant les relectures redondantes constatées jusqu'à 9x par exécution",
    ];
}
