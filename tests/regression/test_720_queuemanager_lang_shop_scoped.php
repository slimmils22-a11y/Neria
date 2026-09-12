<?php
/**
 * Régression : `QueueManager` résolvait `PS_LANG_DEFAULT` sans `$idShop`
 * explicite à 3 endroits (`enqueue()`, `enqueueAt()`, `processSingle()`)
 * — même piège déjà corrigé pour `PS_SHOP_NAME`/`PS_CURRENCY_DEFAULT`
 * ailleurs dans le module (rounds 106/187/336/338), jamais porté ici.
 *
 * Bug identifié le 12/09/2026 (round 346, audit QueueManager). Un client
 * sans `id_lang` connu (envoi manuel à une adresse sans compte via
 * `ManualSendManager`, ou ligne de file avec `id_lang` NULL) recevait
 * alors l'email programmé dans la langue par défaut de la boutique
 * AMBIANTE plutôt que celle réellement résolue pour cet envoi ($idShop),
 * en multi-boutique avec des langues par défaut différentes.
 *
 * Corrigé le 12/09/2026 (round 346) : `$idShop` explicite ajouté aux 3
 * résolutions `PS_LANG_DEFAULT` concernées (déplacé avant son usage dans
 * `enqueue()`/`enqueueAt()`, où il était jusqu'ici défini APRÈS).
 *
 * Test structurel sur les 3 sites (comportement non testable end-to-end
 * dans cet environnement de dev mono-boutique — Configuration::get()
 * ignore silencieusement tout idShop explicite quand
 * Shop::isFeatureActive()===false, même limite déjà documentée pour ce
 * piège ailleurs dans la série, cf. test_188/test_695/test_699).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
    neria_assert($src !== false, 'Impossible de lire src/QueueManager.php');

    $count = preg_match_all(
        "/\\\\Configuration::get\\('PS_LANG_DEFAULT', null, null, \\\$idShop\\)/",
        $src
    );

    neria_assert(
        $count === 3,
        "QueueManager ne résout plus PS_LANG_DEFAULT avec \$idShop explicite aux 3 emplacements attendus (enqueue(), enqueueAt(), processSingle()) — trouvé {$count}/3. Régression du bug corrigé le 12/09/2026 (round 346) : un client sans id_lang connu recevrait de nouveau l'email dans la langue par défaut de la boutique ambiante plutôt que celle réellement résolue pour l'envoi"
    );

    return [
        'pass'    => true,
        'message' => "QueueManager résout bien PS_LANG_DEFAULT avec \$idShop explicite aux 3 emplacements concernés (enqueue(), enqueueAt(), processSingle()) — bug corrigé le 12/09/2026 (round 346)",
    ];
}
