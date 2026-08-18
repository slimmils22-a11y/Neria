<?php
/**
 * Régression : SeasonalCampaignManager::countEligible() (aperçu BO du
 * nombre de destinataires) n'appliquait pas le même override
 * gift_mode → target_segment='ambassador,loyal' que runDueCampaigns()
 * (envoi réel) — l'aperçu utilisait le segment CONFIGURÉ par le marchand
 * (potentiellement vide = tous les clients) au lieu du segment RÉELLEMENT
 * restreint à l'envoi en mode "idées cadeaux", annonçant un nombre de
 * destinataires trompeur (souvent bien supérieur au nombre réel d'envois
 * le jour J).
 *
 * Corrigé le 18/08/2026 (round 185) : countEligible() applique désormais
 * le même override que runDueCampaigns() avant d'appeler
 * getEligibleCustomers().
 *
 * Test comportemental réel : compare countEligible() sur une campagne
 * gift_mode=true avec target_segment volontairement vide (= "tous les
 * clients" sans le correctif) au résultat de getEligibleCustomers() appelé
 * directement avec target_segment='ambassador,loyal' (le comportement
 * attendu) — les deux doivent produire exactement le même nombre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';

    $module = neria_test_module();
    $mgr = new SeasonalCampaignManager($module);

    $campaignEmptySegment = [
        'target_segment' => '',
        'target_gender'  => '',
        'target_lang'    => '',
        'min_age'        => null,
        'max_age'        => null,
        'gift_mode'      => 1,
        'template'       => 'gift_ideas',
    ];

    $countViaMethod = $mgr->countEligible($campaignEmptySegment);

    $campaignForcedSegment = $campaignEmptySegment;
    $campaignForcedSegment['target_segment'] = 'ambassador,loyal';
    $customersForced = $mgr->getEligibleCustomers($campaignForcedSegment);

    // countEligible() applique en plus le filtre PreferencesManager (opt-out
    // template gift_ideas) — on ne peut donc pas comparer un compte brut à
    // un autre. On vérifie plutôt que countEligible() NE RENVOIE JAMAIS PLUS
    // que le nombre de clients réellement dans le segment restreint (borne
    // supérieure stricte) : s'il ignorait encore le gift_mode, il pourrait
    // dépasser ce plafond dès qu'un client hors segment ambassador/loyal
    // existe et est opt-in sur gift_ideas.
    neria_assert(
        $countViaMethod <= count($customersForced),
        "countEligible() en mode gift_mode renvoie {$countViaMethod}, supérieur au nombre de clients du segment restreint ambassador/loyal (" . count($customersForced) . ") — régression du bug corrigé le 18/08/2026 (round 185) : le segment gift_mode='ambassador,loyal' ne serait plus appliqué à l'aperçu BO"
    );

    // Vérification structurelle complémentaire : l'override est bien codé.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');
    $posMethod = strpos($src, 'public function countEligible(array $campaign): int');
    neria_assert($posMethod !== false, 'countEligible() introuvable — jeu de test invalide');
    $posGetEligible = strpos($src, '$customers = $this->getEligibleCustomers($campaign);', $posMethod);
    $body = substr($src, $posMethod, $posGetEligible - $posMethod);
    neria_assert(
        strpos($body, "\$campaign['target_segment'] = 'ambassador,loyal';") !== false,
        "countEligible() n'applique plus l'override gift_mode → target_segment avant getEligibleCustomers() — régression du bug corrigé le 18/08/2026 (round 185)"
    );

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::countEligible() applique bien le même override gift_mode que l'envoi réel — bug corrigé le 18/08/2026 (round 185)",
    ];
}
