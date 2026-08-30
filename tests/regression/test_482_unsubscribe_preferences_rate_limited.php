<?php
/**
 * Régression round 247 (30/08/2026) : controllers/front/unsubscribe.php et
 * controllers/front/preferences.php n'avaient AUCUNE limitation de débit,
 * contrairement au pattern déjà établi sur track.php (round 164),
 * certificate.php (round 212) et cron.php (round 216) pour ce même type de
 * risque. Un token HMAC valide (reçu dans UN SEUL email légitime) reste
 * connu indéfiniment par son destinataire — sans frein, un rejeu
 * automatisé (script) du même lien en boucle déclenchait à CHAQUE requête :
 *
 * - unsubscribe.php : UPDATE customer + SELECT id_customer +
 *   PreferencesManager::saveByCustomer() (autre UPDATE/INSERT) +
 *   SHOW TABLES/UPDATE emailsubscription + WebhookManager::trigger()
 *   (appel HTTP SORTANT configurable par le marchand — amplification
 *   réseau possible vers un tiers).
 * - preferences.php : Shop::setContext() + Customer::customerExists() +
 *   PreferencesManager::getByCustomer() à CHAQUE requête, même en simple
 *   GET sans soumission ; en POST, en plus saveByCustomer() + UPDATE
 *   customer + log Watchdog.
 *
 * Épuisement DB/CPU applicatif sur un endpoint public sans authentification
 * (le token EST valide — ce n'est pas un défaut d'auth, une absence de
 * limitation de débit sur une action légitime mais coûteuse et rejouable à
 * volonté).
 *
 * Corrigé le 30/08/2026 (round 247) : même schéma APCu fail-open que
 * track.php, scopé IP+token (clé `neria_unsub_rl_`/`neria_prefs_rl_`) —
 * ne bloque jamais l'action visible elle-même (le lien DOIT toujours
 * répondre, RFC 8058 l'exige pour unsubscribe), saute uniquement le
 * traitement coûteux au-delà du seuil.
 *
 * Test structurel assumé explicitement : APCu n'est pas chargée sur cet
 * environnement PHP CLI de développement (vérifié : apcu_enabled() ===
 * false), rendant impossible une démonstration comportementale réelle du
 * seuil sans installer l'extension sur la machine de dev — même situation
 * que pour le throttling déjà en place sur track.php, jamais testé en
 * conditions réelles non plus. Vérifie la présence du garde-fou (clé APCu,
 * scoping IP+token, fail-open explicite) aux deux endroits.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $unsubSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/unsubscribe.php');
    neria_assert($unsubSrc !== false, 'Impossible de lire unsubscribe.php');
    $unsubSrc = str_replace("\r", '', $unsubSrc);

    neria_assert(
        strpos($unsubSrc, "function_exists('apcu_enabled') && apcu_enabled()") !== false,
        "unsubscribe.php ne vérifie plus la disponibilité d'APCu avant de limiter le débit — régression du bug corrigé le 30/08/2026 (round 247) : sans ce garde fail-open explicite, le comportement en l'absence d'APCu ne serait plus garanti non-bloquant"
    );
    neria_assert(
        strpos($unsubSrc, "'neria_unsub_rl_' . md5(\$ip . '|' . \$token)") !== false,
        "unsubscribe.php ne scope plus sa clé de limitation par IP+token — régression du bug corrigé le 30/08/2026 (round 247) : sans ce scoping, un seul destinataire très actif épuiserait le quota pour tous les autres visiteurs de la même IP (même piège déjà corrigé sur track.php au round 164)"
    );
    neria_assert(
        strpos($unsubSrc, 'if ($hits > 5) {') !== false,
        "unsubscribe.php n'a plus de seuil de limitation explicite — régression du bug corrigé le 30/08/2026 (round 247)"
    );

    $prefsSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/preferences.php');
    neria_assert($prefsSrc !== false, 'Impossible de lire preferences.php');
    $prefsSrc = str_replace("\r", '', $prefsSrc);

    neria_assert(
        strpos($prefsSrc, "function_exists('apcu_enabled') && apcu_enabled()") !== false,
        "preferences.php ne vérifie plus la disponibilité d'APCu avant de limiter le débit — régression du bug corrigé le 30/08/2026 (round 247)"
    );
    neria_assert(
        strpos($prefsSrc, "'neria_prefs_rl_' . md5(\$ip . '|' . \$token)") !== false,
        "preferences.php ne scope plus sa clé de limitation par IP+token — régression du bug corrigé le 30/08/2026 (round 247)"
    );
    neria_assert(
        strpos($prefsSrc, 'if ($hits > 20) {') !== false,
        "preferences.php n'a plus de seuil de limitation explicite — régression du bug corrigé le 30/08/2026 (round 247)"
    );

    // Confirme, par cohérence, que le seuil est bien VÉRIFIÉ AVANT le
    // traitement coûteux (Customer::customerExists()) et non après — sinon
    // le frein n'empêcherait rien.
    $posThrottle = strpos($prefsSrc, "'neria_prefs_rl_'");
    $posExpensive = strpos($prefsSrc, 'Customer::customerExists($email, true)');
    neria_assert(
        $posThrottle !== false && $posExpensive !== false && $posThrottle < $posExpensive,
        "preferences.php : la limitation de débit n'est plus positionnée AVANT Customer::customerExists() — elle ne protégerait plus rien"
    );

    return [
        'pass'    => true,
        'message' => "unsubscribe.php et preferences.php limitent bien le débit de traitement (schéma APCu fail-open, scopé IP+token, même pattern que track.php round 164) — bug corrigé le 30/08/2026 (round 247)",
    ];
}
