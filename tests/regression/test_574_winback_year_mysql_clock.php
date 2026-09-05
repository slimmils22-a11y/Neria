<?php
/**
 * Régression : BehavioralCronManager::sendWinBacks() sourçait l'année de
 * déduplication via `date('Y')` (horloge/fuseau PHP), alors que le test
 * d'éligibilité (MAX(o.date_add) <= DATE_SUB(NOW(), ...)) tourne
 * entièrement côté MySQL (NOW()) — même piège déjà identifié et corrigé
 * pour sendBirthdays() (round 281) et sendRelationshipAnniversaries(),
 * jamais étendu ici malgré le même schéma de dédup annuelle (ref_id =
 * année).
 *
 * Scénario concret : autour du nouvel an, si le serveur PHP tourne dans un
 * fuseau horaire différent de la session MySQL (fréquent en hébergement
 * mutualisé), date('Y') (PHP) peut diverger de l'année réellement détectée
 * par le SELECT — un client pouvait alors recevoir l'email win_back deux
 * fois (ou jamais l'année suivante) autour du 31/12-01/01.
 *
 * Corrigé le 05/09/2026 (round 305) : $year = (int) $this->db->getValue
 * ('SELECT YEAR(NOW())'), même pattern que sendBirthdays().
 *
 * Test structurel : vérifie que sendWinBacks() sourçe désormais l'année
 * via YEAR(NOW()) SQL, pas date('Y') PHP.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    $posFn = strpos($src, 'private function sendWinBacks(): void');
    neria_assert($posFn !== false, 'sendWinBacks() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1200);

    neria_assert(
        strpos($body, "\$year   = (int) \$this->db->getValue('SELECT YEAR(NOW())');") !== false,
        "sendWinBacks() ne sourçe plus l'année via YEAR(NOW()) SQL — régression du bug corrigé le 05/09/2026 (round 305) : un décalage de fuseau horaire PHP/MySQL autour du nouvel an pourrait de nouveau faire diverger la clé de déduplication de l'année réellement détectée par le test d'éligibilité SQL"
    );
    neria_assert(
        strpos($body, "\$year   = (int) date('Y');") === false,
        "sendWinBacks() utilise encore date('Y') PHP quelque part dans son corps — régression du bug corrigé le 05/09/2026 (round 305)"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::sendWinBacks() sourçe bien l'année de déduplication via YEAR(NOW()) SQL, cohérent avec le test d'éligibilité entièrement côté MySQL — bug corrigé le 05/09/2026 (round 305)",
    ];
}
