<?php
/**
 * Régression : ConfigManager::saveDesignConfig()/saveTypographyConfig()/
 * saveSocialConfig()/saveCustomVariables()/resetToDefaults()/
 * resetDesignOnly() ne doivent plus court-circuiter sur le premier échec
 * d'écriture — `$success = $success && $this->set(...)` faisait que dès que
 * $success valait false (premier set() échoué), l'opérande droit du && ne
 * s'évaluait PLUS DU TOUT : tous les set() suivants de la même
 * boucle/méthode n'étaient jamais TENTÉS, pas seulement comptés en échec.
 *
 * Bug identifié le 14/09/2026 (round 355, audit multi-agents, confiance
 * haute — comportement du court-circuit && en PHP déterministe, pas une
 * hypothèse externe). Scénario concret : un marchand modifie 10 réglages
 * Design dans le même submit ; si l'écriture du 1er champ échoue (verrou
 * DB transitoire), les 9 suivants ne sont plus JAMAIS écrits en base,
 * silencieusement — le marchand voit un message d'échec générique sans
 * savoir que la quasi-totalité du formulaire a été ignorée plutôt que
 * réellement tentée-et-échouée.
 *
 * Corrigé le 14/09/2026 : chaque appel set()/setCustomVariable() est
 * désormais capturé dans $ok AVANT d'être agrégé dans $success
 * (`$ok = $this->set(...); $success = $success && $ok;`), garantissant que
 * CHAQUE champ est toujours réellement tenté, peu importe l'issue des
 * précédents.
 *
 * Test comportemental réel : sous-classe ConfigManager qui force
 * artificiellement l'ÉCHEC du tout premier appel à set() (simulant un
 * verrou DB transitoire), enregistre TOUTES les clés effectivement
 * appelées, puis vérifie que saveDesignConfig() a bien tenté d'écrire les
 * 10 champs (pas seulement le premier) malgré cet échec initial.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    // ── Partie 1 : structurel — aucun site ne court-circuite plus ───────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ConfigManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ConfigManager.php');

    neria_assert(
        preg_match('/\$success\s*=\s*\$success\s*&&\s*\$this->(set|setCustomVariable)\(/', $src) !== 1,
        "ConfigManager contient encore un site \$success = \$success && \$this->set(...) court-circuitant — régression du bug corrigé le 14/09/2026 (round 355)"
    );
    $okAssignCount = substr_count($src, '$ok = $this->set(') + substr_count($src, '$ok = $this->setCustomVariable(');
    neria_assert(
        $okAssignCount >= 18,
        "ConfigManager n'a plus les 18 sites \$ok = \$this->set(...)/setCustomVariable(...) attendus (trouvé {$okAssignCount}) — régression du bug corrigé le 14/09/2026 (round 355)"
    );

    // ── Partie 2 : comportemental réel — le 1er échec ne bloque pas les suivants
    eval('
        class NeriaTest747ConfigManager extends ConfigManager
        {
            public array $attempted = [];
            private bool $firstCallDone = false;

            public function set(string $key, $value): bool
            {
                $this->attempted[] = $key;
                if (!$this->firstCallDone) {
                    $this->firstCallDone = true;
                    return false; // simule un échec du tout premier set() (verrou DB transitoire)
                }
                return parent::set($key, $value);
            }
        }
    ');

    $module = neria_test_module();
    $mgr    = new NeriaTest747ConfigManager($module);

    // Sauvegarde les valeurs d'origine pour restauration.
    $originalValues = [];
    foreach (ConfigManager::DEFAULTS as $key => $defaultValue) {
        $originalValues[$key] = Configuration::get($key);
    }

    try {
        $data = [
            'color_background' => '#ffffff',
            'color_container'  => '#f5f5f5',
            'color_accent'     => '#000000',
            'color_text'       => '#111111',
            'btn_color'        => '#222222',
            'color_header_bg'  => '#333333',
            'color_footer_bg'  => '#444444',
            'color_footer_text'=> '#555555',
            'dark_mode'        => '1',
            'container_width'  => '600',
            'logo_width'       => '120',
            'btn_radius'       => '6',
            'section_padding'  => '32',
            'block_spacing'    => '24',
            'separator_style'  => 'line',
            'card_shadow'      => 'soft',
        ];

        $result = $mgr->saveDesignConfig($data);

        neria_assert(
            $result === false,
            "saveDesignConfig() devrait retourner false (le 1er set() a échoué) — le test lui-même est invalide si ce n'est pas le cas"
        );

        // Le point clé du correctif : TOUS les champs doivent avoir été
        // TENTÉS (attempted), pas seulement le premier avant l'échec.
        neria_assert(
            count($mgr->attempted) >= 16,
            "saveDesignConfig() n'a tenté que " . count($mgr->attempted) . " écriture(s) sur 16 attendues après l'échec du 1er set() — régression du bug corrigé le 14/09/2026 (round 355) : le court-circuit && a de nouveau bloqué les écritures suivantes"
        );

        // Les couleurs après la première (color_background, qui a échoué
        // par construction du test) doivent avoir été réellement écrites
        // en base malgré l'échec initial.
        neria_assert(
            (string) Configuration::get(ConfigManager::KEY_COLOR_CONTAINER) === '#f5f5f5',
            "color_container n'a pas été écrit en base après l'échec du 1er set() — régression du bug corrigé le 14/09/2026 (round 355)"
        );
        neria_assert(
            (string) Configuration::get(ConfigManager::KEY_CARD_SHADOW) === 'soft',
            "card_shadow (dernier champ de la méthode) n'a pas été écrit en base après l'échec du 1er set() — régression du bug corrigé le 14/09/2026 (round 355)"
        );

        return [
            'pass'    => true,
            'message' => "ConfigManager::saveDesignConfig() tente bien TOUTES les écritures même après l'échec du 1er set() (" . count($mgr->attempted) . " champs tentés, color_container et card_shadow réellement écrits) — bug corrigé le 14/09/2026 (round 355)",
        ];
    } finally {
        foreach ($originalValues as $key => $value) {
            if ($value !== false) {
                Configuration::updateValue($key, $value);
            }
        }
    }
}
