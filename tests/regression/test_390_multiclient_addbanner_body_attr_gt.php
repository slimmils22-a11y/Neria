<?php
/**
 * Régression : MultiClientPreviewManager::addBanner() insérait le bandeau
 * juste après <body ...> via une regex naïve [^>]* qui s'arrête au premier
 * '>' littéral rencontré, MÊME à l'intérieur d'une valeur d'attribut entre
 * guillemets (légal en HTML5, ex. data-track="a>b"). La balise <body>
 * était tronquée en plein milieu d'un attribut, et le bandeau injecté dans
 * la valeur au lieu du contenu du <body> — un guillemet restait orphelin,
 * cassant potentiellement le reste du rendu de l'aperçu pour ce client.
 *
 * Corrigé le 19/08/2026 (round 186) : la regex respecte désormais les
 * valeurs d'attribut entre guillemets simples ou doubles.
 *
 * Test comportemental réel : appelle addBanner() (privée, via réflexion)
 * sur un <body> contenant un '>' littéral dans un attribut, vérifie que la
 * balise <body> reste intacte (pas tronquée) et que le bandeau est bien
 * inséré juste après la vraie fin de la balise.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php';

    $module = neria_test_module();
    $mgr = new MultiClientPreviewManager($module);

    $ref = new ReflectionMethod(MultiClientPreviewManager::class, 'addBanner');
    $ref->setAccessible(true);

    $html = '<html><body class="x" data-track="a>b" style="color:red">Hello</body></html>';
    $result = $ref->invoke($mgr, $html, 'gmail');

    neria_assert(
        strpos($result, '<body class="x" data-track="a>b" style="color:red">') !== false,
        "addBanner() a tronqué la balise <body> contenant un '>' littéral dans un attribut — régression du bug corrigé le 19/08/2026 (round 186). Résultat obtenu : " . substr($result, 0, 200)
    );

    neria_assert(
        strpos($result, 'Hello</body>') !== false,
        "addBanner() a corrompu le contenu du <body> (attendu : 'Hello</body>' intact) — régression du bug corrigé le 19/08/2026 (round 186)"
    );

    // Le bandeau doit être inséré APRÈS la vraie fin de <body ...>, pas au
    // milieu de l'attribut data-track.
    $posBodyEnd = strpos($result, 'style="color:red">') + strlen('style="color:red">');
    $posHello   = strpos($result, 'Hello');
    neria_assert(
        $posHello > $posBodyEnd,
        "Le bandeau/contenu n'apparaît pas après la fin réelle de la balise <body> — insertion mal positionnée"
    );

    return [
        'pass'    => true,
        'message' => "MultiClientPreviewManager::addBanner() respecte bien les valeurs d'attribut entre guillemets, ne tronque plus <body> sur un '>' littéral — bug corrigé le 19/08/2026 (round 186)",
    ];
}
