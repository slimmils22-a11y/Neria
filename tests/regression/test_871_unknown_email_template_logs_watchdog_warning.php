<?php
/**
 * Décision utilisateur du 25/09/2026 : si PrestaShop (ou un module) envoie un e-mail sans modèle Neria, il part sans
 * traduction ni design Neria — le marchand doit en être averti par le Watchdog, dans sa langue (19 langues).
 *
 * Corrigé : EmailRenderer::applyNeriaRendering() journalise watchdog.template_not_covered (niveau warning, pas d'alerte
 * immédiate) quand aucun modèle Neria n'existe pour le message ; occurrences identiques consolidées par le Watchdog.
 *
 * Test comportemental : envoi factice d'un modèle inconnu -> une ligne neria_log avec le modèle nommé, l'e-mail reste
 * inchangé ; message présent dans les 19 langues avec la variable {template}.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $db = neria_test_db();
    $p = neria_test_prefix();
    $tpl = 'regtest871_modele_inconnu';

    $tr = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'] as $l) {
        $t = (string) ($tr['watchdog.template_not_covered'][$l] ?? '');
        neria_assert($t !== '' && str_contains($t, '{template}'), "watchdog.template_not_covered absent ou sans {template} en {$l}");
    }

    // Un e-mail « existant côté PrestaShop » mais sans modèle Neria : fichier factice dans /mails/en/.
    $mailDir = _PS_ROOT_DIR_ . '/mails/en/';
    $created = [];
    foreach (['.html', '.txt'] as $ext) {
        if (!is_file($mailDir . $tpl . $ext) && is_dir($mailDir) && @file_put_contents($mailDir . $tpl . $ext, 'regtest') !== false) {
            $created[] = $mailDir . $tpl . $ext;
        }
    }
    neria_assert($created !== [], 'Impossible de créer le fichier e-mail factice');
    $db->execute("DELETE FROM {$p}neria_log WHERE message LIKE '%" . pSQL($tpl) . "%'");
    try {
        $renderer = new EmailRenderer($module);
        $params = ['template' => $tpl, 'idLang' => (int) Configuration::get('PS_LANG_DEFAULT'), 'subject' => 'Sujet d\'origine', 'templateVars' => [], 'to' => 'regtest871@example.com', 'templatePath' => _PS_MAIL_DIR_];
        $ok = $renderer->processEmailParams($params);
        neria_assert($ok === true, "L'envoi d'un modèle inconnu ne doit pas être bloqué");
        neria_assert(($params['subject'] ?? '') === 'Sujet d\'origine', "Le sujet d'origine doit rester inchangé pour un modèle inconnu : " . ($params['subject'] ?? ''));
        $row = $db->getRow("SELECT level, message FROM {$p}neria_log WHERE message LIKE '%" . pSQL($tpl) . "%' ORDER BY id_log DESC", false);
        neria_assert(is_array($row), "Aucune ligne Watchdog pour le modèle inconnu — régression du correctif du 25/09/2026");
        neria_assert($row['level'] === 'warning', 'Niveau attendu warning, obtenu ' . $row['level']);
    } finally {
        foreach ($created as $f) {
            @unlink($f);
        }
        $db->execute("DELETE FROM {$p}neria_log WHERE message LIKE '%" . pSQL($tpl) . "%'");
    }

    return ['pass' => true, 'message' => "Un e-mail sans modèle Neria est signalé par le Watchdog (warning, 19 langues), sans être bloqué ni modifié — décision du 25/09/2026"];
}
