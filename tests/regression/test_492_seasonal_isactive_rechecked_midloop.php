<?php
/**
 * Régression : SeasonalCampaignManager::runDueCampaigns() ne lisait
 * `is_active` qu'UNE SEULE FOIS (snapshot getAll() en tête de méthode),
 * avant la boucle `foreach ($customers as $customer)` — laquelle n'est
 * bornée par AUCUN LIMIT côté getEligibleCustomers().
 *
 * Bug identifié le 31/08/2026 (round 256, audit "lecture unique de config
 * au début d'un cron long") : si le marchand clique sur toggle() (BO) pour
 * stopper en urgence une campagne dont l'envoi est en cours (ex. plusieurs
 * milliers de clients ciblés par un segment large), le cron continuait à
 * envoyer à TOUS les clients restants du lot déjà chargé en mémoire,
 * ignorant totalement la désactivation jusqu'au prochain passage cron.
 *
 * Corrigé le 31/08/2026 (round 256) : nouvelle méthode privée
 * isStillActive($idCampaign) qui relit is_active en base (requête légère,
 * clé primaire), appelée tous les 20 clients dans la boucle d'envoi ; un
 * retour false interrompt l'envoi de cette campagne via un `break`.
 *
 * Test comportemental réel sur isStillActive() (le helper effectivement
 * appelé par la boucle) : crée une vraie campagne active, vérifie qu'elle
 * est lue comme active, appelle le VRAI toggle() (celui que le marchand
 * déclenche depuis le BO), puis vérifie que la relecture reflète bien la
 * désactivation. Complété par une vérification structurelle que la boucle
 * d'envoi appelle bien ce helper et interrompt l'envoi (break) sur un
 * retour false — une fixture avec des milliers de vrais clients et un
 * toggle() interleavé PENDANT l'exécution de runDueCampaigns() serait hors
 * périmètre d'un test isolé (même contrainte que test_417/test_385).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';

    $module = neria_test_module();
    $mgr    = new SeasonalCampaignManager($module);

    $idCampaign = $mgr->create([
        'name'          => 'Test round 256 isStillActive',
        'template'      => 'gift_ideas',
        'annual_date'   => '01-01',
        'days_before'   => 0,
        'is_active'     => 1,
        'target_segment' => '',
        'target_gender' => 0,
        'target_lang'   => '',
        'min_age'       => 0,
        'max_age'       => 0,
        'gift_mode'     => 0,
    ]);
    neria_assert($idCampaign > 0, "Jeu de test invalide : create() n'a pas renvoyé d'id de campagne valide");

    try {
        $ref = new ReflectionMethod(SeasonalCampaignManager::class, 'isStillActive');
        $ref->setAccessible(true);

        $activeBefore = $ref->invoke($mgr, $idCampaign);
        neria_assert(
            $activeBefore === true,
            "isStillActive() renvoie false juste après create(is_active=1) — jeu de test invalide ou helper cassé"
        );

        // Le VRAI toggle() déclenché par le marchand depuis le BO.
        $mgr->toggle($idCampaign);

        $activeAfter = $ref->invoke($mgr, $idCampaign);
        neria_assert(
            $activeAfter === false,
            "isStillActive() ne reflète pas la désactivation faite via toggle() — régression du correctif du 31/08/2026 (round 256) : un toggle() BO en cours d'envoi ne serait plus détecté par la boucle"
        );
    } finally {
        $mgr->delete($idCampaign);
    }

    // Vérification structurelle complémentaire : le helper est bien
    // appelé DANS la boucle d'envoi, avec un break sur retour false.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');

    $posLoop = strpos($src, 'foreach ($customers as $customer) {');
    $posMailSend = strpos($src, '$ok = \Mail::Send(');
    neria_assert($posLoop !== false && $posMailSend !== false, "Boucle d'envoi ou appel Mail::Send() introuvable — jeu de test invalide");

    $loopHead = substr($src, $posLoop, $posMailSend - $posLoop);
    neria_assert(
        strpos($loopHead, '$this->isStillActive($idCampaign)') !== false,
        "La boucle d'envoi de runDueCampaigns() n'appelle plus isStillActive() — régression du correctif du 31/08/2026 (round 256)"
    );
    neria_assert(
        preg_match('/!\$this->isStillActive\(\$idCampaign\)\)\s*\{\s*break;/', $loopHead) === 1,
        "La boucle d'envoi ne s'interrompt plus (break) sur une campagne désactivée en cours de route — régression du correctif du 31/08/2026 (round 256)"
    );

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::runDueCampaigns() relit désormais is_active en cours de boucle (via isStillActive(), tous les 20 clients) et s'interrompt si le marchand désactive la campagne pendant l'envoi — bug corrigé le 31/08/2026 (round 256)",
    ];
}
