# Smoke test global — couverture automatique par réflexion

Répond à une note laissée de côté : *"il faudrait un test de smoke
automatique qui appelle chaque méthode critique avec des données fictives
et vérifie qu'aucune requête SQL ne plante."*

## Ce que ça fait

`smoke_test.php` scanne tous les Managers de `src/`, instancie chacun, et
appelle automatiquement (via réflexion PHP) toute méthode publique dont le
nom commence par `get`, `list`, `count`, `audit`, `is`, `has` ou `find`
**et** ne contient aucun verbe d'écriture (`send`, `delete`, `save`,
`update`, `trigger`, etc.) **et** n'a aucun paramètre obligatoire.

Toute exception (SQL cassé, colonne renommée, jointure invalide) est
capturée et signalée avec la classe, la méthode et le message exact.

## Usage

```bash
# Copier à la racine de l'installation PS, puis :
php smoke_test.php /chemin/vers/prestashop
```

Supprimer le fichier du serveur après usage.

## Résultat de référence (2026-07-19)

Testé identique sur PS8 (Laragon) et PS9 réel (melleina.com) :
**120 méthodes appelées, 120 OK, 0 échec.**

## Limites connues

- Ne couvre que les méthodes **sans paramètre obligatoire** — les méthodes
  nécessitant un ID réel (`getCustomerScore(int $id)`) ne sont pas
  appelées. Restent couvertes par les tests manuels ciblés
  (`tests/regression/`).
- Piège rencontré en le construisant : `class_exists('index')` avec
  autoload activé (comportement par défaut) exécute le stub anti-listing
  `src/index.php` (qui contient un `die()`), tuant le script silencieusement
  sans aucune erreur visible — d'où le skip explicite du nom de fichier
  `index` avant tout appel à `class_exists()`.
- Écrire `get*/list*` dans un commentaire PHP `/* */` casse le
  commentaire : la séquence `*/` le referme prématurément, peu importe le
  contexte texte autour.

## À faire évoluer

Rejouer avant chaque packaging Addons. Étendre avec des ID réels
plausibles (ex. un `id_customer`/`id_order` de test) pour couvrir aussi
les méthodes à paramètre obligatoire, en restant strictement en lecture.
