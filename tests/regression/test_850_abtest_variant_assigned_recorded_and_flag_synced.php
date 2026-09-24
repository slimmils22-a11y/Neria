<?php
/**
 * Régression (constat réel du 24/09/2026, P6 sur ps-test) : un test A/B créé et activé depuis le
 * back-office n'attribuait JAMAIS de variante. Deux causes cumulées :
 *   1. EmailRenderer::resolveABVariant() exigeait NERIA_ABTEST_ENABLED = 1, réglage semé à 0 à
 *      l'installation et qu'aucun écran ni aucun code ne passait jamais à 1 ;
 *   2. même avec ce garde ouvert, EmailRenderer ne renseignait jamais $params['neria_variant'] :
 *      StatsManager::recordSent() enregistrait donc abtest_variant = '' et les rapports A/B
 *      (filtrés sur 'A'/'B') restaient vides pour toujours.
 *
 * Corrigé : l'attribution dépend de l'existence réelle d'un test actif (ABTestManager::
 * hasAnyActiveTest()), $params['neria_variant'] est posé par processEmailParams(), et
 * NERIA_ABTEST_ENABLED (affiché par le Centre de contrôle) suit désormais l'état réel des tests.
 *
 * Test comportemental : test A/B réel sur win_back (texte B en français), deux destinataires dont la
 * répartition (crc32) donne A et B ; processEmailParams() réel : variante posée, texte B présent dans
 * le fichier compilé de B seulement ; le réglage passe à 1 à l'activation et retombe à 0 à l'arrêt.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';
    $module = neria_test_module();
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $template = 'win_back';
    $idLang = (int) Language::getIdByIso('fr') ?: (int) Configuration::get('PS_LANG_DEFAULT');
    $iso = (string) Language::getIsoById($idLang);
    $marker = 'VARIANTE-B-850';

    $preActive = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_abtest WHERE is_active = 1");
    neria_assert($preActive === 0, "Jeu de test invalide : {$preActive} test(s) A/B déjà actif(s) sur cette base");
    $flagBefore = Configuration::getGlobalValue('NERIA_ABTEST_ENABLED');

    // Deux destinataires, un par variante (répartition crc32 identique à ABTestManager::assignVariant)
    $emails = ['A' => '', 'B' => ''];
    for ($i = 1; $i < 400 && ($emails['A'] === '' || $emails['B'] === ''); $i++) {
        $e = "regtest850-{$i}@example.com";
        $v = (abs(crc32($template . '|' . $e)) % 100) < 50 ? 'A' : 'B';
        if ($emails[$v] === '') {
            $emails[$v] = $e;
        }
    }

    $renderer = new EmailRenderer($module);
    $ab = new ABTestManager($module);
    $result = [];
    try {
        $ab->deleteTests($template);
        $ab->createTest($template, 'Chaleureux 850', 'Direct 850', 50);
        $ab->activateTest($template);
        neria_assert(
            (int) Configuration::getGlobalValue('NERIA_ABTEST_ENABLED') === 1,
            "NERIA_ABTEST_ENABLED n'est pas passé à 1 à l'activation d'un test — le Centre de contrôle afficherait A/B « Inactif » alors qu'un test tourne — régression du bug corrigé le 24/09/2026"
        );
        $idB = (int) $db->getValue("SELECT id_abtest FROM {$prefix}neria_abtest WHERE template='{$template}' AND variant='B' AND is_active=1");
        neria_assert($idB > 0, 'Variante B introuvable après activation');
        $ab->saveVariantBTranslations($idB, $iso, ['greeting_main' => $marker]);

        foreach (['A', 'B'] as $expected) {
            $params = [
                'template' => $template, 'idLang' => $idLang, 'subject' => '',
                'to' => $emails[$expected], 'toName' => 'Regtest',
                'templateVars' => ['{firstname}' => 'Test', '{lastname}' => 'Regtest', '{shop_name}' => 'Shop'],
            ];
            $ok = $renderer->processEmailParams($params);
            neria_assert($ok !== false, "Rendu refusé pour la variante {$expected}");
            $got = (string) ($params['neria_variant'] ?? '');
            neria_assert(
                $got === $expected,
                "Variante posée « {$got} » au lieu de « {$expected} » pour {$emails[$expected]} — un test A/B actif n'attribue plus de variante ou ne la transmet plus à StatsManager::recordSent() — régression du bug corrigé le 24/09/2026"
            );
            $dir = _PS_MODULE_DIR_ . 'neria/mails/' . $iso . '/' . $params['template'] . '.html';
            neria_assert(is_file($dir), "Fichier compilé introuvable : {$dir}");
            $html = (string) file_get_contents($dir);
            $hasB = strpos($html, $marker) !== false;
            neria_assert(
                $hasB === ($expected === 'B'),
                $expected === 'B'
                    ? "Le texte de la variante B est absent du courriel envoyé à un destinataire B"
                    : "Le texte de la variante B a fuité dans le courriel d'un destinataire A"
            );
            $subjectHasB = strpos((string) ($params['subject'] ?? ''), $marker) !== false;
            neria_assert(
                $subjectHasB === ($expected === 'B'),
                $expected === 'B'
                    ? "Le sujet du courriel B ne reprend pas le titre de la variante B : « {$params['subject']} » — le test A/B ne pourrait pas mesurer l'effet d'un autre sujet"
                    : "Le sujet du courriel A contient le texte de la variante B : « {$params['subject']} »"
            );
            @unlink($dir);
            @unlink(preg_replace('/\.html$/', '.txt', $dir));
            $result[$expected] = $got;
        }

        $ab->deactivateTest($template);
        neria_assert(
            (int) Configuration::getGlobalValue('NERIA_ABTEST_ENABLED') === 0,
            "NERIA_ABTEST_ENABLED est resté à 1 alors qu'aucun test n'est plus actif"
        );
        $params = [
            'template' => $template, 'idLang' => $idLang, 'subject' => '',
            'to' => $emails['B'], 'toName' => 'Regtest',
            'templateVars' => ['{firstname}' => 'Test', '{lastname}' => 'Regtest', '{shop_name}' => 'Shop'],
        ];
        $renderer->processEmailParams($params);
        neria_assert((string) ($params['neria_variant'] ?? 'x') === '', "Une variante est attribuée alors qu'aucun test n'est actif");
        $dir = _PS_MODULE_DIR_ . 'neria/mails/' . $iso . '/' . $params['template'] . '.html';
        @unlink($dir);
        @unlink(preg_replace('/\.html$/', '.txt', $dir));
    } finally {
        $ab->deleteTests($template);
        Configuration::updateGlobalValue('NERIA_ABTEST_ENABLED', $flagBefore === false ? 0 : (int) $flagBefore);
    }

    return [
        'pass'    => true,
        'message' => "Un test A/B actif attribue A ou B selon la répartition, la variante est posée pour StatsManager, le texte B n'apparaît que dans le courriel B, et le réglage du Centre de contrôle suit l'état réel des tests — bug corrigé le 24/09/2026",
    ];
}
