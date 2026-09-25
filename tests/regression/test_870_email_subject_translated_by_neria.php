<?php
/**
 * Décision utilisateur du 25/09/2026 : Neria traduit TOUS les sujets d'e-mails, natifs ou non, par le même mécanisme
 * que le corps (dictionnaire, 19 langues), que le marchand ait ajouté un pack de langue PrestaShop ou non. Constat
 * préalable sur ps-test : sujets d'états de commande en anglais dans les 8 langues (« Payment accepted », « Shipped »),
 * 6 sujets natifs non traduits en arabe/japonais/russe.
 *
 * Corrigé : clé « subject » (40 modèles natifs × 19 langues, data/translations.json) appliquée par
 * EmailRenderer::dictionarySubject() à la place du sujet fourni par PrestaShop ; variables substituées, variables non
 * résolues retirées ; sans clé « subject », le sujet fourni est conservé.
 *
 * Test : dictionnaire complet (19 langues, sans accolade parasite) + comportement de dictionarySubject() sur la base
 * (recharger les traductions si la base est antérieure à la 1.0.50).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $langs = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    $dict = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/translations.json'), true);
    $native = ['account','backoffice_order','bankwire','cheque','contact','contact_form','credit_slip','customer_qty','delivered','download_product','employee_password','forward_msg','guest_to_customer','import','in_transit','newsletter','newsletter_conf','newsletter_verif','newsletter_voucher','order_canceled','order_changed','order_conf','order_customer_comment','order_merchant_comment','order_return_state','outofstock','password','password_query','payment','payment_error','preparation','productcoverage','productoutofstock','refund','reply_msg','return_slip','shipped','voucher','voucher_new','new_order'];
    foreach ($native as $t) {
        foreach ($langs as $l) {
            $s = (string) ($dict[$t][$l]['subject'] ?? '');
            neria_assert($s !== '', "Sujet manquant : {$t}/{$l}");
            neria_assert(mb_strlen($s) <= 90, "Sujet trop long ({$t}/{$l}) : {$s}");
            neria_assert(preg_replace('/\{order_name\}/', '', $s) === str_replace('{order_name}', '', $s) && strpos(str_replace('{order_name}', '', $s), '{') === false, "Variable inconnue dans le sujet {$t}/{$l} : {$s}");
        }
        neria_assert($dict[$t]['ja']['subject'] !== $dict[$t]['en']['subject'], "Sujet japonais identique à l'anglais : {$t}");
    }

    // Comportement (base à jour 1.0.50)
    $db = neria_test_db();
    $p = neria_test_prefix();
    if ((int) $db->getValue("SELECT COUNT(*) FROM {$p}neria_translation WHERE template='payment' AND translation_key='subject'") === 0) {
        (new TranslationInstaller($module))->importFromJson(_PS_MODULE_DIR_ . 'neria/data/translations.json');
    }
    $renderer = new EmailRenderer($module);
    $rm = new ReflectionMethod($renderer, 'dictionarySubject');
    $rm->setAccessible(true);
    $call = static function (string $tpl, string $lang, array $vars = []) use ($rm, $renderer): string {
        return (string) $rm->invoke($renderer, $tpl, $lang, ['templateVars' => $vars], '');
    };
    neria_assert($call('payment', 'fr') === 'Paiement accepté', 'payment/fr : ' . $call('payment', 'fr'));
    neria_assert($call('payment', 'ja') === 'お支払いを確認しました', 'payment/ja : ' . $call('payment', 'ja'));
    neria_assert($call('shipped', 'ar') === 'تم شحن طلبكم', 'shipped/ar');
    neria_assert($call('order_conf', 'de', ['{order_name}' => 'ABCDEFGHI']) === 'Bestellbestätigung ABCDEFGHI', 'variable {order_name} non substituée : ' . $call('order_conf', 'de', ['{order_name}' => 'ABCDEFGHI']));
    neria_assert($call('order_conf', 'de') === 'Bestellbestätigung', 'variable non résolue non retirée : ' . $call('order_conf', 'de'));
    neria_assert($call('modele_inconnu_de_neria', 'fr') === '', 'un modèle sans clé subject doit conserver le sujet fourni');

    $er = str_replace("
", "
", (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php'));
    neria_assert(str_contains($er, '$dictSubject = $this->dictionarySubject($template, $lang, $params, $variant);'), "applyNeriaRendering() n'appelle plus dictionarySubject()");
    neria_assert(str_contains($er, "\$params['subject'] = \$dictSubject;"), "Le sujet du dictionnaire n'est plus appliqué");

    return ['pass' => true, 'message' => "Sujets traduits par Neria pour 40 modèles natifs × 19 langues (variables substituées/retirées, sujet fourni conservé sans clé « subject ») — décision du 25/09/2026"];
}
