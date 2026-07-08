<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — CssInliner
 *
 * Inline les règles CSS d'un bloc <style> directement dans les attributs
 * style="" de chaque élément HTML. Améliore la compatibilité avec les clients
 * email qui suppriment les blocs <style> (Gmail, Orange Mail, Yahoo Mail…).
 *
 * Le bloc <style> est conservé pour les clients qui le supportent (Apple Mail,
 * Outlook desktop). Les styles inlinés servent de fallback pour les autres.
 *
 * Sélecteurs pris en charge : .classe, element, element.classe
 * Sélecteurs ignorés         : pseudo-classes (:hover), @media, descendants
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CssInliner
{
    /**
     * Inline les règles CSS dans le HTML fourni.
     * Retourne le HTML original si une erreur survient.
     */
    public static function inline(string $html): string
    {
        try {
            return self::process($html);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    // ============================================================
    // TRAITEMENT PRINCIPAL
    // ============================================================

    private static function process(string $html): string
    {
        // Extraire le CSS de tous les blocs <style>
        if (!preg_match_all('/<style\b[^>]*>(.*?)<\/style>/si', $html, $sm)) {
            return $html;
        }
        $cssText = implode("\n", $sm[1]);

        // Parser les règles simples
        $rules = self::parseRules($cssText);
        if (!$rules) {
            return $html;
        }

        // Trier par spécificité croissante (element < .classe < element.classe)
        // pour que les règles les plus spécifiques soient appliquées en premier :
        // merge() ignore une propriété déjà inlinée, donc l'ordre de traitement
        // détermine quelle règle "gagne" en cas de conflit (ex: a{color} vs .neria-btn{color}).
        usort($rules, function ($a, $b) {
            return self::specificity($b[0]) <=> self::specificity($a[0]);
        });

        // Charger dans DOMDocument
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        foreach ($rules as [$sel, $props]) {
            $xp = self::toXpath($sel);
            if (!$xp) {
                continue;
            }
            $nodes = $xpath->query($xp);
            if (!$nodes) {
                continue;
            }
            foreach ($nodes as $node) {
                if (!($node instanceof \DOMElement)) {
                    continue;
                }
                $node->setAttribute('style', self::merge($props, $node->getAttribute('style')));
            }
        }

        $result = $dom->saveHTML();
        // Supprimer la PI XML ajoutée en tête si présente
        $result = preg_replace('/^<\?[^?]+\?>\s*/s', '', $result ?? '') ?? $result;

        return ($result !== '' && $result !== null) ? $result : $html;
    }

    // ============================================================
    // PARSEUR CSS
    // ============================================================

    private static function parseRules(string $css): array
    {
        // Supprimer commentaires
        $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
        // Supprimer @-blocs (media, font-face, keyframes…)
        $css = preg_replace('/@[a-z-]+[^{]*\{(?:[^{}]*|\{[^{}]*\})*\}/si', '', $css) ?? $css;

        $rules = [];
        preg_match_all('/([^{}@]+)\{([^{}]*)\}/s', $css, $ms, PREG_SET_ORDER);

        foreach ($ms as $m) {
            $props = trim($m[2]);
            if ($props === '') {
                continue;
            }
            foreach (explode(',', $m[1]) as $rawSel) {
                $sel = trim($rawSel);
                if ($sel === '') {
                    continue;
                }
                // Ignorer pseudo-classes, combinateurs, @-rules
                if (str_contains($sel, ':') || str_contains($sel, '@')
                    || str_contains($sel, '>') || str_contains($sel, '+')
                    || str_contains($sel, '~')) {
                    continue;
                }
                // Ignorer les sélecteurs descendants (espace entre deux identifiants)
                if (preg_match('/[\w)\]]\s+[a-zA-Z.#[*]/', $sel)) {
                    continue;
                }
                $rules[] = [$sel, $props];
            }
        }

        return $rules;
    }

    // ============================================================
    // SPÉCIFICITÉ (approximation simple pour l'ordre de traitement)
    // ============================================================

    private static function specificity(string $sel): int
    {
        // element.classe (le plus spécifique des sélecteurs supportés)
        if (preg_match('/^[a-zA-Z][\w]*\.[a-zA-Z][\w-]*$/', $sel)) {
            return 2;
        }
        // .classe
        if (preg_match('/^\.[a-zA-Z][\w-]*$/', $sel)) {
            return 1;
        }
        // element
        return 0;
    }

    // ============================================================
    // CONVERSION CSS → XPATH
    // ============================================================

    private static function toXpath(string $sel): ?string
    {
        // .classe
        if (preg_match('/^\.([a-zA-Z][\w-]*)$/', $sel, $m)) {
            $c = $m[1];
            return "//*[contains(concat(' ',normalize-space(@class),' '),' $c ')]";
        }

        // element (body, p, a, table, td…)
        if (preg_match('/^([a-zA-Z][\w]*)$/', $sel)) {
            return '//' . strtolower($sel);
        }

        // element.classe (td.summary)
        if (preg_match('/^([a-zA-Z][\w]*)\.([a-zA-Z][\w-]*)$/', $sel, $m)) {
            return "//{$m[1]}[contains(concat(' ',normalize-space(@class),' '),' {$m[2]} ')]";
        }

        return null;
    }

    // ============================================================
    // FUSION DE STYLES
    // ============================================================

    /**
     * Ajoute $newProps au style inline existant sans écraser les déclarations
     * déjà présentes (les styles inline ont une priorité plus haute).
     */
    private static function merge(string $newProps, string $existingInline): string
    {
        $result = [];

        if ($existingInline !== '') {
            foreach (explode(';', $existingInline) as $decl) {
                $decl = trim($decl);
                if ($decl !== '' && str_contains($decl, ':')) {
                    [$k, $v] = explode(':', $decl, 2);
                    $result[trim($k)] = trim($v);
                }
            }
        }

        foreach (explode(';', $newProps) as $decl) {
            $decl = trim($decl);
            if ($decl !== '' && str_contains($decl, ':')) {
                [$k, $v] = explode(':', $decl, 2);
                $k = trim($k);
                if (!array_key_exists($k, $result)) {
                    $result[$k] = trim($v);
                }
            }
        }

        $parts = [];
        foreach ($result as $k => $v) {
            $parts[] = "$k: $v";
        }
        return implode('; ', $parts);
    }
}
