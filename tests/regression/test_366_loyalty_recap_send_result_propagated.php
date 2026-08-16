<?php
/**
 * Régression : LoyaltyManager::sendRecapToCustomer() appelait Mail::Send()
 * "à la volée" sans capturer son retour, puis exécutait un `return true;`
 * inconditionnel juste après — contrairement à sendRewardEmail() du même
 * fichier, qui capture et retourne bien le booléen réel. Un échec
 * d'envoi (adresse invalide, SMTP indisponible) était donc TOUJOURS traité
 * comme un succès par sendMonthlyRecaps() : le compteur d'emails "envoyés"
 * était gonflé ET le throttle mensuel (CONFIG_RECAP_LAST_SENT) était quand
 * même posé côté appelant — le client ne recevait jamais son récap ce
 * mois-ci, sans qu'aucune alerte Watchdog ne le signale.
 *
 * Corrigé le 16/08/2026 (round 180) : le retour réel de Mail::Send() est
 * désormais capturé et retourné, avec un log Watchdog sur échec (même
 * pattern que les autres méthodes d'envoi de ce fichier).
 *
 * Test structurel (forcer un échec réel de Mail::Send() nécessiterait de
 * casser la config SMTP ou le template en base, risqué pour le reste de la
 * suite) : vérifie que le résultat de Mail::Send() est bien capturé dans
 * une variable et retourné (pas un `return true;` inconditionnel), avec le
 * log d'échec associé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LoyaltyManager.php');

    $posMethod = strpos($src, 'private function sendRecapToCustomer(int $idCustomer, ?int $idShop = null, int $windowDays = 30): bool');
    neria_assert($posMethod !== false, "Méthode sendRecapToCustomer() introuvable — jeu de test invalide");
    $posNextMethod = strpos($src, 'public function getTopCustomers(int $limit = 10): array', $posMethod);
    neria_assert($posNextMethod !== false, "Méthode getTopCustomers() introuvable pour borner la fenêtre — jeu de test invalide");
    $body = substr($src, $posMethod, $posNextMethod - $posMethod);

    neria_assert(
        strpos($body, '$sent = (bool) \Mail::Send(') !== false,
        "sendRecapToCustomer() ne capture plus le retour de Mail::Send() dans \$sent — régression du bug corrigé le 16/08/2026 (round 180) : un échec d'envoi serait de nouveau traité comme un succès inconditionnel"
    );
    neria_assert(
        strpos($body, 'return $sent;') !== false,
        "sendRecapToCustomer() ne retourne plus la variable \$sent — régression du bug corrigé le 16/08/2026 (round 180)"
    );
    neria_assert(
        strpos($body, "watchdog.send_silent_fail', ['template' => 'loyalty_recap'") !== false,
        "sendRecapToCustomer() ne journalise plus l'échec d'envoi — régression du bug corrigé le 16/08/2026 (round 180)"
    );

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::sendRecapToCustomer() capture bien le retour réel de Mail::Send() et le propage — bug corrigé le 16/08/2026 (round 180)",
    ];
}
