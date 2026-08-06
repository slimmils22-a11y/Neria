<?php
/**
 * Régression : CalendarManager::getEligibleCustomers() doit détecter le
 * dépassement du plafond de 500 destinataires (LIMIT+1 + troncature), et
 * processEvent() doit journaliser un avertissement Watchdog quand ce
 * plafond est atteint — même pattern que SegmentManager::preflightCheck().
 *
 * Bug réel corrigé le 06/08/2026 (round 69) : la requête plafonnait
 * silencieusement à 500 clients (ORDER BY id_customer ASC, LIMIT 500), sans
 * aucune détection. Sur une boutique dépassant 500 clients éligibles, ce
 * tri déterministe renvoyait TOUJOURS les 500 premiers id_customer — les
 * clients inscrits après les 500 premiers ne recevaient jamais aucune
 * campagne calendaire, année après année, silencieusement.
 *
 * Test structurel (créer 501 vrais clients de test pour un test purement
 * comportemental serait un effet de bord disproportionné sur les données
 * de l'environnement partagé) : vérifie au niveau du code source que le
 * mécanisme de détection (LIMIT+1, array_slice, log Watchdog) est bien en
 * place, garde-fou déjà utilisé pour des cas similaires (test_58, test_61).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CalendarManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CalendarManager.php');

    neria_assert(
        strpos($src, 'LIMIT " . (self::MAX_RECIPIENTS_PER_EVENT + 1);') !== false,
        "getEligibleCustomers() n'interroge plus MAX_RECIPIENTS_PER_EVENT + 1 lignes — régression du bug corrigé le 06/08/2026 (round 69) : le dépassement du plafond de 500 destinataires ne serait plus détectable"
    );

    neria_assert(
        strpos($src, '$capped = count($rows) > self::MAX_RECIPIENTS_PER_EVENT;') !== false
        && strpos($src, 'array_slice($rows, 0, self::MAX_RECIPIENTS_PER_EVENT)') !== false,
        "getEligibleCustomers() ne détecte/tronque plus correctement le dépassement du plafond"
    );

    neria_assert(
        strpos($src, "\\WatchdogManager::i18nMsg('watchdog.calendar_recipient_cap_exceeded'") !== false,
        "processEvent() ne journalise plus d'avertissement Watchdog quand le plafond de 500 destinataires est atteint — le marchand ne serait de nouveau jamais informé que des clients éligibles n'ont pas reçu la campagne"
    );

    $dict = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($dict), 'Impossible de décoder data/admin_translations.json');
    neria_assert(
        isset($dict['watchdog.calendar_recipient_cap_exceeded']) && count($dict['watchdog.calendar_recipient_cap_exceeded']) === 19,
        "la clé watchdog.calendar_recipient_cap_exceeded est absente ou incomplète (19 langues attendues) dans data/admin_translations.json"
    );

    return ['pass' => true, 'message' => "getEligibleCustomers() détecte bien le dépassement du plafond de 500 destinataires (LIMIT+1, troncature, log Watchdog 19 langues)"];
}
