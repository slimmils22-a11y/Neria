<?php
/**
 * Régression : CalendarManager::getEventDisplayInfo() doit trouver le
 * marqueur "dernier envoi" posé sur l'année SUIVANTE, pas seulement
 * l'année courante et l'année précédente.
 *
 * Bug réel corrigé le 07/08/2026 (round 98) : processEvent() résout la date
 * d'un événement en essayant [$year, $year+1] (nécessaire pour les
 * occasions à cheval sur le Nouvel An — ex. new_year J-7, envoyé le 25
 * décembre avec eventYear = année+1) et pose le marqueur "envoyé" avec CET
 * eventYear. Mais getEventDisplayInfo(), qui affiche le "dernier envoi" au
 * marchand, ne cherchait que [$year, $year-1] — jamais $year+1. Un envoi
 * qui venait de partir aujourd'hui restait affiché "jamais envoyé" dans le
 * BO jusqu'au 1er janvier suivant, alors que l'email avait réellement été
 * envoyé à tous les clients éligibles.
 *
 * Test comportemental réel : pose manuellement un marqueur "envoyé" sur
 * l'année SUIVANTE (comme le ferait processEvent() pour new_year fin
 * décembre) et vérifie que getEventDisplayInfo() le retrouve bien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';

    $mgr = new CalendarManager(neria_test_module());
    $buildSentKey = new ReflectionMethod(CalendarManager::class, 'buildSentKey');
    $buildSentKey->setAccessible(true);

    $eventKey    = 'new_year';
    $lang        = 'fr';
    $countryCode = '';
    $nextYear    = (int) (new DateTime('today'))->format('Y') + 1;

    $sentKey = $buildSentKey->invoke($mgr, $eventKey, $lang, $countryCode, $nextYear);
    $previousValue = Configuration::get($sentKey);

    $fakeSentAt = date('Y-m-d H:i:s') . '|42';
    Configuration::updateValue($sentKey, $fakeSentAt);

    try {
        $event = [
            'event_key'        => $eventKey,
            'lang'             => $lang,
            'country_code'     => $countryCode,
            'send_days_before' => 7,
            'custom_date'      => '',
        ];

        $info = $mgr->getEventDisplayInfo($event);

        neria_assert(
            $info['last_sent'] !== null,
            "CalendarManager::getEventDisplayInfo() ne trouve plus le marqueur 'envoyé' posé sur l'année suivante — régression du bug corrigé le 07/08/2026 (round 98) : un envoi de fin d'année (new_year J-7 par exemple) resterait affiché 'jamais envoyé' dans le BO jusqu'au 1er janvier suivant, alors que l'email a réellement été envoyé"
        );
        neria_assert(
            (int) ($info['last_sent']['count'] ?? 0) === 42,
            "le compteur du dernier envoi retrouvé ne correspond pas au marqueur posé sur l'année suivante — jeu de test invalide ou régression"
        );

        return [
            'pass'    => true,
            'message' => "CalendarManager::getEventDisplayInfo() retrouve bien un marqueur 'envoyé' posé sur l'année suivante (cas new_year fin décembre)",
        ];
    } finally {
        if ($previousValue !== false && $previousValue !== '') {
            Configuration::updateValue($sentKey, $previousValue);
        } else {
            Configuration::deleteByName($sentKey);
        }
    }
}
