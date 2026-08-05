<?php
/**
 * Régression : CooldownManager::BYPASS_TEMPLATES doit exempter le vrai
 * template 'password' (confirmation de nouveau mot de passe,
 * PasswordController::postProcess()) — 'password_reset' n'existe dans
 * aucun template PrestaShop réel et n'a jamais bypassé quoi que ce soit.
 *
 * Bug réel corrigé le 05/08/2026 (round 53) : un client qui double-soumet
 * le formulaire de nouveau mot de passe (double-clic, page lente) dans la
 * fenêtre de cooldown voyait son 2e email de confirmation silencieusement
 * bloqué par le Mode Silence — alors que ce flux est explicitement
 * déclenché par l'utilisateur et devrait toujours partir (même principe
 * que 'password_query', déjà correctement exempté).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CooldownManager.php';

    $mgr = new CooldownManager();
    $idShop = (int) Context::getContext()->shop->id;
    $email  = 'regtest-cooldown-' . uniqid() . '@example.com';

    // isDuplicate() doit retourner false (pas de blocage) pour 'password'
    // SANS même avoir besoin de seed une entrée neria_stat existante — le
    // bypass intervient avant toute lecture de la base.
    $result = $mgr->isDuplicate($email, 'password', 60, $idShop);
    neria_assert(
        $result === false,
        "CooldownManager::isDuplicate() bloque de nouveau le template 'password' — régression du bug corrigé le 05/08/2026 : un client qui double-soumet son nouveau mot de passe verrait le 2e email silencieusement bloqué"
    );

    // Confirme que 'password_reset' (jamais un vrai nom de template) a été
    // retiré — code mort qui ne protégeait rien, ne doit pas faire croire
    // à une couverture qui n'existe pas.
    neria_assert(
        !in_array('password_reset', CooldownManager::BYPASS_TEMPLATES, true),
        "'password_reset' est de retour dans BYPASS_TEMPLATES — ce nom ne correspond à aucun template PrestaShop réel, sa présence est trompeuse"
    );
    neria_assert(
        in_array('password', CooldownManager::BYPASS_TEMPLATES, true),
        "'password' (vrai template de confirmation de nouveau mot de passe) n'est plus dans BYPASS_TEMPLATES"
    );

    return [
        'pass'    => true,
        'message' => "CooldownManager exempte bien le vrai template 'password' du Mode Silence",
    ];
}
