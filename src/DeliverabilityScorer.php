<?php
/**
 * NERIA — DeliverabilityScorer
 *
 * Analyse le contenu HTML d'un email et retourne un score de
 * délivrabilité de 0 à 100 avec recommandations détaillées.
 * Aide le marchand à comprendre pourquoi un email risque le dossier spam.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class DeliverabilityScorer
{
    /**
     * Liste exhaustive des mots et expressions déclencheurs de filtres
     * anti-spam — sources : SpamAssassin, Barracuda, Proofpoint, Microsoft
     * SmartScreen, Gmail, Outlook. Couvre FR + EN + DE + ES + IT + PT car
     * le module est international.
     *
     * @var array
     */
    private array $spamTriggers = [

        // ── FRANÇAIS ────────────────────────────────────────────
        // Offres commerciales agressives
        'gratuit', 'gratis', 'cadeau', 'offre spéciale', 'offre limitée',
        'offre exceptionnelle', 'prix réduit', 'réduction exceptionnelle',
        'remise immédiate', 'soldes', 'liquidation', 'déstockage',
        'promotion', 'promo', 'bon plan', 'bonne affaire', 'affaire',
        'économisez', 'économies', 'rabais', 'moins cher', 'pas cher',

        // Urgence et pression
        'urgent', 'urgence', 'immédiatement', 'maintenant', 'vite',
        'dépêchez-vous', 'dépêchez vous', 'dernière chance',
        'offre valable', 'expire', 'expiration', 'limité dans le temps',
        'temps limité', 'ne ratez pas', 'ne manquez pas', 'saisir',
        'profitez maintenant', 'agissez maintenant', 'aujourd\'hui seulement',

        // Gains et argent
        'gagner de l\'argent', 'gagnez', 'revenus supplémentaires',
        'revenus additionnels', 'argent facile', 'fortune',
        'enrichissez-vous', 'devenez riche', 'millionnaire',
        'investissement garanti', 'rendement garanti', 'sans risque',
        'bénéfices', 'profit', 'cash', 'liquidités',

        // Appels à l'action suspects
        'cliquez ici', 'cliquez maintenant', 'cliquer ici',
        'cliquez vite', 'accédez ici', 'téléchargez gratuitement',
        'inscrivez-vous gratuitement', 'essai gratuit',
        'sans engagement', 'sans frais', 'zéro frais',

        // Santé et bien-être douteux
        'perdez du poids', 'maigrir', 'minceur', 'régime miracle',
        'pilules', 'médicament', 'traitement', 'guérison',
        'remède miracle', 'sans ordonnance', 'viagra', 'cialis',

        // Jeux et loteries
        'vous avez gagné', 'félicitations', 'gagnant', 'lot',
        'loterie', 'tirage au sort', 'jackpot', 'casino',
        'paris sportifs', 'jeux d\'argent',

        // Formules typiques spam
        'cher ami', 'cher abonné', 'cher client',
        'cette offre vous est réservée', 'vous avez été sélectionné',
        'vous êtes l\'heureux', 'profitez de cette opportunité',
        'opportunité unique', 'opportunité exceptionnelle',

        // ── ANGLAIS ─────────────────────────────────────────────
        'free', 'free gift', 'free offer', 'free trial', 'free access',
        'free money', 'free consultation', 'absolutely free',
        'no cost', 'no fees', 'no charge', 'no credit card',
        'discount', 'big discount', 'huge discount', 'special discount',
        'limited offer', 'limited time', 'limited time offer',
        'act now', 'act immediately', 'order now', 'buy now',
        'click here', 'click now', 'click below', 'click to',
        'urgent', 'warning',
        'you have been selected', 'you are a winner', 'you won',
        'congratulations', 'winner', 'winning', 'prize', 'lottery',
        'earn money', 'earn extra', 'extra income', 'make money',
        'work from home', 'be your own boss', 'financial freedom',
        'guaranteed', 'guarantee', '100% guaranteed', 'risk free',
        'no risk', 'risk-free', 'satisfaction guaranteed',
        'million dollars', 'billion', 'investment opportunity',
        'special promotion', 'exclusive offer', 'best price',
        'lowest price', 'cheapest', 'save big', 'save money',
        'cash', 'cash bonus', 'extra cash', 'fast cash',
        'weight loss', 'lose weight', 'diet', 'miracle',
        'casino', 'poker', 'gambling', 'jackpot', 'lottery',
        'viagra', 'cialis', 'pharmacy', 'prescription',
        'dear friend', 'dear customer', 'dear subscriber',
        'this is not spam', 'not junk', 'remove me',

        // ── ALLEMAND ────────────────────────────────────────────
        'kostenlos', 'gratis', 'geschenk', 'sonderangebot',
        'sofort', 'dringend', 'jetzt kaufen', 'jetzt klicken',
        'hier klicken', 'geld verdienen', 'reich werden',
        'garantiert', 'ohne risiko', 'gewinner', 'gewonnen',
        'glückwunsch', 'glückwünsche',

        // ── ESPAGNOL ────────────────────────────────────────────
        'gratis', 'gratuito', 'oferta especial', 'oferta limitada',
        'haga clic', 'haga clic aquí', 'gane dinero',
        'garantizado', 'sin riesgo', 'ganador', 'ha ganado',
        'felicitaciones', 'urgente',

        // ── ITALIEN ─────────────────────────────────────────────
        'gratuito', 'gratis', 'offerta speciale', 'clicca qui',
        'guadagna', 'garantito', 'vincitore', 'ha vinto',
        'congratulazioni', 'urgente',

        // ── PORTUGAIS ───────────────────────────────────────────
        'gratuito', 'grátis', 'oferta especial', 'clique aqui',
        'ganhe dinheiro', 'garantido', 'vencedor', 'parabéns',
        'urgente',
    ];

    /**
     * Mots dans le SUJET particulièrement dangereux — les filtres scrutent
     * le sujet en priorité.
     *
     * @var array
     */
    private array $subjectSpamTriggers = [
        // FR
        'gratuit', 'urgent', 'gagner', 'gagnez', 'offre',
        'promo', 'réduction', 'soldes', 'cash', 'argent',
        'félicitations', 'vous avez gagné', 'dernier',
        // EN
        'free', 'urgent', 'win', 'winner', 'discount',
        'offer', 'sale', 'cash', 'money', 'congratulations',
        'you won', 'limited', 'act now', 'click',
        // DE
        'kostenlos', 'dringend', 'gewinner', 'angebot',
        // ES/IT/PT
        'gratis', 'urgente', 'oferta', 'gratuito',
    ];

    /**
     * Patterns techniques détectés par les filtres.
     *
     * @var array
     */
    /**
     * Patterns suspects analysés sur le TEXTE VISIBLE (ce que lit réellement le
     * destinataire) — évite les faux positifs dus au CSS/markup.
     *
     * @var array
     */
    private array $textPatterns = [
        'excessive_caps'    => '/[A-ZÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖÙÚÛÜÝ]{6,}/',
        'excessive_exclaim' => '/!{3,}/',
        'excessive_dollar'  => '/\${2,}/',
    ];

    /**
     * Patterns suspects analysés sur le HTML BRUT (techniques de masquage CSS,
     * raccourcisseurs d'URL). Le lookbehind sur hidden_text évite de confondre
     * « color:#fff » (texte) avec « background-color:#fff » (fond légitime).
     *
     * @var array
     */
    private array $htmlPatterns = [
        'tiny_font'     => '/font-size\s*:\s*[01]px/i',
        'url_shortener' => '/(?:bit\.ly|tinyurl|goo\.gl|t\.co|ow\.ly|buff\.ly)/i',
    ];

    /**
     * Point d'entrée principal : analyse un email et retourne le score.
     *
     * @param string $htmlContent HTML complet de l'email rendu
     * @param string $subject     Sujet de l'email
     * @param string $lang        Code langue (contexte)
     * @return array {score:int, grade:string, color:string, label:string,
     *               criteria:array, recommendations:array}
     */
    public function score(string $htmlContent, string $subject, string $lang = 'fr'): array
    {
        $score    = 100;
        $criteria = [];
        $recs     = [];

        // ── Critère 1 : longueur du sujet (−20 max) ──────────────
        $subjectLen = mb_strlen(trim($subject));
        if ($subjectLen === 0) {
            $score -= 20;
            $criteria[] = $this->criterion('error', 'Sujet', 'Sujet vide', -20);
            $recs[]     = ['type' => 'error', 'message' => 'Le sujet est vide — un email sans sujet est automatiquement classé spam.'];
        } elseif ($subjectLen < 20) {
            $score -= 10;
            $criteria[] = $this->criterion('warning', 'Sujet', "Trop court ({$subjectLen} car.)", -10);
            $recs[]     = ['type' => 'warning', 'message' => "Sujet trop court ({$subjectLen} caractères). Idéal : 35-50 caractères."];
        } elseif ($subjectLen <= 50) {
            $criteria[] = $this->criterion('success', 'Sujet', "Longueur optimale ({$subjectLen} car.)", 0);
        } elseif ($subjectLen <= 70) {
            $score -= 5;
            $criteria[] = $this->criterion('warning', 'Sujet', "Un peu long ({$subjectLen} car.)", -5);
            $recs[]     = ['type' => 'warning', 'message' => "Sujet un peu long ({$subjectLen} caractères). Certains clients email tronquent au-delà de 50 caractères."];
        } else {
            $score -= 15;
            $criteria[] = $this->criterion('error', 'Sujet', "Trop long ({$subjectLen} car.)", -15);
            $recs[]     = ['type' => 'error', 'message' => "Sujet trop long ({$subjectLen} caractères). Il sera tronqué dans la majorité des clients email."];
        }

        // ── Critère 2 : mots spam dans le sujet (−8/mot, −24 max) ─
        $subjectLower     = mb_strtolower($subject);
        $subjectSpamFound = [];
        foreach ($this->subjectSpamTriggers as $trigger) {
            if (str_contains($subjectLower, mb_strtolower($trigger))) {
                $subjectSpamFound[] = $trigger;
            }
        }
        $subjectSpamFound = array_values(array_unique($subjectSpamFound));
        if ($subjectSpamFound) {
            $penalty = min(24, count($subjectSpamFound) * 8);
            $score  -= $penalty;
            $criteria[] = $this->criterion('error', 'Mots spam (sujet)', implode(', ', $subjectSpamFound), -$penalty);
            $recs[]     = ['type' => 'error', 'message' => 'Mots à risque dans le sujet : "' . implode('", "', $subjectSpamFound) . '". Le sujet est analysé en priorité par les filtres anti-spam.'];
        } else {
            $criteria[] = $this->criterion('success', 'Mots spam (sujet)', 'Aucun mot à risque', 0);
        }

        // ── Critère 3 : ratio texte/HTML (−20 max) ───────────────
        // Texte VISIBLE (sans CSS/JS) rapporté au poids HTML total.
        $visible = $this->visibleText($htmlContent);
        $textLen = mb_strlen(trim($visible));
        $htmlLen = strlen($htmlContent);
        $ratio       = $htmlLen > 0 ? round(($textLen / $htmlLen) * 100, 1) : 0;

        // Seuils calibrés pour des emails HTML soignés (markup + styles inline
        // légitimes) : on ne pénalise vraiment qu'un ratio anormalement bas.
        if ($ratio < 8) {
            $score -= 15;
            $criteria[] = $this->criterion('error', 'Ratio texte/HTML', "{$ratio}%", -15);
            $recs[]     = ['type' => 'error', 'message' => "Ratio texte/HTML très faible ({$ratio}%). Les filtres anti-spam suspectent les emails quasi-entièrement graphiques. Ajoutez plus de texte."];
        } elseif ($ratio < 15) {
            $score -= 8;
            $criteria[] = $this->criterion('warning', 'Ratio texte/HTML', "{$ratio}%", -8);
            $recs[]     = ['type' => 'warning', 'message' => "Ratio texte/HTML faible ({$ratio}%). Enrichissez le contenu textuel ou allégez le HTML."];
        } elseif ($ratio < 25) {
            $score -= 3;
            $criteria[] = $this->criterion('info', 'Ratio texte/HTML', "{$ratio}%", -3);
            $recs[]     = ['type' => 'info', 'message' => "Ratio texte/HTML correct ({$ratio}%). Au-delà de 25 % serait idéal, mais ce niveau reste acceptable pour un email HTML soigné."];
        } else {
            $criteria[] = $this->criterion('success', 'Ratio texte/HTML', "{$ratio}% — excellent", 0);
        }

        // ── Critère 4 : mots spam dans le corps (−5/mot, −20 max) ─
        $bodyText      = mb_strtolower($visible);
        $bodySpamFound = [];
        foreach ($this->spamTriggers as $trigger) {
            if (mb_strlen($trigger) >= 4 && str_contains($bodyText, mb_strtolower($trigger))) {
                $bodySpamFound[] = $trigger;
            }
        }
        $bodySpamFound = array_values(array_unique($bodySpamFound));

        if ($bodySpamFound) {
            $penalty = min(20, count($bodySpamFound) * 5);
            $score  -= $penalty;
            $criteria[] = $this->criterion('warning', 'Mots spam (corps)', count($bodySpamFound) . ' trouvé(s)', -$penalty);
            $recs[]     = [
                'type'    => 'warning',
                'message' => 'Expressions à risque dans le corps : "' . implode('", "', array_slice($bodySpamFound, 0, 8)) . '"'
                    . (count($bodySpamFound) > 8 ? ' (et ' . (count($bodySpamFound) - 8) . ' autres)' : '') . '.',
            ];
        } else {
            $criteria[] = $this->criterion('success', 'Mots spam (corps)', 'Aucun mot à risque', 0);
        }

        // ── Critère 5 : lien de désabonnement (−15) ──────────────
        $hasUnsubscribe = str_contains($htmlContent, 'unsubscribe')
            || str_contains($htmlContent, 'désabonnement')
            || str_contains($htmlContent, 'désinscrire')
            || str_contains($htmlContent, 'se désinscrire')
            || str_contains($htmlContent, 'abmelden')
            || str_contains($htmlContent, 'darse de baja')
            || str_contains($htmlContent, 'cancelar suscripción')
            || str_contains($htmlContent, 'annullare iscrizione')
            || str_contains($htmlContent, 'cancelar inscrição')
            || str_contains($htmlContent, 'List-Unsubscribe');

        if (!$hasUnsubscribe) {
            $score -= 15;
            $criteria[] = $this->criterion('error', 'Désabonnement', 'Absent', -15);
            $recs[]     = ['type' => 'error', 'message' => 'Aucun lien de désabonnement détecté. Requis par la loi (RGPD, CAN-SPAM, CASL) pour les emails marketing. Les filtres Gmail et Outlook pénalisent fortement son absence.'];
        } else {
            $criteria[] = $this->criterion('success', 'Désabonnement', 'Présent', 0);
        }

        // ── Critère 6 : poids de l'email (−15 max) ───────────────
        $sizeKb = round(strlen($htmlContent) / 1024, 1);
        if ($sizeKb > 200) {
            $score -= 15;
            $criteria[] = $this->criterion('error', 'Poids', "{$sizeKb} ko", -15);
            $recs[]     = ['type' => 'error', 'message' => "Email très lourd ({$sizeKb} ko). Gmail tronque les emails dépassant 102 ko, Outlook peut les bloquer. Idéal : moins de 100 ko."];
        } elseif ($sizeKb > 100) {
            $score -= 8;
            $criteria[] = $this->criterion('warning', 'Poids', "{$sizeKb} ko", -8);
            $recs[]     = ['type' => 'warning', 'message' => "Email lourd ({$sizeKb} ko). Gmail tronque au-delà de 102 ko. Réduisez le HTML ou les images embarquées."];
        } elseif ($sizeKb > 60) {
            $score -= 3;
            $criteria[] = $this->criterion('info', 'Poids', "{$sizeKb} ko", -3);
            $recs[]     = ['type' => 'info', 'message' => "Poids acceptable ({$sizeKb} ko). En dessous de 60 ko serait optimal."];
        } else {
            $criteria[] = $this->criterion('success', 'Poids', "{$sizeKb} ko — optimal", 0);
        }

        // ── Critère 7 : patterns techniques suspects (−5/pattern, −15 max) ─
        $technicalIssues = [];
        foreach ($this->textPatterns as $key => $pattern) {
            if (preg_match($pattern, $visible)) {
                $technicalIssues[] = $key;
            }
        }
        foreach ($this->htmlPatterns as $key => $pattern) {
            if (preg_match($pattern, $htmlContent)) {
                $technicalIssues[] = $key;
            }
        }
        // Texte masqué : vraie technique de masquage (texte blanc SANS fond dans
        // le même élément) — distingue du texte blanc légitime sur bouton coloré.
        if ($this->hasHiddenWhiteText($htmlContent)) {
            $technicalIssues[] = 'hidden_text';
        }
        if ($technicalIssues) {
            $penalty = min(15, count($technicalIssues) * 5);
            $score  -= $penalty;
            $labels  = [
                'excessive_caps'    => 'Texte en MAJUSCULES excessif',
                'excessive_exclaim' => 'Points d\'exclamation répétés (!!)',
                'excessive_dollar'  => 'Symboles $ répétés ($$)',
                'hidden_text'       => 'Texte potentiellement masqué (couleur blanche)',
                'tiny_font'         => 'Police invisible (0-1px)',
                'url_shortener'     => 'URL raccourcie (bit.ly, tinyurl...)',
            ];
            $issueLabels = array_map(fn ($k) => $labels[$k] ?? $k, $technicalIssues);
            $criteria[]  = $this->criterion('error', 'Patterns suspects', implode(', ', $issueLabels), -$penalty);
            foreach ($issueLabels as $label) {
                $recs[] = ['type' => 'error', 'message' => "Pattern suspect détecté : {$label}. Ce type de contenu peut déclencher les filtres anti-spam."];
            }
        } else {
            $criteria[] = $this->criterion('success', 'Patterns suspects', 'Aucun pattern détecté', 0);
        }

        // ── Critère 8 : domaine de la boutique présent (−5) ──────
        $shopDomain = (string) Configuration::get('PS_SHOP_DOMAIN');
        if ($shopDomain !== '' && !str_contains($htmlContent, $shopDomain)) {
            $score -= 5;
            $criteria[] = $this->criterion('warning', 'Domaine boutique', 'Absent des liens', -5);
            $recs[]     = ['type' => 'info', 'message' => "Le domaine de la boutique ({$shopDomain}) n'apparaît dans aucun lien. Les filtres préfèrent les emails dont les liens pointent vers le domaine expéditeur."];
        } else {
            $criteria[] = $this->criterion('success', 'Domaine boutique', 'Présent', 0);
        }

        // ── Score final ──────────────────────────────────────────
        $score = (int) max(0, min(100, $score));

        if (empty($recs)) {
            $recs[] = ['type' => 'success', 'message' => 'Excellent — aucun problème détecté. Cet email est optimisé pour la délivrabilité.'];
        }

        return [
            'score'           => $score,
            'grade'           => $this->getGrade($score),
            'color'           => $this->getColor($score),
            'label'           => $this->getLabel($score),
            'criteria'        => $criteria,
            'recommendations' => $recs,
        ];
    }

    /**
     * @param string $type   success|warning|error|info
     * @param string $name
     * @param string $detail
     * @param int    $penalty
     * @return array
     */
    /**
     * Extrait le texte réellement visible par le destinataire : retire le
     * contenu des <style>/<script> (sinon le CSS/JS pollue l'analyse), puis les
     * balises, et décode les entités HTML.
     *
     * @param string $html
     * @return string
     */
    private function visibleText(string $html): string
    {
        $clean = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', ' ', $html);
        return html_entity_decode(strip_tags((string) $clean), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Détecte la vraie technique de masquage par couleur : un texte blanc
     * (color:#fff) dont l'élément ne déclare AUCUN fond dans le même attribut
     * style. Le texte blanc sur un bouton/encart à fond coloré (légitime) n'est
     * donc pas signalé.
     *
     * @param string $html
     * @return bool
     */
    private function hasHiddenWhiteText(string $html): bool
    {
        if (!preg_match_all('/style\s*=\s*"([^"]*)"/i', $html, $m)) {
            return false;
        }
        foreach ($m[1] as $style) {
            $s = strtolower($style);
            if (preg_match('/(?<![-\w])color\s*:\s*#(?:fff|ffffff)\b/', $s)
                && !str_contains($s, 'background')) {
                return true;
            }
        }
        return false;
    }

    private function criterion(string $type, string $name, string $detail, int $penalty): array
    {
        return compact('type', 'name', 'detail', 'penalty');
    }

    private function getGrade(int $score): string
    {
        if ($score >= 90) {
            return 'A';
        }
        if ($score >= 75) {
            return 'B';
        }
        if ($score >= 60) {
            return 'C';
        }
        if ($score >= 40) {
            return 'D';
        }
        return 'F';
    }

    private function getColor(int $score): string
    {
        if ($score >= 90) {
            return '#1a7a40';
        }
        if ($score >= 75) {
            return '#b38b59';
        }
        if ($score >= 60) {
            return '#a0520d';
        }
        if ($score >= 40) {
            return '#c0392b';
        }
        return '#8b0000';
    }

    private function getLabel(int $score): string
    {
        if ($score >= 90) {
            return 'Excellent';
        }
        if ($score >= 75) {
            return 'Bon';
        }
        if ($score >= 60) {
            return 'Acceptable';
        }
        if ($score >= 40) {
            return 'Risqué';
        }
        return 'Critique';
    }
}
