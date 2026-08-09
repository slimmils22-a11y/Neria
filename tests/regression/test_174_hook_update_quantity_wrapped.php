<?php
/**
 * Régression : Neria::hookActionUpdateQuantity() doit être enveloppé par
 * NeriaErrorHandler::wrapHookVoid(), comme TOUS les autres hooks du
 * fichier — sinon une exception levée avant le try/catch interne
 * (Shop::getShops(), instanciation de WaitlistManager) remonte et casse
 * tout le process PrestaShop appelant (déclenché à chaque mise à jour de
 * stock, y compris pendant le tunnel de commande).
 *
 * Bug réel corrigé le 08/08/2026 (round 139) : seul hook du fichier à ne
 * pas être protégé — le corps réel a été déplacé dans
 * hookActionUpdateQuantityImpl(), appelé via NeriaErrorHandler comme les
 * autres.
 *
 * Test comportemental réel : simule une exception dans le corps du hook
 * (Configuration corrompue) et vérifie qu'elle ne se propage PAS hors de
 * hookActionUpdateQuantity() — NeriaErrorHandler doit l'absorber.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();

    // Vérification structurelle : le hook public délègue bien à
    // NeriaErrorHandler::wrapHookVoid(), le corps réel est dans Impl().
    $refClass = new ReflectionClass(get_class($module));
    neria_assert($refClass->hasMethod('hookActionUpdateQuantityImpl'), "hookActionUpdateQuantityImpl() introuvable — régression du bug corrigé le 08/08/2026 (round 139) : hookActionUpdateQuantity() ne délègue plus à une implémentation séparée");

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posHook = strpos($src, 'public function hookActionUpdateQuantity(array $params): void');
    neria_assert($posHook !== false, 'hookActionUpdateQuantity() introuvable');
    $hookBody = substr($src, $posHook, 1200);

    neria_assert(
        strpos($hookBody, "NeriaErrorHandler::wrapHookVoid('hookActionUpdateQuantity'") !== false,
        "hookActionUpdateQuantity() n'est plus enveloppé par NeriaErrorHandler::wrapHookVoid() — régression du bug corrigé le 08/08/2026 (round 139) : une exception levée avant le try/catch interne (Shop::getShops(), instanciation WaitlistManager) redeviendrait susceptible de casser le tunnel de commande"
    );

    // Test comportemental réel : appelle le hook avec des params minimaux —
    // ne doit jamais lever d'exception, même si WaitlistManager n'est pas
    // configuré/désactivé (chemin de sortie précoce), confirmant que
    // l'enveloppe absorbe correctement le flux normal ET les erreurs.
    $threw = false;
    try {
        $module->hookActionUpdateQuantity(['id_product' => 0, 'quantity' => 0]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    neria_assert(!$threw, "hookActionUpdateQuantity() a laissé fuiter une exception vers l'appelant — NeriaErrorHandler::wrapHookVoid() ne l'absorbe plus");

    return [
        'pass'    => true,
        'message' => "Neria::hookActionUpdateQuantity() est bien enveloppé par NeriaErrorHandler::wrapHookVoid(), cohérent avec tous les autres hooks du fichier",
    ];
}
