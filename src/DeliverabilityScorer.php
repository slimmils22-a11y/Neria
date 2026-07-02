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

        // ── FRANÇAIS — Gratuité ──────────────────────────────────
        'gratuit', 'gratuite', 'gratuitement', '100% gratuit',
        'accès gratuit', 'échantillon gratuit',
        'offert', 'offerte', 'cadeau gratuit', 'essai gratuit',
        'livraison gratuite', 'sans frais', 'sans engagement', 'sans risque',
        'bonus',

        // ── FRANÇAIS — Gain / Loterie ────────────────────────────
        'gagner', 'gagnez', 'gagnez de l\'argent', 'vous avez gagné', 'gagnant',
        'prix gagné', 'héritage',
        'félicitations', 'félicitation', 'vous êtes sélectionné',
        'vous avez été choisi', 'tirage au sort', 'tirage', 'lot', 'loterie', 'lotterie',
        'casino', 'casino en ligne', 'pari', 'poker', 'jetons',
        'cadeau', 'cadeaux', 'surprise',

        // ── FRANÇAIS — Remise / Prix ─────────────────────────────
        'promo', 'promotion', 'code promo', 'bon de réduction', 'bon plan',
        'coupon', 'voucher', 'réduction', 'remise', 'rabais',
        'solde', 'soldes', 'soldé', 'soldée', 'soldés', 'solder',
        'déstockage', 'liquidation', 'démarque', 'invendu', 'invendus',
        'bradé', 'bradée', 'bradés', 'brader',
        'sacrifié', 'sacrifiée', 'sacrifiés', 'prix sacrifié', 'prix sacrifiés',
        'prix choc', 'prix cassé', 'prix imbattable', 'prix défiant',
        'meilleur prix', 'plus bas prix', 'prix plancher',
        'économisez', 'économies', 'moins cher', 'pas cher', 'pas chers',
        'offre spéciale', 'offre exclusive', 'offre limitée',
        'offre exceptionnelle', 'offre unique', 'offre incroyable',
        'bonne affaire',

        // ── FRANÇAIS — Urgence / Rareté ──────────────────────────
        'urgent', 'urgence', 'vite', 'maintenant', 'tout de suite',
        'hâtez-vous', 'dépêchez', 'dépêchez-vous', 'pressez-vous',
        'dernière chance', 'dernier jour', 'derniers jours',
        'dernière opportunité', 'ne ratez pas', 'ne manquez pas',
        'ne perdez pas', 'saisissez', 'profitez-en', 'profitez aujourd\'hui',
        'aujourd\'hui seulement', 'seulement aujourd\'hui',
        'disponible maintenant', 'expirant bientôt',
        'limité dans le temps', 'offre limitée',
        'cliquez ici', 'cliquez maintenant', 'commandez maintenant',
        'achetez maintenant', 'agissez maintenant', 'inscrivez-vous maintenant',
        'plus que', 'stock limité', 'bientôt épuisé', 'presque épuisé',
        'while supplies last',
        'expire', 'expiré', 'expiration', 'va expirer', 'se termine',
        'temps limité', 'durée limitée', 'offre valable',
        'plus disponible', 'en rupture',
        'rareté', 'rarissime', 'pièce unique', 'édition limitée',
        'quantité limitée', 'exemplaires restants',

        // ── FRANÇAIS — Argent ────────────────────────────────────
        'argent', 'argent facile', 'cash', 'revenus', 'revenu passif',
        'gain', 'gains', 'gains garantis', 'rendement garanti',
        'investissement', 'investir', 'rendement', 'rentable', 'rentabilité',
        'retour garanti', 'devenez riche', 'doublez vos revenus', 'richesse',
        'bitcoin', 'cryptomonnaie', 'crypto',
        'pour cent', 'pourcent', 'pas de frais', '100% satisfaction',

        // ── FRANÇAIS — Garantie / Exagération ───────────────────
        'garantie', '100% garanti', 'satisfait ou remboursé',
        '100% satisfait', 'résultats garantis', 'certifié',
        'incroyable', 'incroyables', 'exceptionnel', 'exceptionnelle',
        'extraordinaire', 'fantastique', 'spectaculaire', 'révolutionnaire',
        'exclusif', 'exclusive', 'hallucinant', 'miracle', '100%',
        'ultra efficace', 'bonne affaire', 'travail à domicile',

        // ── FRANÇAIS — Santé / Médical ───────────────────────────
        'médicament', 'pilule', 'traitement', 'maigrir', 'perdre du poids',
        'perte de poids', 'guérir', 'viagra', 'cialis', 'levitra',

        // ── ANGLAIS — Free / No cost ─────────────────────────────
        'free', 'free gift', 'free offer', 'free trial',
        'free access', 'free money', 'absolutely free',
        'no cost', 'no fees', 'no charge', 'no credit card',
        'risk-free', 'risk free', 'no risk', 'money-back',

        // ── ANGLAIS — Win / Congratulations ─────────────────────
        'win', 'winner', 'winning', 'you won', 'you\'ve won',
        'congratulations', 'prize', 'lottery', 'jackpot',
        'selected', 'you\'ve been selected', 'chosen',

        // ── ANGLAIS — Discount / Price ───────────────────────────
        'discount', 'big discount', 'huge discount',
        'special discount', 'off', '% off',
        'sale', 'clearance', 'blowout', 'markdown',
        'best price', 'lowest price', 'unbeatable',
        'save big', 'save now', 'huge savings',
        'coupon', 'promo code', 'voucher', 'discount code',
        'special offer', 'exclusive offer', 'limited offer',

        // ── ANGLAIS — Urgency / Scarcity ────────────────────────
        'urgent', 'act now', 'act immediately', 'act fast',
        'hurry', 'hurry up', 'hurry before',
        'last chance', 'final notice', 'final day', 'ending soon',
        'expires', 'expiring', 'expiring soon',
        'don\'t miss', 'don\'t miss out', 'don\'t wait',
        'today only', 'this week only', 'one day only', 'limited time',
        'limited stock', 'running out', 'almost gone',
        'click here', 'click now', 'buy now', 'order now', 'shop now',

        // ── ANGLAIS — Money ──────────────────────────────────────
        'cash', 'money', 'earn money', 'make money', 'earn extra cash',
        'extra cash', 'fast cash', 'passive income', 'additional income',
        'investment opportunity', 'guaranteed income',
        'get rich quick', 'big bucks', 'cheap',
        'bitcoin', 'crypto', 'billion', 'million dollars',

        // ── ANGLAIS — Guarantee ──────────────────────────────────
        'guaranteed', '100% guaranteed', 'satisfaction guaranteed',
        'no obligation', 'this is not spam', 'as seen on',
        'be your own boss', 'all natural',
        '50% off', '$$$', '€€€',

        // ── ALLEMAND ─────────────────────────────────────────────
        'kostenlos', 'gratis', 'umsonst', 'kostenfrei',
        'dringend', 'sofort', 'jetzt', 'nur heute',
        'gewinner', 'gewonnen', 'glückwunsch', 'herzlichen glückwunsch',
        'angebot', 'sonderangebot', 'exklusives angebot',
        'rabatt', 'ermäßigung', 'aktion', 'sparpreis',
        'beeilen', 'letzte chance', 'läuft ab', 'endet bald',
        'jetzt kaufen', 'hier klicken', 'garantiert',

        // ── ESPAGNOL ─────────────────────────────────────────────
        'gratis', 'gratuito', 'sin costo', 'sin cargo',
        'urgente', 'ahora', 'actúe ahora', 'acción inmediata',
        'ganador', 'usted ganó', 'felicitaciones',
        'oferta', 'oferta especial', 'oferta limitada',
        'descuento', 'rebaja', 'precio especial',
        'cupón', 'código promocional',
        'última oportunidad', 'no se pierda', 'date prisa',
        'compre ahora', 'haga clic', 'garantizado',

        // ── ITALIEN ──────────────────────────────────────────────
        'gratuito', 'gratis', 'senza costi',
        'urgente', 'ora', 'agisci ora',
        'vincitore', 'hai vinto', 'congratulazioni',
        'offerta', 'offerta speciale', 'offerta limitata',
        'sconto', 'saldo', 'codice sconto', 'coupon',
        'ultima occasione', 'affrettati', 'clicca qui',
        'garantito',

        // ── PORTUGAIS ────────────────────────────────────────────
        'gratuito', 'grátis', 'sem custo',
        'urgente', 'agora', 'aja agora',
        'vencedor', 'você ganhou', 'parabéns',
        'oferta', 'oferta especial', 'oferta limitada',
        'desconto', 'promoção', 'código promocional', 'cupom',
        'última chance', 'não perca', 'compre agora',
        'clique aqui', 'garantido',

        // ── NÉERLANDAIS ──────────────────────────────────────────
        'gratis', 'kosteloos', 'aanbieding', 'korting', 'actie',
        'winnaar', 'gewonnen', 'dringend', 'nu kopen', 'klik hier',
        'gegarandeerd', 'exclusief', 'beperkt', 'laatste kans',
        'profiteer nu', 'alleen vandaag', 'bijna uitverkocht',

        // ── POLONAIS ─────────────────────────────────────────────
        'bezpłatny', 'darmowy', 'za darmo', 'oferta', 'rabat', 'zniżka',
        'pilne', 'wygrana', 'wygrałeś', 'kup teraz', 'kliknij tutaj',
        'gwarantowany', 'ekskluzywny', 'ograniczony', 'ostatnia szansa',
        'tylko dziś', 'nie przegap',

        // ── SUÉDOIS ──────────────────────────────────────────────
        'gratis', 'kostnadsfri', 'erbjudande', 'rabatt', 'rea',
        'vinnare', 'vann', 'brådskande', 'köp nu', 'klicka här',
        'garanterad', 'exklusiv', 'begränsad', 'sista chansen',
        'bara idag', 'missa inte',

        // ── DANOIS ───────────────────────────────────────────────
        'gratis', 'tilbud', 'rabat', 'udsalg', 'vinder', 'vandt',
        'haster', 'køb nu', 'klik her', 'garanteret', 'eksklusiv',
        'begrænset', 'sidste chance', 'kun i dag', 'gå ikke glip af',

        // ── FINNOIS ──────────────────────────────────────────────
        'ilmainen', 'tarjous', 'alennus', 'alennusmyynti',
        'voittaja', 'voitit', 'kiireellinen', 'osta nyt',
        'napsauta tästä', 'taattu', 'eksklusiivinen',
        'rajoitettu', 'viimeinen mahdollisuus', 'vain tänään',

        // ── NORVÉGIEN ────────────────────────────────────────────
        'gratis', 'tilbud', 'rabatt', 'salg', 'vinner', 'vant',
        'haster', 'kjøp nå', 'klikk her', 'garantert', 'eksklusiv',
        'begrenset', 'siste sjanse', 'bare i dag', 'ikke gå glipp av',

        // ── TURC ─────────────────────────────────────────────────
        'ücretsiz', 'bedava', 'teklif', 'indirim', 'kampanya',
        'kazanan', 'kazandınız', 'acil', 'şimdi satın al',
        'buraya tıkla', 'garantili', 'özel', 'sınırlı',
        'son şans', 'sadece bugün', 'kaçırmayın',

        // ── TCHÈQUE ──────────────────────────────────────────────
        'zdarma', 'bezplatný', 'nabídka', 'sleva', 'výprodej',
        'výherce', 'vyhráli jste', 'urgentní', 'kupte nyní',
        'klikněte zde', 'zaručený', 'exkluzivní', 'omezený',
        'poslední šance', 'jen dnes', 'nenechte si ujít',

        // ── HONGROIS ─────────────────────────────────────────────
        'ingyenes', 'ajánlat', 'kedvezmény', 'akció', 'nyertes',
        'nyert', 'sürgős', 'vásároljon most', 'kattintson ide',
        'garantált', 'exkluzív', 'korlátozott', 'utolsó esély',
        'csak ma', 'ne hagyja ki',

        // ── ROUMAIN ──────────────────────────────────────────────
        'gratuit', 'ofertă', 'reducere', 'promoție', 'câștigător',
        'câștigat', 'urgent', 'cumpărați acum', 'faceți clic',
        'garantat', 'exclusiv', 'limitat', 'ultima șansă',
        'doar azi', 'nu ratați',

        // ── RUSSE ────────────────────────────────────────────────
        'бесплатно', 'акция', 'скидка', 'предложение', 'выиграли',
        'победитель', 'срочно', 'купить сейчас', 'нажмите здесь',
        'гарантировано', 'эксклюзивно', 'ограничено',
        'последний шанс', 'только сегодня', 'не упустите',

        // ── ARABE ────────────────────────────────────────────────
        'مجاني', 'عرض', 'خصم', 'تخفيض', 'فوز', 'فائز', 'عاجل',
        'اشتر الآن', 'انقر هنا', 'مضمون', 'حصري', 'محدود',
        'آخر فرصة', 'اليوم فقط', 'لا تفوت',

        // ── CHINOIS SIMPLIFIÉ ────────────────────────────────────
        '免费', '优惠', '折扣', '促销', '赢了', '获奖', '紧急',
        '立即购买', '点击这里', '保证', '独家', '限量', '限时',
        '最后机会', '仅限今天', '不要错过',

        // ── JAPONAIS ─────────────────────────────────────────────
        '無料', '特典', '割引', 'プロモーション', '当選', '急ぎ',
        '今すぐ購入', 'こちらをクリック', '保証', '限定',
        '最後のチャンス', '期間限定', '今日だけ', '見逃すな',

        // ── CORÉEN ───────────────────────────────────────────────
        '무료', '혜택', '할인', '프로모션', '당첨', '긴급',
        '지금 구매', '여기를 클릭', '보장', '독점', '한정',
        '마지막 기회', '오늘만', '놓치지 마세요',
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

    /** Cache DNS par domaine (évite plusieurs lookups pour un même score) */
    private static ?array $dnsCache = null;

    /**
     * Point d'entrée principal : analyse un email et retourne le score.
     *
     * @param string $htmlContent HTML complet de l'email rendu
     * @param string $subject     Sujet de l'email
     * @param string $lang        Code langue (contexte)
     * @return array {score:int, grade:string, color:string, label:string,
     *               criteria:array, recommendations:array}
     */
    public function getSubjectSpamTriggers(): array
    {
        return $this->subjectSpamTriggers;
    }

    public function score(string $htmlContent, string $subject, string $lang = 'fr'): array
    {
        $score    = 100;
        $criteria = [];
        $recs     = [];

        // ── Critère 1 : longueur du sujet (−20 max) ──────────────
        $subjectLen = mb_strlen(trim($subject));
        $cSubject   = $this->t('score.criterion_subject');
        if ($subjectLen === 0) {
            $score -= 20;
            $criteria[] = $this->criterion('error', $cSubject, $this->t('score.detail_empty'), -20);
            $recs[]     = ['type' => 'error', 'message' => $this->t('score.rec_empty_subject')];
        } elseif ($subjectLen < 20) {
            $score -= 10;
            $criteria[] = $this->criterion('warning', $cSubject, $this->t('score.detail_too_short', ['n' => $subjectLen]), -10);
            $recs[]     = ['type' => 'warning', 'message' => $this->t('score.rec_too_short', ['n' => $subjectLen])];
        } elseif ($subjectLen <= 50) {
            $criteria[] = $this->criterion('success', $cSubject, $this->t('score.detail_optimal', ['n' => $subjectLen]), 0);
        } elseif ($subjectLen <= 70) {
            $score -= 5;
            $criteria[] = $this->criterion('warning', $cSubject, $this->t('score.detail_slightly_long', ['n' => $subjectLen]), -5);
            $recs[]     = ['type' => 'warning', 'message' => $this->t('score.rec_slightly_long', ['n' => $subjectLen])];
        } else {
            $score -= 15;
            $criteria[] = $this->criterion('error', $cSubject, $this->t('score.detail_too_long', ['n' => $subjectLen]), -15);
            $recs[]     = ['type' => 'error', 'message' => $this->t('score.rec_too_long', ['n' => $subjectLen])];
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
            $criteria[] = $this->criterion('error', $this->t('score.criterion_spam_subject'), implode(', ', $subjectSpamFound), -$penalty);
            $recs[]     = ['type' => 'error', 'message' => $this->t('score.rec_spam_subject', ['words' => implode('", "', $subjectSpamFound)])];
        } else {
            $criteria[] = $this->criterion('success', $this->t('score.criterion_spam_subject'), $this->t('score.detail_no_spam'), 0);
        }

        // ── Critère 3 : ratio texte/HTML (−20 max) ───────────────
        // Texte VISIBLE (sans CSS/JS) rapporté au poids HTML total.
        // strlen() (octets) des deux côtés — pas mb_strlen() pour le texte :
        // mélanger caractères (numérateur) et octets (dénominateur) fausse le
        // ratio pour les langues non-latines (japonais, arabe, chinois, coréen,
        // russe — 6 des 18 langues du module), où chaque caractère visible
        // pèse 2-3 octets UTF-8 alors que le balisage HTML reste en ASCII.
        $visible = $this->visibleText($htmlContent);
        $textLen = strlen(trim($visible));
        $htmlLen = strlen($htmlContent);
        $ratio       = $htmlLen > 0 ? round(($textLen / $htmlLen) * 100, 1) : 0;

        $cRatio = $this->t('score.criterion_ratio');
        // Seuils calibrés pour des emails HTML soignés (markup + styles inline
        // légitimes) : on ne pénalise vraiment qu'un ratio anormalement bas.
        if ($ratio < 8) {
            $score -= 15;
            $criteria[] = $this->criterion('error', $cRatio, $this->t('score.detail_ratio', ['n' => $ratio]), -15);
            $recs[]     = ['type' => 'error', 'message' => $this->t('score.rec_ratio_very_low', ['n' => $ratio])];
        } elseif ($ratio < 15) {
            $score -= 8;
            $criteria[] = $this->criterion('warning', $cRatio, $this->t('score.detail_ratio', ['n' => $ratio]), -8);
            $recs[]     = ['type' => 'warning', 'message' => $this->t('score.rec_ratio_low', ['n' => $ratio])];
        } elseif ($ratio < 25) {
            $score -= 3;
            $criteria[] = $this->criterion('info', $cRatio, $this->t('score.detail_ratio', ['n' => $ratio]), -3);
            $recs[]     = ['type' => 'info', 'message' => $this->t('score.rec_ratio_ok', ['n' => $ratio])];
        } else {
            $criteria[] = $this->criterion('success', $cRatio, $this->t('score.detail_ratio_excellent', ['n' => $ratio]), 0);
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
            $criteria[] = $this->criterion('warning', $this->t('score.criterion_spam_body'), $this->t('score.detail_spam_found', ['n' => count($bodySpamFound)]), -$penalty);
            $more = count($bodySpamFound) > 8
                ? $this->t('score.rec_spam_body_more', ['n' => count($bodySpamFound) - 8])
                : '';
            $recs[] = [
                'type'    => 'warning',
                'message' => $this->t('score.rec_spam_body', [
                    'words' => implode('", "', array_slice($bodySpamFound, 0, 8)),
                    'more'  => $more,
                ]),
            ];
        } else {
            $criteria[] = $this->criterion('success', $this->t('score.criterion_spam_body'), $this->t('score.detail_no_spam'), 0);
        }

        // ── Critère 5 : lien de désabonnement (−15) ──────────────
        $hasUnsubscribe = str_contains($htmlContent, 'unsubscribe')
            || str_contains($htmlContent, 'désabonnement')
            || str_contains($htmlContent, 'désabonner')
            || str_contains($htmlContent, 'désinscrire')
            || str_contains($htmlContent, 'se désinscrire')
            || str_contains($htmlContent, 'abmelden')
            || str_contains($htmlContent, 'darse de baja')
            || str_contains($htmlContent, 'cancelar suscripción')
            || str_contains($htmlContent, 'annullare iscrizione')
            || str_contains($htmlContent, 'cancelar inscrição')
            || str_contains($htmlContent, 'List-Unsubscribe');

        $cUnsub = $this->t('score.criterion_unsub');
        if (!$hasUnsubscribe) {
            $score -= 15;
            $criteria[] = $this->criterion('error', $cUnsub, $this->t('score.detail_absent'), -15);
            $recs[]     = ['type' => 'error', 'message' => $this->t('score.rec_no_unsub')];
        } else {
            $criteria[] = $this->criterion('success', $cUnsub, $this->t('score.detail_present'), 0);
        }

        // ── Critère 6 : poids de l'email (−15 max) ───────────────
        $sizeKb = round(strlen($htmlContent) / 1024, 1);
        $cWeight = $this->t('score.criterion_weight');
        if ($sizeKb > 200) {
            $score -= 15;
            $criteria[] = $this->criterion('error', $cWeight, $this->t('score.detail_weight', ['n' => $sizeKb]), -15);
            $recs[]     = ['type' => 'error', 'message' => $this->t('score.rec_weight_very_heavy', ['n' => $sizeKb])];
        } elseif ($sizeKb > 100) {
            $score -= 8;
            $criteria[] = $this->criterion('warning', $cWeight, $this->t('score.detail_weight', ['n' => $sizeKb]), -8);
            $recs[]     = ['type' => 'warning', 'message' => $this->t('score.rec_weight_heavy', ['n' => $sizeKb])];
        } elseif ($sizeKb > 60) {
            $score -= 3;
            $criteria[] = $this->criterion('info', $cWeight, $this->t('score.detail_weight', ['n' => $sizeKb]), -3);
            $recs[]     = ['type' => 'info', 'message' => $this->t('score.rec_weight_ok', ['n' => $sizeKb])];
        } else {
            $criteria[] = $this->criterion('success', $cWeight, $this->t('score.detail_weight_optimal', ['n' => $sizeKb]), 0);
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
                'excessive_caps'    => $this->t('score.pattern_excessive_caps'),
                'excessive_exclaim' => $this->t('score.pattern_excessive_exclaim'),
                'excessive_dollar'  => $this->t('score.pattern_excessive_dollar'),
                'hidden_text'       => $this->t('score.pattern_hidden_text'),
                'tiny_font'         => $this->t('score.pattern_tiny_font'),
                'url_shortener'     => $this->t('score.pattern_url_shortener'),
            ];
            $issueLabels = array_map(fn ($k) => $labels[$k] ?? $k, $technicalIssues);
            $criteria[]  = $this->criterion('error', $this->t('score.criterion_patterns'), implode(', ', $issueLabels), -$penalty);
            foreach ($issueLabels as $label) {
                $recs[] = ['type' => 'error', 'message' => $this->t('score.rec_pattern', ['label' => $label])];
            }
        } else {
            $criteria[] = $this->criterion('success', $this->t('score.criterion_patterns'), $this->t('score.detail_no_pattern'), 0);
        }

        // ── Critère 8 : domaine de la boutique présent (−5) ──────
        $shopDomain = (string) Configuration::get('PS_SHOP_DOMAIN');
        $cDomain = $this->t('score.criterion_domain');
        if ($shopDomain !== '' && !str_contains($htmlContent, $shopDomain)) {
            $score -= 5;
            $criteria[] = $this->criterion('warning', $cDomain, $this->t('score.detail_absent'), -5);
            $recs[]     = ['type' => 'info', 'message' => $this->t('score.rec_no_domain', ['domain' => $shopDomain])];
        } else {
            $criteria[] = $this->criterion('success', $cDomain, $this->t('score.detail_present'), 0);
        }

        // ── Critères DNS : SPF, DMARC, DKIM ─────────────────────
        $fromEmail = (string) Configuration::get('PS_MAIL_EMAIL_MESSAGE_FROM');
        if ($fromEmail === '' || !str_contains($fromEmail, '@')) {
            $fromEmail = (string) Configuration::get('PS_SHOP_EMAIL');
        }
        $sendingDomain = str_contains($fromEmail, '@')
            ? trim(explode('@', $fromEmail)[1])
            : '';

        if ($sendingDomain !== '') {
            $dns = $this->getDnsStatus($sendingDomain);

            if (!$dns['spf']) {
                $score -= 15;
                $criteria[] = $this->criterion('error', $this->t('score.criterion_spf'), $this->t('score.detail_not_configured'), -15);
                $recs[]     = ['type' => 'error', 'message' => $this->t('score.rec_no_spf', ['domain' => $sendingDomain])];
            } else {
                $criteria[] = $this->criterion('success', $this->t('score.criterion_spf'), $this->t('score.detail_configured'), 0);
            }

            if (!$dns['dmarc']) {
                $score -= 10;
                $criteria[] = $this->criterion('error', $this->t('score.criterion_dmarc'), $this->t('score.detail_not_configured'), -10);
                $recs[]     = ['type' => 'error', 'message' => $this->t('score.rec_no_dmarc', ['domain' => $sendingDomain])];
            } else {
                $criteria[] = $this->criterion('success', $this->t('score.criterion_dmarc'), $this->t('score.detail_configured'), 0);
            }

            if (!$dns['dkim']) {
                $score -= 10;
                $criteria[] = $this->criterion('warning', $this->t('score.criterion_dkim'), $this->t('score.detail_dkim_not_detected'), -10);
                $recs[]     = ['type' => 'warning', 'message' => $this->t('score.rec_no_dkim', ['domain' => $sendingDomain])];
            } else {
                $criteria[] = $this->criterion('success', $this->t('score.criterion_dkim'), $this->t('score.detail_configured'), 0);
            }
        }

        // ── Score final ──────────────────────────────────────────
        $score = (int) max(0, min(100, $score));

        if (empty($recs)) {
            $recs[] = ['type' => 'success', 'message' => $this->t('score.rec_perfect')];
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

    private function t(string $key, array $vars = []): string
    {
        $str = class_exists('AdminTranslator') ? AdminTranslator::t($key) : $key;
        foreach ($vars as $k => $v) {
            $str = str_replace('{' . $k . '}', (string) $v, $str);
        }
        return $str;
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

    private function getDnsStatus(string $domain): array
    {
        // Clé par domaine — un cache non clé renvoyait silencieusement les
        // résultats SPF/DKIM/DMARC du PREMIER domaine analysé pour tout appel
        // suivant sur un domaine différent (scénario multi-expéditeur par
        // langue avec des domaines d'envoi distincts).
        if (self::$dnsCache !== null && isset(self::$dnsCache[$domain])) {
            return self::$dnsCache[$domain];
        }

        $result = ['spf' => false, 'dmarc' => false, 'dkim' => false];

        try {
            // SPF : enregistrement TXT commençant par "v=spf1"
            $records = @dns_get_record($domain, DNS_TXT);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (isset($r['txt']) && str_starts_with(trim($r['txt']), 'v=spf1')) {
                        $result['spf'] = true;
                        break;
                    }
                }
            }

            // DMARC : TXT sur _dmarc.<domaine>
            $records = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (isset($r['txt']) && str_contains($r['txt'], 'v=DMARC1')) {
                        $result['dmarc'] = true;
                        break;
                    }
                }
            }

            // DKIM : sélecteurs courants (services d'envoi + génériques)
            $selectors = [
                'default', 'mail', 'google', 'k1', 'dkim', 'smtp', 'email',
                'selector1', 'selector2', 'mailjet', 'brevo', 'sendgrid', 's1', 's2',
            ];
            foreach ($selectors as $sel) {
                $records = @dns_get_record($sel . '._domainkey.' . $domain, DNS_TXT);
                if (is_array($records) && !empty($records)) {
                    foreach ($records as $r) {
                        if (isset($r['txt']) && str_contains($r['txt'], 'v=DKIM1')) {
                            $result['dkim'] = true;
                            break 2;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // DNS indisponible — on laisse les valeurs par défaut (false)
        }

        self::$dnsCache ??= [];
        self::$dnsCache[$domain] = $result;
        return $result;
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
            return $this->t('score.grade_excellent');
        }
        if ($score >= 75) {
            return $this->t('score.grade_good');
        }
        if ($score >= 60) {
            return $this->t('score.grade_acceptable');
        }
        if ($score >= 40) {
            return $this->t('score.grade_risky');
        }
        return $this->t('score.grade_critical');
    }
}
