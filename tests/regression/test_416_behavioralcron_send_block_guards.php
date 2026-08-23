<?php
/**
 * Régression : BehavioralCronManager::send() (méthode partagée par ~15
 * templates comportementaux — anniversaire, relance panier, panier
 * fantôme, win-back, etc.) n'avait pas de garde-fous bounce/blacklist/
 * cooldown avant Mail::Send() — seules les préférences par catégorie
 * étaient vérifiées. Même piège Mail::Send()===true déjà corrigé pour
 * CollectionManager (round 180)/LookCompletionManager (round 190)/
 * WaitlistManager (round 194) mais jamais étendu ici.
 *
 * Bug réel identifié le 23/08/2026 (round 195) : un client bloqué
 * (bounce/blacklist/cooldown) au moment de l'envoi voyait son template
 * marqué "envoyé" via INSERT IGNORE INTO neria_behavioral_sent (contrainte
 * UNIQUE) — exclu à vie de CE template précis, même après la levée du
 * blocage, sans jamais être retenté par le cron.
 *
 * Corrigé le 23/08/2026 (round 195) : les 3 garde-fous ajoutés AVANT
 * Mail::Send(), chacun retournant (return;) sans jamais atteindre l'INSERT
 * IGNORE ci-dessous.
 *
 * Test structurel (send() est privée, appelée en interne par ~15 méthodes
 * d'envoi comportemental différentes — un test comportemental complet
 * nécessiterait de simuler tout le flux d'un cron, hors de portée d'un
 * test isolé, cf. test_395 pour la même contrainte sur ce fichier) :
 * vérifie que les 3 garde-fous précèdent bien Mail::Send().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    $posSend = strpos($src, 'private function send(');
    neria_assert($posSend !== false, 'BehavioralCronManager::send() introuvable — jeu de test invalide');

    $posMailSend = strpos($src, '$sent = \Mail::Send(', $posSend);
    neria_assert($posMailSend !== false, "Appel Mail::Send() introuvable dans send() — jeu de test invalide");

    $posBounce = strpos($src, "\\BounceManager::isBounced(\$email)", $posSend);
    $posBlacklist = strpos($src, "BlacklistManager(\$idShop))->isBlacklisted(\$template", $posSend);
    $posCooldown = strpos($src, "CooldownManager())->isDuplicate(\$email, \$template", $posSend);

    neria_assert(
        $posBounce !== false && $posBounce < $posMailSend,
        "BehavioralCronManager::send() ne vérifie plus BounceManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 195)"
    );
    neria_assert(
        $posBlacklist !== false && $posBlacklist < $posMailSend,
        "BehavioralCronManager::send() ne vérifie plus BlacklistManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 195)"
    );
    neria_assert(
        $posCooldown !== false && $posCooldown < $posMailSend,
        "BehavioralCronManager::send() ne vérifie plus CooldownManager avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 195)"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::send() vérifie bien bounce/blacklist/cooldown avant Mail::Send() — bug corrigé le 23/08/2026 (round 195)",
    ];
}
