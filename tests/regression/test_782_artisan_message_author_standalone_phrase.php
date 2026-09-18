<?php
/**
 * Contenu : la traduction 'artisan_message_author' du template
 * 'artisan_message' était une simple préposition dans les 19 langues
 * ("From" EN/GB, "Von" DE, "De la part de" FR, "Da parte di" IT, "من طرف"
 * AR, "より" JA...) — censée introduire un nom d'artisan, mais AUCUNE
 * variable de nom n'existe dans ce template (vérifié dans
 * mails/themes/neria_global/core/artisan_message.html : la signature est
 * `— {neria_trad key='artisan_message_author'}`, rien d'autre). Résultat
 * dans l'email réel : "— From" tout seul, comme si un nom avait été
 * oublié — maladroit pour un module pensé pour du luxe.
 *
 * Repéré via un envoi manuel de test réel (bloc 3, feuille de route de
 * tests manuels, 18/09/2026).
 *
 * Corrigé : remplacé par une formule autonome et complète dans les 19
 * langues (ex. "The Workshop" / "L'atelier" / "Die Werkstatt"...), qui se
 * lit naturellement seule après le tiret, sans laisser deviner un nom
 * manquant. Appliqué au fichier source (data/translations.json) ET aux
 * bases déjà installées (neria_translation, is_custom=0 uniquement — ne
 * touche pas une éventuelle personnalisation marchand).
 *
 * Test comportemental réel : vérifie, pour les 19 langues, que la
 * traduction compilée n'est plus une préposition orpheline connue.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $module = neria_test_module();
    $engine = new TranslationEngine($module);

    // Anciennes valeurs "préposition seule" — si l'une d'elles réapparaît,
    // c'est que le contenu a régressé (réinstallation avec un fichier
    // source obsolète, ou UPDATE annulé).
    $oldBrokenValues = [
        'fr' => 'De la part de',
        'en' => 'From',
        'de' => 'Von',
        'it' => 'Da parte di',
        'es' => 'De parte de',
        'pt' => 'Da parte de',
        'br' => 'Da parte de',
        'gb' => 'From',
        'ar' => 'من طرف',
        'ja' => 'より',
        'ko' => '로부터',
        'zh' => '来自',
        'tw' => '來自',
        'ru' => 'От',
        'tr' => 'Tarafından',
        'sv' => 'Från',
        'no' => 'Fra',
        'da' => 'Fra',
        'nl' => 'Van',
    ];

    $stillBroken = [];
    foreach ($oldBrokenValues as $lang => $brokenValue) {
        $current = $engine->get('artisan_message', 'artisan_message_author', $lang);
        if ($current === $brokenValue) {
            $stillBroken[] = "{$lang}: '{$current}'";
        }
        neria_assert(
            $current !== '',
            "artisan_message_author est vide pour la langue '{$lang}' — jeu de test invalide ou régression du seed de traduction"
        );
    }

    neria_assert(
        empty($stillBroken),
        "artisan_message_author reste une préposition orpheline connue pour : " . implode(', ', $stillBroken) . " — régression du correctif de contenu bloc 3 (18/09/2026), l'email afficherait de nouveau une signature du type '— From' sans nom"
    );

    return [
        'pass'    => true,
        'message' => "artisan_message_author est désormais une formule autonome et complète dans les 19 langues, plus une préposition orpheline — corrigé round bloc 3 (18/09/2026)",
    ];
}
