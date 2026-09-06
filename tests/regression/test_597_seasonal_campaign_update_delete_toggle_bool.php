<?php
/**
 * Régression : SeasonalCampaignManager::update()/delete()/toggle()
 * étaient void — les actions BO save_seasonal_campaign/
 * delete_seasonal_campaign/toggle_seasonal_campaign (neria.php)
 * affichaient donc un message de succès inconditionnellement dès que
 * l'id_campaign fourni était numériquement positif, sans jamais savoir
 * si la clause WHERE (id_campaign + id_shop) avait réellement touché une
 * ligne — même pattern que restore_translation/restore_variant_b/
 * add_calendar_event (round 310).
 *
 * Corrigé le 06/09/2026 (round 311) : les 3 méthodes renvoient désormais
 * bool (Affected_Rows() > 0) ; neria.php affiche neria_error
 * (msg.seasonal_campaign_not_found) si false.
 *
 * Test comportemental réel : crée une vraie campagne, vérifie que
 * update()/toggle() renvoient true sur cet id réel, puis que
 * update()/delete()/toggle() renvoient bien false sur un id inexistant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $mgr    = new SeasonalCampaignManager(neria_test_module());

    $data = [
        'name' => 'Regtest597', 'template' => 'christmas', 'annual_date' => '12-25',
        'days_before' => 7, 'is_active' => 1, 'target_segment' => '', 'target_gender' => 0,
        'target_lang' => '', 'min_age' => 0, 'max_age' => 0, 'gift_mode' => 0,
    ];
    $idCampaign = $mgr->create($data);
    neria_assert($idCampaign > 0, "create() a échoué — jeu de test invalide");

    try {
        // Round 311 (piège découvert pendant la vérification de ce test) :
        // Affected_Rows() sur un UPDATE ne compte que les lignes dont une
        // VALEUR a réellement changé (pas de CLIENT_FOUND_ROWS sur cette
        // connexion PDO) — appeler update() avec EXACTEMENT les mêmes
        // valeurs que celles déjà en base (aucun changement réel, sinon
        // date_upd qui n'entre pas dans l'égalité testée ici) doit malgré
        // tout renvoyer true : la campagne existe bien, l'update a
        // légitimement réussi (no-op), ce n'est PAS un cas "introuvable".
        $resultUpdateSameData = $mgr->update($idCampaign, $data);
        neria_assert(
            $resultUpdateSameData === true,
            "SeasonalCampaignManager::update() renvoie " . var_export($resultUpdateSameData, true) . " au lieu de true quand appelé avec des valeurs IDENTIQUES à celles déjà en base — régression potentielle : Affected_Rows() (lignes changées, pas lignes matchées) confondrait 'aucun changement réel' avec 'campagne introuvable', affichant à tort une erreur pour un marchand qui resoumet son formulaire sans rien modifier"
        );

        $resultUpdateReal = $mgr->update($idCampaign, $data);
        neria_assert(
            $resultUpdateReal === true,
            "SeasonalCampaignManager::update() renvoie " . var_export($resultUpdateReal, true) . " au lieu de true pour un id_campaign réellement existant — régression du bug corrigé le 06/09/2026 (round 311)"
        );

        $idFake = 999888777;
        $resultUpdateFake = $mgr->update($idFake, $data);
        neria_assert(
            $resultUpdateFake === false,
            "SeasonalCampaignManager::update() renvoie " . var_export($resultUpdateFake, true) . " au lieu de false pour un id_campaign inexistant — régression du bug corrigé le 06/09/2026 (round 311) : neria.php afficherait de nouveau 'Campagne mise à jour' à tort"
        );

        $resultToggleFake = $mgr->toggle($idFake);
        neria_assert(
            $resultToggleFake === false,
            "SeasonalCampaignManager::toggle() renvoie " . var_export($resultToggleFake, true) . " au lieu de false pour un id_campaign inexistant — régression du bug corrigé le 06/09/2026 (round 311)"
        );

        $resultToggleReal = $mgr->toggle($idCampaign);
        neria_assert(
            $resultToggleReal === true,
            "SeasonalCampaignManager::toggle() renvoie " . var_export($resultToggleReal, true) . " au lieu de true pour un id_campaign réellement existant — régression du bug corrigé le 06/09/2026 (round 311)"
        );

        $resultDeleteFake = $mgr->delete($idFake);
        neria_assert(
            $resultDeleteFake === false,
            "SeasonalCampaignManager::delete() renvoie " . var_export($resultDeleteFake, true) . " au lieu de false pour un id_campaign inexistant — régression du bug corrigé le 06/09/2026 (round 311)"
        );

        $resultDeleteReal = $mgr->delete($idCampaign);
        neria_assert(
            $resultDeleteReal === true,
            "SeasonalCampaignManager::delete() renvoie " . var_export($resultDeleteReal, true) . " au lieu de true pour un id_campaign réellement existant — régression du bug corrigé le 06/09/2026 (round 311)"
        );
        $idCampaign = null; // déjà supprimé, éviter un DELETE redondant dans finally

        return [
            'pass'    => true,
            'message' => "SeasonalCampaignManager::update()/delete()/toggle() renvoient bien true/false selon qu'une ligne a réellement été affectée (Affected_Rows()) — bug corrigé le 06/09/2026 (round 311)",
        ];
    } finally {
        if ($idCampaign !== null) {
            $db->execute("DELETE FROM {$prefix}neria_seasonal_campaign WHERE id_campaign = " . (int) $idCampaign);
        }
    }
}
