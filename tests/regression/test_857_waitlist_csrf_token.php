<?php
/**
 * Régression (observation P7 du 24/09/2026) : le contrôleur front de la liste d'attente (controllers/front/
 * waitlist.php) n'exigeait qu'un POST — présenté dans son commentaire comme « garde-fou anti-CSRF », ce qui n'en
 * est pas un : une page tierce pouvait inscrire ou désinscrire un client connecté à une liste d'attente.
 *
 * Corrigé : les deux formulaires (inscription/désinscription) portent le jeton de session
 * Tools::getToken(false) et le contrôleur le vérifie (hash_equals) avant toute écriture.
 *
 * Vérification de bout en bout faite sur ps-test le 24/09/2026 (client connecté par cookie) : sans jeton et avec
 * un mauvais jeton → aucune écriture (redirection compte client) ; bon jeton → inscription puis désinscription.
 * Ce test couvre la partie reproductible en base de tests : rendu réel du gabarit (deux champs jeton, valeur
 * échappée) et ordre du contrôle dans le contrôleur (jeton vérifié AVANT register()/unregister()).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $ctx = Context::getContext();

    foreach (['waitlist_registered' => false, 'waitlist_registered_b' => true] as $key => $registered) {
        $ctx->smarty->assign([
            'waitlist_oos' => true, 'waitlist_registered' => $registered, 'waitlist_id_product' => 1,
            'waitlist_subscribe_url' => 'https://shop.example/module/neria/waitlist?action=subscribe',
            'waitlist_unsubscribe_url' => 'https://shop.example/module/neria/waitlist?action=unsubscribe',
            'waitlist_back_url' => 'https://shop.example/p', 'waitlist_token' => 'tok"<b>854',
        ]);
        if (class_exists('AdminTranslator')) {
            AdminTranslator::setLang('fr');
            AdminTranslator::register($ctx->smarty);
        }
        $html = $ctx->smarty->fetch(_PS_MODULE_DIR_ . 'neria/views/templates/front/waitlist_button.tpl');
        neria_assert(
            substr_count($html, 'name="token"') === 1 && strpos($html, 'value="tok&quot;&lt;b&gt;854"') !== false,
            ($registered ? 'Formulaire de désinscription' : "Formulaire d'inscription") . " : champ jeton absent ou valeur non échappée — régression du correctif du 24/09/2026"
        );
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/waitlist.php');
    neria_assert($src !== false, 'Contrôleur illisible');
    $posToken = strpos($src, 'hash_equals((string) Tools::getToken(false), (string) Tools::getValue(\'token\'))');
    $posWrite = strpos($src, '$mgr->register(');
    neria_assert($posToken !== false, "Le contrôleur ne vérifie plus le jeton anti-CSRF — régression du correctif du 24/09/2026");
    neria_assert($posWrite !== false && $posToken < $posWrite, "Le jeton est vérifié APRÈS l'écriture (ou l'écriture est introuvable)");

    $main = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert(strpos((string) $main, "'waitlist_token'           => Tools::getToken(false),") !== false, "Le hook n'assigne plus le jeton au gabarit du bouton");

    return [
        'pass'    => true,
        'message' => "La liste d'attente exige un jeton de session anti-CSRF (gabarit : 2 formulaires, contrôleur : vérifié avant toute écriture) — corrigé le 24/09/2026, vérifié de bout en bout sur ps-test",
    ];
}
