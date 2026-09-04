<?php
/**
 * Régression : `WatchdogManager::getAlertEmail()` (repli `NERIA_ALERT_EMAIL`
 * → `PS_SHOP_EMAIL`) échoue silencieusement quand les DEUX sont invalides
 * ou vides — `sendImmediateAlert()`/`sendDailyDigest()` retournaient
 * simplement sans rien envoyer, structurellement indiscernable du cas
 * "rien à signaler" : aucune trace nulle part (contrairement à un échec
 * réel de `mail()`, qui lui journalise déjà explicitement via
 * `error_log()`). Un marchand ayant fait une faute de frappe sur
 * `NERIA_ALERT_EMAIL` (et dont `PS_SHOP_EMAIL` n'est pas non plus valide)
 * ne recevait alors plus JAMAIS aucune alerte critique ni digest
 * quotidien — précisément le scénario que ce mécanisme est censé
 * prévenir, mais appliqué à sa propre configuration.
 *
 * Bug identifié le 04/09/2026 (round 298, audit "boîte de réception de
 * l'alerte elle-même").
 *
 * Corrigé le 04/09/2026 (round 298) : `error_log()` ajouté aux deux points
 * d'échec (`sendImmediateAlert()`/`sendDailyDigest()`), et un nouveau
 * contrôle de santé BO (`HealthCheckManager::checkAlertEmailInvalid()`)
 * expose explicitement ce cas dans le tableau de bord — seul endroit où
 * un marchand qui ne consulte jamais les logs serveur peut découvrir que
 * son mécanisme d'alerte est cassé.
 *
 * Test comportemental réel : bascule `NERIA_ALERT_EMAIL`/`PS_SHOP_EMAIL`
 * sur des valeurs invalides, vérifie que `checkAlertEmailInvalid()`
 * détecte bien ce cas (statut WARNING), puis restaure les valeurs
 * d'origine et vérifie le retour au statut OK.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $origAlertEmail = (string) Configuration::getGlobalValue('NERIA_ALERT_EMAIL');
    $idShop = (int) Context::getContext()->shop->id;
    $origShopEmail = (string) Configuration::get('PS_SHOP_EMAIL', null, null, $idShop);

    try {
        Configuration::updateGlobalValue('NERIA_ALERT_EMAIL', 'pas-un-email-valide');
        Configuration::updateValue('PS_SHOP_EMAIL', 'pas-un-email-valide-non-plus', false, null, $idShop);

        $hcm = new HealthCheckManager(neria_test_module());
        $ref = new ReflectionMethod(HealthCheckManager::class, 'checkAlertEmailInvalid');
        $ref->setAccessible(true);
        $result = $ref->invoke($hcm);

        neria_assert(
            ($result['status'] ?? '') === 'warning',
            "HealthCheckManager::checkAlertEmailInvalid() ne détecte plus une adresse d'alerte Watchdog invalide (statut '" . ($result['status'] ?? '?') . "' au lieu de 'warning') — régression du bug corrigé le 04/09/2026 (round 298) : un marchand ayant fait une faute de frappe sur son adresse d'alerte ne recevrait plus jamais aucune alerte critique, sans que rien ne le signale dans le BO"
        );

        // Restaure une adresse valide et vérifie le retour à OK.
        Configuration::updateGlobalValue('NERIA_ALERT_EMAIL', 'contact@example.test');
        $result2 = $ref->invoke($hcm);
        neria_assert(
            ($result2['status'] ?? '') === 'ok',
            "HealthCheckManager::checkAlertEmailInvalid() reste en warning malgré une adresse valide restaurée — jeu de test invalide ou régression"
        );

        // Vérification structurelle du error_log() ajouté dans WatchdogManager.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php');
        neria_assert($src !== false, 'Impossible de lire src/WatchdogManager.php');
        neria_assert(
            strpos($src, "error_log('[Neria WatchdogManager] Alerte immédiate non envoyée") !== false
                && strpos($src, "error_log('[Neria WatchdogManager] Digest quotidien non envoyé") !== false,
            "WatchdogManager ne journalise plus via error_log() les 2 cas d'email d'alerte invalide (sendImmediateAlert()/sendDailyDigest()) — régression du bug corrigé le 04/09/2026 (round 298)"
        );

        return [
            'pass'    => true,
            'message' => "checkAlertEmailInvalid() détecte désormais une adresse d'alerte Watchdog invalide et WatchdogManager journalise via error_log() ce cas jusqu'ici totalement silencieux — bug corrigé le 04/09/2026 (round 298)",
        ];
    } finally {
        Configuration::updateGlobalValue('NERIA_ALERT_EMAIL', $origAlertEmail);
        Configuration::updateValue('PS_SHOP_EMAIL', $origShopEmail, false, null, $idShop);
    }
}
