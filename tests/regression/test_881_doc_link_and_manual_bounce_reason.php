<?php
/**
 * Régression (P8c, 26/09/2026) : le bouton « Documentation » pointait vers neria.io/docs (domaine parqué) et la raison d'un rejet
 * ajouté manuellement était stockée en français quelle que soit la langue du back-office.
 * Corrigé : lien vers la notice PDF du module dans la langue du back-office ; raison stockée sous forme de marqueur neutre,
 * traduite à l'affichage (les anciennes lignes françaises aussi).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $help = str_replace("\r\n", "\n", (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/help.tpl'));
    neria_assert(!str_contains($help, 'neria.io/docs'), 'help.tpl renvoie de nouveau vers le domaine parqué neria.io');
    neria_assert(str_contains($help, 'neria_notice_file'), 'help.tpl ne lie plus la notice du module');
    neria_assert(\Neria::noticeFileForLang('fr') === 'Neria_Notice_Utilisation_FR.pdf', 'notice FR');
    neria_assert(\Neria::noticeFileForLang('zz') === 'Neria_Notice_Utilisation_EN.pdf', 'repli EN');
    neria_assert(\Neria::noticeFileForLang('') !== '' && is_file(_PS_MODULE_DIR_ . 'neria/docs/' . \Neria::noticeFileForLang('br')), 'notice BR absente');
    $bm = str_replace("\r\n", "\n", (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BounceManager.php'));
    neria_assert(str_contains($bm, "self::REASON_MANUAL_BO, 'manual'") && !str_contains($bm, "recordBounce(\$email, \$type, 'Ajout manuel"), 'raison manuelle de nouveau stockée en français');
    $tpl = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/bounces.tpl');
    neria_assert(str_contains($tpl, "key='bounces.reason_manual'"), 'bounces.tpl n\'affiche plus la raison manuelle traduite');
    $tr = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(count($tr['bounces.reason_manual'] ?? []) === 19, 'bounces.reason_manual doit avoir 19 langues');

    return ['pass' => true, 'message' => 'Lien Documentation -> notice PDF ; raison de rejet manuel neutre et traduite — corrigé le 26/09/2026'];
}
