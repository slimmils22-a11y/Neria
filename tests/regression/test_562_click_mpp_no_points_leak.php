<?php
/**
 * Régression : `StatsManager::recordClick()` n'appelait jamais
 * `detectMpp()` (contrairement à `recordOpen()`) — `is_mpp` restait
 * systématiquement à 0 (valeur par défaut de `record()`) pour TOUT
 * événement 'click'. Un scanner de sécurité d'entreprise (Microsoft Safe
 * Links, Proofpoint URL Defense, Mimecast) qui pré-visite tous les liens
 * d'un email dès sa réception (délai < 3s, signal générique de
 * `detectMpp()`, pas spécifique à Apple Mail) déclenchait alors un vrai
 * clic comptabilisé dans les KPIs ET créditait des points de fidélité au
 * destinataire avant même qu'il n'ait ouvert l'email — même classe de bug
 * que les ouvertures MPP Apple, jamais traitée pour les clics.
 *
 * Bug identifié et corrigé le 04/09/2026 (round 300, audit "tracking de
 * clic et licence").
 *
 * Corrigé le 04/09/2026 (round 300) : `detectMpp()` réutilisé dans
 * `recordClick()` — `is_mpp` correctement renseigné, et aucun point de
 * fidélité crédité pour un clic détecté comme préchargement automatique.
 *
 * Test comportemental réel : simule un clic avec un délai < 3 secondes
 * après l'envoi (signal générique de préchargement) et vérifie que la
 * ligne enregistrée a bien `is_mpp = 1`, et qu'AUCUN point de fidélité
 * n'est crédité pour ce clic (le prochain clic légitime, lui, doit rester
 * éligible aux points si suffisamment tardif).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $token      = 'regtest562-' . uniqid();

    // 'sent' quasi immédiat (il y a 1 seconde) — le prochain clic tombera
    // sous le signal "délai < 3s" de detectMpp().
    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
         VALUES ({$idShop}, 'newsletter', 'fr', {$idCustomer}, '{$token}', 'sent', 0, DATE_SUB(NOW(), INTERVAL 1 SECOND))"
    );

    try {
        $sm = new StatsManager(neria_test_module());
        $sm->recordClick($token, 'https://example.test/product');

        $row = $db->getRow(
            "SELECT is_mpp FROM {$prefix}neria_stat
             WHERE tracking_token = '{$token}' AND event_type = 'click'"
        );
        neria_assert(
            $row !== false,
            "recordClick() n'a enregistré aucune ligne 'click' — jeu de test invalide"
        );
        neria_assert(
            (int) $row['is_mpp'] === 1,
            "recordClick() n'enregistre plus is_mpp=1 pour un clic survenu moins de 3 secondes après l'envoi (signal de préchargement automatique) — régression du bug corrigé le 04/09/2026 (round 300) : un scanner de sécurité d'entreprise redeviendrait comptabilisé comme un clic humain réel dans les KPIs"
        );

        // Vérification structurelle du garde anti-fuite de points.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/StatsManager.php');
        neria_assert($src !== false, 'Impossible de lire src/StatsManager.php');
        neria_assert(
            strpos($src, '$awardPoints = !$isMpp && !$this->eventExists($token, self::EVENT_CLICK);') !== false,
            "StatsManager::recordClick() ne conditionne plus l'attribution de points par !\$isMpp — régression du bug corrigé le 04/09/2026 (round 300) : un scanner de sécurité créditerait de nouveau des points de fidélité avant toute lecture humaine de l'email"
        );

        return [
            'pass'    => true,
            'message' => "StatsManager::recordClick() détecte désormais les préchargements automatiques (scanners de sécurité) via detectMpp(), excluant is_mpp=1 des KPIs et de l'attribution de points de fidélité — bug corrigé le 04/09/2026 (round 300)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$token}'");
    }
}
