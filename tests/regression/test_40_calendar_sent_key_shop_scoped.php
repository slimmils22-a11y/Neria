<?php
/** Régression : CalendarManager::buildSentKey() doit inclure idShop, sinon le marqueur "déjà envoyé" d'une campagne calendaire est partagé entre toutes les boutiques d'une install multi-boutique et bloque silencieusement l'envoi pour les boutiques suivantes. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CalendarManager.php');

    neria_assert(
        (bool) preg_match('/private function buildSentKey\([^)]*\)\s*:\s*string\s*\{.*?idShop.*?\}/s', $src),
        "CalendarManager::buildSentKey() ne semble plus inclure idShop — régression du bug de marqueur d'envoi partagé entre boutiques corrigé le 05/08/2026"
    );

    return ['pass' => true, 'message' => 'CalendarManager::buildSentKey() reste scopé par idShop'];
}
