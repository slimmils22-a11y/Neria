<?php
/**
 * Régression (P8c, 26/09/2026, constatée dans le vrai back-office de ps-test, PrestaShop 9) : le formulaire « Filtrer » de
 * l'onglet Rejets est un formulaire GET. Un formulaire GET n'envoie que ses champs (la chaîne de requête de l'attribut action est
 * ignorée) : sans le jeton CSRF de l'URL, la route Symfony de PS 9 répondait « Invalid token » à chaque filtrage. Ses champs
 * cachés « configure=AdminModules&module_name=neria » étaient en outre des restes de l'ancienne URL.
 *
 * Corrigé : tous les paramètres courants (dont _token) repris en champs cachés, comme customer_history.tpl ; le lien
 * « effacer le filtre » retire seulement nb_filter de l'URL courante.
 *
 * Test : aucun formulaire GET des modèles d'administration ne perd le jeton (champs cachés issus de $smarty.get).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $dir = _PS_MODULE_DIR_ . 'neria/views/templates/admin/';
    $found = 0;
    foreach (glob($dir . '*.tpl') as $file) {
        $src = str_replace("\r\n", "\n", (string) file_get_contents($file));
        if (!preg_match_all('/<form\b[^>]*method="get"[^>]*>(.*?)<\/form>/is', $src, $forms)) {
            continue;
        }
        foreach ($forms[1] as $body) {
            $found++;
            neria_assert(str_contains($body, '{foreach from=$smarty.get key=k item=v}'), basename($file) . " : un formulaire GET ne reprend plus les paramètres courants (jeton CSRF perdu -> « Invalid token » sur PrestaShop 9)");
        }
    }
    neria_assert($found >= 2, 'Formulaires GET attendus : au moins 2 (bounces, customer_history), trouvés ' . $found);
    $b = str_replace("\r\n", "\n", (string) file_get_contents($dir . 'bounces.tpl'));
    neria_assert(!str_contains($b, 'configure=AdminModules&module_name=neria'), 'bounces.tpl contient de nouveau les paramètres de l\'ancienne URL legacy');

    return ['pass' => true, 'message' => "Les formulaires GET du back-office conservent le jeton CSRF (filtre des rejets, recherche d'historique) — corrigé le 26/09/2026"];
}
