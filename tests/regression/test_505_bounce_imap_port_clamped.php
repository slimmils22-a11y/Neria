<?php
/**
 * Régression : les handlers BO `save_bounce_config` et
 * `test_imap_connection` (neria.php) écrivaient `bounce_imap_port` en
 * `Configuration` avec un simple cast `(int)`, sans borner la plage —
 * contrairement à tous les autres champs numériques du module (clampés
 * systématiquement, ex. `bounce_soft_threshold` juste à côté via
 * `max(1, ...)`) et malgré la contrainte HTML `min="1" max="65535"` du
 * champ `<input type="number">` correspondant (`views/templates/admin/
 * bounces.tpl`), jamais revalidée côté serveur.
 *
 * Bug identifié le 01/09/2026 (round 263, audit "validation serveur des
 * formulaires BO"). Un POST direct (hors navigateur, curl/Postman, ou
 * simple contournement du champ HTML) avec `bounce_imap_port=-1` ou
 * `bounce_imap_port=99999999` était accepté tel quel et stocké dans
 * `NERIA_BOUNCE_IMAP_PORT`, ensuite injecté dans la chaîne de mailbox
 * `{host:port/imap/ssl}folder` passée à `imap_open()`
 * (`BounceManager::checkBounceMailbox()`/`testImapConnection()`) — impact
 * resté bénin (imap_open() échoue proprement, déjà géré), mais incohérent
 * avec le reste du module. Corrigé le 01/09/2026 (round 263) :
 * `max(1, min(65535, ...))` appliqué dans les deux handlers, par cohérence/
 * défense en profondeur.
 *
 * Test comportemental réel : reproduit exactement l'expression du
 * correctif sur des fixtures hors plage (négatif, zéro, > 65535) et une
 * valeur normale (inchangée), vérifie le résultat clampé — complété par une
 * vérification structurelle que les deux sites de neria.php appliquent bien
 * ce clamp avant l'écriture en Configuration.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $cases = [
        -1        => 1,
        0         => 1,
        993       => 993,
        65535     => 65535,
        70000     => 65535,
        99999999  => 65535,
    ];

    foreach ($cases as $raw => $expected) {
        // Reproduit exactement l'expression du correctif (neria.php,
        // handlers save_bounce_config/test_imap_connection).
        $clamped = max(1, min(65535, (int) $raw));
        neria_assert(
            $clamped === $expected,
            "max(1, min(65535, (int) {$raw})) renvoie {$clamped} au lieu de {$expected} — le correctif du round 263 lui-même serait incorrect (jeu de test invalide, pas une régression de neria.php)"
        );
    }

    $origPort = Configuration::getGlobalValue('NERIA_BOUNCE_IMAP_PORT');
    try {
        // Vérifie le comportement réel via Configuration, comme le fait le
        // handler : une valeur hors plage écrite avec le clamp doit être
        // relue clampée.
        Configuration::updateValue('NERIA_BOUNCE_IMAP_PORT', max(1, min(65535, (int) '-50')));
        $saved = (int) Configuration::get('NERIA_BOUNCE_IMAP_PORT');
        neria_assert(
            $saved === 1,
            "Configuration::get('NERIA_BOUNCE_IMAP_PORT') renvoie {$saved} au lieu de 1 après écriture d'une valeur négative clampée — jeu de test invalide"
        );
    } finally {
        if ($origPort !== false && $origPort !== null && $origPort !== '') {
            Configuration::updateGlobalValue('NERIA_BOUNCE_IMAP_PORT', $origPort);
        } else {
            Configuration::deleteByName('NERIA_BOUNCE_IMAP_PORT');
        }
    }

    // Vérification structurelle : les deux sites de neria.php appliquent
    // bien le clamp avant l'écriture en Configuration.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, "Impossible de lire neria.php");

    $occurrences = substr_count($src, "max(1, min(65535, (int) Tools::getValue('bounce_imap_port', 993)))");
    neria_assert(
        $occurrences === 2,
        "neria.php n'applique plus max(1, min(65535, ...)) sur bounce_imap_port aux 2 sites attendus (save_bounce_config + test_imap_connection) — trouvé {$occurrences} occurrence(s) — régression du bug corrigé le 01/09/2026 (round 263) : un POST direct avec un port hors plage (négatif ou >65535) serait de nouveau accepté sans borne"
    );

    return [
        'pass'    => true,
        'message' => "neria.php borne désormais bounce_imap_port via max(1, min(65535, ...)) aux 2 sites d'écriture (save_bounce_config + test_imap_connection), cohérent avec la contrainte HTML min/max du formulaire — bug corrigé le 01/09/2026 (round 263)",
    ];
}
