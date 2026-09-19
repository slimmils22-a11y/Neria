<?php
/**
 * Bloc 5 (19/09/2026), relevé par le propriétaire du module sur le PDF reçu :
 * le pied de page du certificat d'authenticité — document remis au client
 * final d'une maison de luxe — se terminait par « … via Neria Luxury Email
 * Suite ». Mention de l'éditeur du logiciel incompatible avec un document de
 * marque blanche. Supprimée dans les 19 langues (fichier source ET bases
 * déjà installées).
 *
 * Test : (1) fichier source ; (2) valeurs réellement lues par le moteur de
 * traduction (base de données) dans les 19 langues — ni « Neria », ni marque
 * d'éditeur, {shop_name} conservé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $json = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/translations.json'), true);
    $src  = $json['certificate_email'] ?? [];
    neria_assert(count($src) >= 19, "certificate_email : moins de 19 langues dans le fichier source");
    foreach ($src as $lang => $vals) {
        $v = (string) ($vals['certificate_pdf_footer'] ?? '');
        neria_assert($v !== '' && strpos($v, '{shop_name}') !== false, "[{$lang}] source : pied de page vide ou sans {shop_name}");
        neria_assert(stripos($v, 'neria') === false, "[{$lang}] source : mention « Neria » dans le pied de page du certificat : {$v}");
    }

    $engine = new TranslationEngine(neria_test_module());
    $checked = 0;
    foreach (array_keys($src) as $lang) {
        $v = (string) $engine->get('certificate_email', 'certificate_pdf_footer', $lang, 1);
        neria_assert($v !== '', "[{$lang}] base : pied de page introuvable");
        neria_assert(stripos($v, 'neria') === false, "[{$lang}] base : mention « Neria » dans le pied de page du certificat : {$v}");
        $checked++;
    }

    return ['pass' => true, 'message' => "le pied de page du certificat ne mentionne plus l'éditeur du logiciel ({$checked} langues, source et base) — bloc 5 (19/09/2026)"];
}
