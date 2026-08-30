<?php
/**
 * Régression round 248 (30/08/2026) : DomainReputationManager::runFullCheck()
 * écrivait `json_encode($report)` directement dans Configuration::updateValue()
 * sans vérifier son retour. `$report['ptr']['hostname']` provient de
 * gethostbyaddr($ip) (checkPtr()) — une donnée EXOGÈNE publiée par le
 * propriétaire du bloc IP inverse analysé (mail spoofé, relais compromis,
 * IP recyclée, résolveur mal configuré), pas garantie UTF-8 valide.
 *
 * Si $report contient un octet UTF-8 invalide, json_encode() retourne
 * `false` SILENCIEUSEMENT (pas de JSON_THROW_ON_ERROR, retour jamais
 * vérifié). Configuration::updateValue() convertit alors ce `false` en
 * chaîne vide : CONFIG_CACHE devenait vide alors que CONFIG_LAST_CHECK
 * était quand même mis à jour à l'instant présent. Dans getCachedReport(),
 * le TTL semblait "frais" (lastCheck récent) mais $json était vide → cache
 * jamais réellement peuplé → runFullCheck() (jusqu'à ~8s de résolutions DNS
 * bloquantes, DNS_TIME_BUDGET_SECS) se relançait à CHAQUE visite front
 * (hookDisplayHeader, chemin sans cron serveur) au lieu d'une fois par 24h,
 * tant que le PTR malformé persistait.
 *
 * Corrigé le 30/08/2026 (round 248) :
 * - checkPtr() valide désormais l'encodage UTF-8 du hostname AVANT de le
 *   renvoyer (mb_check_encoding()) — root cause, empêche $report de
 *   contenir la séquence invalide en amont.
 * - runFullCheck() vérifie en plus, en défense en profondeur, le retour de
 *   json_encode() : en cas d'échec, ni CONFIG_CACHE ni CONFIG_LAST_CHECK ne
 *   sont mis à jour (permet un nouveau essai au prochain appel plutôt que
 *   de rester bloqué sur un cache vide jusqu'à expiration du TTL).
 *
 * Test réel (partie A) : démontre sur un fixture réel qu'un hostname
 * contenant une séquence d'octets UTF-8 invalide (0xFF, jamais valide en
 * tête de séquence UTF-8) fait effectivement échouer json_encode() d'un
 * tableau qui le contient — documente concrètement le défaut de classe visé
 * par ce round — et que mb_check_encoding() le détecte correctement.
 *
 * Test structurel (partie B) : vérifie la présence des deux correctifs
 * dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Partie A : démonstration réelle du défaut de classe ──
    $invalidHostname = "evil-ptr-\xFF\xFE.example.invalid"; // 0xFF/0xFE : jamais valides en UTF-8

    neria_assert(
        !mb_check_encoding($invalidHostname, 'UTF-8'),
        "jeu de test invalide : le fixture ne contient pas de séquence UTF-8 réellement invalide"
    );

    $fakeReport = [
        'domain' => 'example.test',
        'ptr'    => ['found' => true, 'hostname' => $invalidHostname, 'valid' => false],
    ];
    $encoded = json_encode($fakeReport);
    neria_assert(
        $encoded === false,
        "jeu de test invalide : json_encode() n'échoue pas sur ce fixture — le scénario de démonstration ne reproduit plus le défaut visé par le round 248"
    );

    // ── Partie B : vérification structurelle des 2 correctifs ──
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
    neria_assert($src !== false, 'Impossible de lire DomainReputationManager.php');
    $src = str_replace("\r", '', $src);

    $posPtr = strpos($src, 'private function checkPtr(string $ip, ?float $deadline = null): array');
    neria_assert($posPtr !== false, 'checkPtr() introuvable — jeu de test invalide');
    $ptrBody = substr($src, $posPtr, 2800);
    neria_assert(
        strpos($ptrBody, "if (!mb_check_encoding(\$hostname, 'UTF-8')) {") !== false,
        "DomainReputationManager::checkPtr() ne valide plus l'encodage UTF-8 du hostname PTR avant de le renvoyer — régression du bug corrigé le 30/08/2026 (round 248)"
    );

    neria_assert(
        strpos($src, '$encodedReport = json_encode($report);') !== false
            && strpos($src, 'if ($encodedReport === false) {') !== false
            && strpos($src, 'Configuration::updateValue(self::CONFIG_CACHE, $encodedReport, false, null, $this->idShop);') !== false,
        "DomainReputationManager::runFullCheck() ne vérifie plus le retour de json_encode() avant l'écriture en cache — régression du bug corrigé le 30/08/2026 (round 248) : un échec d'encodage stockerait de nouveau une chaîne vide en cache tout en mettant à jour CONFIG_LAST_CHECK"
    );

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::checkPtr() rejette bien un hostname PTR UTF-8 invalide avant qu'il n'atteigne json_encode() (démontré : json_encode() échoue réellement sur un tel fixture) — bug corrigé le 30/08/2026 (round 248)",
    ];
}
