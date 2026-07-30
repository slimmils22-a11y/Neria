const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, TableOfContents,
  PageBreak, AlignmentType, BorderStyle, Table, TableRow, TableCell,
  WidthType, LevelFormat, convertInchesToTwip, ImageRun, Header, Footer,
  PageNumber, NumberFormat
} = require("docx");
const fs = require("fs");
const path = require("path");

const ACCENT = "B38B59";
const DARK   = "2B2520";
const GREY   = "666666";
const LGREY  = "999999";

const logoData = fs.readFileSync(path.join(__dirname, '..', 'logo.png'));

// ─── HELPERS ────────────────────────────────────────────────────────────────

function h1(text) {
  return new Paragraph({ text, heading: HeadingLevel.HEADING_1, spacing: { before: 500, after: 200 }, pageBreakBefore: true });
}
function h2(text) {
  return new Paragraph({ text, heading: HeadingLevel.HEADING_2, spacing: { before: 320, after: 140 } });
}
function h3(text) {
  return new Paragraph({ text, heading: HeadingLevel.HEADING_3, spacing: { before: 220, after: 100 } });
}
function p(text, opts = {}) {
  return new Paragraph({ children: [new TextRun({ text, ...opts })], spacing: { after: 160 } });
}
function bullet(text, level = 0) {
  return new Paragraph({
    children: [new TextRun({ text })],
    numbering: { reference: "puces", level },
    spacing: { after: 80 },
  });
}
function step(n, text) {
  return new Paragraph({
    children: [new TextRun({ text: `${n}. `, bold: true, color: ACCENT }), new TextRun({ text })],
    spacing: { after: 120 },
    indent: { left: 200 },
  });
}
function note(text) {
  return new Paragraph({
    children: [new TextRun({ text: "ℹ️  Remarque — ", bold: true, color: ACCENT }), new TextRun({ text })],
    spacing: { before: 120, after: 180 },
    indent: { left: 360 },
  });
}
function warning(text) {
  return new Paragraph({
    children: [new TextRun({ text: "⚠️  Attention — ", bold: true, color: "CC4400" }), new TextRun({ text })],
    spacing: { before: 120, after: 180 },
    indent: { left: 360 },
  });
}
function capture(label) {
  return new Paragraph({
    children: [new TextRun({ text: `[ CAPTURE : ${label} ]`, italics: true, color: "888888", size: 20 })],
    spacing: { before: 140, after: 200 },
    alignment: AlignmentType.CENTER,
    border: {
      top:    { style: BorderStyle.DASHED, size: 4, color: "CCCCCC" },
      bottom: { style: BorderStyle.DASHED, size: 4, color: "CCCCCC" },
      left:   { style: BorderStyle.DASHED, size: 4, color: "CCCCCC" },
      right:  { style: BorderStyle.DASHED, size: 4, color: "CCCCCC" },
    },
  });
}
function img(filename, origW, origH, displayW = 500) {
  const displayH = Math.round(displayW * origH / origW);
  const data = fs.readFileSync(path.join(__dirname, 'captures', filename));
  return new Paragraph({
    children: [new ImageRun({ data, transformation: { width: displayW, height: displayH } })],
    spacing: { before: 120, after: 200 },
    alignment: AlignmentType.CENTER,
  });
}
function sep() {
  return new Paragraph({
    border: { bottom: { style: BorderStyle.SINGLE, size: 2, color: "E8D5B0" } },
    spacing: { before: 200, after: 200 },
    text: "",
  });
}

// ─── HEADER / FOOTER ────────────────────────────────────────────────────────

const pageHeader = new Header({
  children: [
    new Paragraph({
      children: [
        new ImageRun({ data: logoData, transformation: { width: 36, height: 36 } }),
        new TextRun({ text: "   NERIA — Luxury Email Suite  |  Notice d'utilisation v1.0.32", size: 16, color: LGREY }),
      ],
      border: { bottom: { style: BorderStyle.SINGLE, size: 3, color: ACCENT } },
      spacing: { after: 80 },
    }),
  ],
});

const pageFooter = new Footer({
  children: [
    new Paragraph({
      alignment: AlignmentType.CENTER,
      children: [
        new TextRun({ text: "© 2026 Neriasoftware — Tous droits réservés  |  ", size: 16, color: LGREY }),
        new TextRun({ children: [PageNumber.CURRENT], size: 16, color: LGREY }),
        new TextRun({ text: " / ", size: 16, color: LGREY }),
        new TextRun({ children: [PageNumber.TOTAL_PAGES], size: 16, color: LGREY }),
      ],
      border: { top: { style: BorderStyle.SINGLE, size: 3, color: ACCENT } },
    }),
  ],
});

// ─── SECTIONS ───────────────────────────────────────────────────────────────
const S = [];

// ══════════════════════════ PAGE DE GARDE ═══════════════════════════════════
S.push(
  new Paragraph({ text: "", spacing: { before: 1800 } }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new ImageRun({ data: logoData, transformation: { width: 110, height: 110 } })],
    spacing: { after: 400 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "NERIA", bold: true, size: 80, color: DARK })],
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Luxury Email Suite", size: 36, color: ACCENT, italics: true })],
    spacing: { after: 600 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Notice d'utilisation complète", bold: true, size: 44 })],
    spacing: { after: 160 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "Module PrestaShop 8 & 9 — Version 1.0.32", size: 24, color: GREY })],
    spacing: { after: 160 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "19 langues  ·  117 templates  ·  88 fonctionnalités", size: 22, color: LGREY })],
    spacing: { after: 2400 },
  }),
  new Paragraph({
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: "© 2026 Neriasoftware — Tous droits réservés", size: 18, color: LGREY })],
  }),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ SOMMAIRE ════════════════════════════════════════
S.push(
  new Paragraph({ text: "Sommaire", heading: HeadingLevel.HEADING_1, spacing: { before: 400, after: 200 } }),
  new TableOfContents("Sommaire", { hyperlink: true, headingStyleRange: "1-2" }),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 1. À PROPOS ══════════════════════════════════════
S.push(
  h1("1. À propos de Neria"),
  p("Neria — Luxury Email Suite est un module PrestaShop premium qui remplace intégralement le système d'emails natif de la plateforme par un moteur de rendu unifié, élégant et multilingue, enrichi de 88 fonctionnalités allant des automatisations comportementales à l'intelligence analytique avancée."),
  p("Il s'adresse aux boutiques de produits haut de gamme — bijouterie, maroquinerie, mode, artisanat d'art, décoration — pour lesquelles chaque email est un acte de communication de marque, pas une simple notification système."),
  h2("1.1 Compatibilité"),
  bullet("PrestaShop 8.x (testé jusqu'à 8.2) et PrestaShop 9.x (testé jusqu'à 9.0.2 sur nouvelle interface Hummingbird)."),
  bullet("PHP 7.4 minimum, PHP 8.1+ recommandé. Extensions requises : OpenSSL, cURL, GD ou Imagick, mbstring, json."),
  bullet("Compatible mono-boutique et multi-boutique. Toutes les fonctionnalités sont isolées par boutique."),
  bullet("Serveur mail : tout serveur compatible SMTP (natif PrestaShop). Neria n'envoie pas lui-même — il habille et enrichit."),
  h2("1.2 Ce que Neria fait automatiquement"),
  p("Dès l'activation, Neria intercepte via le hook actionEmailSendBefore chaque email envoyé par PrestaShop ou tout autre module installé. Il identifie le template, résout la langue réelle du destinataire, applique le design de marque (couleurs, logo, typographie), injecte les variables d'enrichissement (tracking, A/B, fidélité, social…) et retransmet l'email enrichi au mécanisme d'envoi natif de PrestaShop. Aucune configuration supplémentaire n'est nécessaire pour que les emails transactionnels standards bénéficient immédiatement du nouveau rendu."),
  h2("1.3 Appels réseau externes"),
  p("Neria effectue un seul appel réseau externe vers son propre serveur de licences (neriasoftware.com), une fois toutes les 24h maximum, pour vérifier la validité de la clé. Données transmises : clé de licence, nom de domaine, version du module. Aucune donnée personnelle client n'est transmise. En cas d'indisponibilité du serveur, la dernière vérification en cache reste valide indéfiniment — les envois d'emails ne sont jamais interrompus par une panne côté éditeur."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 2. PRÉREQUIS ════════════════════════════════════
S.push(
  h1("2. Prérequis techniques"),
  h2("2.1 Environnement serveur"),
  bullet("PHP : 7.4 minimum (8.1+ recommandé pour les performances JSON)"),
  bullet("Extension OpenSSL : requise pour le chiffrement AES-256-GCM des données sensibles en base"),
  bullet("Extension cURL : requise pour les appels API (licence, Gmail Postmaster, Google Search Console, PageSpeed, DeepL, Semrush, Moz)"),
  bullet("Extension GD ou Imagick : requise pour la génération des signatures manuscrites (SignatureGenerator)"),
  bullet("Extension mbstring : requise pour le traitement des chaînes multilingues (arabe, japonais, coréen, chinois)"),
  bullet("Extension json : standard, incluse dans toutes les installations PHP modernes"),
  bullet("MySQL 5.7+ ou MariaDB 10.3+ : 38 tables créées à l'installation (préfixe neria_)"),
  h2("2.2 Hooks PrestaShop utilisés"),
  bullet("actionEmailSendBefore — interception de tous les emails sortants"),
  bullet("actionMailAlterMessageBeforeSend — ajout List-Unsubscribe RFC 8058"),
  bullet("actionObjectOrderAddAfter — déclenchement post-commande (attribution, fidélité, paliers)"),
  bullet("actionOrderStatusPostUpdate — suivi statuts commande (expédié, livré, remboursé, retour)"),
  bullet("actionObjectOrderSlipAddAfter — déclenchement remboursement"),
  bullet("actionObjectOrderReturnAddAfter — déclenchement retour marchandise"),
  bullet("actionUpdateQuantity — détection réapprovisionnement (liste d'attente)"),
  bullet("actionDeleteGDPRCustomer — purge des données client sur demande RGPD"),
  bullet("displayAdminOrderMainBottom — bloc certificat sur fiche commande"),
  bullet("displayCustomerAccount — historique emails sur fiche client BO"),
  bullet("displayHeader — fallback cron comportemental si cron serveur absent"),
  h2("2.3 Tables SQL créées"),
  p("38 tables sont créées à l'installation, toutes préfixées par le préfixe de la boutique suivi de neria_ :"),
  bullet("neria_stat : événements de tracking (envois, ouvertures, clics, conversions)"),
  bullet("neria_translation / neria_translation_history : textes localisés des templates (19 langues) et historique des modifications"),
  bullet("neria_abtest / neria_abtest_translation / neria_abtest_history : définition, variantes de texte et historique des tests A/B terminés"),
  bullet("neria_loyalty_points / neria_loyalty_rewards : cumul de points et récompenses du programme de fidélité"),
  bullet("neria_waitlist : inscriptions liste d'attente par produit"),
  bullet("neria_customer_segment : segmentation comportementale par client"),
  bullet("neria_churn_score : score de risque de désabonnement par client"),
  bullet("neria_propensity_score : score de propension à l'achat"),
  bullet("neria_attribution : tracking last-click 24h pour l'attribution de revenus"),
  bullet("neria_upsell : suggestions d'upsell post-achat envoyées"),
  bullet("neria_webhook_queue : file d'attente des webhooks sortants"),
  bullet("neria_bounces : adresses emails invalides (hard bounce / soft bounce)"),
  bullet("neria_log : journal interne Watchdog (événements INFO/WARNING/ERROR)"),
  bullet("neria_collection / neria_collection_sent : règles et déduplication des envois de complétion de collection"),
  bullet("neria_look_rule / neria_look_sent : règles et déduplication des envois « Complétez votre look »"),
  bullet("neria_seasonal_campaign : campagnes saisonnières récurrentes"),
  bullet("neria_queue : file d'attente d'envoi différé à l'heure préférée"),
  bullet("neria_certificate : registre des certificats d'authenticité émis"),
  bullet("neria_quote : relances de devis B2B"),
  bullet("neria_reconciliation : réconciliation des remboursements partiels/multi-étapes"),
  bullet("neria_product_lifespan : rappels de durée de vie produit"),
  bullet("neria_preferences : centre de préférences client (opt-in/out par catégorie)"),
  bullet("neria_voice_profile : empreinte vocale (mots bannis/préférés, notes de ton)"),
  bullet("neria_birthday_voucher / neria_milestone_voucher : bons de réduction anniversaire et palier de commandes"),
  bullet("neria_behavioral_sent : déduplication des emails comportementaux déjà envoyés"),
  bullet("neria_blacklist : templates désactivés (rendu natif PrestaShop repris)"),
  bullet("neria_config / neria_custom_variable / neria_signature / neria_cron_health / neria_calendar_event : configuration, variables personnalisées, signature manuscrite, suivi des crons et occasions calendaires"),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 3. INSTALLATION ══════════════════════════════════
S.push(
  h1("3. Installation"),
  img('image-2.png', 1910, 864, 500),
  h2("3.1 Depuis PrestaShop Addons (recommandé)"),
  step(1, "Dans le back-office PrestaShop, allez dans Modules → Catalogue de modules."),
  step(2, "Recherchez « Neria » dans la barre de recherche."),
  step(3, "Cliquez sur Acheter puis, une fois l'achat confirmé, sur Installer. L'installation s'effectue automatiquement."),
  step(4, "Une fois terminé, le module Neria apparaît dans le menu latéral gauche."),
  h2("3.2 Installation manuelle (fichier .zip)"),
  step(1, "Téléchargez le fichier neria-X.Y.Z.zip depuis votre espace client ou votre email de confirmation d'achat. Placez-le sur votre bureau."),
  img('image1-.png', 1793, 1008, 260),
  step(2, "Dans Modules → Catalogue de modules, cliquez sur Installer un module en haut à droite."),
  img('image-2.png', 1910, 864, 500),
  step(3, "La fenêtre d'import s'ouvre. Cliquez sur sélectionnez un fichier ou glissez-déposez directement le zip dans la zone."),
  img('image-3.png', 1910, 855, 500),
  step(4, "Dans l'explorateur Windows, sélectionnez le fichier neria-X.Y.Z.zip et cliquez sur Ouvrir."),
  img('image-4.png', 1899, 995, 500),
  step(5, "PrestaShop importe et installe le module. La confirmation « Module installé ! » s'affiche."),
  img('image-5.png', 1906, 861, 500),
  step(6, "Le menu Neria apparaît immédiatement dans la barre latérale gauche du back-office."),
  img('image-7.png', 1910, 862, 500),
  h2("3.3 Ce que l'installation crée"),
  p("L'installation ne modifie aucune donnée existante. Elle crée uniquement :"),
  bullet("38 tables dédiées (préfixe neria_) pour les statistiques, la fidélité, les certificats, etc."),
  bullet("Les traductions des 117 templates en 19 langues, chargées en base de données."),
  bullet("Les réglages par défaut de toutes les fonctionnalités — toutes actives, sauf les tests A/B et le palier fidélité par bon, désactivés par prudence."),
  note("Dès l'installation, tous les emails standards de PrestaShop (confirmation de commande, mot de passe, newsletter…) sont automatiquement habillés avec le design par défaut de Neria. Aucune configuration n'est obligatoire pour ce rendu initial."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 4. ACTIVATION LICENCE ═══════════════════════════
S.push(
  h1("4. Activation de la licence"),
  p("Neria nécessite une clé de licence valide, liée au nom de domaine de votre boutique, pour fonctionner au-delà de la période de grâce de 30 jours post-installation."),
  h2("4.1 Activer votre clé"),
  step(1, "Ouvrez le menu Neria → n'importe quel onglet. Si aucune licence n'est active, une bannière orange s'affiche en haut de l'écran."),
  step(2, "Saisissez votre clé de licence au format NERIA-XXXX-XXXX-XXXX, fournie par email lors de votre achat sur PrestaShop Addons."),
  step(3, "Cliquez sur Activer. La clé est vérifiée en ligne et liée à votre domaine."),
  img('image-6.png', 1703, 743, 500),
  img('image-license.png', 1839, 591, 500),
  h2("4.2 Changement de domaine"),
  p("En cas de migration vers un nouveau nom de domaine, réutilisez la même clé. Neria détecte le changement et envoie un email de confirmation à votre adresse d'achat. Le lien de confirmation transfère la licence sur le nouveau domaine. Ce mécanisme est limité à deux transferts par an."),
  h2("4.3 Tolérance aux pannes"),
  p("Si le serveur de vérification de licences est temporairement indisponible, le module conserve le dernier statut connu en cache et continue d'envoyer les emails normalement. Aucune interruption de service ne peut résulter d'une panne côté éditeur."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 5. PREMIER TOUR DU BACK-OFFICE ══════════════════
S.push(
  h1("5. Vue d'ensemble du back-office Neria"),
  p("Le menu Neria regroupe l'ensemble des fonctionnalités dans une navigation latérale structurée. Voici une présentation rapide de chaque onglet disponible."),
  img('image-accueil.png', 1907, 859, 500),
  h2("5.1 Liste des onglets"),
  bullet("Accueil — Tableau de bord, KPIs 30 jours, occasions calendaires à venir, configuration générale"),
  bullet("Design — Couleurs, logo, mode sombre, largeur du conteneur, styles rapides, aperçu live"),
  bullet("Typographie — Polices par langue et famille de script (latin, arabe, japonais, coréen, chinois)"),
  bullet("Traductions — Édition des textes de tous les templates en 19 langues, DeepL, export/import CSV"),
  bullet("Réseaux sociaux — Configuration des liens RS injectés dans le pied de chaque email"),
  bullet("Statistiques — KPIs, tracking, attribution, segmentation, churn score, CLV, délivrabilité"),
  bullet("Tests A/B — Création et suivi des tests sur les templates"),
  bullet("Envoi manuel — Envoi à la demande vers un client, planification différée"),
  bullet("Aperçu multi-client — Simulation du rendu dans Gmail, Outlook, Apple Mail, Orange, Yahoo"),
  bullet("Historique clients — Emails reçus par client, timeline, alertes, export CSV"),
  bullet("Calendrier — Occasions calendaires automatiques (25 occasions, 19 langues)"),
  bullet("Webhooks — Notifications sortantes vers CRM, Zapier, Make"),
  bullet("Segments — Tableau de bord 5 segments comportementaux clients"),
  bullet("Campagnes saisonnières — Campagnes récurrentes personnalisées"),
  bullet("Bounces — Gestion des adresses email invalides (IMAP + webhook ESP)"),
  bullet("RGPD — Audit de conformité et purge des données personnelles"),
  bullet("Academy — Tutoriels et guides intégrés directement dans le back-office"),
  bullet("Certificats — Configuration et émission des certificats d'authenticité PDF"),
  bullet("Centre de contrôle — Visibilité des fonctionnalités dans le menu"),
  bullet("Aide — Journal Watchdog, diagnostic complet, token cron"),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 6. CONFIGURATION GÉNÉRALE ═══════════════════════
S.push(
  h1("6. Onglet Accueil — Configuration générale"),
  p("L'onglet Accueil est le tableau de bord principal de Neria. Il regroupe les KPIs des 30 derniers jours, les occasions calendaires à venir, et les paramètres généraux du module."),
  img('image-accueil.png', 1907, 859, 500),
  h2("6.1 KPIs 30 jours"),
  p("En haut de l'onglet, quatre indicateurs clés sont affichés : emails envoyés, taux d'ouverture, taux de clic, et langues actives sur 19. Ces chiffres donnent une vue rapide de la santé globale des envois."),
  img('image-accueil2.png', 1910, 863, 500),
  h2("6.2 Détection automatique de la langue"),
  p("Neria choisit la langue de chaque email selon le client : son choix explicite s'il a sélectionné une langue, sinon le pays de son adresse de livraison. Un client étranger reçoit ainsi l'email dans sa langue, même si la boutique est configurée en une seule langue."),
  img('image-accueil3.png', 1910, 859, 500),
  h2("6.3 Smart Salutation — Heure locale"),
  p("Neria injecte automatiquement la bonne formule de salutation selon l'heure locale du client (déduite de son adresse de livraison). Aucune retouche de template nécessaire — personnalisez simplement les formules ci-dessous par langue et par créneau."),
  img('image-accueil4.png', 1910, 863, 500),
  img('image-accueil5.png', 1910, 859, 500),
  h2("6.4 Paramètres généraux"),
  bullet("Variables personnalisées : le marchand peut définir ses propres variables ({shop_instagram}, {shop_hashtag}, etc.) injectées dans tous les templates."),
  bullet("Multi-expéditeur : configure des paires Nom/Email d'expéditeur différentes par langue ou par template."),
  bullet("Témoin silencieux (BCC) : envoie une copie BCC de chaque email à une adresse d'archive."),
  bullet("Empreinte carbone : affiche une mention dans le pied des emails avec un lien vers votre page de compensation."),
  img('image-accueil6.png', 1903, 854, 500),
  img('image-accueil7.png', 1903, 852, 500),
  img('image-accueil8.png', 1910, 856, 500),
  img('image-accueil9.png', 1908, 863, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 7. DESIGN ════════════════════════════════════════
S.push(
  h1("7. Onglet Design — Identité visuelle"),
  p("L'onglet Design configure l'habillage visuel de tous les emails envoyés par la boutique. Toute modification est répercutée immédiatement sur l'ensemble des templates via l'aperçu live."),
  img('image-design.png', 1905, 862, 500),
  h2("7.1 Couleurs"),
  bullet("Couleur d'accent : utilisée pour les boutons CTA, liens, montants mis en avant, codes promo."),
  bullet("Couleur de fond : fond général de l'email (hors carte centrale, toujours blanche pour la lisibilité)."),
  bullet("Couleur de texte principal et secondaire."),
  step(1, "Cliquez sur le sélecteur de couleur souhaité."),
  step(2, "Choisissez via la palette graphique ou saisissez le code hexadécimal (#B38B59 pour le bronze Neria, par exemple)."),
  step(3, "L'aperçu live se met à jour immédiatement. Cliquez sur Enregistrer."),
  img('image-design2.png', 1910, 863, 500),
  h2("7.2 Logo"),
  step(1, "Cliquez sur le bouton de téléchargement du logo."),
  step(2, "Sélectionnez votre fichier logo (PNG transparent recommandé, minimum 300×100 px)."),
  step(3, "Le logo s'affiche dans l'aperçu en haut de chaque email. Ajustez la largeur d'affichage si nécessaire."),
  h2("7.3 Mode sombre"),
  p("Le mode sombre verrouille le rendu en thème clair même si le client mail du destinataire applique un thème sombre automatique (meta-color-scheme forcé à light). Recommandé pour les boutiques avec un logo ou des couleurs de marque sensibles à l'inversion."),
  h2("7.4 Largeur du conteneur"),
  p("La largeur de la carte centrale de l'email est réglable entre 480 px et 700 px. Valeur recommandée : 600 px (standard industrie emailing)."),
  img('image-design3.png', 1904, 854, 500),
  h2("7.5 Styles rapides — Préréglages en un clic"),
  p("Six préréglages appliquent instantanément une combinaison cohérente de couleurs et polices :"),
  bullet("Élégance dorée — tons or/crème, serif discret"),
  bullet("Minimalisme noir & blanc — sans-serif moderne"),
  bullet("Bleu marine & or — style nautique premium"),
  bullet("Bordeaux & ivoire — style vigneron, maroquinerie"),
  bullet("Vert forêt & bronze — artisanat nature"),
  bullet("Rose poudré & gris — mode féminine"),
  step(1, "Cliquez sur l'un des 6 styles dans la section Styles rapides."),
  step(2, "L'aperçu live se met à jour instantanément."),
  step(3, "Cliquez sur Appliquer ce style pour confirmer, ou choisissez un autre style."),
  step(4, "Le bouton Réinitialisation usine restaure les valeurs d'origine à tout moment."),
  h2("7.6 Signature manuscrite"),
  p("Neria peut générer une image de signature calligraphique à partir du nom saisi, avec la bibliothèque GD et des polices manuscrites (Dancing Script, Great Vibes, Sacramento, Pinyon Script). Cette signature est injectée dans le pied de chaque email, sous la formule de politesse."),
  step(1, "Saisissez le nom à calligraphier dans le champ Nom de la signature."),
  step(2, "Choisissez la police parmi les 4 disponibles."),
  step(3, "Ajustez la taille et la couleur."),
  step(4, "Cliquez sur Générer l'aperçu, puis sur Enregistrer."),
  img('image-design4.png', 1900, 863, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 8. TYPOGRAPHIE ═══════════════════════════════════
S.push(
  h1("8. Onglet Typographie"),
  p("L'onglet Typographie configure les polices utilisées dans les emails, par famille de script. Neria gère 5 familles : latin, arabe (RTL), japonais, coréen, chinois simplifié/traditionnel."),
  img('image-typo.png', 1824, 804, 500),
  h2("8.1 Configuration des polices"),
  step(1, "Sélectionnez la famille de script à configurer (Latin, Arabe, Japonais, Coréen, Chinois)."),
  step(2, "Choisissez la police de titre dans le menu déroulant (ex. : Cormorant Garamond, EB Garamond…)."),
  step(3, "Choisissez la police de texte courant (ex. : Lato, Open Sans…)."),
  step(4, "L'aperçu montre le rendu dans un extrait d'email. Cliquez sur Enregistrer."),
  img('image-typo2.png', 1829, 804, 500),
  note("Neria génère automatiquement les balises @font-face et les stacks de repli pour garantir un rendu élégant même dans Outlook, qui ne supporte pas les polices web."),
  img('image-typo3.png', 1831, 804, 500),
  img('image-typo4.png', 1831, 806, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 9. RÉSEAUX SOCIAUX ═══════════════════════════════
S.push(
  h1("9. Onglet Réseaux sociaux"),
  p("Cet onglet configure les icônes et liens de réseaux sociaux injectés automatiquement dans le pied de page de chaque email. Jusqu'à 9 plateformes disponibles : Instagram, Facebook, Pinterest, TikTok, YouTube, LinkedIn, X/Twitter, Snapchat, WhatsApp."),
  img('image-rs.png', 1817, 810, 500),
  step(1, "Saisissez l'URL complète de votre profil pour chaque réseau souhaité (laissez vide pour masquer le réseau)."),
  step(2, "Réordonnez les icônes par glisser-déposer si nécessaire."),
  step(3, "Cliquez sur Enregistrer, puis envoyez-vous un email de test pour voir le rendu des icônes dans le pied de page (l'aperçu à l'écran de l'onglet Prévisualisation n'affiche pas les réseaux sociaux)."),
  img('image-rs2.png', 673, 789, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 10. TRADUCTIONS ═══════════════════════════════════
S.push(
  h1("10. Onglet Traductions — 19 langues"),
  p("L'onglet Traductions permet d'éditer le texte de chacun des 117 templates email, dans chacune des 19 langues disponibles (fr, en, de, it, es, pt, br, gb, ar, ja, ko, zh, tw, ru, tr, sv, no, da, nl), avec un aperçu live avant sauvegarde."),
  img('image-trad.png', 1844, 799, 500),
  h2("10.1 Éditer un template"),
  step(1, "Sélectionnez la langue dans le menu déroulant en haut."),
  step(2, "Choisissez le template à éditer dans la liste (ou utilisez la recherche globale)."),
  step(3, "Cliquez sur Modifier. L'éditeur s'ouvre avec les champs : Objet, Corps (HTML), Corps texte brut."),
  step(4, "Modifiez le texte. Les variables disponibles ({firstname}, {order_reference}…) sont listées à droite."),
  step(5, "L'aperçu live se met à jour à chaque frappe. Cliquez sur Enregistrer."),
  img('image-trad2.png', 1830, 808, 500),
  h2("10.2 Traduction automatique DeepL"),
  step(1, "Dans l'éditeur, cliquez sur le bouton Traduire avec DeepL."),
  step(2, "Neria envoie le texte source à l'API DeepL et remplit automatiquement le champ cible."),
  step(3, "Relisez et ajustez si nécessaire avant de sauvegarder."),
  note("Une clé API DeepL Free (500 000 caractères/mois gratuits) doit être configurée dans l'onglet Accueil → Paramètres généraux → Clé DeepL."),
  h2("10.3 Export et import CSV"),
  step(1, "Cliquez sur Exporter CSV pour télécharger l'ensemble des traductions d'une langue."),
  step(2, "Modifiez le fichier CSV dans Excel ou Google Sheets (colonne A : clé, colonne B : traduction)."),
  step(3, "Cliquez sur Importer CSV et sélectionnez le fichier modifié. Les traductions sont mises à jour en masse."),
  h2("10.4 Blacklister un template"),
  p("Si vous souhaitez qu'un template précis soit géré par le système natif de PrestaShop plutôt que par Neria (globalement ou pour une langue donnée), rendez-vous dans Accueil → Blacklist de templates :"),
  step(1, "Sélectionnez le template dans la liste déroulante."),
  step(2, "Choisissez une langue précise, ou laissez « Toutes les langues » pour un blocage global."),
  step(3, "Cliquez sur Ajouter. PrestaShop gère alors cet email nativement pour Neria."),
  step(4, "Pour réactiver, retirez la règle dans le tableau des règles actives."),
  img('image-blacklist.png', 1807, 302, 500),
  h2("10.5 Historique des traductions"),
  p("Chaque modification de traduction est journalisée avec horodatage. L'historique est accessible depuis le bouton Historique dans l'éditeur d'un template."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 11. EMPREINTE VOCALE ══════════════════════════════
S.push(
  h1("11. Empreinte vocale de la marque"),
  p("L'empreinte vocale permet de définir par langue les mots bannis, les mots préférés, et les notes de ton éditorial. Neria scanne automatiquement les traductions existantes et signale les incohérences, sans recourir à une IA externe."),
  img('image-vocal.png', 1817, 811, 500),
  step(1, "Dans la section Empreinte vocale, sélectionnez la langue."),
  step(2, "Saisissez les Mots bannis (ex. : « pas cher », « promo », « solde ») séparés par des virgules."),
  step(3, "Saisissez les Mots préférés (ex. : « exclusif », « artisanal », « sur-mesure »)."),
  step(4, "Ajoutez des Notes de ton (ex. : « vouvoiement systématique », « pas de point d'exclamation »)."),
  step(5, "Cliquez sur Lancer l'audit rétroactif pour analyser tous les templates existants dans cette langue."),
  step(6, "Neria affiche la liste des templates contenant des mots bannis, avec le contexte."),
  img('image-vocal2.png', 1821, 806, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 12. ENVOI MANUEL ══════════════════════════════════
S.push(
  h1("12. Onglet Envoi manuel"),
  p("L'onglet Envoi manuel permet d'envoyer n'importe quel template email à n'importe quel client, immédiatement ou en différé, depuis le back-office."),
  img('image-envoi.png', 1910, 863, 500),
  step(1, "Dans le champ Client, commencez à taper le nom ou l'email du destinataire. L'auto-complétion propose les clients correspondants."),
  step(2, "Sélectionnez le template à envoyer dans la liste déroulante."),
  step(3, "Si le template nécessite des variables spécifiques (numéro de commande, montant…), remplissez les champs qui apparaissent."),
  step(4, "Choisissez la langue d'envoi (par défaut : langue préférée du client)."),
  step(5, "Optionnel : cochez Planifier pour définir une date et heure d'envoi différées."),
  step(6, "Cliquez sur Prévisualiser pour contrôler le rendu, puis sur Envoyer."),
  note("Neria détecte si un email identique a déjà été envoyé récemment au même client et affiche un avertissement de doublon potentiel avant l'envoi."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 13. APERÇU MULTI-CLIENT ═══════════════════════════
S.push(
  h1("13. Onglet Prévisualisation multi-client"),
  p("Cet onglet simule le rendu de n'importe quel template dans 10 clients email différents : Apple Mail, Gmail, Outlook Desktop, Orange Mail, Yahoo Mail, Outlook Web (Hotmail/Live/MSN), QQ Mail, Mail.ru, Samsung Email, GMX/Web.de. Le CSS est inliné côté serveur pour chaque client simulé — aucune donnée n'est transmise à l'extérieur."),
  img('image-preview.png', 1817, 742, 500),
  step(1, "Sélectionnez le template à prévisualiser dans la liste déroulante."),
  step(2, "Sélectionnez la langue."),
  step(3, "Cliquez sur Prévisualiser. Les 10 rendus s'affichent en grille."),
  step(4, "Cliquez sur l'un des rendus pour l'agrandir et faire défiler l'email complet."),
  img('image-preview2.png', 1823, 751, 500),
  h2("13.1 Simuler le mode sombre"),
  p("Le bouton Simuler le mode sombre (tous les clients) bascule l'affichage en thème sombre pour vérifier que le rendu reste lisible et que les couleurs de marque ne s'inversent pas de façon indésirable."),
  img('image-preview3.png', 1828, 807, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 14. AUTOMATISATIONS COMPORTEMENTALES ═══════════════
S.push(
  h1("14. Automatisations comportementales"),
  p("L'onglet ⚙ Automatisations regroupe l'ensemble des 21 tâches quotidiennes déclenchées par le cron comportemental de Neria. Chaque automatisation dispose de son propre interrupteur ON/OFF, d'un compteur d'emails envoyés aujourd'hui et au total, et d'un indicateur de déclencheur."),
  img('image-auto.png', 1906, 865, 500),
  img('image-auto2.png', 1910, 863, 500),
  note("Le bouton « Forcer l'exécution maintenant » déclenche immédiatement tous les crons actifs pour la boutique courante. Les 3 dernières lignes (Score de propension, Segmentation, Score de risque) n'envoient pas d'emails : elles recalculent des scores internes en base de données et affichent « ⚙ calcul » à la place du bouton."),

  h2("14.1 Configuration du cron serveur"),
  p("Pour garantir l'exécution quotidienne, configurez une tâche cron sur votre serveur. Le token d'authentification est disponible dans l'onglet Aide → Configuration cron."),
  note("Sur O2switch, ajoutez la tâche depuis cPanel → Tâches Cron. Fréquence recommandée : 1 fois/jour à 8h00."),

  h2("14.2 Anniversaires clients"),
  p("Trois emails d'anniversaire sont disponibles, chacun avec son propre interrupteur :"),
  bullet("Anniversaire client (birthday) : envoyé le jour de l'anniversaire du client, avec bon de réduction configurable."),
  bullet("Premier anniversaire de commande (first_anniversary) : envoyé exactement 1 an après la première commande."),
  bullet("Anniversaire de la relation (relationship_anniversary) : envoyé chaque année à la date du premier achat (2 ans, 3 ans…)."),

  h2("14.3 Panier abandonné — 3 vagues"),
  p("Trois relances sont envoyées automatiquement après un abandon de panier, avec déduplication stricte (un client ne reçoit jamais deux relances pour le même panier) :"),
  bullet("Vague 1 — abandoned_cart_1 : 1 heure après l'abandon (rappel doux)."),
  bullet("Vague 2 — abandoned_cart_2 : 24 heures après l'abandon (relance avec incentive optionnel)."),
  bullet("Vague 3 — abandoned_cart_3 : 72 heures après l'abandon (dernière chance)."),
  note("Si le client finalise sa commande entre deux vagues, les vagues suivantes sont automatiquement annulées."),

  h2("14.4 Abandon de paiement"),
  p("L'email checkout_abandonment est déclenché 1 heure après que le client a sélectionné une adresse et un transporteur, sans aller jusqu'au paiement. Signal plus précoce que le panier abandonné classique."),

  h2("14.5 Suivi post-achat"),
  bullet("Soin du produit (post_purchase_care) : envoyé 7 jours après livraison confirmée. Email de conseil d'entretien et prise en charge."),
  bullet("Demande d'avis (post_purchase_review) : envoyé 14 jours après livraison. Email de sollicitation d'avis, avec lien direct vers la fiche produit."),

  h2("14.6 Relance de réachat"),
  p("L'email reorder_reminder est envoyé 30 jours après la dernière commande pour les produits à consommation récurrente (cosmétiques, alimentation, consommables). Peut être filtré par catégorie de produits."),

  h2("14.7 Reconquête (Win-back)"),
  p("L'email win_back est envoyé 90 jours après la dernière commande aux clients inactifs. Inclut optionnellement un bon de réduction de reconquête."),

  h2("14.8 Retard de livraison"),
  p("L'email order_shipped_delay est envoyé si une commande marquée Expédié n'a reçu aucun accusé de livraison 7 jours après l'expédition. Email proactif informant le client et proposant de contacter le transporteur."),

  h2("14.9 Panier fantôme"),
  p("L'email ghost_cart cible les paniers créés depuis plus de N jours (configurable) sans avoir abouti à une commande ni fait l'objet d'une relance panier abandonné. Relance silencieuse pour les clients ayant créé un panier mais sans jamais l'avoir actif au moment du cron standard."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 15. DÉCLENCHEURS COMMANDE ═════════════════════════
S.push(
  h1("15. Déclencheurs commande"),
  p("Les déclencheurs commande sont des emails envoyés automatiquement en arrière-plan, sans aucune configuration nécessaire dans le BO. Ils s'activent via des hooks PrestaShop dès que l'événement correspondant se produit sur la boutique."),
  h2("15.1 Paliers de commande (milestone_order)"),
  p("Email de félicitation envoyé au client lorsqu'il atteint un palier symbolique : 5e, 10e, 25e, 50e ou 100e commande. Le message met en valeur la fidélité du client et peut inclure un bon de réduction."),
  h2("15.2 Mise en attente de commande"),
  p("Email informatif envoyé automatiquement lorsqu'une commande passe dans un statut configuré comme « bloquant » (ex. : en attente de paiement complémentaire, problème douanier)."),
  h2("15.3 Expédition partielle"),
  p("Si une commande est expédiée en plusieurs fois, l'email order_partial_shipped informe le client de ce qui part dans le colis et de ce qui reste en préparation."),
  h2("15.4 Remboursement traité"),
  p("L'email refund_processed est envoyé automatiquement lors de la création d'un avoir. Trois emails de suivi de réconciliation post-remboursement (refund_reconciliation_1/2/3) sont envoyés à J+1, J+3, J+7 pour fidéliser le client malgré l'incident."),
  h2("15.5 Retour reçu"),
  p("L'email return_received est envoyé automatiquement à la réception physique du retour marchandise, confirmant que le retour est bien enregistré. Aucune action marchand n'est requise : le hook PrestaShop déclenche l'envoi dès que le retour est créé en base."),
  note("Ces déclencheurs n'ont pas d'interface de configuration dédiée. Pour vérifier qu'un envoi a eu lieu, consultez l'historique email du client concerné dans l'onglet Historique clients."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 16. OCCASIONS CALENDAIRES ═════════════════════════
S.push(
  h1("16. Onglet Calendrier — Occasions calendaires"),
  p("Neria envoie automatiquement des emails pour 25 occasions calendaires récurrentes, dans la langue préférée de chaque client, avec une résolution intelligente des dates à 4 niveaux (override marchand, algorithme hégirien/lunaire, dates pré-calculées 2025-2035, dates fixes)."),
  img('image-cal.png', 1910, 863, 500),
  p("Occasions disponibles : Noël, Jour de l'an, Saint-Valentin, Fête des Mères, Fête des Pères, Fête des Grands-Parents, Halloween, Black Friday, Eid al-Fitr, Eid al-Adha, Ramadan (début), Diwali, Nouvel An Chinois/Lunaire, Hanoukka, Pâques, Fête du Travail, Journée Internationale des Droits des Femmes, et plus."),
  h2("16.1 Activer / désactiver une occasion"),
  step(1, "Trouvez l'occasion dans la liste."),
  step(2, "Cliquez sur la bascule Actif/Inactif. L'occasion sera (ou ne sera pas) envoyée lors du prochain cron."),
  h2("16.2 Personnaliser la date"),
  step(1, "Cliquez sur l'icône Modifier de l'occasion."),
  step(2, "Cochez Override de date et saisissez la date souhaitée (format JJ/MM)."),
  step(3, "Cliquez sur Enregistrer. Votre date personnalisée a la priorité sur le calcul automatique."),
  img('image-cal2.png', 1910, 863, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 17. CAMPAGNES SAISONNIÈRES ════════════════════════
S.push(
  h1("17. Onglet Campagnes saisonnières"),
  p("Les campagnes saisonnières permettent au marchand de créer des envois récurrents personnalisés — propres à son activité — qui se déclenchent automatiquement chaque année à la même date."),
  img('image-saison.png', 1813, 809, 500),
  h2("17.1 Créer une campagne"),
  step(1, "Cliquez sur Nouvelle campagne."),
  step(2, "Saisissez le nom de la campagne (usage interne uniquement)."),
  step(3, "Sélectionnez le template email à envoyer."),
  step(4, "Définissez la date annuelle de déclenchement (format JJ/MM)."),
  step(5, "Configurez le ciblage : segment client, langue, pays, genre, tranche d'âge (tous les champs sont optionnels)."),
  step(6, "Activez la campagne et cliquez sur Enregistrer."),
  img('image-saison2.png', 1808, 808, 500),
  img('image-saison3.png', 1809, 777, 500),
  note("Une campagne saisonnière ne peut être envoyée qu'une seule fois par an au même client, même si le cron s'exécute plusieurs fois le même jour."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 18. PROGRAMME DE FIDÉLITÉ ═════════════════════════
S.push(
  h1("18. Programme de fidélité"),
  p("Neria intègre un système complet de fidélité par email : points cumulés à chaque interaction, paliers récompensés, récapitulatif mensuel automatique."),
  img('image-fidelite.png', 1650, 749, 500),
  h2("18.1 Attribution des points"),
  bullet("Ouverture d'email : +1 point"),
  bullet("Clic dans un email : +3 points"),
  bullet("Achat validé : +10 points (configurable)"),
  p("Les métriques en temps réel (clients actifs, points distribués, bons envoyés, ouvertures, clics, achats trackés) sont visibles en haut de la section."),
  h2("18.2 Paliers de fidélité"),
  p("Trois paliers sont configurables (seuil de points, récompense en montant fixe ou en pourcentage) :"),
  bullet("Bronze : 50 points — bon de 5 € (par défaut)"),
  bullet("Argent : 150 points — bon de 10 € (par défaut)"),
  bullet("Or : 300 points — bon de 20 € (par défaut)"),
  step(1, "Modifiez le seuil et le montant de récompense de chaque palier."),
  step(2, "Cochez « Réduction en % » si vous préférez un pourcentage plutôt qu'un montant fixe."),
  step(3, "Cliquez sur Enregistrer les paliers."),
  h2("18.3 Emails de fidélité"),
  bullet("loyalty_tier_upgrade : email automatique dès qu'un client franchit un palier, avec son bon de récompense."),
  bullet("loyalty_recap : récapitulatif mensuel des points du client."),
  bullet("loyalty_reward_expiry : alerte envoyée 7 jours avant l'expiration d'un bon de récompense."),
  h2("18.4 Cumul multi-boutique"),
  p("Sur une installation multi-boutique, la bascule « Cumul entre boutiques » permet de choisir si les points se cumulent toutes boutiques confondues ou restent isolés par boutique. Par défaut : cumulé sur toutes les boutiques."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 19. SEGMENTATION COMPORTEMENTALE ═══════════════════
S.push(
  h1("19. Onglet Segments — Segmentation comportementale"),
  p("Neria classe automatiquement chaque client dans l'un des 5 segments comportementaux, recalculés quotidiennement à partir de l'historique des emails et des achats."),
  img('image-segments.png', 1812, 796, 500),
  h2("19.1 Les 5 segments"),
  bullet("Ambassador : ouvre systématiquement les emails, a converti au moins 2 fois. Clients les plus engagés."),
  bullet("Loyal : ouvre régulièrement, au moins 1 conversion. Clients fidèles."),
  bullet("Warm : ouvre parfois, dernier email ouvert il y a moins de 90 jours."),
  bullet("Dormant : dernier email ouvert il y a plus de 90 jours. Risque de désengagement."),
  bullet("Ghost : n'a jamais ouvert un email Neria. Peut indiquer un problème de délivrabilité ou un désintérêt réel."),
  h2("19.2 Utiliser les segments dans les campagnes"),
  p("Dans les campagnes saisonnières et les filtres d'envoi manuel, le champ Segment permet de cibler uniquement les clients d'un ou plusieurs segments (ex. : envoyer une offre de reconquête uniquement aux Dormants et Ghosts)."),
  img('image-segments2.png', 1813, 809, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 20. GOLDEN HOUR ════════════════════════════════════
S.push(
  h1("20. Golden Hour — Meilleure heure d'envoi"),
  p("La fonctionnalité Golden Hour analyse les ouvertures d'emails par jour de la semaine et par heure, par langue, pour déterminer la plage horaire où vos clients sont le plus susceptibles d'ouvrir un email."),
  img('image-golden.png', 1658, 408, 500),
  p("Les cases les plus foncées indiquent les créneaux avec le plus d'ouvertures. La légende Moins → Plus d'ouvertures en bas de la heatmap permet de calibrer la lecture. Un indicateur de confiance basé sur le volume de données collectées précise la fiabilité de la recommandation."),
  note("Golden Hour est informatif : il ne modifie pas automatiquement l'heure d'envoi des crons comportementaux. Utilisez ces données pour configurer manuellement l'heure de votre cron serveur."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 21. FENÊTRE D'ACHAT INDIVIDUELLE ══════════════════
S.push(
  h1("21. Fenêtre d'achat individuelle"),
  p("La fenêtre d'achat individuelle détecte l'heure préférée d'achat de chaque client à partir de l'historique de ses commandes. Les emails comportementaux sont mis en file d'attente et délivrés à cette heure optimale — pas une heure globale, une heure par client."),
  img('image-fenetre.png', 1656, 390, 500),
  p("Le tableau de bord affiche en temps réel : emails en attente, envoyés sur 30 jours, délai moyen, pourcentage de clients avec fenêtre détectée et heure de pointe globale."),
  h2("21.1 Activer la file d'attente"),
  step(1, "Dans Accueil → Paramètres généraux, activez la bascule Envoi à l'heure préférée du client."),
  step(2, "Les emails comportementaux sont désormais mis en file d'attente et envoyés à l'heure d'achat historique de chaque destinataire."),
  note("À partir de 2 achats validés à la même heure, le pattern est considéré fiable. Les clients sans fenêtre détectée reçoivent leurs emails immédiatement, sans délai."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 22. UPSELL & CROSS-SELL ═══════════════════════════
S.push(
  h1("22. Upsell et complétion de collection"),
  h2("22.1 Upsell intelligent"),
  p("Le moteur d'upsell sélectionne automatiquement un produit à suggérer dans les emails post-achat (J+14 après livraison) selon 3 niveaux de priorité :"),
  bullet("Niveau 1 — Accessoire : produit associé manuellement dans la fiche produit PrestaShop."),
  bullet("Niveau 2 — Co-achat : produit statistiquement commandé ensemble par d'autres clients."),
  bullet("Niveau 3 — Meilleure vente : bestseller de la même catégorie (fallback garanti)."),
  img('image-upsell.png', 1817, 815, 500),
  p("Le tableau de bord affiche suggestions, clics, conversions, CA généré et panier moyen. La section Prévisualiser permet de simuler le bloc upsell pour n'importe quelle commande livrée sans envoyer d'email réel."),
  note("Les produits déjà achetés par le client et les articles hors stock sont automatiquement exclus de la suggestion."),
  h2("22.2 Complétez votre look"),
  p("48h après livraison confirmée, l'email look_completion suggère 2 à 3 produits complémentaires selon les règles d'association catégorie → produits définies dans le BO."),
  img('image-look.png', 1793, 475, 500),
  step(1, "Sélectionnez une catégorie déclencheur dans le menu déroulant."),
  step(2, "Saisissez les IDs produits suggérés (2 à 3, séparés par des virgules)."),
  step(3, "Cliquez sur Ajouter. La règle est active immédiatement."),
  note("Un seul email par commande — déduplication automatique. L'email arrive quand le client a le produit entre les mains, au meilleur moment pour une suggestion complémentaire."),
  h2("22.3 Complétion de collection"),
  p("Neria détecte les clients à une pièce de compléter une collection et leur envoie automatiquement un email avec le produit manquant mis en avant."),
  img('image-collection.png', 1675, 746, 500),
  step(1, "Saisissez le nom de la collection (ex. : Trio Soin Visage)."),
  step(2, "Entrez les IDs produits qui composent la collection, séparés par des virgules."),
  step(3, "Cliquez sur Ajouter. Neria surveille les commandes dès le lendemain."),
  note("Un seul email par client et par collection, même si le client continue d'acheter d'autres pièces par la suite."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 23. LISTE D'ATTENTE ════════════════════════════════
S.push(
  h1("23. Liste d'attente produits"),
  p("Quand un produit est en rupture de stock, Neria affiche un bouton « Prévenir quand disponible » sur la fiche produit. Dès que le stock est réapprovisionné, un email unique est envoyé au client avec une réservation temporelle."),
  img('image-attente.png', 1394, 672, 500),
  h2("23.1 Configuration"),
  step(1, "Dans Accueil → Liste d'attente, activez la fonctionnalité."),
  step(2, "Configurez la durée de réservation (temps pendant lequel le produit est « réservé » pour le client avant de redevenir disponible publiquement)."),
  step(3, "Personnalisez le texte du bouton (par langue) dans l'onglet Traductions."),
  img('image-attente2.png', 1666, 676, 500),
  h2("23.2 Gérer les inscriptions"),
  p("La page Liste d'attente affiche les statistiques globales (clients en attente, produits surveillés, notifiés) ainsi que le tableau par produit avec le nombre d'inscrits et la durée d'attente maximale."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 24. TESTS A/B ══════════════════════════════════════
S.push(
  h1("24. Onglet Tests A/B"),
  p("Neria permet de tester deux variantes d'un même email — objet, contenu ou design — sur un échantillon de destinataires, et de désigner automatiquement la variante gagnante."),
  img('image-ab.png', 1909, 863, 500),
  h2("24.1 Créer un test"),
  step(1, "Cliquez sur Nouveau test A/B."),
  step(2, "Sélectionnez le template à tester."),
  step(3, "Saisissez l'objet et/ou le corps de la variante B (la variante A est le template original)."),
  step(4, "Définissez la répartition (ex. : 50 % A / 50 % B, ou 70 % / 30 %)."),
  step(5, "Cliquez sur Lancer le test. L'affectation A/B est assignée à chaque client par hash consistant — sans table supplémentaire, un même client reçoit toujours la même variante."),
  img('image-ab2.png', 1907, 853, 500),
  img('image-ab3.png', 1910, 863, 500),
  h2("24.2 Suivre les résultats"),
  p("Le tableau de bord du test affiche en temps réel les taux d'ouverture, de clic et de conversion pour chaque variante, avec l'intervalle de confiance statistique."),
  img('image-ab4.png', 1910, 860, 500),
  h2("24.3 Désigner le gagnant"),
  step(1, "Une fois le volume suffisant atteint (significativité statistique > 95 %), cliquez sur Désigner gagnant."),
  step(2, "Sélectionnez la variante A ou B. Elle devient le template par défaut pour tous les envois suivants."),
  step(3, "Le test passe en statut Terminé. La variante perdante est archivée."),
  img('image-ab5.png', 1818, 258, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 25. STATISTIQUES ═══════════════════════════════════
S.push(
  h1("25. Onglet Statistiques"),
  p("L'onglet Statistiques regroupe l'ensemble des données analytiques de Neria : tracking, attribution, délivrabilité, scoring client et réputation de domaine."),
  img('image-stats.png', 1844, 808, 500),
  img('image-stats2.png', 1831, 818, 500),
  h2("25.1 Tableau de bord KPIs"),
  bullet("Emails envoyés, ouverts, cliqués, convertis sur la période sélectionnée."),
  bullet("Taux d'ouverture, de clic, de conversion et de désabonnement."),
  bullet("Comparaison avec la période précédente (flèches de tendance)."),
  bullet("Top 5 des templates les plus performants."),
  img('image-stats3.png', 1808, 810, 500),
  img('image-stats4.png', 1827, 803, 500),
  h2("25.2 Tracking des ouvertures et clics"),
  p("Neria injecte un pixel de tracking 1×1 dans chaque email HTML et transforme tous les liens en liens trackés. Le front controller track.php enregistre chaque événement (open, click) dans neria_stat et redirige immédiatement le client vers l'URL cible."),
  img('image-stats5.png', 1819, 814, 500),
  h2("25.3 Attribution de revenus"),
  p("Quand un client clique dans un email Neria, un cookie neria_ref est placé (durée 24h). Si une commande est passée dans ce délai, la commande est attribuée à Neria avec le template déclencheur. Le chiffre d'affaires attribué est visible dans l'onglet Statistiques."),
  img('image-stats6.png', 1818, 810, 500),
  img('image-stats7.png', 1817, 799, 500),
  h2("25.4 Score de délivrabilité"),
  p("Neria analyse le HTML de chaque email et calcule un score de délivrabilité de 0 à 100, basé sur : présence de mots spam (FR/EN/DE/ES/IT/PT), ratio texte/image, liens suspects, balises manquantes, poids total. Le score est affiché sur chaque template dans la liste."),
  img('image-stats8.png', 1817, 807, 500),
  img('image-stats9.png', 1829, 804, 500),
  h2("25.5 Churn Score"),
  p("Le churn score (0-100) mesure le risque de désabonnement de chaque client, calculé quotidiennement à partir de la récence, du taux d'ouverture récent et de la tendance sur 3 périodes de 30 jours. Les clients avec un score > 70 sont signalés en rouge sur leur fiche."),
  img('image-stats10.png', 1826, 810, 500),
  img('image-stats11.png', 1817, 804, 500),
  h2("25.6 CLV — Valeur vie client sur 12 mois"),
  p("La CLV (Customer Lifetime Value) est estimée par la formule : panier moyen × fréquence d'achat × score d'engagement email × coefficient de segment × facteur de risque (churn). Le Top 20 des clients par CLV est affiché dans l'onglet Statistiques."),
  img('image-stats12.png', 1815, 806, 500),
  img('image-stats13.png', 1819, 801, 500),
  h2("25.7 Score de propension à l'achat"),
  p("Score 0-100 calculé quotidiennement pour chaque client, combinant récence (40 pts), fréquence (25 pts), engagement email (25 pts) et saisonnalité (10 pts). Un score > 75 déclenche une alerte « Fenêtre d'achat optimale » visible sur la fiche client."),
  img('image-stats14.png', 1815, 798, 500),
  img('image-stats15.png', 1820, 799, 500),
  h2("25.8 Historique emails client"),
  p("Dans la fiche de chaque client PrestaShop (BO → Clients), un bloc Neria affiche la timeline complète des emails reçus par ce client : date, template, statut (envoyé, ouvert, cliqué, converti), badge d'engagement global. Un bouton permet d'exporter l'historique en CSV."),
  img('image-stats16.png', 1817, 803, 500),
  img('image-stats17.png', 1820, 801, 500),
  img('image-stats18.png', 1827, 810, 500),
  img('image-stats19.png', 1823, 806, 500),
  img('image-stats20.png', 1831, 808, 500),
  img('image-stats21.png', 1819, 595, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 25-BIS. HISTORIQUE CLIENTS (ONGLET) ════════════════
S.push(
  h1("25b. Onglet Historique clients"),
  p("L'onglet Historique clients offre une vue consolidée de tous les emails envoyés par Neria, filtrables par client, template, langue, statut et période. Contrairement au bloc sur la fiche client (qui montre l'historique d'un seul client), cet onglet donne une vue globale multi-clients."),
  img('image-histo.png', 1670, 746, 500),
  h2("25b.1 Filtres disponibles"),
  bullet("Client : recherche par nom ou email."),
  bullet("Template : filtrer par type d'email (ex. : tous les emails d'anniversaire envoyés)."),
  bullet("Statut : envoyé, ouvert, cliqué, converti, bounced."),
  bullet("Langue : filtrer par langue d'envoi."),
  bullet("Période : sélecteur de dates de début et fin."),
  h2("25b.2 Export CSV"),
  p("Le bouton Exporter CSV télécharge l'ensemble des lignes filtrées avec toutes les colonnes : date, client, template, langue, statut, montant attribué."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 26. RAPPORT MENSUEL ════════════════════════════════
S.push(
  h1("26. Rapport mensuel automatique"),
  p("Le 1er de chaque mois, Neria envoie automatiquement un rapport complet de performance email au(x) destinataire(s) configuré(s). Ce rapport inclut : KPIs globaux du mois, top et flop templates, langue championne, meilleur moment d'envoi, résultats A/B, chiffre d'affaires attribué, et 3 recommandations automatiques générées à partir des données."),
  img('image-rapport.png', 554, 885, 500),
  h2("26.1 Configuration"),
  step(1, "Dans Accueil → Rapport mensuel, saisissez les adresses email des destinataires (plusieurs adresses séparées par des virgules)."),
  step(2, "Choisissez la langue du rapport."),
  step(3, "Activez la bascule et enregistrez."),
  img('image-rapport2.png', 1810, 299, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 27. RÉPUTATION DE DOMAINE ═════════════════════════
S.push(
  h1("27. Réputation du domaine d'envoi"),
  p("Neria vérifie quotidiennement la réputation de votre domaine d'envoi via 4 axes : SPF, DKIM (17 sélecteurs testés), DMARC, et 42 listes noires DNS (RBL). Les résultats sont mis en cache 24h et affichés dans l'onglet Statistiques."),
  img('image-reputation.png', 1808, 803, 500),
  h2("27.1 Interpréter les résultats"),
  bullet("SPF ✅ : votre domaine autorise le serveur d'envoi. Indispensable."),
  bullet("DKIM ✅ : la signature cryptographique est valide. Fortement recommandé."),
  bullet("DMARC ✅ : la politique DMARC est publiée. Recommandé (policy=quarantine ou reject)."),
  bullet("RBL ✅ : votre IP n'est sur aucune liste noire connue. Critique pour la délivrabilité."),
  note("En cas d'alerte SPF ou DKIM, consultez la documentation de votre hébergeur pour publier les enregistrements DNS manquants."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 28. INTÉGRATIONS SEO / MARKETING ══════════════════
S.push(
  h1("28. Intégrations Google et SEO"),
  h2("28.1 Gmail Postmaster Tools"),
  p("Neria s'intègre à l'API Gmail Postmaster Tools via OAuth 2.0 gratuit. Il affiche dans le BO les données réelles de réputation Google : taux de spam signalé, réputation du domaine, réputation de l'IP, et succès SPF/DKIM/DMARC selon Google."),
  step(1, "Dans Statistiques → Postmaster, cliquez sur Connecter avec Google."),
  step(2, "Autorisez Neria à accéder à vos données Postmaster (lecture seule)."),
  step(3, "Les données s'affichent dans le tableau de bord Postmaster."),
  img('image-seo.png', 1808, 811, 500),
  h2("28.2 Google Search Console"),
  p("Intégration OAuth 2.0 avec l'API Google Search Console v3. Affiche impressions, clics, CTR, position moyenne, top requêtes et top pages de votre boutique. Données directement dans le BO Neria."),
  step(1, "Dans Statistiques → Search Console, cliquez sur Connecter avec Google."),
  step(2, "Autorisez l'accès (lecture seule)."),
  step(3, "Sélectionnez votre propriété Search Console dans la liste."),
  img('image-seo2.png', 1809, 665, 500),
  h2("28.3 Google PageSpeed Insights"),
  p("Neria récupère les scores Lighthouse et Core Web Vitals (LCP, CLS, TBT) pour mobile et desktop via l'API PageSpeed Insights v5 (clé API gratuite Google Cloud). Cache 24h."),
  step(1, "Dans Accueil → Paramètres → Clé API PageSpeed, saisissez votre clé (obtenue sur console.cloud.google.com, quota gratuit)."),
  step(2, "Dans Statistiques → PageSpeed, cliquez sur Analyser maintenant."),
  img('image-seo3.png', 1800, 609, 500),
  h2("28.4 Semrush et Moz (optionnel)"),
  p("Intégration optionnelle avec Semrush (trafic, mots-clés, backlinks) et Moz (Domain Authority, Page Authority, spam score). Ces outils nécessitent une clé API payante."),
  step(1, "Dans Accueil → Paramètres → Intégrations SEO, saisissez votre clé Semrush et/ou Moz."),
  step(2, "Les données SEO s'affichent dans l'onglet Statistiques → Référencement."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 29. GESTION DES BOUNCES ═══════════════════════════
S.push(
  h1("29. Onglet Bounces — Gestion des emails invalides"),
  p("Neria détecte et gère automatiquement les adresses email invalides via deux canaux : lecture d'une boîte IMAP/POP3 configurée, et réception de webhooks entrants d'ESP (Mailgun, SendGrid, Postmark…)."),
  img('image-bounces.png', 1910, 860, 500),
  h2("29.1 Configuration IMAP"),
  step(1, "Dans Bounces → Configuration, saisissez les paramètres de la boîte email de rebond (hôte IMAP, port, login, mot de passe). Généralement une boîte noreply@ dédiée."),
  step(2, "Cliquez sur Tester la connexion pour vérifier."),
  step(3, "Activez le traitement IMAP. Neria lira cette boîte à chaque exécution du cron et extraira les NDR (Non-Delivery Reports)."),
  img('image-bounces2.png', 1910, 863, 500),
  h2("29.2 Configuration webhook ESP"),
  p("Si vous utilisez Mailgun, SendGrid ou Postmark, configurez dans votre ESP l'URL webhook fournie par Neria (Bounces → Configuration → URL webhook) pour recevoir les notifications de bounce en temps réel."),
  img('image-bounces3.png', 1910, 860, 500),
  h2("29.3 Hard bounce et soft bounce"),
  bullet("Hard bounce : adresse définitivement invalide (utilisateur inconnu, domaine inexistant). Bloquée immédiatement et définitivement par Neria."),
  bullet("Soft bounce : échec temporaire (boîte pleine, serveur indisponible). Bloquée après N échecs consécutifs (seuil configurable, défaut : 3)."),
  img('image-bounces4.png', 1910, 863, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 30. CERTIFICATS D'AUTHENTICITÉ ════════════════════
S.push(
  h1("30. Certificats d'authenticité"),
  p("Neria génère et joint automatiquement un certificat d'authenticité en PDF à l'email de confirmation de commande pour les boutiques vendant des pièces uniques ou numérotées."),
  img('image-cert.png', 1910, 860, 500),
  h2("30.1 Configuration"),
  step(1, "Dans l'onglet Certificats, activez la génération automatique."),
  step(2, "Personnalisez : titre, sous-titre, corps de texte, logo, signature."),
  step(3, "Choisissez si un QR code est inclus (renvoie vers une page de vérification en ligne)."),
  step(4, "Sélectionnez le format de numérotation automatique (ex. : CERT-2026-00001)."),
  img('image-cert2.png', 1910, 864, 500),
  h2("30.2 Émettre un certificat manuellement"),
  p("Depuis la fiche commande PrestaShop (bloc Neria en bas de page), un bouton Émettre un certificat permet de générer et envoyer le PDF manuellement pour une commande spécifique."),
  warning("Chaque certificat contient un numéro de série unique. Ne jamais dupliquer ou réutiliser un certificat entre boutiques."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 31. PRÉFÉRENCES CLIENT ════════════════════════════
S.push(
  h1("31. Centre de préférences client"),
  p("Chaque email envoyé par Neria inclut un lien « Gérer mes préférences » permettant au client de choisir précisément les catégories d'emails qu'il souhaite recevoir, sans se désabonner totalement."),
  p("La page de préférences est accessible via le lien « Gérer mes préférences » présent en pied de chaque email. Elle s'affiche dans le thème de la boutique et permet au client d'activer ou désactiver chaque catégorie d'email indépendamment."),
  h2("31.1 Catégories de préférences"),
  bullet("Emails transactionnels : confirmation de commande, expédition, livraison (toujours activés, non désactivables)."),
  bullet("Emails marketing : campagnes, occasions calendaires."),
  bullet("Paniers abandonnés : relances de récupération de panier."),
  bullet("Programme de fidélité : points, paliers, récapitulatif."),
  bullet("Emails comportementaux : anniversaires, win-back, post-achat."),
  bullet("Emails saisonniers : campagnes récurrentes du marchand."),
  bullet("Emails B2B : devis, relances professionnelles."),
  h2("31.2 Désabonnement global"),
  p("La page de désabonnement (accessible via le lien en pied d'email) permet un désabonnement total sécurisé par jeton HMAC-SHA256. Compatible avec le désabonnement en un clic RFC 8058 (Gmail, Yahoo)."),
  p("La page de désabonnement est sécurisée par un jeton HMAC-SHA256 unique par client. Elle s'affiche dans le thème de la boutique et propose un désabonnement total en un clic, compatible RFC 8058 (Gmail, Yahoo Mail)."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 32. RGPD ═══════════════════════════════════════════
S.push(
  h1("32. Onglet RGPD — Audit et conformité"),
  p("L'onglet RGPD fournit un tableau de bord de conformité automatique et des outils de purge des données personnelles."),
  img('image-rgpd.png', 1829, 804, 500),
  h2("32.1 Score de conformité"),
  p("Neria calcule automatiquement un score de conformité RGPD sur 3 axes : durée de conservation des données (vs. durées légales), mécanisme de consentement en place, et droit à l'oubli opérationnel. Le score global est affiché avec les points d'amélioration détaillés."),
  img('image-rgpd2.png', 1816, 814, 500),
  h2("32.2 Durées de rétention"),
  p("Chaque table Neria a une durée de rétention configurée (par défaut : 36 mois pour les stats, 12 mois pour les logs techniques). Ces durées sont affichées dans le tableau de l'onglet RGPD."),
  img('image-rgpd3.png', 1813, 809, 500),
  h2("32.3 Purge des données"),
  step(1, "Pour purger les données d'un client spécifique : accédez à sa fiche dans PS → bouton Purger les données Neria. Toutes ses données dans les tables Neria sont supprimées."),
  step(2, "Pour une purge par ancienneté : dans RGPD → Purge automatique, cliquez sur Purger les données > N mois. Neria nettoie toutes les tables selon leurs durées de rétention respectives."),
  img('image-rgpd4.png', 1828, 818, 500),
  img('image-rgpd8.png', 1828, 534, 500),
  note("La purge individuelle est déclenchée automatiquement par le hook actionDeleteGDPRCustomer lors d'une suppression de compte depuis le module officiel RGPD PrestaShop."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 33. CHIFFREMENT AES-256-GCM ═══════════════════════
S.push(
  h1("33. Chiffrement des données sensibles"),
  p("Neria chiffre automatiquement l'ensemble des données sensibles stockées en base via AES-256-GCM — l'algorithme de chiffrement symétrique authentifié le plus robuste disponible en PHP. Le marchand en est informé dès l'ouverture du back-office via un bandeau de statut en haut de l'onglet Accueil."),
  img('image-crypto.png', 1698, 392, 500),
  p("En cas de problème (clé absente, OpenSSL indisponible), le bandeau passe au rouge avec le détail de l'anomalie :"),
  img('image-crypto2.png', 1699, 383, 500),
  h2("33.1 Ce qui est chiffré"),
  bullet("Clés API tierces : DeepL, Google PageSpeed, SEMrush, Moz (access ID + secret), Litmus, Email on Acid."),
  bullet("OAuth Google : client secrets, access tokens et refresh tokens — Postmaster Tools et Search Console."),
  bullet("Sécurité webhooks et bounces : secret HMAC-SHA256 des webhooks sortants, mot de passe IMAP de gestion des bounces."),
  bullet("Historique emails clients : champ rendered_vars — variables personnalisées de chaque email archivé (prénom, montant, références commande…)."),
  bullet("Données RGPD : exports d'audit générés par GdprAuditManager — données personnelles extraites pour les rapports de conformité."),
  h2("33.2 Fonctionnement"),
  bullet("La clé AES-256 est générée automatiquement à l'installation et stockée dans la configuration PrestaShop, hors base de données."),
  bullet("Un vecteur d'initialisation (IV) aléatoire est généré pour chaque enregistrement : un même message produit des chiffrés différents."),
  bullet("Chiffrement avant insertion, déchiffrement à la lecture — entièrement transparent pour les autres fonctionnalités."),
  h2("33.3 Surveillance par le Watchdog"),
  p("Le Watchdog exécute 4 contrôles distincts sur le chiffrement à chaque diagnostic :"),
  bullet("Test aller-retour : chiffre une chaîne aléatoire et la déchiffre immédiatement pour vérifier que la clé fonctionne."),
  bullet("Test sur données réelles : déchiffre un échantillon de secrets déjà stockés en base — détecte si la clé a été écrasée après une restauration."),
  bullet("Contrôle proactif : vérifie que chaque secret de la liste centralisée est bien chiffré et non stocké en clair."),
  bullet("Contrôle de cohérence : si OpenSSL est absent du serveur mais que des données chiffrées existent — alerte immédiate."),
  p("Si un problème est détecté, le bandeau de statut passe au rouge dans le back-office, et une alerte email est envoyée au marchand."),
  h2("33.4 Migration rétroactive"),
  p("Si la clé de chiffrement est configurée sur une installation existante, toutes les données en clair déjà présentes en base sont migrées automatiquement au premier démarrage. Aucune action manuelle n'est requise."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 34. WEBHOOKS SORTANTS ═════════════════════════════
S.push(
  h1("34. Onglet Webhooks — Intégrations tierces"),
  p("Neria peut notifier des applications tierces (CRM, Zapier, Make, n8n…) via HTTP POST signé HMAC-SHA256 lors de 5 événements clés."),
  img('image-webhook.png', 1807, 753, 500),
  h2("34.1 Événements disponibles"),
  bullet("email_sent : email envoyé avec succès."),
  bullet("email_opened : email ouvert par le destinataire."),
  bullet("conversion : achat attribué à un email Neria."),
  bullet("unsubscribed : client désabonné."),
  bullet("ab_winner : gagnant d'un test A/B désigné."),
  h2("34.2 Configurer un endpoint"),
  step(1, "Dans l'onglet Webhooks, cliquez sur Ajouter un endpoint."),
  step(2, "Saisissez l'URL cible (ex. : votre webhook Zapier ou Make)."),
  step(3, "Sélectionnez les événements à notifier."),
  step(4, "Copiez le secret HMAC affiché pour valider la signature côté récepteur."),
  step(5, "Cliquez sur Tester pour envoyer un événement de test."),
  note("En cas d'échec de livraison (timeout ou erreur HTTP), Neria effectue 3 tentatives automatiques avec délai exponentiel avant d'abandonner. Les échecs sont loggués dans le journal Watchdog."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 35. TÉMOIN SILENCIEUX ═════════════════════════════
S.push(
  h1("35. Témoin silencieux — Archivage BCC"),
  p("Le témoin silencieux envoie une copie BCC (Blind Carbon Copy) de chaque email sortant vers une adresse d'archive configurable. Utile pour l'audit interne, le contrôle qualité éditorial, et la traçabilité juridique des communications."),
  step(1, "Dans Accueil → Paramètres généraux, activez la bascule Témoin silencieux."),
  step(2, "Saisissez l'adresse email d'archive."),
  img('image-witness.png', 1816, 396, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 36. EMAIL DE SECOURS ══════════════════════════════
S.push(
  h1("36. Email de secours intelligent"),
  p("Si le rendu d'un email échoue pour une raison technique (template introuvable, erreur Smarty, variable manquante), Neria envoie automatiquement un email de secours élégant (template neria_fallback) à la place d'un silence ou d'un email natif PrestaShop dégradé."),
  p("L'incident est journalisé dans le Watchdog avec le contexte complet (template, langue, client, message d'erreur) pour correction ultérieure. Le client, lui, reçoit toujours un email soigné."),
  img('image-fallback.png', 1813, 667, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 37. MODE SILENCE ══════════════════════════════════
S.push(
  h1("37. Mode Silence — Anti-doublon"),
  p("Le Mode Silence empêche l'envoi d'un email identique au même client dans une fenêtre de temps configurable. Si un template a déjà été envoyé récemment au même destinataire, Neria bloque le second envoi automatiquement et le journalise."),
  step(1, "Dans Accueil → Paramètres généraux, activez Mode Silence."),
  step(2, "Configurez la fenêtre de protection globale (en minutes, de 1 à 60 — recommandé : 10 min)."),
  img('image-silence.png', 1804, 635, 500),
  note("Le Mode Silence ne bloque jamais les emails transactionnels critiques (confirmation de commande, réinitialisation de mot de passe). Il s'applique uniquement aux emails comportementaux et marketing."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 38. WATCHDOG ══════════════════════════════════════
S.push(
  h1("38. Onglet Aide — Watchdog & Diagnostics"),
  p("Neria embarque un système de surveillance interne (Watchdog) qui vérifie en continu plus de 100 points de contrôle et journalise tout dans neria_log. L'onglet Aide est le centre de contrôle de la santé du module."),
  img('image-aide.png', 1910, 860, 500),
  h2("38.1 Journal des événements"),
  p("Le journal affiche tous les événements avec trois niveaux de gravité :"),
  bullet("INFO — Fonctionnement normal, envois confirmés, purges effectuées."),
  bullet("WARNING — Point à surveiller, sans impact immédiat (ex. : score délivrabilité en baisse)."),
  bullet("ERROR — Anomalie à corriger (ex. : échec d'envoi, erreur de rendu)."),
  img('image-aide2.png', 1910, 863, 500),
  img('image-aide3.png', 1903, 852, 500),
  h2("38.2 Alertes automatiques"),
  bullet("Toute erreur de niveau ERROR déclenche un email d'alerte immédiat au marchand."),
  bullet("Les avertissements WARNING sont regroupés dans un digest quotidien pour éviter la saturation de la boîte mail."),
  img('image-aide4.png', 1906, 850, 500),
  h2("38.3 Auto-réparation"),
  p("Certaines anomalies mineures — scores comportementaux non recalculés, segmentation obsolète, cache de traduction expiré — sont corrigées automatiquement par le Watchdog sans intervention. Ces corrections sont mentionnées dans le journal avec la mention « autocorrigé »."),
  img('image-aide5.png', 1907, 861, 500),
  h2("38.4 Diagnostic complet"),
  step(1, "Dans l'onglet Aide, cliquez sur Lancer le diagnostic complet."),
  step(2, "Neria exécute les 76+ contrôles de santé et affiche un rapport détaillé : hooks enregistrés, état des tables, licences, cron, configuration."),
  img('image-aide6.png', 1910, 856, 500),
  img('image-aide7.png', 1904, 852, 500),
  h2("38.5 Page d'urgence"),
  p("En cas de plantage critique du back-office PrestaShop, une page de diagnostic autonome est disponible à l'URL : https://votre-boutique.com/modules/neria/neria-emergency.php?token=VOTRE_TOKEN. Elle fonctionne indépendamment de PrestaShop."),
  step(1, "Récupérez le token d'urgence dans Aide → Token d'urgence."),
  step(2, "Accédez à l'URL ci-dessus avec votre token."),
  step(3, "La page affiche l'état du module, les dernières erreurs, et des boutons de réparation rapide."),
  img('image-aide8.png', 1910, 853, 500),
  img('image-aide9.png', 1910, 863, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 39. CENTRE DE CONTRÔLE ════════════════════════════
S.push(
  h1("39. Centre de contrôle"),
  p("Le Centre de contrôle permet au marchand d'afficher ou masquer les liens de chaque fonctionnalité dans le menu Neria, sans modifier leur état actif/inactif réel. Utile pour simplifier le menu si certaines fonctionnalités avancées ne sont pas utilisées."),
  img('image-ctrl.png', 1910, 854, 500),
  step(1, "Pour chaque fonctionnalité, la pastille de couleur indique son statut réel (vert = actif, rouge = inactif)."),
  step(2, "Le bouton bascule à droite contrôle la visibilité du lien dans le menu (indépendamment du statut)."),
  step(3, "Masquer un lien ne désactive pas la fonctionnalité — elle continue de fonctionner en arrière-plan."),
  img('image-ctrl2.png', 1909, 861, 500),
  img('image-ctrl3.png', 1910, 861, 500),
  img('image-ctrl4.png', 1910, 864, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 40. NERIA ACADEMY ══════════════════════════════════
S.push(
  h1("40. Neria Academy"),
  p("L'onglet Academy met à disposition des guides de formation directement intégrés dans le back-office, dans la langue du marchand. Huit guides sont disponibles à l'installation."),
  img('image-academy.png', 1909, 856, 500),
  bullet("Guide 1 : Pourquoi mon taux d'ouverture baisse — 8 causes • diagnostic • solutions Neria."),
  bullet("Guide 2 : Écrire un objet qui performe — 5 règles • exemples avant/après • mots à éviter."),
  bullet("Guide 3 : RGPD & emails : l'essentiel — 3 bases légales • obligations • checklist complète."),
  bullet("Guide 4 : Pourquoi mes emails finissent en spam — 4 piliers • authentification • réputation • contenu."),
  bullet("Guide 5 : Utiliser la segmentation comportementale — 5 segments • ciblage • stratégies par profil."),
  bullet("Guide 6 : Fidélité et upsell : augmenter la valeur client — 3 paliers • points • upsell post-achat."),
  bullet("Guide 7 : A/B Testing : tester sans se tromper — 4 règles • seuils statistiques • ce qui vaut la peine d'être testé."),
  bullet("Guide 8 : Récupérer un panier sans agacer — 3 relances • cadence 1h/24h/72h • arrêt automatique."),
  step(1, "Cliquez sur un guide pour l'ouvrir en pleine page dans le BO."),
  step(2, "Chaque guide est disponible dans les 19 langues du module."),
  img('image-academy2.png', 1910, 863, 500),
  img('image-academy3.png', 1910, 860, 500),
  img('image-academy4.png', 1910, 866, 500),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 41. CATALOGUE DES TEMPLATES ════════════════════════
S.push(
  h1("41. Catalogue des 117 templates email"),
  p("Neria inclut 117 templates HTML + TXT dans 19 langues, organisés en 7 catégories."),
  h2("41.1 Emails transactionnels PrestaShop (redesignés)"),
  bullet("order_conf, payment, shipped, delivered, in_transit, password, newsletter, account, guest_tracking_info, return_slip, log_alert, reply_msg, et tous les standards PrestaShop."),
  h2("41.2 Emails comportementaux"),
  bullet("birthday, first_anniversary, relationship_anniversary, abandoned_cart_1/2/3, checkout_abandonment, post_purchase_care, post_purchase_review, reorder_reminder, win_back, ghost_cart, order_shipped_delay, milestone_order, refund_processed, return_received, refund_reconciliation_1/2/3."),
  h2("41.3 Emails luxe & expérience"),
  bullet("artisan_message, bespoke_ready, care_certificate, certificate_provenance, concierge_followup, craftsmanship_update, personal_shopper_intro, private_invitation, unboxing_guide, white_glove_apology, alteration_update, repair_completed, repair_request_confirm, packaging_choice."),
  h2("41.4 Emails fidélité"),
  bullet("loyalty_tier_upgrade, loyalty_recap, loyalty_reward_expiry, look_completion, collection_completion, waitlist_available, wishlist_reminder."),
  h2("41.5 Emails calendaires"),
  bullet("christmas, new_year, valentine, mothers_day, fathers_day, grandparents_day, halloween, black_friday, eid_al_fitr, eid_al_adha, ramadan, diwali, lunar_new_year, hanukkah, easter, labor_day, womens_day, et variantes."),
  h2("41.6 Emails B2B"),
  bullet("corporate_order_confirm, quote_expiry_48h, quote_expiry_day, quote_extension_offer, product_lifespan_reminder."),
  h2("41.7 Emails système Neria"),
  bullet("neria_fallback (email de secours), log_alert (alerte Watchdog), monthly_report_merchant, vip, early_access, exclusive_preview, private_sale, customs_alert, delivery_attempt_failed, extended_warranty, gift_guarantee, tax_refund_eligible."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 42. FAQ ════════════════════════════════════════════
S.push(
  h1("42. Questions fréquentes"),
  h2("Les emails ne partent pas — que vérifier ?"),
  bullet("Onglet Aide → Journal Watchdog : une erreur y est toujours journalisée en cas d'échec."),
  bullet("Vérifiez que votre licence est active (bannière verte dans n'importe quel onglet Neria)."),
  bullet("Vérifiez la configuration SMTP de PrestaShop (Paramètres avancés → Email) : Neria n'envoie pas lui-même, il s'appuie sur le mécanisme d'envoi natif de PS."),
  bullet("Testez l'envoi depuis Paramètres avancés → Email → Envoyer un email de test."),
  h2("Puis-je personnaliser le texte d'un email précis ?"),
  p("Oui, depuis l'onglet Traductions : chaque template est éditable indépendamment, dans chacune des 19 langues, avec un aperçu live avant sauvegarde."),
  h2("Un template ne me convient pas — puis-je le désactiver ?"),
  p("Oui, via la fonction Blacklist de templates dans l'onglet Accueil. Neria laisse alors PrestaShop gérer cet envoi avec son rendu natif."),
  h2("Comment personnaliser l'heure d'envoi des automatisations ?"),
  p("Configurez l'heure d'exécution de votre cron serveur. Les recommandations Golden Hour (onglet Statistiques) indiquent la meilleure heure pour vos clients."),
  h2("Le module ralentit-il ma boutique ?"),
  p("Non : le rendu des emails est mis en cache côté base de données et régénéré uniquement lors d'un envoi réel ou d'un changement de configuration. Les automatisations tournent en tâche de fond via cron, jamais pendant la navigation d'un visiteur."),
  h2("Comment obtenir de l'aide ?"),
  p("Via l'email support@neriasoftware.com (réponse sous 24h ouvrées) ou via PrestaShop Addons → Votre compte → Aide → Neria. Précisez la version du module (visible dans Modules → Neria) et joignez un extrait du journal Watchdog si disponible."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ 43. SUPPORT & BUSINESS CARE ═══════════════════════
S.push(
  h1("43. Support & Business Care"),
  h2("43.1 Support technique"),
  bullet("Email : support@neriasoftware.com"),
  bullet("Délai de réponse garanti : 24 heures ouvrées (du lundi au vendredi)."),
  bullet("Langues : français et anglais."),
  h2("43.2 Business Care — 12 mois inclus"),
  p("Chaque licence Neria inclut 12 mois de Business Care couvrant :"),
  bullet("Mises à jour du module (nouvelles fonctionnalités, correctifs de sécurité, compatibilité nouvelles versions PS)."),
  bullet("Assistance technique par email pour les questions d'installation, de configuration et d'utilisation."),
  bullet("Mise à jour de la base de traductions (nouvelles langues ou corrections linguistiques)."),
  h2("43.3 Renouvellement"),
  p("Après 12 mois, le module continue de fonctionner indéfiniment avec la version installée. Le renouvellement du Business Care est optionnel et permet de continuer à recevoir les mises à jour et le support."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ ANNEXE A — MENTIONS LÉGALES ════════════════════════
S.push(
  h1("Annexe A — Mentions légales"),
  h2("A.1 Licence du module"),
  p("Neria — Luxury Email Suite est distribué sous licence Academic Free License 3.0 (AFL-3.0), conformément aux exigences de PrestaShop Addons."),
  h2("A.2 Droits d'auteur"),
  p("© 2026 Neriasoftware — Tous droits réservés. Le code source, les templates email, les traductions et la documentation de ce module sont la propriété exclusive de leur auteur. Toute reproduction, distribution ou utilisation non autorisée est interdite."),
  h2("A.3 Polices tierces utilisées"),
  bullet("Cormorant Garamond, EB Garamond, Lato, Open Sans, Noto Serif JP/KR/SC/TC — Google Fonts, licence SIL Open Font License."),
  bullet("Dancing Script, Great Vibes, Sacramento, Pinyon Script — Google Fonts, licence SIL Open Font License."),
  h2("A.4 Bibliothèques tierces"),
  bullet("TCPDF — Nicola Asuni, licence LGPL 2.1+ (génération des certificats PDF)."),
  bullet("Les autres dépendances éventuelles sont listées dans le fichier composer.json du module."),
  h2("A.5 Données personnelles"),
  p("Pour la politique complète de traitement des données personnelles, consultez la page dédiée sur neriasoftware.com/privacy."),
  new Paragraph({ children: [new PageBreak()] })
);

// ══════════════════════════ GÉNÉRATION DU DOCUMENT ════════════════════════════

const doc = new Document({
  numbering: {
    config: [{
      reference: "puces",
      levels: [{ level: 0, format: LevelFormat.BULLET, text: "•", alignment: AlignmentType.LEFT,
        style: { paragraph: { indent: { left: convertInchesToTwip(0.35), hanging: convertInchesToTwip(0.2) } } } }],
    }],
  },
  styles: {
    default: { document: { run: { font: "Calibri", size: 22 } } },
    paragraphStyles: [
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 34, bold: true, color: DARK, font: "Calibri" },
        paragraph: { spacing: { before: 500, after: 200 }, outlineLevel: 0,
          border: { bottom: { style: BorderStyle.SINGLE, size: 3, color: ACCENT } } } },
      { id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 26, bold: true, color: ACCENT, font: "Calibri" },
        paragraph: { spacing: { before: 300, after: 150 }, outlineLevel: 1 } },
      { id: "Heading3", name: "Heading 3", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 23, bold: true, italics: true, color: DARK, font: "Calibri" },
        paragraph: { spacing: { before: 200, after: 100 }, outlineLevel: 2 } },
    ],
  },
  sections: [{
    properties: {
      page: { size: { width: 11906, height: 16838 } },
    },
    headers: { default: pageHeader },
    footers: { default: pageFooter },
    children: S,
  }],
});

Packer.toBuffer(doc).then((buffer) => {
  fs.writeFileSync(path.join(__dirname, "Neria_Notice_Utilisation.docx"), buffer);
  console.log("✓ Notice générée : Neria_Notice_Utilisation.docx");
});
