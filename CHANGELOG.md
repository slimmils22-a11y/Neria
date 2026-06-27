# Changelog — Neria Luxury Email Suite

Toutes les modifications notables de ce module sont documentées ici.
Format : [Semantic Versioning](https://semver.org/lang/fr/)

---

## [1.0.11] — 2026-06-25

### Ajouté
- **Panier fantôme récurrent** : détecte les clients qui ajoutent le même produit 3 fois ou plus sans jamais acheter et leur envoie un email d'ouverture de dialogue personnalisé (template `ghost_cart`). Déduplication automatique par client et par produit.

### Technique
- Nouvelle clé de configuration `NERIA_GHOST_CART_ENABLED`
- Rechargement complet des traductions depuis `translations.json` (fix clés absentes sur installs existantes)

---

## [1.0.10] — 2026-06-25

### Ajouté
- **Liste d'attente produits** : les clients peuvent s'inscrire sur liste d'attente pour un produit en rupture de stock. Neria les notifie automatiquement dès le retour en stock (template `waitlist_available`).
- Durée de réservation prioritaire configurable (1–72 h) avec mention psychologique dans l'email.

### Technique
- Nouvelle table `neria_waitlist` (TABLE 28)
- Nouvelle clé de configuration `NERIA_WAITLIST_ENABLED`
- Endpoint front `/module/neria/waitlist` (inscription via lien GET)

---

## [1.0.9] — 2026-06-24

### Ajouté
- **Complétez votre look** : 48 h après la livraison, suggère 1 à 3 produits complémentaires selon des règles définies par catégorie (template `complete_your_look`). Déduplication par commande.

### Technique
- Nouvelles tables `neria_look_rule` et `neria_look_sent`

---

## [1.0.8] — 2026-06-24

### Ajouté
- **Complétion de collection** : détecte les clients à une pièce de compléter une collection et leur envoie un email personnalisé avec le produit manquant (template `collection_completion`). Un seul email par client par collection.

### Technique
- Nouvelles tables `neria_collection` et `neria_collection_sent`

---

## [1.0.7] — 2026-06-23

### Ajouté
- **Mode cadeau sur les campagnes saisonnières** : nouvelle option `gift_mode` permettant de basculer une campagne saisonnière en ton "offrir" avec ciblage des segments fidèles (templates `gift_ideas`).

### Technique
- Nouvelle colonne `gift_mode` sur `neria_seasonal_campaign`

---

## [1.0.6] — 2026-06-24

### Ajouté
- **Fenêtre d'achat individuelle** : Neria détecte l'heure naturelle d'achat de chaque client et programme automatiquement les emails comportementaux pour arriver dans cette fenêtre. File d'attente avec retry automatique.

### Technique
- Nouvelle table `neria_queue` (TABLE 27)
- Nouvelle clé de configuration `NERIA_PURCHASE_WINDOW_ENABLED`

---

## [1.0.5] — 2026-06-23

### Ajouté
- **Anniversaire de la relation client** : chaque année, à la date exacte du premier achat, le client reçoit un email personnalisé adapté à l'ancienneté ("Il y a un an…", "Il y a deux ans…") dans sa langue (template `relationship_anniversary`).

---

## [1.0.4] — 2026-06-23

### Ajouté
- **Score de propension à l'achat** : calcul quotidien d'un score 0–100 par client basé sur 4 facteurs (récence, fréquence, engagement email, saisonnalité). Affiché dans l'onglet Segments avec bouton "Envoyer offre" pour les clients à score ≥ 75.

### Technique
- Nouvelle table `neria_propensity_score` (TABLE 26)

---

## [1.0.3] — 2026-06-22

### Ajouté
- **Rappel fin de vie produit** : associez une durée de vie estimée à vos produits consommables. Neria envoie automatiquement un email de rappel X jours avant l'épuisement estimé (template `product_lifespan_reminder`).

### Technique
- Nouvelle table `neria_product_lifespan` (TABLE 25)
- Nouvelle clé de configuration `NERIA_LIFESPAN_ENABLED`

---

## [1.0.2] — 2026-06-22

### Ajouté
- **Réconciliation post-remboursement** : séquence automatique de 3 emails discrets (J+1, J+3, J+7) pour reconquérir les clients remboursés. Annulée automatiquement si le client passe une nouvelle commande (templates `refund_reconciliation_1/2/3`).

### Technique
- Nouvelle table `neria_reconciliation` (TABLE 24)
- Nouvelle clé de configuration `NERIA_REFUND_RECONCILIATION_ENABLED`

---

## [1.0.1] — 2026-06-23

### Ajouté
- **Relances Devis B2B** : suivi manuel des devis avec 3 emails automatiques (rappel 48 h avant expiration, jour J, offre de prolongation). Marquage "Gagné" pour arrêter la séquence (templates `quote_expiry_48h`, `quote_expiry_day`, `quote_extension_offer`).

### Technique
- Nouvelle table `neria_quote` (TABLE 23)
- Nouvelle clé de configuration `NERIA_QUOTE_REMINDERS_ENABLED`
- Import des traductions `checkout_abandonment` manquantes sur installs existantes

---

## [1.0.0] — 2026-06-01

### Version initiale

**Templates email (111 templates × 18 langues)**
- Confirmation de commande, expédition, livraison, facture
- Relances panier abandonné (3 niveaux)
- Emails post-achat (avis, upsell, message artisan)
- Emails fidélité (paliers Bronze/Argent/Or, récapitulatif mensuel)
- Emails comportementaux (anniversaire, win-back, réapprovisionnement, VIP, vente privée…)
- Emails transactionnels (changement mot de passe, confirmation newsletter, retour produit…)
- Emails saisonniers (Noël, Aïd, Diwali, Halloween, Saint-Valentin, Ramadan, Nouvel An lunaire…)
- Emails spéciaux (rappel produit, parrainage, certificat d'authenticité, rapport mensuel)

**Fonctionnalités BO**
- Design personnalisable (couleurs, logo, mise en page, mode sombre)
- Typographie par famille de langues (Latin, Arabe, Japonais, Coréen, Chinois)
- Traductions éditables par template et par langue (18 langues)
- Envoi manuel avec ciblage avancé (segment, langue, pays, CLV, tranche horaire)
- Prévisualisation multi-client (Gmail, Outlook, Apple Mail, Orange, Yahoo, mobile)
- A/B Testing sur l'objet de l'email
- Statistiques complètes (ouvertures, clics, revenus attribués, heure d'or, abandon de caisse)
- Score de délivrabilité (8 critères anti-spam, analyse HTML/TXT)
- Réputation de domaine (SPF/DKIM/DMARC + 42 listes noires RBL)
- Détection des bounces (IMAP + webhook ESP)
- Segmentation comportementale (5 segments : Ambassador → Ghost)
- Programme de fidélité par email (points ouverture/clic/achat, CartRule PS)
- Campagnes saisonnières avec calendrier (CRUD + 12 occasions)
- Webhooks sortants (5 événements, retry 3×, HMAC-SHA256)
- Audit RGPD automatique (score, purge par table, rapport PDF)
- Chiffrement AES-256-GCM des données sensibles au repos
- Certificat d'authenticité (PDF TCPDF, QR code optionnel)
- Score CLV 12 mois (formule transparente, Top 20 clients)
- Multi-expéditeur par langue (nom + adresse par langue)
- Empreinte carbone dynamique dans le footer
- Mode Silence anti-doublon
- Détection automatique de la langue client
- Watchdog interne (4 niveaux, rétention 30j, alertes email)
- Page d'urgence autonome (12 contrôles santé, token secret)
- Académie intégrée (3 guides : ouverture, objet, RGPD)
- Historique emails par client (fiche client + onglet BO)
- Réseaux sociaux (Instagram, Pinterest, Facebook, X, YouTube, TikTok)
- Rapport mensuel automatique (Top 5 templates, taux, recommandations)
- Blacklist de templates par langue
