<?php
/**
 * Bloc 6 (19/09/2026) : l'effacement RGPD PAR ADRESSE EMAIL SEULE (personne sans
 * compte client — destinataire d'un envoi manuel, abonné newsletter, bounce ;
 * psgdpr deleteCustomer('email') appelle actionDeleteGDPRCustomer avec
 * ['email' => ...] et AUCUN id) était silencieusement ignoré : le crochet de
 * Neria faisait `return` dès que id_customer <= 0. Le droit à l'effacement
 * n'était donc pas honoré pour ces personnes.
 *
 * Corrigé : le crochet accepte l'email seul (adresse valide) et
 * GdprAuditManager::purgeCustomerData() n'exécute plus la boucle par id_customer
 * quand l'id vaut 0 — sinon « DELETE ... WHERE id_customer = 0 » aurait effacé
 * les lignes de TOUS les destinataires anonymes.
 *
 * Test comportemental réel via le VRAI crochet (Hook::exec) : une adresse sans
 * compte (bounce + préférence) est purgée ; la ligne anonyme d'un AUTRE
 * destinataire (id_customer = 0) survit ; une adresse invalide ne fait rien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';
    $module = neria_test_module();
    $db     = neria_test_db();
    $p      = neria_test_prefix();

    $email  = 'regtest803-guest@example.com';
    $other  = 'regtest803-other@example.com';
    $marker = 'regtest803_marker';

    $cleanup = function () use ($db, $p, $email, $other, $marker): void {
        $db->execute("DELETE FROM {$p}neria_bounces WHERE email IN ('{$email}','{$other}')");
        $db->execute("DELETE FROM {$p}neria_preferences WHERE email IN ('{$email}','{$other}')");
        $db->execute("DELETE FROM {$p}neria_behavioral_sent WHERE template = '{$marker}'");
    };
    $cleanup();

    try {
        $idShop = (int) \Context::getContext()->shop->id;
        (new BounceManager($module))->recordBounce($email, 'hard', 'regtest803', 'manual');
        (new BounceManager($module))->recordBounce($other, 'hard', 'regtest803', 'manual');
        $db->execute("INSERT INTO {$p}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
                      VALUES ({$idShop}, 0, '{$email}', 'loyalty', 0, NOW())");
        // Ligne anonyme d'un AUTRE destinataire : ne doit JAMAIS disparaître.
        $db->execute("INSERT INTO {$p}neria_behavioral_sent (id_customer, template, ref_id, id_shop, sent_at)
                      VALUES (0, '{$marker}', 1, {$idShop}, NOW())");

        $n = function (string $t, string $c, string $v) use ($db, $p): int {
            return (int) $db->getValue("SELECT COUNT(*) FROM {$p}{$t} WHERE {$c} = '{$v}'", false);
        };
        neria_assert($n('neria_bounces', 'email', $email) === 1, "jeu de test invalide : bounce non créé");
        neria_assert($n('neria_preferences', 'email', $email) === 1, "jeu de test invalide : préférence non créée");
        neria_assert($n('neria_behavioral_sent', 'template', $marker) === 1, "jeu de test invalide : ligne anonyme non créée");

        // Adresse invalide : aucun effet.
        \Hook::exec('actionDeleteGDPRCustomer', ['email' => 'pas-un-email']);
        neria_assert($n('neria_bounces', 'email', $email) === 1, "une adresse invalide a déclenché une purge");

        // Effacement par email seul, exactement comme le fait psgdpr.
        \Hook::exec('actionDeleteGDPRCustomer', ['email' => $email]);

        neria_assert($n('neria_bounces', 'email', $email) === 0, "le bounce de l'adresse sans compte n'a pas été effacé (effacement par email ignoré)");
        neria_assert($n('neria_preferences', 'email', $email) === 0, "la préférence de l'adresse sans compte n'a pas été effacée");
        neria_assert($n('neria_bounces', 'email', $other) === 1, "le bounce d'une AUTRE adresse a été effacé à tort");
        neria_assert($n('neria_behavioral_sent', 'template', $marker) === 1, "la ligne anonyme d'un autre destinataire (id_customer = 0) a été effacée à tort — boucle par id_customer exécutée avec id = 0");
    } finally {
        $cleanup();
    }

    return ['pass' => true, 'message' => "l'effacement RGPD par email seul purge l'adresse sans compte sans toucher aux données des autres destinataires anonymes — bloc 6 (19/09/2026)"];
}
