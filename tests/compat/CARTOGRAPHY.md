# Cartographie de compatibilité PS8 → PS9

Liste exhaustive des axes sur lesquels PrestaShop 8 et PrestaShop 9 peuvent
différer et casser silencieusement Neria. Chaque axe a une méthode de
vérification dédiée et un statut. Objectif : ne plus découvrir de bug de
compatibilité au hasard, mais par couverture méthodique.

## État global

| # | Axe | Statut | Méthode |
|---|-----|--------|---------|
| 1 | Appels statiques du cœur PHP | ✅ Fait (2026-07-19) | `ps_core_diff.php` |
| 2 | Objets reçus en paramètre de hook | 🟡 Partiel (1 cas connu) | À construire |
| 3 | Schéma SQL des tables core | ✅ Fait (2026-07-19) | `ps_schema_diff.php` |
| 4 | Existence/enregistrement des hooks | ✅ Fait (2026-07-19) | `neria_hooks_check.php` (ponctuel) |
| 5 | Rendu BO (Smarty vs Twig) | 🟡 Vérifié empiriquement, pas formalisé | Checklist visuelle |
| 6 | Système de traduction cœur | 🟢 Hors risque (Neria a son propre système) | — |
| 7 | ObjectModel / ORM | ⬜ À faire (si Neria étend des classes core) | Vérifier héritages |
| 8 | ACL / permissions employé | ⬜ À faire | Vérifier `Profile`/`AdminController::viewAccess` |
| 9 | Cron / tâches planifiées | 🟢 Testé réel sur PS9 | — |
| 10 | Multi-boutique (`Shop::`) | 🟢 Couvert par axe 1 | — |
| 11 | Dépendances vendor (TCPDF, etc.) | 🟢 Testé réel sur PS9 | — |
| 12 | Typage PHP strict / fonctions dépréciées | 🟡 Partiel (trouvé via axe 1) | Continu |
| 13 | Contrôleurs front/admin (signatures de base) | ⬜ À faire | Vérifier `ModuleFrontController`, `ModuleAdminController` |
| 14 | Réseau sortant (SMTP, HTTP, DNS) | 🟢 Testé réel (webhooks OK, SMTP bloqué par pare-feu hébergeur, pas un bug Neria) | — |

---

## Détail par axe

### 1. Appels statiques du cœur PHP — ✅ Fait

56 appels (`Tools::`, `Db::`, `Mail::`, `Configuration::`, etc.) comparés par
réflexion entre PS8 8.1.7 et PS9 9.0.2. 1 différence trouvée
(`Tools::displayPrice` supprimée), déjà corrigée.
Outil : `ps_core_diff.php`. Détail : `README.md`.

### 2. Objets reçus en paramètre de hook — 🟡 Partiel

**Risque** : un hook garde le même nom et se déclenche toujours, mais
l'objet qu'il transmet change de classe ou de forme de retour sur ses
méthodes (cas réel trouvé : `actionMailAlterMessageBeforeSend` passe un
`Symfony\Component\Mime\Email` sur PS9 au lieu d'un `Swift_Message`, mêmes
noms de méthode `getTo()`/`getHeaders()` mais forme de retour différente).

**Méthode à construire** : lister tous les hooks que Neria implémente
(`grep -n "public function hook" neria.php`), puis pour chacun, sur PS9
réel, logger temporairement `get_class($param)` pour chaque paramètre objet
reçu et comparer à ce qui est supposé dans le code. Prioriser les hooks
recevant des objets du cœur (Mail, Order, Customer, Cart) plutôt que des
scalaires/tableaux simples.

### 3. Schéma SQL des tables core — ✅ Fait

**Risque** : Neria interroge directement en SQL brut des tables core
(`order_detail.product_id`, `orders.valid`, `customer.id_lang`, etc., déjà
un piège connu documenté dans les mémoires du projet). Une colonne
renommée/supprimée entre PS8 et PS9 casse la requête sans passer par aucune
méthode PHP scannée à l'axe 1.

**Résultat (2026-07-19)** : 12 tables core comparées (`accessory`,
`category`, `category_lang`, `category_product`, `customer`, `image`,
`order_detail`, `order_history`, `orders`, `product`, `product_lang`,
`stock_available`) entre PS8 8.1.7 et PS9 9.0.2 via `ps_schema_diff.php`.
Différences trouvées : `category_lang.meta_keywords` et
`product_lang.meta_keywords` supprimées sur PS9 ; `product.ean13` et
`orders.reference` élargies ; `accessory` gagne une clé primaire composite
(était un simple index) ; `category` gagne 2 colonnes (`redirect_type`,
`id_type_redirected`). **Aucune n'affecte Neria** — colonnes supprimées
jamais lues/écrites dans le code (vérifié par grep), reste purement additif
ou cosmétique (élargissement de colonne, index renforcé).

Outil : `ps_schema_diff.php`. Méthode : `grep -rhoE '\{\$this->prefix\}[a-z_]+' src/ | sort -u` (+ variante avec `.$this->prefix.'...'`) pour lister les tables, puis diff `SHOW COLUMNS` entre les deux versions.

### 4. Existence/enregistrement des hooks — ✅ Fait (partiel — timing non couvert)

**Risque** : un hook utilisé par Neria est renommé, supprimé côté cœur PS,
ou son `registerHook()` échoue silencieusement à l'installation
(protection déjà présente dans `neria.php:198-200` : les échecs sont
ignorés pour rester compatible entre versions, mais ça masque aussi un
hook qui ne serait plus reconnu).

**Résultat (2026-07-19)** : les 14 hooks de `self::HOOKS` (neria.php:141)
vérifiés un par un sur PS8 8.1.7 et PS9 9.0.2 réels via
`Hook::getIdByName()` + requête sur `ps_hook_module` — **les 14 sont
reconnus par le cœur ET effectivement enregistrés pour le module Neria sur
les deux versions**. Aucune perte silencieuse.

**Non couvert** (reste à faire si besoin) : le **timing réel** de
déclenchement (ex. `actionOrderStatusPostUpdate` toujours appelé après la
mise à jour effective du statut, pas avant) — seul un test de scénario réel
en conditions de production peut le confirmer, pas une simple vérification
d'existence. Plusieurs scénarios (changement de statut, avoir, retour) ont
déjà été testés en conditions réelles lors des vagues de test précédentes
(voir tâches complétées #9 OrderTriggersManager).

### 5. Rendu BO (Smarty vs Twig) — 🟡 Vérifié empiriquement

Tous les onglets BO de Neria chargent sans erreur sur PS9 réel (vérifié
lors de l'installation). Pas de checklist visuelle formalisée par écran.

**Méthode à construire** (optionnelle, priorité basse) : capture d'écran de
chaque onglet BO sur PS8 et PS9, comparaison visuelle manuelle.

### 7. ObjectModel / ORM — ⬜ À faire

**Risque** : si une classe Neria étend `ObjectModel` (à vérifier — les
Managers actuels utilisent `Db` directement, pas `ObjectModel`), un
changement de signature dans les méthodes `ObjectModel::add/update/save`
casserait silencieusement.

**Méthode** : `grep -rn "extends.*ObjectModel" src/` — si aucun résultat,
cet axe est sans objet et passe directement en 🟢.

### 8. ACL / permissions employé — ⬜ À faire

**Risque** : les onglets BO de Neria déclarent des droits d'accès
(`AdminController::viewAccess`, tables `ps_authorization_role`,
`ps_module_access`). Si PS9 a changé le système de permissions par défaut,
un employé pourrait perdre l'accès aux pages Neria après upgrade.

**Méthode** : vérifier manuellement sur PS9 réel qu'un profil employé
non-SuperAdmin a bien accès aux onglets Neria après installation.

### 13. Contrôleurs front/admin (signatures de base) — ⬜ À faire

**Risque** : `controllers/front/track.php` étend `ModuleFrontController`.
Si les méthodes `initContent()`, `postProcess()`, ou les propriétés
attendues (`$context`, `$module`) changent de signature sur PS9, le
contrôleur front casse.

**Méthode** : même principe que l'axe 1, mais sur les classes
`ModuleFrontController` / `ModuleAdminController` plutôt que sur les
classes utilitaires statiques.

---

## Prochaine session

Prioriser dans l'ordre : axe 3 (schéma SQL — le plus mécanisable et le plus
dangereux, requêtes silencieusement cassées), puis axe 2 (hooks — déjà 1 bug
réel trouvé, probable qu'il y en ait d'autres), puis axes 4/13 (contrôleurs
et déclenchement des hooks).
