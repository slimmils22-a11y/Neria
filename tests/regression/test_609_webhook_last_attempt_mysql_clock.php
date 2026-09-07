<?php
/**
 * Régression : WebhookManager::processQueue() calculait `$now =
 * date('Y-m-d H:i:s')` (horloge PHP) puis écrivait cette valeur dans
 * `last_attempt` — colonne ensuite comparée exclusivement côté MySQL
 * (`DATE_SUB(NOW(), INTERVAL 10 MINUTE)` pour récupérer les lignes
 * 'sending' bloquées après un crash, et `DATE_SUB(NOW(), INTERVAL
 * POW(2, attempts) MINUTE)` pour le backoff exponentiel). Si le serveur
 * web (PHP) et le serveur MySQL n'ont pas le même fuseau horaire : PHP en
 * avance → last_attempt semble "dans le futur" pour MySQL, le nettoyage
 * des lignes bloquées ne les récupère jamais ; PHP en retard → la fenêtre
 * de backoff est raccourcie/contournée, risque de double livraison au
 * endpoint externe du marchand.
 *
 * Corrigé le 07/09/2026 (round 314) : $now lu via SELECT NOW() côté MySQL.
 *
 * Vérification structurelle ciblée (comportemental non praticable sans
 * mocker fire()/l'appel HTTP externe réel) : confirme que
 * `$this->db->getValue('SELECT NOW()')` alimente bien $now dans le corps
 * de processQueue(), immédiatement avant son utilisation dans l'UPDATE de
 * réservation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $posFn = strpos($src, 'public function processQueue(): void');
    neria_assert($posFn !== false, 'processQueue() introuvable — jeu de test invalide');

    $body = substr($src, $posFn, 9500);

    neria_assert(
        strpos($body, "\$now   = (string) \$this->db->getValue('SELECT NOW()');") !== false,
        "WebhookManager::processQueue() ne calcule plus \$now via SELECT NOW() côté MySQL — régression du bug corrigé le 07/09/2026 (round 314) : last_attempt redeviendrait basé sur l'horloge PHP, incohérent avec les comparaisons DATE_SUB(NOW(), ...) utilisées pour le nettoyage des lignes bloquées et le backoff exponentiel"
    );

    neria_assert(
        strpos($body, "`last_attempt` = '{\$now}'") !== false,
        "WebhookManager::processQueue() n'écrit plus last_attempt à partir de la variable \$now — jeu de test invalide (littéral déplacé ?)"
    );

    return [
        'pass'    => true,
        'message' => "WebhookManager::processQueue() calcule bien \$now via SELECT NOW() côté MySQL avant d'écrire last_attempt, cohérent avec les comparaisons MySQL du nettoyage/backoff — bug corrigé le 07/09/2026 (round 314)",
    ];
}
