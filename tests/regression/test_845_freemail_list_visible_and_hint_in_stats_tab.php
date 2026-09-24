<?php
/**
 * Round 371 (suite du round 370) : la liste intégrée des domaines de messagerie gratuite n'était
 * visible nulle part — le marchand ne pouvait pas savoir si son fournisseur était couvert. Elle est
 * désormais consultable (bloc repliable, en lecture seule) sous le champ « Autres domaines de
 * messagerie gratuite » de l'onglet Statistiques, avec les variantes par pays reconnues, et
 * l'audit d'un domaine peu noté (C/D/F) rappelle qu'un fournisseur non reconnu peut être ajouté.
 *
 * Test comportemental : rendu réel de l'onglet Statistiques (fr et ar). La liste affichée contient
 * bien les domaines intégrés et leur nombre exact ; le rappel n'apparaît que pour un grade C/D/F
 * (jamais pour un bon score ni pour un expéditeur déjà reconnu comme messagerie gratuite).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $db = neria_test_db();
    $p = neria_test_prefix();

    $list = DomainReputationManager::getBuiltinFreemailDomains();
    neria_assert(count($list) >= 250, 'La liste intégrée doit compter au moins 250 domaines (obtenu ' . count($list) . ')');
    $sorted = $list; sort($sorted);
    neria_assert($list === $sorted && $list === array_values(array_unique($list)), 'La liste doit être triée et sans doublon');
    foreach ($list as $d) {
        neria_assert($d === strtolower($d) && strpos($d, '.') !== false, "Entrée invalide dans la liste : {$d}");
    }
    neria_assert(in_array('gmail.com', $list, true) && in_array('seznam.cz', $list, true) && in_array('uol.com.br', $list, true), 'Domaines de référence absents');
    neria_assert(in_array('yahoo', DomainReputationManager::getFreemailBaseLabels(), true), 'Variantes par pays absentes');

    $ctx = Context::getContext();
    $origEmployee = $ctx->employee; $origLang = $ctx->language;
    $ctx->employee = new Employee((int) $db->getValue("SELECT id_employee FROM {$p}employee ORDER BY id_employee"));
    $idShop = (int) $ctx->shop->id;
    $origCache = Configuration::get(DomainReputationManager::CONFIG_CACHE, null, null, $idShop);

    $render = static function (string $lang) use ($module, $ctx): string {
        $idLang = (int) Language::getIdByIso($lang) ?: (int) Configuration::get('PS_LANG_DEFAULT');
        $ctx->language = new Language($idLang);
        $ctx->employee->id_lang = $idLang;
        AdminTranslator::reset();
        AdminTranslator::setLang($lang);
        $_GET = $_REQUEST = ['neria_tab' => 'stats', 'configure' => 'neria'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Tools::resetStaticCache();
        ob_start();
        $html = '';
        try {
            $html = (string) $module->getContent();
        } finally {
            $html .= (string) ob_get_clean();
        }
        return $html;
    };
    $setReport = static function (array $r) use ($idShop): void {
        Configuration::updateValue(DomainReputationManager::CONFIG_CACHE, json_encode($r + [
            'ip' => null, 'checked_at' => date('Y-m-d H:i:s'), 'timestamp' => time(),
            'spf' => ['found' => true], 'dkim' => ['found' => true], 'dmarc' => ['found' => true, 'policy' => 'reject'],
            'mx' => ['found' => true], 'ptr' => ['found' => true], 'bimi' => ['found' => false],
            'blacklists' => ['checked' => 0, 'hits' => [], 'clean' => 0],
        ]), false, null, $idShop);
    };

    try {
        foreach (['fr', 'ar'] as $lang) {
            $setReport(['domain' => 'boutique-test.example', 'score' => 40, 'grade' => 'D', 'color' => '#c0392b']);
            $html = $render($lang);
            neria_assert(strpos($html, 'id="neria-freemail-builtin"') !== false, "[{$lang}] bloc repliable de la liste intégrée absent de l'onglet Statistiques");
            neria_assert(strpos($html, '(' . count($list) . ')') !== false, "[{$lang}] le nombre exact de domaines intégrés (" . count($list) . ") n'est pas affiché");
            neria_assert(strpos($html, 'gmail.com, ') !== false && strpos($html, 'seznam.cz') !== false, "[{$lang}] la liste affichée ne contient pas les domaines intégrés");
            neria_assert(strpos($html, 'yahoo.*') !== false, "[{$lang}] les variantes par pays (yahoo.*) ne sont pas affichées");
            neria_assert(strpos($html, 'id="neria-freemail-hint"') !== false, "[{$lang}] le rappel « fournisseur non reconnu » manque pour un grade D");

            $setReport(['domain' => 'boutique-test.example', 'score' => 95, 'grade' => 'A', 'color' => '#27ae60']);
            neria_assert(strpos($render($lang), 'id="neria-freemail-hint"') === false, "[{$lang}] le rappel ne doit pas apparaître pour un bon score (grade A)");

            $setReport(['domain' => 'gmail.com', 'freemail' => true, 'score' => 0, 'grade' => '-', 'color' => '#8c857e']);
            neria_assert(strpos($render($lang), 'id="neria-freemail-hint"') === false, "[{$lang}] le rappel ne doit pas apparaître quand l'expéditeur est déjà reconnu comme messagerie gratuite");
        }
    } finally {
        if ($origCache === false || $origCache === null) {
            Configuration::deleteByName(DomainReputationManager::CONFIG_CACHE);
        } else {
            Configuration::updateValue(DomainReputationManager::CONFIG_CACHE, $origCache, false, null, $idShop);
        }
        $ctx->employee = $origEmployee;
        $ctx->language = $origLang;
    }

    return [
        'pass'    => true,
        'message' => 'La liste intégrée (' . count($list) . ' domaines + variantes par pays) est consultable en lecture seule dans l\'onglet Statistiques (fr et ar) ; le rappel « ajoutez votre fournisseur » n\'apparaît que pour un grade C/D/F — round 371',
    ];
}
