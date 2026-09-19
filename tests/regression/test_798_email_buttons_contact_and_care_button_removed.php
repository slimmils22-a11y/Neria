<?php
/**
 * Bloc 5 (19/09/2026) : quatre boutons d'email dont la cible était douteuse.
 * repair_completed (« Arrange return »), tax_refund_eligible (« Start the
 * procedure ») et product_recall (« Learn more ») menaient à l'historique de
 * commandes ou à l'accueil alors que chaque email dit « notre équipe vous
 * contactera / est à votre disposition » : ils pointent désormais vers la page
 * contact ({contact_page_url}) avec un libellé honnête (« Contact our team »,
 * « Contact us ») dans les 19 langues. care_certificate perd son bouton
 * « Download the certificate » (aucun fichier à télécharger : le certificat
 * est joint en PDF, voir test_799).
 *
 * Test : sources HTML+TXT ; libellés des 19 langues ; compilation RÉELLE de
 * repair_completed → lien résolu vers la page contact.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    $dir = _PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/';

    $map = ['repair_completed' => 'repair_completed_btn', 'tax_refund_eligible' => 'tax_refund_btn', 'product_recall' => 'product_recall_btn'];
    $tr  = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/translations.json'), true);
    foreach ($map as $tpl => $key) {
        $html = (string) file_get_contents($dir . $tpl . '.html');
        $txt  = (string) file_get_contents($dir . $tpl . '.txt');
        neria_assert(preg_match('/<a href="\{contact_page_url\}"[^>]*class="neria-btn"/', $html) === 1, "{$tpl}.html : le bouton ne pointe plus vers {contact_page_url}");
        neria_assert(strpos($txt, "{neria_trad key='{$key}'} : {contact_page_url}") !== false, "{$tpl}.txt : ligne du bouton non alignée sur {contact_page_url}");
        $langs = $tr[$tpl] ?? [];
        neria_assert(count($langs) >= 19, "{$tpl} : moins de 19 langues");
        foreach ($langs as $lang => $vals) {
            $v = (string) ($vals[$key] ?? '');
            neria_assert($v !== '', "{$tpl}[{$lang}] : libellé {$key} vide");
            neria_assert(stripos($v, 'contact') !== false || $lang !== 'en', "{$tpl}[en] : libellé attendu « Contact… »");
        }
        neria_assert(in_array($tr[$tpl]['en'][$key], ['Contact our team', 'Contact us'], true), "{$tpl}[en] : libellé inattendu « " . $tr[$tpl]['en'][$key] . " »");
    }

    // care_certificate : plus de bouton, note qui annonce la pièce jointe.
    $careHtml = (string) file_get_contents($dir . 'care_certificate.html');
    $careTxt  = (string) file_get_contents($dir . 'care_certificate.txt');
    neria_assert(strpos($careHtml, 'care_certificate_btn') === false && strpos($careTxt, 'care_certificate_btn') === false, "care_certificate : bouton « Télécharger » de retour");
    neria_assert(strpos($careTxt, "\n\n\n") === false, "care_certificate.txt : lignes vides consécutives");
    foreach ($tr['care_certificate'] as $lang => $vals) {
        neria_assert(stripos((string) ($vals['care_certificate_note'] ?? ''), 'pdf') !== false, "care_certificate[{$lang}] : la note n'annonce plus la pièce jointe PDF");
    }

    // Compilation réelle.
    $module   = neria_test_module();
    $renderer = new EmailRenderer($module);
    $method   = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
    $method->setAccessible(true);
    $out   = 'regtest798_repair';
    $files = [_PS_MODULE_DIR_ . 'neria/mails/en/' . $out . '.html', _PS_MODULE_DIR_ . 'neria/mails/en/' . $out . '.txt'];
    try {
        $res = $method->invoke($renderer, 'repair_completed', 'en', 'en', ['{firstname}' => 'Slim', '{time_greeting}' => 'Hello', '{product_name}' => 'X', '{order_name}' => 'A1'], false, false, $out);
        neria_assert($res !== null && is_file($files[0]), "compilation repair_completed échouée");
        $h = (string) file_get_contents($files[0]);
        neria_assert(preg_match('/<a href="([^"]+)"[^>]*class="neria-btn"[^>]*>([^<]+)</', $h, $m) === 1, "bouton introuvable");
        neria_assert(strpos(html_entity_decode($m[1]), 'contact') !== false, "bouton repair_completed ne pointe pas vers la page contact : " . $m[1]);
        neria_assert(trim($m[2]) === 'Contact our team', "libellé compilé inattendu : " . $m[2]);
    } finally {
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    return ['pass' => true, 'message' => "3 boutons vers la page contact (libellés 19 langues) et bouton « Télécharger » supprimé de care_certificate — bloc 5 (19/09/2026)"];
}
