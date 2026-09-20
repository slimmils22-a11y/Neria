# P2a — rendu automatique des templates d'e-mail (aucun envoi)

Généré le 2026-09-20 12:04:03 — 117 templates × 19 langues = 2223 combinaisons ; **2223 rendues**, 0 échecs ; 23.7 s.

## Résultats par contrôle

| Niveau / code | Occurrences | Templates | Exemple |
|---|---|---|---|
| W R031 | 2223 | 117 | abandoned_cart_1/fr: attribut lang absent de <html> |
| W R090 | 76 | 4 | collection_completion/fr: titre principal (greeting_main) absent : sujet de repli vide |

## Contrastes (WCAG AA : 4,5 texte normal, 3 grand texte)

| Paire | Ratio | AA normal |
|---|---|---|
| texte principal sur fond du conteneur (#2c2c2c / #ffffff) | 13.97 | oui |
| accent (liens, pied de page) sur conteneur (#b38b59 / #ffffff) | 3.11 | non (grand texte seulement) |
| texte du pied de page sur son fond (#6b6459 / #ffffff) | 5.85 | oui |
| texte du bouton sur la couleur du bouton (#ffffff / #2b2520) | 15.13 | oui |

Couleurs de texte sous 4,5:1 contre le blanc : 2 (voir JSON › low_contrast_colors).
