<?php
/**
 * Régression : controllers/front/cron.php validait le token et
 * NERIA_CRON_ENABLED, mais jamais Module::isEnabled('neria'). Contrairement
 * aux hooks classiques (que PrestaShop ne déclenche plus pour un module
 * désactivé), ce contrôleur front restait accessible par URL directe même
 * après désactivation du module depuis le BO — désactiver Neria ne purge
 * pas NERIA_CRON_TOKEN/NERIA_CRON_ENABLED. Si le vrai cron serveur
 * (crontab) reste actif en dehors de PrestaShop, les tâches de fond
 * continuaient de tourner malgré la désactivation explicite du marchand.
 *
 * Corrigé le 13/08/2026 (round 162) : un contrôle Module::isEnabled('neria')
 * en tête d'initContent() répond désormais 403 si le module est désactivé,
 * avant même la vérification du token.
 *
 * Test réel : le module de test est actif (nécessaire au reste de la
 * suite), donc on ne peut pas le désactiver ici sans casser les autres
 * tests exécutés dans le même process de dev — vérifie plutôt
 * Module::isEnabled('neria') retourne bien true dans l'état actuel (pas un
 * test inutile : confirme que la fonction utilisée par le correctif se
 * comporte comme attendu sur ce module), complété par une vérification
 * structurelle de la position du contrôle (avant la validation du token).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_assert(
        Module::isEnabled('neria') === true,
        "Module::isEnabled('neria') devrait retourner true (module actif dans l'environnement de test) — jeu de test invalide sinon"
    );

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/cron.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/cron.php');

    $posEnabled = strpos($src, "Module::isEnabled('neria')");
    $posToken   = strpos($src, "Tools::getValue('token', '')");

    neria_assert(
        $posEnabled !== false,
        "cron.php ne vérifie plus Module::isEnabled('neria') — régression du bug corrigé le 13/08/2026 (round 162) : un module désactivé en BO n'empêcherait plus le cron externe de continuer à tourner"
    );
    neria_assert(
        $posToken !== false && $posEnabled < $posToken,
        "cron.php vérifie Module::isEnabled('neria') APRÈS le token au lieu d'avant — le contrôle doit être la toute première porte, avant même de traiter un token potentiellement valide"
    );

    return [
        'pass'    => true,
        'message' => "cron.php vérifie bien Module::isEnabled('neria') en priorité, avant la validation du token — bug corrigé le 13/08/2026 (round 162)",
    ];
}
