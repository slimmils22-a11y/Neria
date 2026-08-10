<?php
/**
 * Régression : CalendarManager::getEventDisplayInfo() doit essayer $year+1
 * même quand $resolveDate($year) renvoie directement null, pas seulement
 * quand la date d'envoi de $year est déjà passée.
 *
 * Bug réel corrigé le 09/08/2026 (round 142) : le repli sur $year+1 n'était
 * déclenché QUE si $eventDate pour $year avait été résolu ET que sa date
 * d'envoi était déjà passée. Si $resolveDate($year) renvoyait directement
 * null (gap dans la table NIVEAU 3 pré-calculée 2025-2035, ou événement
 * dont le calcul algorithmique NIVEAU 2 est désactivé — cf. commentaires du
 * fichier lignes ~556-582 pour eid/ramadan/lunar_new_year au-delà de 2035),
 * tout le bloc de résolution était sauté : la méthode renvoyait "aucun
 * envoi prévu" alors que les 2 méthodes voisines du même fichier
 * (processEvent(), getUpcomingDates()) testent systématiquement
 * [$year, $year+1] sans cette condition préalable — incohérence d'affichage
 * BO trompeuse.
 *
 * Test structurel assumé explicitement (comme d'autres tests de cette
 * suite quand la reproduction comportementale demanderait de contrôler le
 * "today" interne à la méthode ou de créer artificiellement un gap dans une
 * table de données censée être complète par construction — cf. convention
 * documentée pour les rounds 55-65) : vérifie que le repli sur $year+1 est
 * bien INCONDITIONNEL sur le cas "$eventDate est null", pas seulement sur
 * le cas "$sendDate est dans le passé".
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CalendarManager.php');
    neria_assert($src !== '', 'CalendarManager.php introuvable — jeu de test invalide');

    $pos = strpos($src, 'public function getEventDisplayInfo(array $event): array');
    neria_assert($pos !== false, 'getEventDisplayInfo() introuvable — jeu de test invalide');

    $body = substr($src, $pos, 1600);

    neria_assert(
        strpos($body, 'if (!$eventDate) {') !== false,
        "getEventDisplayInfo() ne teste plus explicitement le cas où \$resolveDate(\$year) renvoie null — régression du bug corrigé le 09/08/2026 (round 142) : le repli sur \$year+1 redeviendrait conditionné uniquement à un envoi déjà passé, pas à une résolution manquante"
    );

    // Le repli doit intervenir AVANT le bloc "if ($eventDate) { ... }" qui
    // calcule sendDate, pas seulement à l'intérieur de celui-ci.
    $posNullCheck = strpos($body, 'if (!$eventDate) {');
    $posMainBlock = strpos($body, 'if ($eventDate) {');
    neria_assert(
        $posNullCheck !== false && $posMainBlock !== false && $posNullCheck < $posMainBlock,
        "le repli sur l'année suivante en cas de résolution nulle n'est plus positionné avant le bloc principal de calcul — régression du bug corrigé le 09/08/2026 (round 142)"
    );

    return [
        'pass'    => true,
        'message' => "getEventDisplayInfo() essaie bien \$year+1 quand \$resolveDate(\$year) renvoie null, alignée sur processEvent()/getUpcomingDates()",
    ];
}
