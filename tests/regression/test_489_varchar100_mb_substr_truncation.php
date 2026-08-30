<?php
/**
 * Régression round 254 (31/08/2026) : `ABTestManager::createTest()`
 * (`variant_name` VARCHAR(100)) et `SeasonalCampaignManager::create()`/
 * `update()` (`name` VARCHAR(100)) écrivaient le texte saisi par le
 * marchand sans AUCUNE troncature explicite préalable — deux champs
 * "libres" (aucun `maxlength` HTML, aucune validation serveur), pour
 * lesquels un marchand décrivant sa variante/campagne en phrase complète
 * plutôt qu'en label court dépasse facilement 100 caractères.
 *
 * Sans troncature explicite EN CARACTÈRES (mb_substr, pas substr), MySQL
 * tronque silencieusement en OCTETS en mode SQL non strict (courant sur
 * hébergement mutualisé) — risque de couper en plein milieu d'un
 * caractère UTF-8 multi-octets (les deux tables sont en utf8mb4),
 * produisant une séquence invalide/mojibake affichée ensuite en BO ; en
 * mode strict, l'écriture échoue purement et simplement.
 *
 * Corrigé le 31/08/2026 (round 254) : mb_substr(..., 0, 100) explicite
 * avant l'écriture, dans les deux classes.
 *
 * Test comportemental réel : construit un nom de 105 caractères dont les
 * 6 derniers sont des caractères arabes multi-octets consécutifs (donc
 * l'ancienne troncature en octets à la position 100 tombait nécessairement
 * en plein milieu d'un de ces caractères), l'insère RÉELLEMENT via
 * createTest()/create(), puis relit la valeur stockée en base et vérifie
 * qu'elle fait exactement 100 caractères ET reste une séquence UTF-8
 * valide (mb_check_encoding).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    // 99 caractères ASCII + 6 caractères arabes multi-octets = 105
    // caractères. Une troncature EN OCTETS à 100 octets tomberait au
    // milieu du 1er ou 2e caractère arabe (chacun sur 2 octets en UTF-8),
    // produisant une séquence invalide.
    $longName = str_repeat('x', 99) . 'مرحباا'; // 99 + 6 = 105 caractères
    neria_assert(mb_strlen($longName, 'UTF-8') === 105, 'jeu de test invalide : le fixture ne fait pas 105 caractères');

    // ── Partie A : ABTestManager::createTest() ──
    require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';
    $prefixTable = $prefix . 'neria_abtest';
    $db->execute("DELETE FROM {$prefixTable} WHERE template = 'regtest489'");

    $abt = new ABTestManager($module);
    $created = $abt->createTest('regtest489', $longName, 'B-regtest489', 50);
    neria_assert($created !== false, "jeu de test invalide : createTest() a échoué");

    try {
        $storedA = (string) $db->getValue("SELECT variant_name FROM {$prefixTable} WHERE template = 'regtest489' AND variant = 'A'");
        neria_assert(
            mb_strlen($storedA, 'UTF-8') === 100,
            "ABTestManager::createTest() ne tronque plus variant_name à exactement 100 caractères — régression du bug corrigé le 31/08/2026 (round 254) : longueur stockée = " . mb_strlen($storedA, 'UTF-8')
        );
        neria_assert(
            mb_check_encoding($storedA, 'UTF-8'),
            "ABTestManager::createTest() a tronqué variant_name en plein milieu d'un caractère UTF-8 multi-octets — séquence invalide stockée en base"
        );
    } finally {
        $db->execute("DELETE FROM {$prefixTable} WHERE template = 'regtest489'");
    }

    // ── Partie B : SeasonalCampaignManager::create()/update() ──
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';
    $campTable = $prefix . 'neria_seasonal_campaign';
    $db->execute("DELETE FROM {$campTable} WHERE template = 'regtest489'");

    $scm = new SeasonalCampaignManager($module);
    $idCampaign = $scm->create(['name' => $longName, 'template' => 'regtest489']);
    neria_assert($idCampaign > 0, "jeu de test invalide : SeasonalCampaignManager::create() a échoué");

    try {
        $storedB = (string) $db->getValue("SELECT name FROM {$campTable} WHERE id_campaign = {$idCampaign}");
        neria_assert(
            mb_strlen($storedB, 'UTF-8') === 100,
            "SeasonalCampaignManager::create() ne tronque plus name à exactement 100 caractères — régression du bug corrigé le 31/08/2026 (round 254) : longueur stockée = " . mb_strlen($storedB, 'UTF-8')
        );
        neria_assert(
            mb_check_encoding($storedB, 'UTF-8'),
            "SeasonalCampaignManager::create() a tronqué name en plein milieu d'un caractère UTF-8 multi-octets — séquence invalide stockée en base"
        );

        // update() aussi.
        $scm->update($idCampaign, ['name' => $longName, 'template' => 'regtest489']);
        $storedB2 = (string) $db->getValue("SELECT name FROM {$campTable} WHERE id_campaign = {$idCampaign}");
        neria_assert(
            mb_strlen($storedB2, 'UTF-8') === 100 && mb_check_encoding($storedB2, 'UTF-8'),
            "SeasonalCampaignManager::update() ne tronque plus name à exactement 100 caractères UTF-8 valides — régression du bug corrigé le 31/08/2026 (round 254)"
        );
    } finally {
        $db->execute("DELETE FROM {$campTable} WHERE id_campaign = {$idCampaign}");
    }

    return [
        'pass'    => true,
        'message' => "ABTestManager::createTest() et SeasonalCampaignManager::create()/update() tronquent bien variant_name/name à exactement 100 caractères UTF-8 valides (démontré avec un fixture de 105 caractères dont 6 multi-octets consécutifs à la frontière) — bug corrigé le 31/08/2026 (round 254)",
    ];
}
