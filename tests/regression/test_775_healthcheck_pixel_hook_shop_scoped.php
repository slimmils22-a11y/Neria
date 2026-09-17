<?php
/**
 * Régression : HealthCheckManager::checkPixelInHtml() comptait les
 * enregistrements du hook actionEmailSendBefore SANS filtrer par
 * id_shop — un hook enregistré sur UNE boutique de l'installation
 * suffisait à afficher "pixel OK" pour TOUTES les boutiques, y compris
 * une où le hook aurait été désenregistré (désinstallation partielle,
 * réimport de config incomplet) et où le pixel de suivi ne se déclenche
 * en réalité jamais — round dédié HealthCheckManager (bloc A, 16/09/2026).
 *
 * Corrigé : la requête filtre désormais explicitement hm.id_shop =
 * $this->idShop.
 *
 * Test comportemental réel : désenregistre le hook actionEmailSendBefore
 * pour la boutique courante SEULEMENT après l'avoir réenregistré sur une
 * boutique fictive — vérifie que checkPixelInHtml() détecte bien
 * l'absence réelle du hook sur la boutique courante malgré sa présence
 * sur une autre boutique de l'installation. Restaure l'état réel après
 * test.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $db       = neria_test_db();
    $prefix   = neria_test_prefix();
    $realShop = (int) Context::getContext()->shop->id;
    $fakeShop = 999997775;
    $module   = neria_test_module();

    $idHook = (int) $db->getValue(
        "SELECT id_hook FROM {$prefix}hook WHERE name = 'actionEmailSendBefore'"
    );
    neria_assert($idHook > 0, "hook actionEmailSendBefore introuvable en base — jeu de test invalide");

    // État réel AVANT modification, pour restauration fidèle (pas supposé).
    $realRow = $db->getRow(
        "SELECT position FROM {$prefix}hook_module
         WHERE id_module = " . (int) $module->id . " AND id_hook = {$idHook} AND id_shop = {$realShop}"
    );

    $cleanup = function () use ($db, $prefix, $module, $idHook, $realShop, $fakeShop, $realRow) {
        $db->execute(
            "DELETE FROM {$prefix}hook_module
             WHERE id_module = " . (int) $module->id . " AND id_hook = {$idHook} AND id_shop = {$fakeShop}"
        );
        if ($realRow !== false) {
            $exists = (int) $db->getValue(
                "SELECT COUNT(*) FROM {$prefix}hook_module
                 WHERE id_module = " . (int) $module->id . " AND id_hook = {$idHook} AND id_shop = {$realShop}"
            );
            if ($exists === 0) {
                $db->execute(
                    "INSERT INTO {$prefix}hook_module (id_module, id_shop, id_hook, position)
                     VALUES (" . (int) $module->id . ", {$realShop}, {$idHook}, " . (int) $realRow['position'] . ")"
                );
            }
        }
    };

    try {
        $hc = new HealthCheckManager($module);
        $method = new ReflectionMethod(HealthCheckManager::class, 'checkPixelInHtml');
        $method->setAccessible(true);

        // Retire le hook de la boutique RÉELLE, l'enregistre seulement sur
        // une boutique FICTIVE — reproduit une désinstallation partielle.
        $db->execute(
            "DELETE FROM {$prefix}hook_module
             WHERE id_module = " . (int) $module->id . " AND id_hook = {$idHook} AND id_shop = {$realShop}"
        );
        $db->execute(
            "INSERT INTO {$prefix}hook_module (id_module, id_shop, id_hook, position)
             VALUES (" . (int) $module->id . ", {$fakeShop}, {$idHook}, 1)"
        );

        $result = $method->invoke($hc);
        neria_assert(
            $result['status'] === 'error',
            "checkPixelInHtml() renvoie '{$result['status']}' alors que le hook n'est enregistré QUE sur une AUTRE boutique (fictive) — régression du scoping id_shop : le pixel de tracking ne se déclenche en réalité jamais sur cette boutique mais le contrôle affiche à tort 'pixel OK'"
        );

        // Réenregistre sur la boutique réelle — doit repasser OK.
        $db->execute(
            "INSERT INTO {$prefix}hook_module (id_module, id_shop, id_hook, position)
             VALUES (" . (int) $module->id . ", {$realShop}, {$idHook}, 1)"
        );
        $resultAfterFix = $method->invoke($hc);
        neria_assert(
            $resultAfterFix['status'] === 'ok',
            "checkPixelInHtml() ne repasse pas 'ok' une fois le hook réellement réenregistré sur la boutique courante — jeu de test invalide ou régression"
        );

        return [
            'pass'    => true,
            'message' => "HealthCheckManager::checkPixelInHtml() vérifie bien l'enregistrement du hook actionEmailSendBefore sur la boutique courante spécifiquement, pas seulement sur l'installation entière — bug corrigé round HealthCheckManager (bloc A, 16/09/2026)",
        ];
    } finally {
        $cleanup();
    }
}
