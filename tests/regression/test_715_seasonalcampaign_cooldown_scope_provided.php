<?php
/**
 * Régression : SeasonalCampaignManager doit fournir {cooldown_scope} dans
 * les templateVars du Mail::Send() d'une campagne saisonnière, pour que le
 * Mode Silence (CooldownManager::isDuplicate(), fenêtre en minutes) puisse
 * réellement fonctionner — même famille de correctif que OrderTriggersManager
 * (round 63/97, {id_order}/{cooldown_scope}), jamais porté ici.
 *
 * Bug réel corrigé le 12/09/2026 (round 344, audit BlacklistManager/
 * CooldownManager) : isDuplicate() est appelé (ligne ~451) avec
 * refScope=$sentKey (ex. 'seasonal_42'), qui génère la condition SQL
 * `ref_scope = 'seasonal_42'`. Mais StatsManager::record() ne lit
 * ref_scope QUE depuis $params['templateVars']['{cooldown_scope}']
 * (jamais renseigné par ce Mail::Send()) — la ligne réellement écrite en
 * base avait donc TOUJOURS ref_scope='', que cette condition ne pouvait
 * jamais matcher. Le Mode Silence (fenêtre en minutes) était donc
 * silencieusement inopérant pour TOUS les envois de campagnes
 * saisonnières, quel que soit le nombre d'envois précédents dans la
 * fenêtre — protection annuelle (claimSend()) non affectée, seule la
 * protection "cooldown en minutes" était cassée.
 *
 * Test comportemental réel (2 volets) :
 * 1. Vérifie directement le mécanisme write→read : une ligne neria_stat
 *    avec ref_scope correctement renseigné EST détectée par isDuplicate()
 *    (chemin nominal, déjà fonctionnel pour les autres managers) —
 *    confirme que la seule pièce manquante était bien l'écriture de
 *    {cooldown_scope} côté SeasonalCampaignManager.
 * 2. Vérifie structurellement (comme test_66 pour OrderTriggersManager —
 *    déclencher un vrai Mail::Send() de campagne saisonnière serait
 *    invasif) que SeasonalCampaignManager fournit bien
 *    '{cooldown_scope}' => $sentKey dans son Mail::Send().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CooldownManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $email      = (string) $db->getValue("SELECT email FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
    $template   = 'neria_test_round344_seasonal';
    $refScope   = 'seasonal_999344';

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$idCustomer} AND template = '{$template}'");

    try {
        // Volet 1 : mécanisme write→read nominal (ref_scope correctement
        // renseigné, comme le fera désormais SeasonalCampaignManager).
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, ref_scope, tracking_token, event_type, date_add)
             VALUES ({$idShop}, '{$template}', 'fr', {$idCustomer}, '{$refScope}', SHA2(RAND(), 256), 'sent', NOW())"
        );

        $cooldownMgr = new CooldownManager();
        $isDup = $cooldownMgr->isDuplicate($email, $template, 60, $idShop, 0, $refScope);
        neria_assert(
            $isDup === true,
            "jeu de test invalide : isDuplicate() ne détecte pas un doublon avec ref_scope correctement renseigné — le mécanisme write→read lui-même serait cassé (pas seulement l'écriture manquante de SeasonalCampaignManager)"
        );

        // Volet 2 : structurel — SeasonalCampaignManager fournit bien
        // désormais {cooldown_scope} => $sentKey dans son Mail::Send().
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
        neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');

        $posDup = strpos($src, "isDuplicate(\$customer['email'], \$template, \$cdMinutes, \$this->idShop, 0, \$sentKey)");
        neria_assert($posDup !== false, "jeu de test invalide : l'appel isDuplicate() attendu n'a pas été retrouvé");

        $posMailSend = strpos($src, '$ok = \Mail::Send(', $posDup);
        neria_assert($posMailSend !== false, "jeu de test invalide : le Mail::Send() attendu n'a pas été retrouvé après isDuplicate()");

        $mailSendBody = substr($src, $posMailSend, 3000);
        neria_assert(
            strpos($mailSendBody, "'{cooldown_scope}' => \$sentKey,") !== false,
            "SeasonalCampaignManager ne fournit plus '{cooldown_scope}' => \$sentKey dans les templateVars de Mail::Send() — régression du bug corrigé le 12/09/2026 (round 344) : le Mode Silence (fenêtre en minutes) redeviendrait silencieusement inopérant pour toutes les campagnes saisonnières"
        );

        return [
            'pass'    => true,
            'message' => "SeasonalCampaignManager fournit désormais {cooldown_scope} dans son Mail::Send(), rendant le Mode Silence (fenêtre en minutes) à nouveau opérant pour les campagnes saisonnières — bug corrigé le 12/09/2026 (round 344)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$idCustomer} AND template = '{$template}'");
    }
}
