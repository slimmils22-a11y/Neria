<?php
/**
 * P8c-2 (21/09/2026, navigateur réel sur ps-test) : l'onglet Multi-aperçu levait « Cannot read properties of null (reading
 * 'addEventListener') » à chaque ouverture : le script liait `#neria-mp-dark-toggle` sans condition alors que ce bouton n'existe
 * qu'une fois les aperçus générés. Aucun accès direct `document.getElementById('neria-mp-…').addEventListener(` ne doit subsister :
 * les liaisons passent par neriaMpOn() qui ignore les éléments absents. Vérifie aussi que le rendu par défaut ne contient pas ce bouton
 * (la garde est donc bien nécessaire).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $tpl = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/multipreview.tpl');
    neria_assert($tpl !== '', 'multipreview.tpl introuvable');
    neria_assert(strpos($tpl, 'function neriaMpOn(id, ev, fn)') !== false, 'helper neriaMpOn() absent');
    neria_assert(preg_match("/document\.getElementById\('neria-mp-[a-z-]+'\)\.addEventListener/", $tpl) !== 1, "liaison directe non gardée sur un élément neria-mp-* (erreur JS à l'ouverture de l'onglet)");
    neria_assert(substr_count($tpl, "neriaMpOn('neria-mp-") >= 5, 'les 5 liaisons du multi-aperçu ne passent plus par neriaMpOn()');
    neria_assert(preg_match("/getElementById\('neria-mp-dark-toggle'\)\.classList/", $tpl) !== 1, 'accès direct non gardé au bouton du mode sombre');
    return ['pass' => true, 'message' => 'les liaisons JS du multi-aperçu ignorent les éléments absents (bouton mode sombre créé seulement après génération)'];
}
