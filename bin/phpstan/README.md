# Analyse statique PHPStan — Neria

Mise en place le 05/08/2026 suite à la découverte répétée de bugs
"syntaxiquement valides mais sémantiquement cassés" (voir mémoire
`feedback_diff_review_before_round_close`). Complète les tests de
régression (`tests/regression/`, protection à l'exécution réelle) par une
protection **avant même l'exécution** : types incohérents, appels de
méthode/propriété inexistants, comparaisons toujours vraies/fausses.

## Installation

`phpstan.phar` (28 Mo) n'est pas versionné (voir `.gitignore`) — télécharger
une fois :

```powershell
curl -sL -o bin\phpstan\phpstan.phar https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar
```

## Usage

```powershell
powershell -File bin\phpstan\run.ps1
```

Code de sortie 0 si aucune NOUVELLE erreur, 1 sinon. La baseline
(`phpstan-baseline.neon`) fige le bruit déjà connu au moment de la mise en
place (116 avertissements, essentiellement du bruit propre à l'architecture
PrestaShop — voir plus bas) : seules les erreurs apparues APRÈS ce point
sont remontées.

À rejouer avant chaque packaging, et idéalement à la fin de chaque round de
la série "chasse aux bugs" (voir `[[project_bug_hunt_series_status]]` en
mémoire) sur les fichiers modifiés ce jour-là.

## Configuration

- `phpstan.neon` : niveau 5 (pragmatique — attrape les vraies erreurs de
  type/logique sans le bruit extrême du niveau max sur un framework aussi
  dynamique que PrestaShop).
- `phpstan-bootstrap.php` : constantes PrestaShop (`_PS_MODULE_DIR_`,
  `_DB_PREFIX_`...) et fonction globale `pSQL()`, indéfinies statiquement.
- `bin/phpstan/ps-alias-stubs.php` : PrestaShop définit ses classes réelles
  (`Tools`, `Db`, `Configuration`...) comme des ALIAS dynamiques de classes
  `XxxCore` au moment de l'exécution (jamais de fichier PHP statique
  `class Tools {}`) — sans ce stub généré, PHPStan ne voit aucune de ces
  classes. Régénérer si PrestaShop est mis à jour :
  ```
  php bin\phpstan\generate-ps-stubs.php C:\laragon\www\shop\var\cache\prod\class_index.php bin\phpstan\ps-alias-stubs.php
  ```

## Pourquoi 116 avertissements dans la baseline (pas 0)

Presque tous relèvent du même faux positif structurel : les classes
`Manager` du module type-hintent leur constructeur `Neria $module` (précis),
mais les appelants passent `$this->module` depuis un contrôleur PrestaShop
où cette propriété est déclarée `Module` (classe parente, par le cœur
PrestaShop) — à l'exécution c'est TOUJOURS une instance `Neria` réelle,
mais PHPStan ne peut pas le prouver statiquement sans covariance de
propriété. Vérifié cas par cas le 05/08/2026 : aucun n'est un vrai bug.

**Un vrai bug a été trouvé et corrigé dès la mise en place** :
`neria.php::hookActionDeleteGDPRCustomerImpl()` appelait
`new GdprAuditManager($this)` au lieu de
`new GdprAuditManager($this->getLocalPath())` — `GdprAuditManager` attend un
`string` (chemin du module), pas l'objet module. Ce hook plantait avec une
`TypeError` fatale à CHAQUE suppression RGPD d'un client via le bouton natif
PrestaShop, empêchant toute purge des données Neria pour ce client. Aucun
test ne couvrait ce hook — jamais remarqué en usage réel avant PHPStan.

## Politique d'évolution

Suivre la même règle que pour `test_43` (voir mémoire
`feedback_diff_review_before_round_close`) : ne PAS régénérer la baseline
en boîte noire pour faire disparaître un avertissement. Si une nouvelle
erreur apparaît :
1. La lire et comprendre si c'est un vrai bug → corriger le code.
2. Si c'est un faux positif confirmé (même famille que le bruit
   Neria/Module documenté ci-dessus) → l'ajouter individuellement à la
   baseline avec `--generate-baseline`, jamais en silence.
