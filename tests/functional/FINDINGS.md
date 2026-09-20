# Constats de la campagne de tests fonctionnels — à arbitrer

Liste vivante, alimentée par chaque phase. **Rien n'est corrigé sans décision de l'utilisateur.**
Statuts : `À arbitrer` · `Corriger` · `Assumé` (décision de ne pas corriger, avec la raison) · `Corrigé` (commit).

| N° | Phase | Constat | Gravité | Preuve | Statut |
|---|---|---|---|---|---|
| F-001 | P2a | L'attribut `lang` est absent de la balise `<html>` de **tous** les e-mails (`layout.html` ne pose que `dir`). Conséquences : lecteurs d'écran qui prononcent mal, correcteur orthographique et « traduire ce message » de Gmail moins fiables, critère WCAG 3.1.1. Correctif simple : injecter `lang` comme `neria_dir`. | Moyenne (accessibilité, i18n) | `results/P2a_render.json` — 2 223 / 2 223 combinaisons | À arbitrer |
| F-002 | P2a | Contraste insuffisant (WCAG AA 4,5:1) : couleur d'accent `#b38b59` sur blanc = **3,11:1** (liens, pied de page, « Gérer mes préférences ») ; gris `#8c857e` = **3,64:1** (en-têtes de tableau). Compromis de charte à trancher : assombrir légèrement l'accent pour le texte (garder l'accent actuel pour les filets/pastilles) ou assumer. | Moyenne (accessibilité, charte) | `results/P2a_render.md` › Contrastes | À arbitrer |
| F-003 | P2a (analyse statique) | Boutons non « bulletproof » pour Outlook bureau : `a.neria-btn` s'appuie sur `padding`/`background-color` de la balise `<a>`, que le moteur Word ignore en partie ; le layout n'a aucun bloc `<!--[if mso]>` ni VML. Risque : bouton réduit à un fond derrière le texte. **À confirmer par une capture Outlook réelle** (décision « source de rendu Outlook » attendue). | Moyenne (rendu) | Analyse de `layout.html` | À confirmer |
| F-004 | Cadrage | Compatibilité annoncée `config.xml` PS ≥ 8.0.0 (PHP 7.2+) mais le code utilise `match` (9 fois) et `?->` (2 fois), invalides avant PHP 8.0. Lint PHP 7.4 à faire pour confirmer (décision « PHP 7.4 portable » attendue). | Haute si confirmé (fatal sur PS 8.0/8.1 en PHP 7.4) | grep du code | À confirmer |
| F-005 | P1 | Action BO `search_customers` sans aucun bouton/script qui la déclenche : code mort probable (l'interface utilise `customer_autocomplete`). | Faible | `INVENTORY.md` | À arbitrer (P8) |
| F-006 | P2a | Sujet de repli : 4 templates n'ont pas de clé `greeting_main` (complete_your_look, collection_completion, return_slip, neria_fallback). Leur sujet doit être fourni par leur gestionnaire — **à vérifier à l'envoi réel** (P3/P4) ; sinon sujet vide/générique en envoi manuel. | Faible à moyenne | `results/P2a_render.json` › R090 | À vérifier |

## Pas des défauts (vérifiés et écartés)

- Liens `#`, images relatives, « français résiduel » (messages factices d'aperçu), variables non résolues : artefacts du jeu de données d'aperçu, corrigés dans le harnais `render_all_templates.php` (le détecteur a été validé sur un template piégé : R010, R011, R020, R021, R040, R050, R051, R070, R080 se déclenchent bien).
