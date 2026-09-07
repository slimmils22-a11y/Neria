<?php
/**
 * Régression : QueueManager::nextOccurrence() calculait $now via
 * `new \DateTime()` (horloge PHP, fuseau date.timezone) alors que la valeur
 * produite (send_at) est stockée telle quelle puis comparée à NOW() côté
 * MySQL dans processQueue() (`send_at <= NOW()`) — même piège horloge
 * PHP/MySQL déjà corrigé ailleurs dans le module (PropensityScoreManager
 * round 303, StatsManager rounds 310-312), jamais porté ici. Si le serveur
 * web (PHP) et le serveur MySQL n'ont pas le même fuseau horaire, un client
 * avec une heure préférée de 14h recevait son email 1 à 2h avant/après
 * l'heure réellement souhaitée, silencieusement.
 *
 * Corrigé le 06/09/2026 (round 313) : $now est désormais lu via
 * `SELECT NOW()` côté MySQL, insensible au fuseau PHP configuré.
 *
 * Test comportemental réel : appelle nextOccurrence() via réflexion sous
 * deux fuseaux PHP radicalement différents (Europe/Paris puis
 * Pacific/Kiritimati, UTC+14) et vérifie que le résultat est identique —
 * preuve que le calcul ne dépend plus de l'horloge PHP.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $originalTz = date_default_timezone_get();

    try {
        $mgr = new QueueManager(neria_test_module());
        $ref = new ReflectionMethod(QueueManager::class, 'nextOccurrence');
        $ref->setAccessible(true);

        date_default_timezone_set('Europe/Paris');
        $resultParis = $ref->invoke($mgr, 14);

        date_default_timezone_set('Pacific/Kiritimati');
        $resultExotic = $ref->invoke($mgr, 14);

        neria_assert(
            $resultParis === $resultExotic,
            "QueueManager::nextOccurrence() renvoie une date différente selon le fuseau PHP configuré (Europe/Paris='{$resultParis}' vs Pacific/Kiritimati='{$resultExotic}') — régression du bug corrigé le 06/09/2026 (round 313) : le calcul dépend de nouveau de l'horloge PHP (new \\DateTime()) au lieu de SELECT NOW() côté MySQL"
        );

        // Vérification structurelle complémentaire : la lecture MySQL est
        // bien la source de $now dans le corps de la méthode elle-même.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
        neria_assert($src !== false, 'Impossible de lire src/QueueManager.php');
        $posFn = strpos($src, 'private function nextOccurrence(int $hour): string');
        neria_assert($posFn !== false, 'nextOccurrence() introuvable — jeu de test invalide');
        $body = substr($src, $posFn, 1500);
        neria_assert(
            strpos($body, "new \\DateTime((string) \$this->db->getValue('SELECT NOW()'))") !== false,
            "nextOccurrence() ne lit plus \$now via SELECT NOW() côté MySQL dans son propre corps — régression du bug corrigé le 06/09/2026 (round 313)"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager::nextOccurrence() calcule désormais \$now via SELECT NOW() côté MySQL, insensible au fuseau horaire PHP configuré (vérifié sous Europe/Paris ET Pacific/Kiritimati, résultat identique) — bug corrigé le 06/09/2026 (round 313)",
        ];
    } finally {
        date_default_timezone_set($originalTz);
    }
}
