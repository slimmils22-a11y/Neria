<?php
/**
 * Amélioration (demande explicite du 22/09/2026, après la résolution du blocage russe/arabe en conditions
 * réelles) : HealthCheckManager::checkSmtpConfig() doit escalader en ERREUR (pas un simple avertissement
 * générique) dès qu'UNE SEULE langue active de la boutique est à alphabet non latin et que le mail() basique
 * (PS_MAIL_METHOD=1) est utilisé — pas seulement à partir de deux langues actives : un marchand mono-langue
 * arabe, par exemple, ne doit pas passer inaperçu sous prétexte qu'aucune "seconde langue" ne serait active.
 *
 * Constat réel à l'origine : sur ps-test, avec mail() basique et un domaine récent/faible volume, les sujets
 * de mail en arabe et en russe étaient bloqués SILENCIEUSEMENT (ni boîte de réception ni indésirables, reproduit
 * sur 3 fournisseurs de réception distincts), résolu uniquement par un SMTP authentifié dédié. Le japonais est
 * passé sans problème dans les mêmes conditions, mais un seul test favorable ne permet pas de le retirer de la
 * liste — les 19 langues n'ont pas toutes été testées, mieux vaut avertir à tort qu'à raison sur un risque de
 * perte SILENCIEUSE de mails.
 *
 * Test comportemental réel : sur l'environnement de test, les 19 langues sont déjà actives (dont les 6 à
 * alphabet non latin) — vérifie directement le cas ERROR, puis désactive temporairement les langues non
 * latines pour vérifier le repli WARNING générique (langues 100% latines), avant restauration garantie.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $originalMethod = (string) Configuration::get('PS_MAIL_METHOD');
    $nonLatin       = HealthCheckManager::NON_LATIN_SCRIPT_LANGS;
    $langIds        = $db->executeS(
        "SELECT id_lang, iso_code FROM {$prefix}lang WHERE iso_code IN ('" . implode("','", array_map('pSQL', $nonLatin)) . "')"
    );
    neria_assert(!empty($langIds), "Aucune des langues à alphabet non latin ({" . implode(',', $nonLatin) . "}) n'existe sur cet environnement de test — jeu de test invalide");

    $module = neria_test_module();
    $check  = new ReflectionMethod(HealthCheckManager::class, 'checkSmtpConfig');
    $check->setAccessible(true);

    Configuration::updateValue('PS_MAIL_METHOD', '1');

    try {
        // 1. Cas réel de l'environnement de test : les langues non latines sont déjà actives pour la boutique
        // → ERROR, message citant au moins une des langues concernées.
        $hcm    = new HealthCheckManager($module);
        $result = $check->invoke($hcm);
        neria_assert(
            ($result['status'] ?? '') === HealthCheckManager::STATUS_ERROR,
            "checkSmtpConfig() ne renvoie plus 'error' avec mail() basique et une langue active à alphabet non latin (obtenu : '" . ($result['status'] ?? '?') . "') — régression du correctif du 22/09/2026"
        );
        $detailPlain = strip_tags((string) ($result['detail'] ?? ''));
        $mentionsOne = false;
        foreach ($nonLatin as $iso) {
            if (strpos($detailPlain, $iso) !== false) {
                $mentionsOne = true;
                break;
            }
        }
        neria_assert($mentionsOne, "Le message d'erreur ne cite aucune des langues non latines actives (obtenu : '{$detailPlain}')");

        // 2. Désactive TOUTES les langues non latines pour cette boutique (une seule suffit à retomber sur
        // l'avertissement générique — testé en ne gardant AUCUNE des 6) → repli WARNING générique.
        // Language::getLanguages() met en cache statique static::$_LANGUAGES, jamais invalidé par une
        // modification SQL directe dans le MÊME process — Language::resetCache() est indispensable ici, sans
        // quoi le contrôle relirait silencieusement l'ancien état (piège déjà rencontré : état static PHP-FPM
        // ≠ portée requête).
        $db->execute("DELETE FROM {$prefix}lang_shop WHERE id_shop={$idShop} AND id_lang IN (" . implode(',', array_column($langIds, 'id_lang')) . ")");
        Language::resetCache();

        $hcm2    = new HealthCheckManager($module);
        $result2 = $check->invoke($hcm2);
        neria_assert(
            ($result2['status'] ?? '') === HealthCheckManager::STATUS_WARNING,
            "checkSmtpConfig() ne retombe plus sur l'avertissement générique quand aucune langue active n'est à alphabet non latin (obtenu : '" . ($result2['status'] ?? '?') . "')"
        );
    } finally {
        // Restauration garantie, dans les deux sens : langues réassociées, méthode mail d'origine, cache
        // Language réinvalidé une dernière fois pour ne rien laisser fuiter au test suivant du même process.
        foreach ($langIds as $row) {
            $db->execute(
                "INSERT IGNORE INTO {$prefix}lang_shop (id_lang, id_shop) VALUES (" . (int) $row['id_lang'] . ", {$idShop})"
            );
        }
        Language::resetCache();
        Configuration::updateValue('PS_MAIL_METHOD', $originalMethod);
    }

    return [
        'pass'    => true,
        'message' => "checkSmtpConfig() escalade bien en erreur dès qu'une seule langue active à alphabet non latin est présente avec mail() basique, et retombe sur l'avertissement générique sinon",
    ];
}
