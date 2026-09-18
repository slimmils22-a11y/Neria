<?php
/**
 * Contenu : l'aide et les exemples des campagnes saisonnières citaient un
 * template 'special_offer' qui n'existe pas (ni dans mails/, ni dans la liste
 * déroulante, limitée aux templates d'envoi manuel) — repéré en revue manuelle
 * du bloc 4 (18/09/2026). Corrigé : private_sale / end_of_year_gift.
 * Le test vérifie que TOUT template cité en <em>slug</em> dans les clés
 * seasonal.* est réellement proposé par ManualSendManager::isSendable().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    $msm  = new ManualSendManager(neria_test_module());
    $data = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);

    $checked = 0;
    $bad     = [];
    foreach (['seasonal.example_blackfriday', 'seasonal.example_christmas', 'seasonal.example_summersale', 'seasonal.usage_item2'] as $key) {
        neria_assert(isset($data[$key]) && count($data[$key]) >= 19, "clé {$key} absente ou incomplète");
        foreach ($data[$key] as $lang => $text) {
            if (preg_match_all('/<em>([a-z_]+)<\/em>/', (string) $text, $m)) {
                foreach ($m[1] as $slug) {
                    if (in_array($slug, ['gift_ideas', 'Gift Ideas Mode'], true)) { continue; }
                    $checked++;
                    if (!$msm->isSendable($slug)) { $bad[] = "{$key}[{$lang}]={$slug}"; }
                }
            }
        }
    }
    neria_assert($checked > 0, "aucun template cité — test invalide");
    neria_assert(empty($bad), "templates cités mais non sélectionnables : " . implode(', ', array_slice($bad, 0, 5)));

    return ['pass' => true, 'message' => "les {$checked} templates cités dans l'aide des campagnes saisonnières sont tous sélectionnables — bloc 4 (18/09/2026)"];
}
