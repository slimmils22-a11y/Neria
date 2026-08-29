<?php
/**
 * Régression round 240 (28/08/2026) : BehavioralCronManager::send() était
 * déclarée `void` — le seul appelant journalisant un résumé agrégé
 * (sendRelationshipAnniversaries()) incrémentait son compteur "sent"
 * INCONDITIONNELLEMENT juste après l'appel, même quand l'envoi était en
 * réalité annulé en interne (préférences refusées, bounce, blacklist,
 * cooldown) ou silencieusement refusé par Mail::Send(). Le résumé
 * Watchdog affichait alors un taux de succès de 100% qui ne reflétait
 * pas la réalité — pas de perte de données (les clients non servis
 * restent éligibles au prochain passage), mais un rapport trompeur.
 *
 * Corrigé le 28/08/2026 (round 240) : send() retourne désormais bool
 * (true = envoi réellement confirmé ou mis en file, false = annulé/
 * bloqué/échoué), et sendRelationshipAnniversaries() n'incrémente $sent
 * que sur un retour true.
 *
 * Test comportemental réel : un email fraîchement marqué en hard bounce
 * (BounceManager::recordBounce()) doit faire retourner FALSE à send()
 * (annulé par le garde-fou anti-bounce), pas juste "ne pas planter".
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $email  = 'regtest476-bounce@example.com';

    $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");

    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';

    $bounceMgr = new BounceManager($module);
    $bounceMgr->recordBounce($email, 'hard', 'regtest476 mailbox does not exist');

    try {
        neria_assert(
            BounceManager::isBounced($email),
            "BounceManager::isBounced() ne détecte pas le hard bounce fraîchement enregistré — jeu de test invalide"
        );

        $cronMgr = new BehavioralCronManager($module);
        $method  = new ReflectionMethod(BehavioralCronManager::class, 'send');
        $method->setAccessible(true);

        $result = $method->invoke(
            $cronMgr,
            'relationship_anniversary',
            ['email' => $email, 'firstname' => 'Test', 'lastname' => 'Regtest476', 'id_customer' => 0, 'id_lang' => 1],
            ['{years_label}' => '1 an'],
            0
        );

        neria_assert(
            $result === false,
            "BehavioralCronManager::send() retourne " . var_export($result, true) . " au lieu de false pour un email en hard bounce — "
            . "régression du bug corrigé le 28/08/2026 (round 240) : le compteur 'sent' du résumé Watchdog serait de nouveau incrémenté même sur un envoi annulé"
        );

        return [
            'pass'    => true,
            'message' => "BehavioralCronManager::send() retourne bien false quand l'envoi est annulé (bounce) — le compteur 'sent' du résumé reflète désormais le résultat réel",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");
    }
}
