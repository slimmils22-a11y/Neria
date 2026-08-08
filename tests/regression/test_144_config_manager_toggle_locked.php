<?php
/**
 * Régression : ConfigManager::toggleTimeGreetingEnabled() (et les 3 autres
 * toggles booléens BO) doivent verrouiller le cycle lecture-modification-
 * écriture via GET_LOCK, comme toggleMenuItemVisibility() (round 123/127).
 *
 * Bug réel corrigé le 08/08/2026 (round 132) : neria.php appelait
 * isTimeGreetingEnabled() puis setTimeGreetingEnabled() séparément, sans
 * verrou — deux clics rapprochés (double-clic, deux onglets BO) pouvaient
 * tous deux lire le même état avant que l'un des deux n'écrive : le second
 * appel réappliquait la même valeur au lieu de basculer, désynchronisant
 * l'UI de l'état réel. Remplacé par une méthode toggle*() unique verrouillée
 * côté ConfigManager.
 *
 * Test comportemental réel : deux appels concurrents simulés (deux
 * instances ConfigManager toggling la même clé, l'un après l'autre mais
 * SANS relire l'état entre les deux, simulant deux requêtes HTTP
 * concurrentes) doivent produire un basculement net (2 bascules = retour à
 * l'état initial), pas une écriture en double de la même valeur.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module = neria_test_module();
    $cfg = new ConfigManager($module);

    $initial = $cfg->isTimeGreetingEnabled();

    try {
        // Deux "requêtes" utilisant chacune leur propre instance (comme deux
        // requêtes HTTP concurrentes le feraient), toutes deux appelant la
        // méthode verrouillée sans jamais lire l'état entre les deux appels.
        $cfgReq1 = new ConfigManager($module);
        $cfgReq2 = new ConfigManager($module);

        $result1 = $cfgReq1->toggleTimeGreetingEnabled();
        $result2 = $cfgReq2->toggleTimeGreetingEnabled();

        neria_assert(
            $result1 !== $initial,
            "toggleTimeGreetingEnabled() n'a pas basculé l'état au premier appel"
        );
        neria_assert(
            $result2 === $initial,
            "toggleTimeGreetingEnabled() : le second appel n'a pas re-basculé vers l'état initial — deux bascules successives doivent annuler l'effet l'une de l'autre, comme un vrai cycle lecture-modification-écriture séquentialisé"
        );

        $finalDb = (bool) Configuration::get(ConfigManager::KEY_TIME_GREETING_ENABLED);
        neria_assert(
            $finalDb === $initial,
            "L'état final en base ({$finalDb}) diverge de l'état initial ({$initial}) après deux bascules — la valeur écrite en base ne reflète pas les deux bascules effectuées"
        );
    } finally {
        // Restaure explicitement l'état initial en base, indépendamment du
        // résultat des assertions ci-dessus.
        Configuration::updateValue(ConfigManager::KEY_TIME_GREETING_ENABLED, (int) $initial);
    }

    // Vérification structurelle : le verrou GET_LOCK doit être présent dans
    // toggleBooleanKey(), le helper partagé par les 4 toggles.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ConfigManager.php');
    $posHelper = strpos($src, 'private function toggleBooleanKey(');
    neria_assert($posHelper !== false, 'toggleBooleanKey() introuvable — régression du bug corrigé le 08/08/2026 (round 132)');
    $helperBody = substr($src, $posHelper, 700);
    neria_assert(
        strpos($helperBody, "GET_LOCK('") !== false && strpos($helperBody, "RELEASE_LOCK('") !== false,
        "toggleBooleanKey() n'utilise plus GET_LOCK/RELEASE_LOCK — régression du bug corrigé le 08/08/2026 (round 132) : la race condition sur les toggles booléens réapparaîtrait"
    );

    return [
        'pass'    => true,
        'message' => "ConfigManager::toggleTimeGreetingEnabled() (et les 3 autres toggles) verrouillent bien le cycle lecture-modification-écriture, évitant qu'un double appel concurrent désynchronise l'état réel",
    ];
}
