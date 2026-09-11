<?php
/**
 * Régression : `newsletter_conf` (email de confirmation de double opt-in à
 * l'inscription newsletter) était listé dans
 * `AbTestManager::getEligibleTemplates()` — catalogue explicitement
 * documenté comme "Templates marketing éligibles à l'A/B testing... pas les
 * transactionnels obligatoires comme order_conf" — alors qu'il s'agit
 * précisément d'un email transactionnel obligatoire du même type. Ce
 * catalogue alimente directement le sélecteur BO de création de test A/B
 * (neria.php, 3 usages), rendant `newsletter_conf` réellement
 * sélectionnable par un marchand.
 *
 * Aggravant : `newsletter_conf` est absent de
 * `PreferencesManager::TEMPLATE_CAT` (délibérément — un client ne peut pas
 * être "préférence-gaté" sur l'email qui confirme sa propre action
 * d'inscription), donc `isAllowed()` le traite comme "non classé → toujours
 * envoyé". Sa présence dans le catalogue A/B créait donc une incohérence :
 * soit le retirer du catalogue marketing (transactionnel, jamais
 * A/B-testable), soit l'ajouter à TEMPLATE_CAT (mais cela bloquerait alors
 * l'envoi de la confirmation elle-même à un client ayant décoché
 * "newsletter" — non-sens, puisque cet email EST l'action d'inscription).
 *
 * Bug identifié le 11/09/2026 (round 336, audit CssInliner/
 * OrderTriggersManager/PreferencesManager).
 *
 * Corrigé le 11/09/2026 (round 336) : `newsletter_conf` retiré du catalogue
 * `AbTestManager::getEligibleTemplates()` — reste absent de TEMPLATE_CAT
 * (transactionnel, cohérent avec certificate_email/neria_fallback/
 * monthly_report). L'exclusion défensive dans le garde-fou
 * HealthCheckManager (array_diff) est conservée par robustesse.
 *
 * Test comportemental réel : vérifie que 'newsletter_conf' n'apparaît plus
 * dans le tableau réellement retourné par getEligibleTemplates() (pas
 * seulement une vérification structurelle du code source), et reste
 * absent de TEMPLATE_CAT.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $module = neria_test_module();
    $ab     = new ABTestManager($module);

    $eligible = $ab->getEligibleTemplates();
    neria_assert(is_array($eligible) && count($eligible) > 10, "getEligibleTemplates() renvoie un catalogue anormalement petit ou invalide — jeu de test invalide");
    neria_assert(
        !array_key_exists('newsletter_conf', $eligible),
        "AbTestManager::getEligibleTemplates() liste encore 'newsletter_conf' — régression du bug corrigé le 11/09/2026 (round 336) : un marchand pourrait de nouveau créer un test A/B sur la confirmation de double opt-in, un email transactionnel obligatoire"
    );

    neria_assert(
        !isset(PreferencesManager::TEMPLATE_CAT['newsletter_conf']),
        "PreferencesManager::TEMPLATE_CAT contient désormais 'newsletter_conf' — contradiction avec le retrait du catalogue A/B : cet email transactionnel ne doit être NI A/B-testable NI préférence-gaté (bloquerait sinon l'envoi de la confirmation d'inscription elle-même à un client ayant décoché 'newsletter')"
    );

    // 'newsletter_voucher' (marketing, distinct) doit lui rester éligible —
    // contrôle négatif garantissant que le retrait a bien ciblé le seul
    // template transactionnel, pas toute la famille 'newsletter_*'.
    neria_assert(
        array_key_exists('newsletter_voucher', $eligible),
        "AbTestManager::getEligibleTemplates() ne liste plus 'newsletter_voucher' — retrait trop large, ce template marketing doit rester éligible à l'A/B testing"
    );

    return [
        'pass'    => true,
        'message' => "AbTestManager::getEligibleTemplates() ne liste plus 'newsletter_conf' (confirmation de double opt-in, transactionnelle) — cohérent avec son absence de PreferencesManager::TEMPLATE_CAT — bug corrigé le 11/09/2026 (round 336)",
    ];
}
