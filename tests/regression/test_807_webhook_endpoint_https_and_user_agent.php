<?php
/**
 * Bloc 6 (19/09/2026) — webhooks sortants, deux défauts relevés en réel sur
 * ps-test avec un récepteur de test :
 * (1) l'interface et le message d'erreur exigent une URL HTTPS, mais une URL
 *     http:// était acceptée : identifiant client et jeton de suivi auraient
 *     circulé en clair. Désormais refusée à l'ENREGISTREMENT (les URL http://
 *     déjà enregistrées continuent d'être livrées, pas de casse) ;
 * (2) aucune requête sortante n'envoyait d'en-tête User-Agent (certains pare-feu
 *     et CDN rejettent les requêtes qui n'en portent pas) : « Neria-Webhook/<version> ».
 *
 * Test : comportement réel de isAcceptableEndpoint() ; le BO l'utilise à
 * l'enregistrement ; les DEUX requêtes (livraison et test) portent le User-Agent.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WebhookManager.php';

    foreach (['http://example.com/hook', 'ftp://example.com/x', 'javascript:alert(1)', 'https://127.0.0.1/x', 'https://localhost/x', 'https://169.254.169.254/latest/', 'https://10.0.0.5/hook', '', '  '] as $bad) {
        neria_assert(WebhookManager::isAcceptableEndpoint($bad) === false, "URL refusée attendue mais acceptée : « {$bad} »");
    }
    // Cas passant : exige une résolution DNS publique — ignoré si la machine est hors ligne.
    if (gethostbyname('example.com') !== 'example.com') {
        neria_assert(WebhookManager::isAcceptableEndpoint('https://example.com/hook') === true, "une URL HTTPS publique valide est refusée");
    }
    // http:// reste livrable s'il est déjà enregistré (isPublicUrl seul à la livraison).
    if (gethostbyname('example.com') !== 'example.com') {
        neria_assert(WebhookManager::isPublicUrl('http://example.com/hook') === true, "isPublicUrl() ne doit pas être durci : les http:// déjà enregistrés doivent rester livrables");
    }

    $neria = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert(strpos($neria, 'WebhookManager::isAcceptableEndpoint($whUrl)') !== false, "le BO n'utilise plus isAcceptableEndpoint() à l'enregistrement");

    $wm = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert(substr_count($wm, "'User-Agent: Neria-Webhook/' . \\Neria::VERSION") === 2, "les deux requêtes sortantes (livraison + test) doivent porter le User-Agent Neria-Webhook");

    return ['pass' => true, 'message' => "les nouvelles URL de webhook exigent HTTPS et les requêtes portent un User-Agent — bloc 6 (19/09/2026)"];
}
