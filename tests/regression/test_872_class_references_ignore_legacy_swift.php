<?php
/**
 * Régression (P8b, 26/09/2026, constatée sur ps-test / PrestaShop 9) : le contrôle « références de classes » du Watchdog et
 * le diagnostic de code du BO affichaient une ERREUR (« 1 classe référencée via class_exists() introuvable : Swift_Message »)
 * sur toute installation PrestaShop 9, où Swift Mailer n'existe plus (Symfony Mailer). Neria référence Swift_Message derrière
 * class_exists() pour rester compatible PS 8 : ce n'est pas une anomalie.
 *
 * Corrigé : les classes Swift_* sont ignorées par checkClassReferencesIntegrity().
 *
 * Test : contrôle structurel de l'exemption + le contrôle répond « ok » sur la base de test.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $src = str_replace("\r\n", "\n", (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php'));
    neria_assert(str_contains($src, "if (strpos(\$class, 'Swift_') === 0) {\n                continue;\n            }"), "L'exemption des classes Swift_* a disparu de checkClassReferencesIntegrity()");

    $h = new HealthCheckManager($module);
    $rm = new ReflectionMethod($h, 'checkClassReferencesIntegrity');
    $rm->setAccessible(true);
    $r = $rm->invoke($h);
    neria_assert(($r['status'] ?? '') === 'ok', 'Le contrôle des références de classes doit être ok : ' . json_encode($r));

    return ['pass' => true, 'message' => "Le contrôle des références de classes ignore les classes Swift Mailer (absentes sur PrestaShop 9) — corrigé le 26/09/2026"];
}
