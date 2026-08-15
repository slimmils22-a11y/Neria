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
            // Échec silencieux jusqu'ici : l'email part sans styles inlinés
            // (illisible sur Gmail/Orange/Yahoo) mais l'envoi "réussit" quand
            // même techniquement. CssInliner est statique, sans accès à
            // WatchdogManager (qui exige une instance Neria) — un compteur
            // Configuration léger est repris par checkCssInlinerSilentFailures.
            //
            // Round 160 : compteur scopé par boutique (clé suffixée par
            // idShop) — auparavant global, un échec d'inlining sur la
            // boutique A déclenchait un warning Health Check visible aussi
            // côté boutique B, et consulter/reset ce contrôle pour B
            // effaçait silencieusement le compteur réel de A. Round 160
            // également : cycle lecture-modification-écriture protégé par
            // GET_LOCK — sans lui, deux échecs d'inlining quasi simultanés
            // (cron d'envoi en masse) pouvaient tous deux lire la même
            // valeur avant que l'un des deux n'écrive, perdant un
            // incrément (lost update), sous-estimant le nombre réel
            // d'échecs silencieux affiché au marchand.
            $idShop = (int) \Context::getContext()->shop->id;
            $key    = 'NERIA_CSS_INLINE_FAILURES_' . $idShop;
            $db     = \Db::getInstance();
            if ((int) $db->getValue("SELECT GET_LOCK('neria_css_inline_failures_" . $idShop . "', 1)") === 1) {
                try {
                    \Configuration::updateValue($key, (int) \Configuration::get($key) + 1);
                } finally {
                    $db->execute("SELECT RELEASE_LOCK('neria_css_inline_failures_" . $idShop . "')");
                }
            }
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

        // Inverser AVANT le tri stable : à spécificité ÉGALE, merge() donne
        // la priorité à la règle traitée en PREMIER (elle ignore ensuite
        // toute propriété déjà inlinée) — sans cette inversion, le tri
        // stable conservait l'ordre d'apparition dans le CSS, donc la
        // PREMIÈRE règle déclarée gagnait à chaque conflit, alors que la
        // cascade CSS standard donne la victoire à la DERNIÈRE règle
        // déclarée à spécificité égale. Deux règles `.neria-btn { color }`
        // (base du thème puis surcharge marchand plus bas dans le CSS)
        // s'inlinaient donc avec la couleur de BASE sur Gmail/Outlook (qui
        // suppriment <style> et ne lisent que le style inline), alors que
        // Apple Mail (qui garde <style>) affichait la bonne couleur — rendu
        // divergent silencieux entre clients mail.
        $rules = array_reverse($rules);
        // Trier par spécificité décroissante (element.classe > .classe >
        // element) pour que les règles les plus spécifiques soient
        // appliquées en premier : merge() ignore une propriété déjà
        // inlinée, donc l'ordre de traitement détermine quelle règle
        // "gagne" en cas de conflit (ex: a{color} vs .neria-btn{color}). Le
        // tri PHP (usort) est stable depuis PHP 8 : à spécificité égale,
        // l'ordre déjà inversé ci-dessus est préservé.
        usort($rules, function ($a, $b) {
            return self::specificity($b[0]) <=> self::specificity($a[0]);
        });

        // Charger dans DOMDocument
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Round 137 : index construit en UNE seule passe sur le DOM (classe
        // → liste d'éléments), au lieu de relancer une requête XPath sur
        // l'intégralité du document POUR CHAQUE règle CSS. Un email avec un
        // style par ligne (tableau produit dynamique — panier abandonné,
        // récap de commande volumineux) génère autant de règles CSS que de
        // lignes, chacune relançant un scan complet du DOM : coût O(règles ×
        // taille du DOM), quadratique dès que les deux grandissent ensemble
        // (mesuré empiriquement : ×4 à chaque doublement du nombre de
        // lignes). Un email à plusieurs milliers de lignes pouvait bloquer
        // le process PHP synchrone plusieurs dizaines de secondes, sans
        // aucune limite de temps/complexité. L'index ramène le coût à
        // O(taille du DOM + règles), chaque règle ne parcourant ensuite que
        // les éléments réellement candidats.
        $classIndex = [];
        foreach ($xpath->query('//*[@class]') as $node) {
            if (!($node instanceof \DOMElement)) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($node->getAttribute('class'))) as $cls) {
                if ($cls !== '') {
                    $classIndex[$cls][] = $node;
                }
            }
        }

        foreach ($rules as [$sel, $props]) {
            $nodes = self::resolveSelector($sel, $dom, $classIndex);
            foreach ($nodes as $node) {
                if (!($node instanceof \DOMElement)) {
                    continue;
                }
                $node->setAttribute('style', self::merge($props, $node->getAttribute('style')));
            }
        }

        $result = $dom->saveHTML();
        // Round 137 : supprime le PI XML ajouté en tête (astuce pour forcer
        // DOMDocument à respecter l'UTF-8) — SANS ancre '^'. DOMDocument::
        // saveHTML() insère systématiquement un DOCTYPE AVANT ce PI dans le
        // résultat, donc l'ancre '^' ne matchait jamais et le PI restait
        // visible en tête de TOUS les emails Neria envoyés (juste après le
        // DOCTYPE), sans exception — dégradation de marque visible pour un
        // module "Luxury". La regex ci-dessous reste bornée à une seule
        // occurrence (limit=1) plutôt que non ancrée sur tout le document.
        $result = preg_replace('/<\?xml encoding="utf-8" \?' . '>\s*/', '', $result ?? '', 1) ?? $result;

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
    // RÉSOLUTION DE SÉLECTEUR → ÉLÉMENTS (via index de classes)
    // ============================================================

    /**
     * Round 137 : remplace l'ancienne conversion CSS → XPath (toXpath(),
     * qui relançait une requête XPath scannant tout le DOM à chaque appel)
     * par une résolution via l'index de classes construit une seule fois
     * dans process(), plus DOMDocument::getElementsByTagName() (natif,
     * bien plus efficace qu'un XPath réinterprété à chaque règle) pour le
     * filtre par élément.
     *
     * @return \DOMElement[]
     */
    private static function resolveSelector(string $sel, \DOMDocument $dom, array $classIndex): array
    {
        // .classe
        if (preg_match('/^\.([a-zA-Z][\w-]*)$/', $sel, $m)) {
            return $classIndex[$m[1]] ?? [];
        }

        // element (body, p, a, table, td…)
        if (preg_match('/^([a-zA-Z][\w]*)$/', $sel)) {
            return iterator_to_array($dom->getElementsByTagName(strtolower($sel)), false);
        }

        // element.classe (td.summary)
        if (preg_match('/^([a-zA-Z][\w]*)\.([a-zA-Z][\w-]*)$/', $sel, $m)) {
            $tag = strtolower($m[1]);
            $candidates = $classIndex[$m[2]] ?? [];
            $out = [];
            foreach ($candidates as $node) {
                if ($node instanceof \DOMElement && strtolower($node->nodeName) === $tag) {
                    $out[] = $node;
                }
            }
            return $out;
        }

        return [];
    }

    // ============================================================
    // FUSION DE STYLES
    // ============================================================

    /**
     * Découpe un bloc de déclarations CSS sur ';', en respectant les
     * parenthèses et guillemets — round 151 : un simple explode(';', ...)
     * coupait au milieu d'un `url(data:image/png;base64,...)` (logos/
     * signatures embarqués, courants dans ce module — cf.
     * SignatureGenerator.php), produisant une déclaration `background`
     * tronquée et invalide, et un second fragment sans ':' silencieusement
     * jeté par parseDecl() : l'image disparaissait dans les clients qui
     * ignorent <style> (Gmail, Outlook — la cible même de CssInliner).
     */
    private static function splitDeclarations(string $css): array
    {
        $decls = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $len = strlen($css);
        for ($i = 0; $i < $len; $i++) {
            $ch = $css[$i];
            if ($quote !== null) {
                $current .= $ch;
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                $current .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth = max(0, $depth - 1);
                $current .= $ch;
                continue;
            }
            if ($ch === ';' && $depth === 0) {
                $decls[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $decls[] = $current;
        }
        return $decls;
    }

    /**
     * Ajoute $newProps au style inline existant sans écraser les déclarations
     * déjà présentes (les styles inline ont une priorité plus haute).
     */
    private static function merge(string $newProps, string $existingInline): string
    {
        $result    = [];
        $important = [];
        $fromInline = [];

        // Round 137 : respecte !important dans la cascade — auparavant
        // totalement ignoré (ni détecté, ni priorisé), traité comme un
        // simple fragment de la valeur. Une règle !important (ex. un thème
        // forçant une couleur pour contourner un style de base) perdait
        // silencieusement face à une règle non-important mais plus
        // spécifique, ou face au style inline déjà présent — cascade CSS
        // standard violée, avec un rendu divergent entre clients qui
        // gardent le <style> (Apple Mail, respecte !important) et ceux qui
        // ne voient que l'inline (Gmail/Outlook, ne le respectait plus).
        // Ordre de priorité implémenté : !important (peu importe la
        // source) > inline non-important > règle non-important par
        // spécificité — conforme à la cascade CSS2.1 simplifiée (sans
        // feuilles utilisateur/agent).
        $parseDecl = static function (string $decl): ?array {
            $decl = trim($decl);
            if ($decl === '' || !str_contains($decl, ':')) {
                return null;
            }
            [$k, $v] = explode(':', $decl, 2);
            $k = trim($k);
            $v = trim($v);
            $isImportant = (bool) preg_match('/!\s*important\s*$/i', $v);
            if ($isImportant) {
                $v = trim(preg_replace('/!\s*important\s*$/i', '', $v));
            }
            return [$k, $v, $isImportant];
        };

        if ($existingInline !== '') {
            foreach (self::splitDeclarations($existingInline) as $decl) {
                $parsed = $parseDecl($decl);
                if ($parsed === null) {
                    continue;
                }
                [$k, $v, $isImportant] = $parsed;
                $result[$k]     = $v;
                $important[$k]  = $isImportant;
                $fromInline[$k] = true;
            }
        }

        foreach (self::splitDeclarations($newProps) as $decl) {
            $parsed = $parseDecl($decl);
            if ($parsed === null) {
                continue;
            }
            [$k, $v, $isImportant] = $parsed;
            if (!array_key_exists($k, $result)) {
                $result[$k]     = $v;
                $important[$k]  = $isImportant;
                $fromInline[$k] = false;
                continue;
            }
            // Round 173 : une déclaration dupliquée AU SEIN de $newProps
            // (même règle CSS, ex. fallback `color:#999; color:red;`, ou
            // style inline d'origine avec la même propriété deux fois) doit
            // suivre la cascade CSS standard "la dernière déclaration
            // gagne" — auparavant seule la première rencontrée était
            // conservée, produisant un rendu différent entre Apple Mail
            // (garde le <style>, respecte l'ordre) et Gmail/Outlook (ne
            // voient que le style inliné ici, recevaient la mauvaise
            // valeur). Ne s'applique qu'entre déclarations de même origine
            // ($fromInline[$k] === false, càd déjà issues de ce même appel
            // à merge()) : le style inline d'origine et les règles
            // provenant d'une autre feuille gardent leur priorité respective
            // via la logique !important ci-dessous, inchangée.
            if (!$fromInline[$k] && (!$important[$k] || $isImportant)) {
                $result[$k]    = $v;
                $important[$k] = $isImportant;
                continue;
            }
            // Une déclaration !important entrante ne peut écraser qu'une
            // déclaration existante NON importante — entre deux !important,
            // la première rencontrée (règle la plus spécifique déjà
            // traitée, ou style inline d'origine) l'emporte, cohérent avec
            // le reste de l'algorithme (premier arrivé = priorité la plus
            // haute déjà établie par l'ordre de traitement).
            if ($isImportant && !$important[$k]) {
                $result[$k]    = $v;
                $important[$k] = true;
            }
        }

        $parts = [];
        foreach ($result as $k => $v) {
            $suffix = !empty($important[$k]) ? ' !important' : '';
            $parts[] = "$k: $v{$suffix}";
        }
        return implode('; ', $parts);
    }
}
