<?php
/**
 * Régressions constatées en pilotant le vrai back-office de ps-test dans un navigateur (P8c, 26/09/2026, PrestaShop 9) :
 *  1. neriaPostLink() (bouton « TEST EMAIL », suppressions confirmées...) envoyait le formulaire SANS la chaîne de requête :
 *     la route Symfony de PS 9 lit le jeton CSRF dans l'URL -> page « Invalid token » à chaque clic.
 *  2. seasonal.tpl échappait l'URL en HTML avant d'en retirer les paramètres puis l'échappait une 2e fois : « &amp;amp; »
 *     s'accumulait à chaque enregistrement et le lien « Modifier » (edit_campaign) cessait de fonctionner.
 *  3. Le message de confirmation de suppression d'une campagne saisonnière n'échappait pas les guillemets des traductions
 *     dans l'attribut data-confirm : texte tronqué (« Delete the campaign » sans le nom).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $dir = _PS_MODULE_DIR_ . 'neria/views/templates/admin/';
    $nav = str_replace("\r\n", "\n", (string) file_get_contents($dir . 'navigation.tpl'));
    neria_assert(str_contains($nav, "['_token', 'token', 'controller', 'configure'].forEach(function (k) {"), "neriaPostLink() ne conserve plus le jeton dans l'URL du formulaire");
    neria_assert(str_contains($nav, "form.action = url.origin + url.pathname + (keepQuery.length ? '?' + keepQuery.join('&') : '');"), "L'action du formulaire de neriaPostLink() n'inclut plus le jeton");

    $sea = str_replace("\r\n", "\n", (string) file_get_contents($dir . 'seasonal.tpl'));
    neria_assert(str_contains($sea, "value=\$smarty.server.REQUEST_URI|regex_replace:'/&neria_action=[^&]*/':''}"), "seasonal.tpl échappe de nouveau l'URL avant d'en retirer les paramètres");
    neria_assert(!str_contains($sea, "{\$tab_url|escape:'html'}"), "seasonal.tpl échappe de nouveau \$tab_url une 2e fois (« &amp;amp; »)");
    neria_assert(str_contains($sea, "{neria_admin key='seasonal.delete_confirm_pre' esc='html'}") && str_contains($sea, "{neria_admin key='seasonal.delete_confirm_post' esc='html'}"), "Le message de confirmation de suppression saisonnière n'est plus échappé pour data-confirm");

    return ['pass' => true, 'message' => "Liens POST du back-office avec jeton, URL de la campagne saisonnière sans double échappement, confirmation de suppression complète — corrigé le 26/09/2026"];
}
