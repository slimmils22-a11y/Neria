<?php
/**
 * Régression : CertificateManager::resolveCertificateLang() appliquait
 * TOUJOURS l'algorithme pays/code-postal (TranslationEngine::
 * resolveOptimalLang()), même quand NERIA_AUTO_LANG est désactivé —
 * contrairement à EmailRenderer::resolveEmailLang() qui court-circuite cet
 * algorithme et retombe sur la langue brute du compte client
 * (langFromId($idLang)) dès que le toggle est désactivé. Un client dont le
 * compte est dans une langue mais dont l'adresse de facturation est dans un
 * pays associé à une autre langue recevait alors un PDF de certificat dans
 * une langue différente de celle de l'email qui l'accompagne — cassant
 * exactement la parité PDF/email que le commentaire de la classe annonce
 * pourtant garantir.
 *
 * Corrigé le 09/08/2026 (round 158) : resolveCertificateLang() vérifie
 * désormais ConfigManager::isAutoLangEnabled() en premier, comme
 * EmailRenderer::resolveEmailLang().
 *
 * Test comportemental réel : désactive NERIA_AUTO_LANG, appelle
 * resolveCertificateLang() (privée, via Reflection) sur une VRAIE commande
 * existante, et vérifie que le résultat est identique à
 * TranslationEngine::langFromId($idLang) — c'est-à-dire que l'algorithme
 * pays n'est plus du tout consulté, quel que soit le pays de facturation
 * réel de cette commande.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $orderRow = $db->getRow("SELECT id_order, id_lang FROM {$prefix}orders WHERE valid = 1 ORDER BY id_order DESC");
    neria_assert($orderRow !== false, 'Aucune commande valide trouvée en base — jeu de test invalide');

    $savedAutoLang = Configuration::get('NERIA_AUTO_LANG');

    try {
        Configuration::updateValue('NERIA_AUTO_LANG', 0);

        $order  = new Order((int) $orderRow['id_order']);
        $idLang = (int) $orderRow['id_lang'];

        $mgr = new CertificateManager(neria_test_module());
        $ref = new ReflectionMethod($mgr, 'resolveCertificateLang');
        $ref->setAccessible(true);
        $resolvedLang = $ref->invoke($mgr, $order, $idLang);

        $engine        = new TranslationEngine(neria_test_module());
        $expectedLang  = $engine->langFromId($idLang);

        neria_assert(
            $resolvedLang === $expectedLang,
            "resolveCertificateLang() a retourné '{$resolvedLang}' au lieu de '{$expectedLang}' (langFromId direct) alors que NERIA_AUTO_LANG est désactivé — régression du bug corrigé le 09/08/2026 (round 158) : l'algorithme pays/code-postal serait de nouveau appliqué inconditionnellement, cassant la parité PDF/email annoncée par EmailRenderer::resolveEmailLang()"
        );

        return [
            'pass'    => true,
            'message' => "CertificateManager::resolveCertificateLang() respecte bien NERIA_AUTO_LANG désactivé (repli sur langFromId, comme EmailRenderer) — bug corrigé le 09/08/2026 (round 158)",
        ];
    } finally {
        if ($savedAutoLang !== false && $savedAutoLang !== null) {
            Configuration::updateValue('NERIA_AUTO_LANG', $savedAutoLang);
        } else {
            Configuration::updateValue('NERIA_AUTO_LANG', 1);
        }
    }
}
