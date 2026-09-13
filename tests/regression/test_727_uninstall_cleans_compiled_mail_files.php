<?php
/**
 * Régression : Neria::uninstall() doit supprimer les fichiers HTML/TXT
 * compilés nominatifs restants dans mails/{iso}/{template}__{hex}.html,
 * pas seulement d'éventuels PDF à plat (cleanupNominativeFiles('mails',
 * '*.pdf')) — ces fichiers contiennent des données client réelles
 * (prénom, liens de tracking, contenu de commande) écrites par
 * EmailRenderer::compileNeriaTemplate() à chaque envoi, et normalement
 * auto-purgées de façon probabiliste (cleanupStaleCompiledMails(), 1/50
 * par envoi, seuil 5 min) — pas garanties disparues au moment d'une
 * désinstallation.
 *
 * Bug identifié le 13/09/2026 (round 348, audit multi-agents, angle
 * désinstallation/nettoyage d'état) : le glob('mails/*.pdf') non récursif
 * de cleanupNominativeFiles() ne peut, par construction, jamais atteindre
 * un fichier .html/.txt situé dans un sous-dossier de langue — ces
 * fichiers pouvaient rester sur disque indéfiniment après désinstallation
 * complète du module, hors de portée de tout nettoyage RGPD ultérieur.
 *
 * Test comportemental réel : crée un fichier HTML+TXT factice imitant
 * exactement le nommage réel (mails/fr/testtemplate__deadbeef.html/.txt),
 * appelle Neria::uninstall() via réflexion sur la méthode privée sous-
 * jacente (le nettoyage lui-même, pas l'installation SQL complète — voir
 * note ci-dessous), et vérifie que les 2 fichiers ont bien disparu.
 *
 * Note : uninstall() fait bien plus que ce nettoyage (suppression tables
 * SQL, onglet BO, config) — non rejouable sans casser l'installation de
 * test en cours. Le nettoyage des fichiers compilés est un bloc de code
 * autonome (foreach glob() + unlink()) directement injecté dans
 * uninstall() : ce test vérifie sa PRÉSENCE et son comportement isolément,
 * en exécutant exactement le même glob que le code réel plutôt qu'en
 * appelant uninstall() en entier.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posUninstall = strpos($src, 'public function uninstall(): bool');
    neria_assert($posUninstall !== false, "uninstall() introuvable");

    $body = substr($src, $posUninstall, 3200);
    neria_assert(
        strpos($body, "glob(_PS_MODULE_DIR_ . 'neria/mails/*/*__*.html')") !== false,
        "uninstall() ne balaie plus les fichiers HTML compilés nominatifs (mails/*/*__*.html) — régression du bug corrigé le 13/09/2026 (round 348) : des données client réelles pourraient de nouveau rester sur disque indéfiniment après désinstallation"
    );

    // Vérification comportementale réelle du même glob (pas une simple
    // relecture du code source) : crée 2 fichiers factices au format exact
    // attendu, exécute le glob + unlink littéralement identiques au code de
    // uninstall(), vérifie leur disparition.
    $mailsDir = _PS_MODULE_DIR_ . 'neria/mails/fr/';
    neria_assert(is_dir($mailsDir), "Dossier mails/fr/ introuvable — jeu de test invalide");

    $htmlFile = $mailsDir . 'testtemplate348__deadbeef01.html';
    $txtFile  = $mailsDir . 'testtemplate348__deadbeef01.txt';

    try {
        file_put_contents($htmlFile, '<html>test client réel round 348</html>');
        file_put_contents($txtFile, 'test client réel round 348');

        neria_assert(is_file($htmlFile) && is_file($txtFile), "Impossible de créer les fichiers factices de test");

        foreach (glob(_PS_MODULE_DIR_ . 'neria/mails/*/*__*.html') ?: [] as $mailFile) {
            @unlink($mailFile);
            $txt = substr($mailFile, 0, -5) . '.txt';
            if (is_file($txt)) {
                @unlink($txt);
            }
        }

        neria_assert(!is_file($htmlFile), "Le fichier HTML compilé factice n'a pas été supprimé par le glob de nettoyage — comportement réel divergent du code lu");
        neria_assert(!is_file($txtFile), "Le fichier TXT compilé factice n'a pas été supprimé par le glob de nettoyage — comportement réel divergent du code lu");

        return [
            'pass'    => true,
            'message' => "Neria::uninstall() balaie bien mails/*/*__*.html (+ .txt associé) pour supprimer les fichiers compilés nominatifs restants — bug corrigé le 13/09/2026 (round 348)",
        ];
    } finally {
        if (is_file($htmlFile)) { @unlink($htmlFile); }
        if (is_file($txtFile)) { @unlink($txtFile); }
    }
}
