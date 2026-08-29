<?php
/**
 * Régression round 236 (28/08/2026) : ManualSendManager::send() ne
 * retirait que les \r\n en tête/fin du sujet BO (trim($subject)), pas les
 * \r\n INTERNES — contrairement au même garde-fou déjà appliqué ailleurs
 * dans le module (WatchdogManager, EmailRenderer::fromName, neria.php)
 * contre l'injection d'en-tête email (CWE-93).
 *
 * Source : Tools::getValue('neria_subject'), saisi librement par un
 * employé BO dans le formulaire d'envoi manuel — pas un vecteur exploitable
 * par un attaquant externe non authentifié (source BO admin-only), mais
 * une incohérence de défense en profondeur par rapport au reste du code.
 *
 * Corrigé le 28/08/2026 (round 236) :
 * trim(str_replace(["\r", "\n"], ' ', $subject)).
 *
 * Test structurel (send() a de nombreuses dépendances — license, bounce,
 * anniversaire — le invoquer en bout en bout hors de leur périmètre
 * n'apporterait rien de plus que vérifier la ligne réellement exécutée) +
 * vérification directe du comportement de la ligne de sanitization
 * elle-même, extraite et exécutée telle quelle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ManualSendManager.php');

    $needle = '$subject = trim(str_replace(["\r", "\n"], \' \', $subject));';
    neria_assert(
        strpos($src, $needle) !== false,
        "ManualSendManager::send() ne filtre plus les \\r\\n internes du sujet BO — régression du bug corrigé le 28/08/2026 (round 236) : un sujet d'envoi manuel pourrait de nouveau contenir des retours à la ligne bruts (injection d'en-tête CWE-93)"
    );

    // Vérification comportementale de la ligne elle-même : un sujet
    // contenant un \r\n interne (tentative d'injection d'en-tête) ne doit
    // plus en contenir après sanitization.
    $malicious = "Promo\r\nBcc: attacker@evil.com";
    $sanitized = trim(str_replace(["\r", "\n"], ' ', $malicious));
    neria_assert(
        strpos($sanitized, "\r") === false && strpos($sanitized, "\n") === false,
        "La logique de sanitization ne retire pas correctement les \\r\\n d'un sujet malveillant"
    );
    neria_assert(
        strpos($sanitized, 'Bcc:') !== false,
        "Jeu de test invalide : le sujet malveillant de test ne contient plus 'Bcc:' après sanitization — la logique testée a changé"
    );

    return [
        'pass'    => true,
        'message' => "ManualSendManager::send() filtre bien les \\r\\n internes du sujet BO avant Mail::Send() (round 236)",
    ];
}
