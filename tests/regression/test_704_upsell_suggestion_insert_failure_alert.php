<?php
/**
 * Régression : UpsellManager::recordSuggestion() doit journaliser une
 * alerte Watchdog critique quand l'INSERT échoue réellement, au lieu
 * d'afficher un succès (via Insert_ID() non vérifié) sans aucune trace.
 *
 * Bug réel corrigé le 12/09/2026 (round 340) : le retour de l'INSERT
 * n'était jamais capturé — Insert_ID() était utilisé sans condition pour
 * journaliser watchdog.upsell_suggestion_created, même si l'écriture avait
 * réellement échoué (contrainte, connexion perdue). L'appelant
 * (BehavioralCronManager) teste bien `$idUpsell > 0`, mais aucune trace
 * Watchdog ne permettait au marchand de distinguer un échec SQL réel d'un
 * cas normal — même pattern "succès affiché sans vérifier l'effet réel"
 * déjà corrigé pour BounceManager::recordBounce() (round 336).
 *
 * Test comportemental réel sur le chemin nominal : recordSuggestion() sur
 * une vraie commande retourne un id_upsell > 0 et la ligne existe bien en
 * base.
 *
 * Test structurel assumé explicitement pour le chemin d'échec : une
 * vérification a été tentée en forçant un dépassement de colonne
 * (reason > VARCHAR(100)), mais la connexion PrestaShop neutralise
 * silencieusement STRICT_TRANS_TABLES pour cette session (le champ est
 * tronqué à 100 caractères sans erreur SQL, contrairement au sql_mode
 * global du serveur) — aucun moyen fiable et portable de provoquer un vrai
 * échec d'INSERT sur cette table (pas de contrainte UNIQUE/FK exploitable)
 * sans mocker la couche DB. Vérifie donc que le code retourne bien la
 * capture du retour de l'INSERT et journalise un Watchdog critique
 * conditionné à son échec, plutôt que d'utiliser Insert_ID() sans garde.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $mgr        = new UpsellManager(neria_test_module());

    $insertedId = null;

    try {
        $upsell = [
            'id_product'  => 1,
            'name'        => 'Regtest Round340',
            'reason'      => 'Notre suggestion pour vous',
            'product_url' => 'http://example.test/produit',
        ];
        $insertedId = $mgr->recordSuggestion($idCustomer, 0, $upsell, $idShop);

        neria_assert(
            $insertedId > 0,
            "recordSuggestion() n'a pas retourné d'ID positif sur un INSERT valide — jeu de test invalide"
        );

        $row = $db->getRow(
            "SELECT id_upsell FROM {$prefix}neria_upsell WHERE id_upsell = {$insertedId}"
        );
        neria_assert(
            $row !== false,
            "recordSuggestion() a retourné un ID mais aucune ligne correspondante n'existe en base — régression du bug corrigé le 12/09/2026 (round 340)"
        );

        // Vérification structurelle du garde-fou d'échec (voir commentaire
        // ci-dessus pour la raison du choix structurel sur ce chemin).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
        $posMethod = strpos($src, 'public function recordSuggestion(');
        neria_assert($posMethod !== false, 'recordSuggestion() introuvable — jeu de test invalide');

        $methodBody = substr($src, $posMethod, 2200);

        neria_assert(
            strpos($methodBody, '$inserted340 = $this->db->execute(') !== false,
            "UpsellManager::recordSuggestion() ne capture plus le retour de l'INSERT — régression du bug corrigé le 12/09/2026 (round 340) : Insert_ID() pourrait de nouveau être utilisé sans vérifier le succès réel de l'écriture"
        );
        neria_assert(
            strpos($methodBody, 'if (!$inserted340) {') !== false
            && strpos($methodBody, 'watchdog.upsell_suggestion_insert_failed') !== false
            && strpos($methodBody, '->critical(') !== false,
            "UpsellManager::recordSuggestion() ne journalise plus d'alerte Watchdog critique en cas d'échec d'INSERT — régression du bug corrigé le 12/09/2026 (round 340)"
        );

        return [
            'pass'    => true,
            'message' => "UpsellManager::recordSuggestion() vérifie bien le succès réel de l'INSERT (chemin nominal confirmé comportementalement, garde-fou d'échec + alerte Watchdog critique confirmés structurellement)",
        ];
    } finally {
        if ($insertedId) {
            $db->execute("DELETE FROM {$prefix}neria_upsell WHERE id_upsell = {$insertedId}");
        }
    }
}
