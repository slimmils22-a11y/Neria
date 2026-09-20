<?php
/**
 * Bloc 7 (19/09/2026) — onglet Calendrier ouvert en anglais sur ps-test :
 *  1. Le sélecteur d'événements affichait « Noël / Christmas », « Fête des Pères / Father's Day »,
 *     « Seollal (Corée) »… — français en tête, format bilingue figé (Neria::getCalendarKnownKeys()).
 *  2. La liste des pays (« Afrique du Sud, Allemagne, Albanie… ») suivait la langue de l'employé
 *     PrestaShop (français) et non celle du BO Neria (anglais).
 * Corrigé : 19 clés calendar.event.<clé> × 19 langues ; noms de pays résolus dans la langue du BO Neria.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator'] as $c) {
        require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
    }
    $langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'];
    $dict  = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    $module = neria_test_module();

    $call = function (string $method) use ($module) {
        $m = new ReflectionMethod(get_class($module), $method);
        $m->setAccessible(true);
        return $m->invoke($module);
    };

    // 1) Couverture : chaque événement connu a sa clé × 19 langues.
    $orig = AdminTranslator::currentLang();
    try {
        AdminTranslator::setLang('fr');
        $frEvents = $call('getCalendarKnownKeys');
        neria_assert(count($frEvents) === 19, "nombre d'événements inattendu (" . count($frEvents) . ") — un événement a été ajouté sans traduction ?");
        foreach (array_keys($frEvents) as $ev) {
            neria_assert(isset($dict["calendar.event.{$ev}"]), "clé calendar.event.{$ev} absente");
            foreach ($langs as $l) {
                neria_assert(trim((string) ($dict["calendar.event.{$ev}"][$l] ?? '')) !== '', "calendar.event.{$ev} vide pour '{$l}'");
            }
        }
        neria_assert($frEvents['christmas'] === 'Noël' && $frEvents['fathers_day'] === 'Fête des Pères', "libellés français inattendus : " . json_encode($frEvents, JSON_UNESCAPED_UNICODE));

        // 2) Langues : un seul libellé, dans la langue du BO.
        AdminTranslator::setLang('en');
        $en = $call('getCalendarKnownKeys');
        neria_assert($en['christmas'] === 'Christmas' && $en['fathers_day'] === "Father's Day" && $en['seollal'] === 'Seollal (Korea)', "libellés EN inattendus : " . json_encode($en, JSON_UNESCAPED_UNICODE));
        foreach ($en as $k => $v) {
            neria_assert(strpos($v, ' / ') === false && preg_match('/Noël|Fête|Pâques|Aïd|Japon|Corée/u', $v) !== 1, "événement {$k} : français ou format bilingue résiduel en EN : {$v}");
        }
        AdminTranslator::setLang('ja');
        neria_assert(preg_match('/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $call('getCalendarKnownKeys')['christmas']) === 1, "événement christmas non traduit en japonais");

        // 3) Pays : noms dans la langue du BO Neria (langue PrestaShop correspondante installée).
        $idEn = (int) Language::getIdByIso('en');
        $idFr = (int) Language::getIdByIso('fr');
        if ($idEn > 0 && $idFr > 0) {
            AdminTranslator::setLang('en');
            $countriesEn = $call('getCountriesListForSelect');
            neria_assert(($countriesEn['DE'] ?? '') === 'Germany' && ($countriesEn['ZA'] ?? '') === 'South Africa', "pays EN inattendus : DE=" . ($countriesEn['DE'] ?? '?') . ", ZA=" . ($countriesEn['ZA'] ?? '?'));
            AdminTranslator::setLang('fr');
            $countriesFr = $call('getCountriesListForSelect');
            neria_assert(($countriesFr['DE'] ?? '') === 'Allemagne' && ($countriesFr['ZA'] ?? '') === 'Afrique du Sud', "pays FR inattendus : DE=" . ($countriesFr['DE'] ?? '?'));
        }
    } finally {
        AdminTranslator::setLang($orig);
    }

    return ['pass' => true, 'message' => "événements du Calendrier (19 × 19 langues) et noms de pays suivent la langue du BO Neria — bloc 7 (19/09/2026)"];
}
