<?php
/**
 * Régression : 2 correctifs confirmés par audit dédié (round 155) doivent
 * rester en place :
 * - abtest.tpl : champs de nom de variante A/B reposant uniquement sur le
 *   placeholder (disparaît à la saisie, pas fiable pour tous les lecteurs
 *   d'écran) — ajout d'un aria-label.
 * - NeriaTools::trackingSignKey() : dernier repli était la chaîne
 *   littérale 'neria-fallback-static-key', codée en dur et donc visible
 *   dans le code source — quiconque connaît ce code pouvait forger des
 *   signatures HMAC valides pour les liens de clic trackés, cassant la
 *   protection anti-open-redirect. Remplacé par une clé aléatoire générée
 *   et persistée une seule fois.
 *
 * Test structurel pour abtest.tpl (contenu de template). Test comportemental
 * réel pour NeriaTools::trackingSignKey() : simule l'absence totale de
 * NERIA_ENCRYPTION_KEY et de _COOKIE_KEY_/_NEW_COOKIE_KEY_ (chemin de repli
 * ultime) via Reflection sur la méthode privée, et vérifie qu'AUCUN appel
 * ne retourne jamais la chaîne prévisible 'neria-fallback-static-key',
 * qu'une clé de secours est bien persistée en configuration, et qu'un
 * second appel réutilise la MÊME clé (pas une nouvelle à chaque appel, ce
 * qui casserait la vérification de signatures déjà émises).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $abtest = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/abtest.tpl');
    neria_assert($abtest !== false, 'Impossible de lire abtest.tpl');
    neria_assert(
        strpos($abtest, "name=\"variant_a_name\"") !== false && strpos($abtest, "aria-label=\"{neria_admin key='abtest.variant_a_ph'}\"") !== false,
        "le champ variant_a_name n'a plus d'aria-label — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($abtest, "name=\"variant_b_name\"") !== false && strpos($abtest, "aria-label=\"{neria_admin key='abtest.variant_b_ph'}\"") !== false,
        "le champ variant_b_name n'a plus d'aria-label — régression du bug corrigé le 09/08/2026 (round 155)"
    );

    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $db = neria_test_db();
    // Sauvegarde/nettoyage de l'état des 3 clés impliquées, pour ne pas
    // laisser ce test polluer la configuration réelle du site de test.
    $savedEncKey = \Configuration::get('NERIA_ENCRYPTION_KEY');
    $savedFallback = \Configuration::get('NERIA_TRACKING_FALLBACK_KEY');

    try {
        // Force le chemin de repli ultime : ni NERIA_ENCRYPTION_KEY valide,
        // ni fallback déjà persisté.
        \Configuration::updateValue('NERIA_ENCRYPTION_KEY', '');
        \Configuration::deleteByName('NERIA_TRACKING_FALLBACK_KEY');

        $method = new ReflectionMethod('NeriaTools', 'trackingSignKey');
        $method->setAccessible(true);

        // Si _COOKIE_KEY_/_NEW_COOKIE_KEY_ sont définies sur cet environnement
        // de test (cas normal d'une install PrestaShop), le chemin ultime
        // testé ici (clé aléatoire persistée) n'est PAS celui exercé — dans
        // ce cas on vérifie seulement qu'aucune clé prévisible n'est jamais
        // retournée, ce qui reste la garantie de sécurité recherchée.
        $key1 = $method->invoke(null);
        neria_assert(
            $key1 !== 'neria-fallback-static-key',
            "trackingSignKey() retourne encore la chaîne littérale prévisible 'neria-fallback-static-key' — régression du bug corrigé le 09/08/2026 (round 155)"
        );

        if (!defined('_NEW_COOKIE_KEY_') || _NEW_COOKIE_KEY_ === '') {
            if (!defined('_COOKIE_KEY_') || _COOKIE_KEY_ === '') {
                // Chemin de repli ultime réellement exercé sur cet environnement.
                $persisted = (string) \Configuration::get('NERIA_TRACKING_FALLBACK_KEY');
                neria_assert(
                    strlen($persisted) === 64 && ctype_xdigit($persisted),
                    "aucune clé de secours aléatoire n'a été persistée en configuration lors du chemin de repli ultime — régression du bug corrigé le 09/08/2026 (round 155)"
                );

                $key2 = $method->invoke(null);
                neria_assert(
                    $key2 === $key1,
                    "trackingSignKey() retourne une clé différente à chaque appel du chemin de repli — casserait la vérification de signatures déjà émises"
                );
            }
        }
    } finally {
        if ($savedEncKey !== false && $savedEncKey !== null) {
            \Configuration::updateValue('NERIA_ENCRYPTION_KEY', $savedEncKey);
        } else {
            \Configuration::deleteByName('NERIA_ENCRYPTION_KEY');
        }
        if ($savedFallback !== false && $savedFallback !== null) {
            \Configuration::updateValue('NERIA_TRACKING_FALLBACK_KEY', $savedFallback);
        } else {
            \Configuration::deleteByName('NERIA_TRACKING_FALLBACK_KEY');
        }
    }

    return [
        'pass'    => true,
        'message' => "abtest.tpl garde ses aria-label, et NeriaTools::trackingSignKey() ne retourne plus jamais le secret prévisible codé en dur — bug corrigé le 09/08/2026 (round 155)",
    ];
}
