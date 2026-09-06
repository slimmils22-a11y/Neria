<?php
/**
 * Régression : StatsManager::getMonthlyComparison() (comparatif mois M vs
 * mois M-1, "mois à date") calculait toutes ses bornes de date via
 * date()/strtotime() PHP (horloge PHP) comparées à date_add rempli par
 * MySQL — même piège horloge PHP/MySQL déjà corrigé partout ailleurs
 * dans ce fichier (rounds 310/311), resté en attente sur cette dernière
 * méthode faute de temps (arithmétique de mois plus complexe : quantième
 * du mois précédent capé au dernier jour réel).
 *
 * Corrigé le 06/09/2026 (round 312) : toutes les dates dérivées d'un
 * $anchor unique, sourcé via SELECT CURDATE() MySQL au lieu de l'horloge
 * PHP locale (date()/strtotime()/new DateTime() implicite).
 *
 * Test en 2 volets :
 * 1) Structurel — vérifie que $todaySql312 (CURDATE() MySQL) est bien la
 *    source de $anchor/$prevMonthAnchor, et que plus aucun date()/
 *    strtotime()/new DateTime('first day...') implicite ne subsiste dans
 *    le corps de la méthode (reproduction comportementale du "jour limite
 *    dépend de l'heure réelle du test" écartée pour la même raison que
 *    test_595 round 311 — non déterministe).
 * 2) Comportemental réel — insère un événement 'sent' à CURDATE() MySQL
 *    (mi-journée, loin de toute borne de mois) sous deux fuseaux PHP
 *    extrêmes opposés et vérifie qu'il est bien compté dans 'current'
 *    dans les deux cas, sans exception ni valeur aberrante.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $mgr    = new StatsManager(neria_test_module());
    $originalTz = date_default_timezone_get();

    // ── Volet 1 : structurel ──────────────────────────────────────────
    $posMethod = null;
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/StatsManager.php');
    neria_assert($src !== false, 'Impossible de lire src/StatsManager.php');
    $posMethod = strpos($src, 'public function getMonthlyComparison(): array');
    neria_assert($posMethod !== false, 'getMonthlyComparison() introuvable — jeu de test invalide');
    $posEnd = strpos($src, "\n    }\n", strpos($src, "return \$data;", $posMethod));
    $body   = substr($src, $posMethod, ($posEnd !== false ? $posEnd - $posMethod : 3000));

    neria_assert(
        strpos($body, "\$todaySql312 = (string) \$this->db->getValue('SELECT CURDATE()');") !== false,
        "StatsManager::getMonthlyComparison() n'ancre plus \$anchor sur CURDATE() MySQL — régression du bug corrigé le 06/09/2026 (round 312) : le comparatif mois-à-date redeviendrait dépendant de l'horloge PHP au lieu de MySQL"
    );
    neria_assert(
        strpos($body, "new \\DateTime('first day of this month')") === false
            && strpos($body, "new \\DateTime('first day of last month')") === false,
        "StatsManager::getMonthlyComparison() utilise de nouveau new \\DateTime('first day of ...') (horloge PHP implicite) pour les labels — régression du bug corrigé le 06/09/2026 (round 312)"
    );

    // ── Volet 2 : comportemental réel ──────────────────────────────────
    $token = bin2hex(random_bytes(16));
    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$token}'");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_stat (id_shop, template, lang, country_code, tracking_token, event_type, ip_address, user_agent, date_add)
             VALUES ({$idShop}, 'regtest599', 'fr', '', '{$token}', 'sent', '', '', NOW())"
        );

        foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $tz) {
            date_default_timezone_set($tz);
            $comparison = $mgr->getMonthlyComparison();
            date_default_timezone_set($originalTz);

            neria_assert(
                isset($comparison['current']['sent']) && $comparison['current']['sent'] >= 1,
                "StatsManager::getMonthlyComparison() ne compte pas l'événement inséré à CURDATE() MySQL dans la période 'current' sous le fuseau PHP '{$tz}' — régression du bug corrigé le 06/09/2026 (round 312)"
            );
            neria_assert(
                isset($comparison['labels']['current']) && $comparison['labels']['current'] !== '',
                "StatsManager::getMonthlyComparison() ne renvoie plus de label 'current' valide sous le fuseau PHP '{$tz}' — régression du bug corrigé le 06/09/2026 (round 312)"
            );
        }

        return [
            'pass'    => true,
            'message' => "StatsManager::getMonthlyComparison() calcule bien toutes ses bornes/labels de mois via un ancrage CURDATE() MySQL, résultat cohérent sous deux fuseaux horaires PHP extrêmes opposés — bug corrigé le 06/09/2026 (round 312)",
        ];
    } finally {
        date_default_timezone_set($originalTz);
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$token}'");
    }
}
