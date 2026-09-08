<?php
/**
 * Régression : neria.php::save_webhooks affichait la même bannière
 * générique "Enregistré" (msg.saved) que webhook_url soit renseignée OU
 * vidée au submit — contrairement aux configs voisines du même fichier
 * (PageSpeed, SEO API, bounce webhook secret, commentaires round 186/172)
 * qui distinguent explicitement un champ vidé. Un marchand vidant
 * accidentellement le champ (resoumission d'un vieux formulaire, bug JS)
 * voyait "Enregistré" sans être averti que ses webhooks sortants venaient
 * d'être désactivés.
 *
 * Corrigé le 08/09/2026 (round 323, traitement différé) : un message
 * distinct (msg.webhook_url_cleared_disabled) est affiché quand
 * webhook_url est vide, sans changer le comportement d'enregistrement
 * lui-même (vider le champ reste le mécanisme normal de désactivation).
 *
 * Test structurel (code inline dans le contrôleur admin, nécessitant un
 * contexte AdminController/Employee complet pour être invoqué réellement
 * — même limitation documentée par test_454/test_635) : vérifie la
 * présence du garde-fou dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $srcRaw = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($srcRaw !== false, 'Impossible de lire neria.php');
    $src = str_replace("\r", '', $srcRaw);

    $posAction = strpos($src, "Tools::getValue('neria_action') === 'save_webhooks'");
    neria_assert($posAction !== false, "Action 'save_webhooks' introuvable — jeu de test invalide");

    $body = substr($src, $posAction, 2200);

    neria_assert(
        strpos($body, "\$whUrl === '' ? 'msg.webhook_url_cleared_disabled' : 'msg.saved'") !== false,
        "neria.php::save_webhooks n'affiche plus de message distinct quand webhook_url est vidée — régression du bug corrigé le 08/09/2026 (round 323) : un champ vidé accidentellement afficherait de nouveau la même bannière générique 'Enregistré' sans avertir que les webhooks sont désactivés"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        isset($translations['msg.webhook_url_cleared_disabled']) && count($translations['msg.webhook_url_cleared_disabled']) === 19,
        "clé msg.webhook_url_cleared_disabled manquante ou incomplète dans admin_translations.json (19 langues attendues)"
    );

    return [
        'pass'    => true,
        'message' => "neria.php::save_webhooks affiche bien un message distinct (msg.webhook_url_cleared_disabled) quand webhook_url est vidée, au lieu de la bannière générique 'Enregistré' — bug corrigé le 08/09/2026 (round 323)",
    ];
}
