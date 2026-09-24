<?php
/**
 * Régression (constat réel du 24/09/2026, campagne P5 sur ps-test, palier de 5 commandes) : quand
 * aucun sujet n'est fourni, EmailRenderer dérive le sujet du titre principal du modèle
 * (greeting_main) mais n'y substituait pas les variables de l'e-mail. Le modèle milestone_order
 * (19 langues) a pour titre « C'est votre {milestone_count} commande » : le sujet partait avec
 * « {milestone_count} » en clair (ps_mail : « [My Store] C'est votre {milestone_count} commande »)
 * alors que le corps, lui, recevait bien « 5ème ».
 *
 * Corrigé : les variables scalaires de templateVars sont substituées dans le sujet dérivé du titre
 * (correctif général, pas limité à ce modèle).
 *
 * Test comportemental : processEmailParams() réel avec les variables du palier ; le sujet doit
 * contenir l'ordinal, aucune accolade, dans plusieurs langues ; un modèle sans variable dans son
 * titre garde un sujet inchangé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    $renderer = new EmailRenderer(neria_test_module());
    $subjectFor = static function (string $template, string $iso, array $vars) use ($renderer): string {
        $idLang = (int) Language::getIdByIso($iso) ?: (int) Configuration::get('PS_LANG_DEFAULT');
        $params = [
            'template' => $template, 'idLang' => $idLang, 'subject' => '',
            'to' => 'regtest847@example.com', 'toName' => 'Regtest',
            'templateVars' => $vars,
        ];
        $renderer->processEmailParams($params);
        return (string) ($params['subject'] ?? '');
    };

    $vars = ['{firstname}' => 'Test', '{lastname}' => 'Regtest', '{shop_name}' => 'Shop', '{milestone_count}' => '5ème', '{order_count}' => '5', '{id_order}' => 1, '{voucher_code}' => '', '{milestone_voucher_block}' => '', '{milestone_voucher_block_txt}' => ''];
    foreach (['fr', 'en', 'de', 'ja'] as $iso) {
        if (!(int) Language::getIdByIso($iso)) {
            continue;
        }
        $subject = $subjectFor('milestone_order', $iso, $vars);
        neria_assert($subject !== '', "[{$iso}] sujet vide");
        neria_assert(strpos($subject, '{') === false && strpos($subject, '}') === false, "[{$iso}] le sujet contient encore une variable non résolue : « {$subject} » — régression du bug corrigé le 24/09/2026");
        neria_assert(strpos($subject, '5ème') !== false, "[{$iso}] l'ordinal {milestone_count} est absent du sujet : « {$subject} »");
        neria_assert(strpos($subject, '  ') === false, "[{$iso}] espaces doublés dans le sujet : « {$subject} »");
    }

    // Titre sans variable : sujet inchangé (aucun effet de bord)
    $plain = $subjectFor('order_conf', 'fr', ['{firstname}' => 'Test', '{lastname}' => 'Regtest', '{shop_name}' => 'Shop']);
    neria_assert($plain !== '' && strpos($plain, '{') === false, "Le sujet d'un modèle sans variable a changé : « {$plain} »");

    return [
        'pass'    => true,
        'message' => "Le sujet dérivé du titre (palier de 5 commandes) contient l'ordinal « 5ème » sans variable en clair ni espace doublé (fr/en/de/ja), un modèle sans variable garde son sujet — bug corrigé le 24/09/2026",
    ];
}
