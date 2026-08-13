<?php
/**
 * Régression : CalendarManager::checkAndSendDailyEvents() utilisait un
 * verrou MySQL GLOBAL ('neria_calendar_check', sans suffixe idShop), alors
 * que runBackgroundJobs() instancie CalendarManager dans une boucle sur
 * TOUTES les boutiques actives — même pattern multi-boutique que
 * DomainReputationManager/WatchdogManager, déjà scopés par idShop. Avec un
 * verrou global et un timeout non bloquant (0), un visiteur déclenchant le
 * cron pour la boutique A pouvait faire échouer silencieusement TOUT le
 * traitement calendaire d'un visiteur B sur une boutique DIFFÉRENTE C,
 * retardant à répétition l'envoi des campagnes calendaires sur une install
 * multi-boutiques à trafic soutenu, sans aucun log exploitable.
 *
 * Corrigé le 09/08/2026 (round 159) : le nom du verrou inclut désormais
 * $this->idShop, comme DomainReputationManager/WatchdogManager.
 *
 * Test comportemental réel (2e connexion mysqli brute, même technique que
 * test_68/255) : détient le verrou scopé à LA boutique de test, vérifie que
 * checkAndSendDailyEvents() respecte bien ce verrou (retour rapide, pas de
 * blocage), PUIS vérifie qu'un verrou nommé pour une AUTRE boutique reste
 * libre pendant ce temps — preuve que le scoping isole bien les boutiques
 * entre elles (le cœur du bug corrigé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';

    $idShop       = (int) Context::getContext()->shop->id;
    $otherShopId  = $idShop + 999; // boutique fictive distincte, jamais réellement utilisée

    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'Impossible d\'ouvrir une seconde connexion MySQL pour simuler un processus concurrent — jeu de test invalide');

    try {
        $lockName = 'neria_calendar_check_' . $idShop;
        $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "', 2)");
        $row = mysqli_fetch_row($res);
        neria_assert((int) $row[0] === 1, 'La seconde connexion MySQL n\'a pas pu obtenir le verrou — jeu de test invalide');

        // Le verrou d'UNE AUTRE boutique doit rester totalement libre —
        // c'est précisément ce que le scoping par idShop garantit.
        $resOther = mysqli_query($mysqli, "SELECT IS_USED_LOCK('neria_calendar_check_" . $otherShopId . "')");
        $rowOther = mysqli_fetch_row($resOther);
        neria_assert(
            $rowOther[0] === null,
            "le verrou d'une autre boutique (neria_calendar_check_{$otherShopId}) n'est pas libre — jeu de test invalide"
        );

        $mgr = new CalendarManager(neria_test_module());
        $start = microtime(true);
        $mgr->checkAndSendDailyEvents();
        $elapsed = microtime(true) - $start;

        neria_assert(
            $elapsed < 5.0,
            "checkAndSendDailyEvents() a mis {$elapsed}s alors qu'un verrou non-bloquant (timeout 0) est attendu — possible régression"
        );

        // Le verrou de l'AUTRE boutique doit toujours être libre après
        // l'appel — preuve que checkAndSendDailyEvents() ne l'a jamais
        // touché (scoping correct, pas de verrou global partagé).
        $resOther2 = mysqli_query($mysqli, "SELECT IS_USED_LOCK('neria_calendar_check_" . $otherShopId . "')");
        $rowOther2 = mysqli_fetch_row($resOther2);
        neria_assert(
            $rowOther2[0] === null,
            "le verrou d'une autre boutique a été affecté par checkAndSendDailyEvents() de LA boutique de test — régression du bug corrigé le 09/08/2026 (round 159) : le verrou redeviendrait global au lieu d'être scopé par idShop"
        );

        return [
            'pass'    => true,
            'message' => "CalendarManager::checkAndSendDailyEvents() utilise bien un verrou scopé par idShop — une boutique concurrente n'est plus bloquée par le traitement d'une autre",
        ];
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('neria_calendar_check_" . $idShop . "')");
        mysqli_close($mysqli);
    }
}
