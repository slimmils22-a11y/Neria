# Scan de compatibilité cœur PrestaShop

Méthode pour détecter, avant tout bug rapporté par un client, les appels au
cœur PrestaShop cassés par une montée de version (ex. PS8 → PS9).

## Pourquoi

Une montée de version majeure de PrestaShop peut supprimer, renommer ou
changer la signature d'une méthode core sans que Neria en soit averti — le
code continue de s'exécuter, parfois sans erreur visible, jusqu'à ce qu'un
client tombe sur le cas cassé en production. Le bug `Tools::displayPrice()`
supprimée sur PS9 (2026-07-18) en est l'exemple direct : cassait tous les
emails affichant un prix, sans qu'aucun log ne le signale.

## Méthode

1. **Lister les points de contact réels** : `ps_core_diff.php` contient la
   liste `$pairs` de tous les appels statiques `Classe::méthode` du cœur PS
   que Neria utilise, extraite par grep sur tout le code source (voir
   commande dans l'en-tête du fichier).

2. **Exécuter le scan sur les deux versions à comparer** :
   - Copier `ps_core_diff.php` à la racine de l'installation PS (à côté de
     `config/`).
   - L'appeler en HTTP : `https://boutique/ps_core_diff.php`.
   - Sauvegarder la sortie dans un fichier.
   - **Supprimer immédiatement le fichier du serveur** (ne jamais le laisser
     accessible publiquement).
   - Répéter sur l'autre version (ex. PS8 sur Laragon, PS9 en réel).

3. **Comparer** les deux sorties avec `diff`. Toute ligne différente
   (`MISSING_CLASS`, `MISSING_METHOD`, `MISSING_PROP`, `MISSING_CONST`, ou
   signature de paramètres différente) est un point à vérifier avant de
   déclarer Neria compatible.

## Limite connue

Ce scan couvre uniquement les **appels statiques directs**. Il ne détecte
**pas** les incompatibilités liées aux **objets reçus en paramètre de hook**
— ex. le bug List-Unsubscribe (2026-07-18) : PS9 passe un
`Symfony\Component\Mime\Email` au lieu d'un `Swift_Message` dans
`actionMailAlterMessageBeforeSend`. Les noms de méthode (`getTo()`,
`getHeaders()`) existent sur les deux classes, donc un simple
`method_exists()` ne voit rien — seule la **forme du retour** diffère
(tableau numéroté d'objets `Address` vs tableau associatif `email => nom`).

Pour cette famille de bugs : identifier tous les hooks qui reçoivent un
objet du cœur PS en paramètre, et vérifier manuellement le vrai type/forme
reçu sur la nouvelle version (via `get_class($param)` + inspection réelle
des données retournées, pas juste `method_exists()`).

## Maintenir la liste à jour

Après tout ajout d'un nouvel appel core dans le code Neria, régénérer
`$pairs` avec :

```bash
grep -rhoE '\b(Tools|Db|Mail|Order|Customer|Context|Validate|Configuration|Hook|Product|Currency|Cart|CartRule|Language|Shop|StockAvailable|ImageType|Address|Country|State|Employee|ObjectModel|Translate|Tag|Category|Manufacturer|Combination|Attribute|Feature|Carrier|Module|Cookie|Link|Media|Warehouse|Supplier|SpecificPrice|TaxRulesGroup|FileLogger|PrestaShopLogger)::[A-Za-z_]+' src/ neria.php controllers/ upgrade/ | sort -u
```

(le `\b` avant le nom de classe est nécessaire pour exclure les faux
positifs comme `NeriaTools::` qui contient `Tools::` en sous-chaîne.)

## Historique des scans

| Date       | PS comparées      | Différences trouvées                                                  |
|------------|--------------------|-------------------------------------------------------------------------|
| 2026-07-19 | PS8 8.1.7 vs PS9 9.0.2 | 1 seule : `Tools::displayPrice` supprimée sur PS9 (déjà corrigée, commit `045a438`, remplacée par `NeriaTools::displayPrice()`) |
