<?php
/**
 * Régression : BlacklistManager::isBlacklisted() comparait le code langue
 * ($lang) de façon SENSIBLE à la casse, contrairement à $template qui
 * bénéficie déjà d'une normalisation mb_strtolower() des deux côtés
 * (écriture ET lecture) depuis le round 136 — le même correctif n'avait
 * jamais été étendu à $lang. add() n'a jamais normalisé la casse du code
 * langue à l'écriture non plus.
 *
 * Non exploitable aujourd'hui via le <select> BO (codes langue déjà en
 * minuscules), mais silencieusement cassé dès qu'une règle serait
 * enregistrée avec un code langue en majuscules par un autre chemin
 * (import CSV, évolution future du BO, insertion directe en base) : la
 * règle "cette langue" ne matcherait alors plus jamais le code langue
 * normalisé (toujours en minuscules) transmis par les appelants réels
 * (EmailRenderer::resolveTemplate() force systématiquement strtolower()),
 * laissant partir un email censé être bloqué pour cette langue.
 *
 * Corrigé le 06/09/2026 (round 308) : mb_strtolower() appliqué à $lang
 * dans add() (écriture) ET isBlacklisted() (lecture), même pattern que
 * $template.
 *
 * Test comportemental réel : insère une règle avec un code langue en
 * MAJUSCULES directement en base (simule le chemin d'écriture non-BO),
 * puis vérifie qu'isBlacklisted() avec le code langue normalisé en
 * minuscules (comportement réel des appelants) la détecte bien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BlacklistManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $template = 'regtest583_template';
    $db->execute("DELETE FROM {$prefix}neria_blacklist WHERE template = '{$template}'");

    try {
        // Simule une règle enregistrée avec un code langue en MAJUSCULES —
        // add() normalise désormais, mais on insère directement en base
        // pour couvrir aussi le cas d'une donnée existante non normalisée
        // (import antérieur au correctif, insertion manuelle).
        $db->execute(
            "INSERT INTO {$prefix}neria_blacklist (id_shop, template, lang, date_add)
             VALUES ({$idShop}, '{$template}', 'FR', NOW())"
        );

        $mgr = new BlacklistManager($idShop);

        neria_assert(
            $mgr->isBlacklisted($template, 'fr') === true,
            "isBlacklisted() ne détecte plus une règle dont le code langue est enregistré en MAJUSCULES ('FR') lorsqu'interrogé avec le code langue normalisé en minuscules ('fr') — régression du bug corrigé le 06/09/2026 (round 308)"
        );
        neria_assert(
            $mgr->isBlacklisted($template, 'en') === false,
            "isBlacklisted() bloque à tort une langue non concernée par la règle — jeu de test invalide"
        );

        // Vérifie aussi que add() normalise désormais à l'écriture.
        $db->execute("DELETE FROM {$prefix}neria_blacklist WHERE template = '{$template}'");
        $ok = $mgr->add($template, 'DE');
        neria_assert($ok, "add() a échoué — jeu de test invalide");
        $storedLang = (string) $db->getValue("SELECT lang FROM {$prefix}neria_blacklist WHERE template = '{$template}'", false);
        neria_assert(
            $storedLang === 'de',
            "add() ne normalise plus la casse du code langue à l'écriture (obtenu : '{$storedLang}') — régression du bug corrigé le 06/09/2026 (round 308)"
        );

        return [
            'pass'    => true,
            'message' => "BlacklistManager normalise désormais la casse du code langue à l'écriture (add()) ET à la lecture (isBlacklisted()), même correctif que \$template (round 136) — bug corrigé le 06/09/2026 (round 308)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_blacklist WHERE template = '{$template}'");
    }
}
