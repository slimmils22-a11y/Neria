<?php
/**
 * Régression : neria.php lisait 'NERIA_PAGESPEED_LAST_ERROR' (clé globale
 * brute) pour la variable de template 'pagespeed_last_error' affichée en
 * BO — mais PageSpeedManager n'écrit cette information QUE via une clé
 * scopée par boutique (cacheKey(), depuis le round 134). La clé globale
 * n'était donc plus jamais écrite : la page Stats affichait toujours
 * "aucune erreur", même en cas de vraie erreur (clé API invalide, quota
 * dépassé...) journalisée correctement côté PageSpeedManager/HealthCheckManager.
 *
 * Corrigé le 19/08/2026 (round 186) : neria.php appelle désormais
 * PageSpeedManager::getLastError() (scopée), et le handler de sauvegarde
 * appelle la nouvelle méthode PageSpeedManager::clearError() (scopée) au
 * lieu de deleteByName() sur la clé globale sans effet.
 *
 * Test comportemental réel : force une erreur PageSpeed réelle pour la
 * boutique courante (via la méthode privée recordError(), réflexion),
 * vérifie que getLastError() la retourne bien, PUIS vérifie que neria.php
 * lit bien via cette méthode (pas la clé globale, qui reste vide).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    $originalGlobalError = $db->getValue("SELECT value FROM {$prefix}configuration WHERE name = 'NERIA_PAGESPEED_LAST_ERROR' AND id_shop IS NULL");

    try {
        $mgr = new PageSpeedManager($module);
        $ref = new ReflectionMethod(PageSpeedManager::class, 'recordError');
        $ref->setAccessible(true);
        $ref->invoke($mgr, 'erreur de test regtest-388 : cle API invalide');

        $lastError = $mgr->getLastError();
        neria_assert(
            $lastError !== '',
            "PageSpeedManager::getLastError() ne retourne rien après recordError() — jeu de test invalide"
        );

        // La clé globale NON scopée ne doit JAMAIS avoir été écrite par ce
        // flux — confirme que la vraie donnée est bien dans la clé scopée.
        $globalRow = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}configuration WHERE name = 'NERIA_PAGESPEED_LAST_ERROR' AND id_shop IS NULL"
        );
        neria_assert(
            $globalRow === 0,
            "Une ligne globale NERIA_PAGESPEED_LAST_ERROR existe alors qu'elle ne devrait jamais être écrite par PageSpeedManager (scopé par boutique) — jeu de test invalide ou pollution d'un test précédent"
        );

        // Vérification structurelle : neria.php lit bien via getLastError().
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
        neria_assert($src !== false, 'Impossible de lire neria.php');
        neria_assert(
            strpos($src, "'pagespeed_last_error'  => class_exists('PageSpeedManager') ? (new PageSpeedManager(\$this))->getLastError() : '',") !== false,
            "neria.php ne lit plus pagespeed_last_error via PageSpeedManager::getLastError() — régression du bug corrigé le 19/08/2026 (round 186) : la page Stats afficherait de nouveau toujours 'aucune erreur' même en cas de vraie erreur"
        );

        $mgr->clearError();
        $lastErrorAfterClear = $mgr->getLastError();
        neria_assert(
            $lastErrorAfterClear === '',
            "PageSpeedManager::clearError() n'a pas effacé l'erreur — la méthode ajoutée au round 186 ne fonctionne pas comme attendu"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name IN ('NERIA_PAGESPEED_LAST_ERROR_{$idShop}', 'NERIA_PAGESPEED_LAST_ERROR_AT_{$idShop}')");
        if ($originalGlobalError !== false && $originalGlobalError !== null) {
            $db->execute(
                "INSERT INTO {$prefix}configuration (name, value, id_shop, date_add, date_upd) VALUES
                 ('NERIA_PAGESPEED_LAST_ERROR', '" . pSQL($originalGlobalError) . "', NULL, NOW(), NOW())"
            );
        }
    }

    return [
        'pass'    => true,
        'message' => "pagespeed_last_error est bien lu via PageSpeedManager::getLastError() (scopé par boutique), plus la clé globale jamais écrite — bug corrigé le 19/08/2026 (round 186)",
    ];
}
