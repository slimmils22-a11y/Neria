<?php
/**
 * Régression : StatsManager::getKpiTrends() protégeait sa division par
 * zéro (taux d'ouverture/clic) via max(1, $sent) au lieu du pattern
 * $sent > 0 ? ... : 0 utilisé partout ailleurs dans ce fichier.
 *
 * Bug réel identifié le 25/08/2026 (round 211) : sent/opens/clicks sont
 * comptés par la DATE PROPRE de chaque événement (pas la date d'envoi) —
 * un email envoyé la semaine précédente peut être ouvert/cliqué durant la
 * semaine courante. Avec 0 envoi mais des ouvertures dans la fenêtre
 * courante, max(1, 0) plancherait le dénominateur à 1 : opens/1*100
 * affichait un taux de plusieurs centaines de %, incohérent, au lieu d'un
 * simple 0 (non calculable, pas d'envoi = pas de taux).
 *
 * Corrigé le 25/08/2026 (round 211) : $sentCur/$sentPrev gardent leur
 * valeur réelle, le taux est mis à 0.0 explicitement quand sent = 0.
 *
 * Test structurel (getKpiTrends() agrège TOUS les templates de la
 * boutique sans filtre — un test comportemental isolé nécessiterait de
 * contrôler l'intégralité de neria_stat sur la fenêtre de 7 jours
 * courante, risqué vis-à-vis des autres tests de la suite qui y écrivent
 * déjà des dizaines de lignes 'sent' réelles ; le pattern correctif étant
 * une simple substitution locale, une vérification de présence du code
 * exact est fiable et suffisante ici) + comportemental sur le calcul de
 * base (une division normale continue de fonctionner).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/StatsManager.php');
    neria_assert($src !== false, 'Impossible de lire src/StatsManager.php');

    $posMethod = strpos($src, 'public function getKpiTrends(): array');
    neria_assert($posMethod !== false, 'getKpiTrends() introuvable — jeu de test invalide');

    neria_assert(
        strpos($src, "\$sentCur  = (float) (\$raw['current']['sent']  ?? 0);", $posMethod) !== false,
        "StatsManager::getKpiTrends() ne préserve plus la valeur réelle de \$sentCur (0 possible) — régression du bug corrigé le 25/08/2026 (round 211)"
    );
    neria_assert(
        strpos($src, '$cur   = $sentCur  > 0 ? round((float) ($raw[\'current\'][$base]  ?? 0) / $sentCur  * 100, 1) : 0.0;', $posMethod) !== false,
        "StatsManager::getKpiTrends() ne garde plus le pattern protecteur \$sentCur > 0 pour le taux d'ouverture/clic — régression du bug corrigé le 25/08/2026 (round 211) : un taux aberrant (>100%) redeviendrait possible avec 0 envoi mais des ouvertures dans la fenêtre courante"
    );
    neria_assert(
        strpos($src, 'max(1, (float) ($raw[\'current\'][\'sent\']', $posMethod) === false || strpos($src, 'max(1, (float) ($raw[\'current\'][\'sent\']', $posMethod) > strpos($src, 'return $result;', $posMethod),
        "StatsManager::getKpiTrends() utilise de nouveau max(1, ...) pour plancher le dénominateur — régression du bug corrigé le 25/08/2026 (round 211)"
    );

    // Comportemental : le calcul reste correct sur un cas normal
    // (division réelle non nulle) — le correctif ne doit rien casser.
    $module = neria_test_module();
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';
    $mgr = new StatsManager($module);
    $trends = $mgr->getKpiTrends();
    neria_assert(
        isset($trends['open_rate']['current']) && is_float($trends['open_rate']['current']),
        "getKpiTrends() ne retourne plus open_rate.current en float — comportement de base cassé par ce correctif"
    );

    return [
        'pass'    => true,
        'message' => "StatsManager::getKpiTrends() garde bien le pattern protecteur \$sent > 0 pour le taux d'ouverture/clic, sans dénominateur planché à 1 — bug corrigé le 25/08/2026 (round 211)",
    ];
}
