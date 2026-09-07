<?php
/**
 * Régression : 3 méthodes de génération de bon (CartRule) écrivaient
 * `date_from`/`date_to` via `date('Y-m-d H:i:s')`/`strtotime()` (horloge
 * PHP) — LoyaltyManager::generateVoucher() (paliers de fidélité),
 * BehavioralCronManager::generateBirthdayVoucher() (anniversaire),
 * OrderTriggersManager::generateMilestoneVoucher() (paliers de commandes).
 * Le cœur PrestaShop valide pourtant la disponibilité du bon au checkout
 * via `NOW() BETWEEN cr.date_from AND cr.date_to` (classes/CartRule.php,
 * comparaison PUREMENT MySQL, vérifié directement dans le cœur). Si le
 * serveur web (PHP) est en avance sur le serveur MySQL, date_from tombait
 * dans le "futur" du point de vue MySQL : le bon fraîchement émis (et déjà
 * envoyé par email au client) était rejeté au checkout ("code invalide")
 * jusqu'à ce que les horloges se rejoignent.
 *
 * Corrigé le 07/09/2026 (round 314) : date_from/date_to ancrés sur un
 * $nowSql314 = (string) $this->db->getValue('SELECT NOW()') dans les 3
 * méthodes.
 *
 * Vérification structurelle ciblée pour les 3 méthodes (comportemental non
 * praticable de façon fiable sans désynchroniser réellement les horloges
 * PHP/MySQL de la machine de test) : confirme que chacune ancre bien
 * date_from sur $nowSql314 (issu de NOW() MySQL), pas sur date() PHP.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $checks = [
        [
            'file'   => 'src/LoyaltyManager.php',
            'anchor' => 'private function generateVoucher(int $idCustomer, array $tier, int $reservationShopId, int $pointsAtReward): string',
            'label'  => 'LoyaltyManager::generateVoucher()',
        ],
        [
            'file'   => 'src/BehavioralCronManager.php',
            'anchor' => 'private function generateBirthdayVoucher(int $idCustomer, \ConfigManager $config, int $idShop, ?int $year = null): string',
            'label'  => 'BehavioralCronManager::generateBirthdayVoucher()',
        ],
        [
            'file'   => 'src/OrderTriggersManager.php',
            'anchor' => 'private function generateMilestoneVoucher(int $idCustomer, int $milestone, \ConfigManager $config, int $idShop): string',
            'label'  => 'OrderTriggersManager::generateMilestoneVoucher()',
        ],
    ];

    foreach ($checks as $c) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/' . $c['file']);
        neria_assert($src !== false, "Impossible de lire {$c['file']}");

        $posFn = strpos($src, $c['anchor']);
        neria_assert($posFn !== false, "{$c['label']} introuvable — jeu de test invalide");

        $body = substr($src, $posFn, 4500);

        neria_assert(
            strpos($body, "\$nowSql314 = (string) \$this->db->getValue('SELECT NOW()');") !== false,
            "{$c['label']} ne calcule plus \$nowSql314 via SELECT NOW() côté MySQL — régression du bug corrigé le 07/09/2026 (round 314)"
        );
        neria_assert(
            strpos($body, '$cartRule->date_from               = $nowSql314;') !== false,
            "{$c['label']} n'ancre plus \$cartRule->date_from sur \$nowSql314 — régression du bug corrigé le 07/09/2026 (round 314) : un bon fraîchement émis serait de nouveau rejeté au checkout si le serveur web est en avance sur MySQL"
        );
    }

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::generateVoucher()/BehavioralCronManager::generateBirthdayVoucher()/OrderTriggersManager::generateMilestoneVoucher() ancrent bien date_from sur NOW() MySQL, cohérent avec la validation du cœur PrestaShop au checkout — bug corrigé le 07/09/2026 (round 314)",
    ];
}
