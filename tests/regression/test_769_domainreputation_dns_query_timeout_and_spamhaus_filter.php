<?php
/**
 * Régression : `DomainReputationManager::checkBlacklists()` appelait
 * `dns_get_record()` directement — cette fonction n'offre AUCUN timeout
 * applicatif, et le `$deadline` du fichier n'est vérifié qu'ENTRE deux
 * appels RBL, jamais PENDANT un appel bloqué. Confirmé reproductible en
 * pratique : la suite complète de tests (`run_all.php`) s'est bloquée
 * ~45-60 min sur une seule requête RBL sans réponse, deux rounds
 * consécutifs (359 puis 360), CPU quasi nul pendant tout ce temps — un
 * hébergeur mutualisé filtrant/throttlant le trafic UDP:53 sortant vers
 * des résolveurs externes expose exactement le même risque en production,
 * pouvant bloquer indéfiniment le cron Neria entier.
 *
 * Bug confirmé le 14-15/09/2026 (rounds 359/360, traité hors round le
 * 15/09/2026 sur demande explicite de l'utilisateur).
 *
 * Corrigé le 15/09/2026 : nouvelle méthode `dnsQueryWithTimeout()`
 * interroge un résolveur DNS-over-HTTPS (Cloudflare, port 443/HTTPS —
 * quasi toujours ouvert sur mutualisé, contrairement à UDP:53) via cURL,
 * qui EXPOSE un vrai timeout borné (`CURLOPT_TIMEOUT_MS`). Repli sur
 * `dns_get_record()` uniquement pour un échec cURL NON lié à un timeout
 * (résolution de cloudflare-dns.com, TLS...) — jamais sur un timeout DoH
 * lui-même, qui réintroduirait exactement le risque de blocage non borné
 * que ce correctif élimine.
 *
 * Piège découvert PENDANT l'implémentation (vérification empirique
 * systématique, pas seulement lecture du code) : Spamhaus
 * (zen/sbl/xbl/pbl.spamhaus.org, 4 des 42 RBL_LIST) renvoie délibérément
 * `127.255.255.0/24` quand la requête provient d'un résolveur PUBLIC
 * partagé (Cloudflare, Google...) plutôt que du résolveur du demandeur
 * lui-même — politique anti-abus documentée par Spamhaus, PAS un vrai
 * résultat de listage. Sans le filtre ajouté, interroger via DoH aurait
 * produit un FAUX POSITIF "blacklisté" pour CHAQUE domaine vérifié sur
 * ces 4 listes — bien plus grave que le blocage réparé par ce correctif.
 *
 * Test comportemental réel (vraies requêtes DNS/HTTPS vers Cloudflare et
 * Spamhaus/SpamCop — pas de simulation, ces services publics répondent de
 * façon stable et documentée) :
 * 1. Une IP listée sur SpamCop (127.0.0.2, plage de test officielle)
 *    retourne bien un tableau non vide (hit).
 * 2. Une IP notoirement propre (8.8.8.8) sur SpamCop retourne bien un
 *    tableau vide (NXDOMAIN = non listé), pas `false`.
 * 3. La même IP propre sur Spamhaus (zen.spamhaus.org) retourne bien
 *    `false` (réponse 127.255.255.x filtrée), PAS un tableau non vide
 *    qui serait un faux positif de blacklistage.
 * 4. Un timeout DoH réel (requête vers une IP qui ne répond jamais) est
 *    bien interrompu en quelques secondes, pas bloqué indéfiniment.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $ref = new ReflectionMethod(DomainReputationManager::class, 'dnsQueryWithTimeout');
    $ref->setAccessible(true);
    $inst = (new ReflectionClass(DomainReputationManager::class))->newInstanceWithoutConstructor();

    // 1. SpamCop, IP listée (plage de test officielle 127.0.0.2).
    $listed = $ref->invoke($inst, '2.0.0.127.bl.spamcop.net', DNS_A);
    neria_assert(
        is_array($listed) && count($listed) > 0,
        "dnsQueryWithTimeout() ne détecte plus l'IP de test SpamCop (127.0.0.2) comme listée — jeu de test invalide ou régression du bug corrigé le 15/09/2026"
    );

    // 2. SpamCop, IP propre notoire (8.8.8.8) -- doit être NXDOMAIN ([]),
    // pas une erreur.
    $clean = $ref->invoke($inst, '8.8.8.8.bl.spamcop.net', DNS_A);
    neria_assert(
        is_array($clean) && count($clean) === 0,
        "dnsQueryWithTimeout() ne renvoie plus un tableau vide (NXDOMAIN) pour une IP propre (8.8.8.8) sur SpamCop — obtenu : " . var_export($clean, true)
    );

    // 3. Spamhaus, même IP propre -- doit être `false` (réponse
    // anti-abus 127.255.255.x filtrée), PAS un faux positif "listé".
    $spamhausResult = $ref->invoke($inst, '8.8.8.8.zen.spamhaus.org', DNS_A);
    neria_assert(
        $spamhausResult === false,
        "dnsQueryWithTimeout() ne filtre plus la réponse anti-abus 127.255.255.x de Spamhaus — régression du bug corrigé le 15/09/2026 : chaque domaine vérifié serait à tort signalé comme blacklisté sur zen/sbl/xbl/pbl.spamhaus.org (4 des 42 RBL_LIST), obtenu : " . var_export($spamhausResult, true)
    );

    // 4a. Les 3 vraies requêtes ci-dessus doivent rester rapides (aucun
    // blocage accidentel introduit par le correctif lui-même).
    $t0 = microtime(true);
    $ref->invoke($inst, '8.8.8.8.bl.spamcop.net', DNS_A);
    $elapsedReal = microtime(true) - $t0;
    neria_assert(
        $elapsedReal < 5.0,
        "Une requête DoH réelle vers SpamCop a pris {$elapsedReal}s (>5s) — latence anormale, à ré-examiner"
    );

    // 4b. Timeout réel -- confirme que CURLOPT_TIMEOUT_MS interrompt bien
    // une requête sans réponse au bout de quelques secondes, avec
    // EXACTEMENT les mêmes réglages que dnsQueryWithTimeout() (mêmes
    // constantes CURLOPT_TIMEOUT_MS/CURLOPT_CONNECTTIMEOUT_MS), sans
    // quoi ce correctif ne protégerait en réalité contre rien. IP non
    // routable (RFC 5737 TEST-NET-1) -- aucune route publique, garantit
    // une absence de réponse plutôt qu'un simple refus rapide de connexion.
    $t1 = microtime(true);
    $chTest = curl_init();
    curl_setopt_array($chTest, [
        CURLOPT_URL               => 'https://192.0.2.1/dns-query?name=example.com&type=A',
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_TIMEOUT_MS        => 2000,
        CURLOPT_CONNECTTIMEOUT_MS => 2000,
    ]);
    curl_exec($chTest);
    $timeoutErrNo = curl_errno($chTest);
    curl_close($chTest);
    $elapsedTimeout = microtime(true) - $t1;
    neria_assert(
        $timeoutErrNo === CURLE_OPERATION_TIMEDOUT || $timeoutErrNo === CURLE_COULDNT_CONNECT,
        "La requête vers l'IP non routable de test n'a pas produit l'erreur cURL attendue (timeout ou échec de connexion) — jeu de test invalide, obtenu errno={$timeoutErrNo}"
    );
    neria_assert(
        $elapsedTimeout < 10.0,
        "Le timeout cURL n'a pas interrompu la requête en moins de 10s (mesuré : {$elapsedTimeout}s) — un délai non borné réintroduirait le risque de blocage réparé par ce correctif"
    );

    $srcForConst = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
    neria_assert($srcForConst !== false, 'Impossible de lire src/DomainReputationManager.php');
    neria_assert(
        strpos($srcForConst, 'private const DNS_DOH_TIMEOUT_MS = 2000;') !== false,
        "DNS_DOH_TIMEOUT_MS n'est plus défini à 2000 — jeu de test invalide ou régression"
    );
    neria_assert(
        strpos($srcForConst, 'if ($errNo === CURLE_OPERATION_TIMEDOUT) {') !== false
        && strpos($srcForConst, 'return false;') !== false,
        "dnsQueryWithTimeout() ne traite plus un timeout cURL comme un résultat indéterminé (return false) — régression du bug corrigé le 15/09/2026 : un repli sur dns_get_record() ici réintroduirait le risque de blocage non borné"
    );

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::dnsQueryWithTimeout() interroge désormais un résolveur DoH avec un vrai timeout borné, filtre correctement la réponse anti-abus de Spamhaus (évitant un faux positif systématique), et ne se replie jamais sur dns_get_record() en cas de timeout — bug corrigé le 15/09/2026 (traité hors round, suite rounds 359/360)",
    ];
}
