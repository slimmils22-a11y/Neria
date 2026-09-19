<?php
/**
 * Bloc 6 (19/09/2026) : le tableau de bord RGPD (onglet Confidentialité) et son
 * rapport téléchargeable étaient ÉCRITS EN FRANÇAIS — registre des données
 * (26 libellés + notes), contrôles de désabonnement, cartographie des données
 * personnelles (« Prénom », « Adresse e-mail »…), base légale — dans un BO
 * proposé en 19 langues : GdprAuditManager (1509 lignes) n'appelait
 * AdminTranslator nulle part. Constaté en ouvrant l'onglet sur ps-test en anglais.
 *
 * Corrigé : chaque texte passe par GdprAuditManager::tr() (clés gdpr.reg.*,
 * gdpr.unsub.*, gdpr.pii.*, gdpr.legal_basis_*, gdpr.report.*), 81+ clés × 19
 * langues ; le texte français d'origine reste le repli.
 *
 * Test comportemental réel : (1) toutes les clés utilisées existent, non vides,
 * dans les 19 langues ; (2) l'AUDIT RÉEL est exécuté en anglais et en japonais
 * — aucun texte français ne subsiste — et en français (repli inchangé) ;
 * (3) le rapport est rendu dans la langue du BO (lang/dir, plus de français).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator', 'GdprAuditManager'] as $c) {
        require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
    }

    $dir  = _PS_MODULE_DIR_ . 'neria/';
    $dict = json_decode((string) file_get_contents($dir . 'data/admin_translations.json'), true);
    $src  = (string) file_get_contents($dir . 'src/GdprAuditManager.php');
    $langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'];

    // 1) Clés attendues : littérales dans le code + dérivées du registre et des variables PII.
    $keys = [];
    preg_match_all("/(?:self::tr|\\\$t)\(\s*'(gdpr\.[a-z0-9_.]+)'/", $src, $m);
    foreach ($m[1] as $k) {
        if (substr($k, -1) === '.') { continue; } // préfixe de clé dynamique
        $keys[$k] = true;
    }
    foreach (GdprAuditManager::REGISTRY as $r) {
        $keys['gdpr.reg.' . $r['table'] . '.label'] = true;
        $keys['gdpr.reg.' . $r['table'] . '.note']  = true;
    }
    foreach (array_keys(GdprAuditManager::PII_VARS) as $var) {
        $keys['gdpr.pii.' . trim($var, '{}')] = true;
    }
    neria_assert(count($keys) >= 85, "nombre de clés RGPD attendues anormalement bas (" . count($keys) . ") — extraction des clés invalide");
    foreach (array_keys($keys) as $k) {
        neria_assert(isset($dict[$k]), "clé de traduction {$k} absente de admin_translations.json");
        foreach ($langs as $l) {
            neria_assert(trim((string) ($dict[$k][$l] ?? '')) !== '', "clé {$k} vide pour la langue '{$l}'");
        }
    }

    // 2) Audit réel dans plusieurs langues.
    $french = '/[éèêëàâçùûôîï]|(désabonn\w*|données|Prénom|Téléphone|Contrat|échecs?|Journal|Statistiques|Historique|envoi|attente|Liste d)/iu';
    $orig   = \AdminTranslator::currentLang();
    $mgr    = new GdprAuditManager(rtrim(_PS_MODULE_DIR_ . 'neria', '/'));
    try {
        foreach (['en', 'ja', 'de'] as $lang) {
            \AdminTranslator::setLang($lang);
            $audit = $mgr->runAudit();
            $texts = [];
            foreach ($audit['unsubscribe']['checks'] as $c) {
                $texts[] = $c['label'];
                $texts[] = $c['detail'];
            }
            foreach ($audit['retention']['rows'] as $r) {
                $texts[] = $r['label'];
                $texts[] = $r['note'];
            }
            foreach ($audit['pii']['map'] as $row) {
                $texts[] = implode(', ', $row['vars']);
                $texts[] = $row['legal_basis'];
            }
            neria_assert(count($texts) >= 60, "[{$lang}] audit : trop peu de textes collectés (" . count($texts) . ")");
            foreach ($texts as $t) {
                neria_assert(preg_match($french, (string) $t) !== 1, "[{$lang}] texte français resté dans l'audit : « " . mb_substr((string) $t, 0, 90) . " »");
            }
            if ($lang === 'ja') {
                neria_assert(preg_match('/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $audit['retention']['rows'][0]['label']) === 1, "[ja] le libellé du registre n'est pas en japonais : " . $audit['retention']['rows'][0]['label']);
            }
        }

        // Français : le texte d'origine est conservé.
        \AdminTranslator::setLang('fr');
        $audit = $mgr->runAudit();
        neria_assert($audit['retention']['rows'][0]['label'] === "Statistiques d'envoi (tracking)", "[fr] le libellé français du registre a changé : " . $audit['retention']['rows'][0]['label']);

        // 3) Rapport téléchargeable.
        \AdminTranslator::setLang('en');
        $html = $mgr->generateReport($mgr->runAudit(), 'Boutique Test');
        neria_assert(strpos($html, '<html lang="en"') !== false, "rapport EN : attribut lang incorrect");
        neria_assert(strpos($html, 'GDPR compliance report') !== false, "rapport EN : titre traduit absent");
        neria_assert(preg_match('/Rapport de conformité|Score de conformité|Système de désabonnement|Document à usage interne|Enregistrer en PDF|Avis de limitation/u', $html) !== 1, "rapport EN : texte français résiduel");
        neria_assert(strpos($html, '%1$d') === false && strpos($html, '%2$d') === false, "rapport EN : marqueurs sprintf non remplacés");
        \AdminTranslator::setLang('ar');
        $htmlAr = $mgr->generateReport($mgr->runAudit(), 'Boutique Test');
        neria_assert(strpos($htmlAr, 'dir="rtl"') !== false, "rapport AR : direction rtl absente");
    } finally {
        \AdminTranslator::setLang($orig);
    }

    return ['pass' => true, 'message' => "le tableau de bord et le rapport RGPD sont traduits dans les 19 langues (" . count($keys) . " clés), plus de français en dur — bloc 6 (19/09/2026)"];
}
