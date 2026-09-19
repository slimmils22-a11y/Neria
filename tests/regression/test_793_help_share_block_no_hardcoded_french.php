<?php
/**
 * Bloc 5 (19/09/2026) : le bloc de partage du journal Watchdog de help.tpl
 * (boutons Gmail/Outlook/copie, formulaire « ajouter une plateforme »,
 * sujet/corps du mail, titre du PDF) et l'infobulle des occurrences du
 * journal étaient codés en dur en français, visibles tels quels dans les 18
 * autres langues du BO ; toLocaleString('fr-FR') figeait aussi la date en
 * français ; CertificateManager repliait sur « Produit #N ».
 *
 * Corrigé : 11 clés traduites en 19 langues (mécanisme window.NERIA_HELP_L10N
 * déjà en place), date dans la locale du navigateur, repli neutre « #N ».
 *
 * Test : plus aucun texte français en dur dans help.tpl ; chaque clé utilisée
 * existe et est renseignée dans les 19 langues ; le Smarty de help.tpl
 * référence bien toutes les clés.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $dir  = _PS_MODULE_DIR_ . 'neria/';
    $help = (string) file_get_contents($dir . 'views/templates/admin/help.tpl');
    $cert = (string) file_get_contents($dir . 'src/CertificateManager.php');

    foreach (['Copier le texte', 'Ajouter une plateforme', 'Veuillez trouver ci-joint', "'Supprimer'", 'fr-FR',
              'Ce message est apparu', 'Nom de la plateforme', 'URL de partage', 'Nom (ex: Slack)'] as $fr) {
        neria_assert(strpos($help, $fr) === false, "help.tpl contient de nouveau « {$fr} » codé en dur");
    }
    neria_assert(strpos($cert, "'Produit #'") === false, "CertificateManager : repli « Produit #N » en français de retour");

    $keys = ['help.share_journal_title', 'help.share_mail_body', 'help.share_copy_text', 'help.share_delete',
             'help.share_add_platform', 'help.share_platform_name', 'help.share_name_placeholder', 'help.share_url_label',
             'help.share_url_placeholder', 'help.share_add_btn', 'help.occurrences_tooltip'];
    $data = json_decode((string) file_get_contents($dir . 'data/admin_translations.json'), true);
    foreach ($keys as $k) {
        neria_assert(strpos($help, "key='{$k}'") !== false, "help.tpl n'utilise plus la clé {$k}");
        $tr = $data[$k] ?? [];
        neria_assert(count($tr) >= 19, "{$k} incomplète (" . count($tr) . " langues)");
        foreach ($tr as $lang => $v) {
            neria_assert(trim((string) $v) !== '', "{$k} vide pour '{$lang}'");
            neria_assert($lang === 'fr' || $v !== ($tr['fr'] ?? null), "{$k}['{$lang}'] est resté identique au français");
        }
    }
    neria_assert(strpos($data['help.occurrences_tooltip']['en'], '%d') !== false, "infobulle occurrences : marqueur %d absent (en)");

    return ['pass' => true, 'message' => "bloc de partage du journal Watchdog traduit en 19 langues via NERIA_HELP_L10N, plus de français en dur dans help.tpl — bloc 5 (19/09/2026)"];
}
