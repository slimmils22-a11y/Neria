# Tests de régression Neria

Suite créée le 17/07/2026, protégeant contre la **récidive** des 64 bugs réels
trouvés et corrigés lors de l'audit exhaustif de ce jour — pas contre
l'apparition de nouveaux bugs inconnus (ça, c'est le rôle d'un audit de code,
pas d'une suite de tests).

## Usage

```
C:\laragon\bin\php\php-8.1.29-Win32-vs16-x64\php.exe run_all.php
```

Code de sortie 0 si tout passe, 1 sinon. À rejouer avant chaque packaging, et
après toute modification touchant l'une des zones couvertes (scoping
`id_shop`, dédup, préférences RGPD, calendrier, A/B testing, traductions).

## Ce que ça couvre

20 tests, chacun ciblant un bug réel précis (voir le commentaire en tête de
chaque fichier pour le commit de référence) :

- Scoping `id_shop` manquant (GdprAuditManager, OrderTriggersManager,
  PropensityScoreManager, QueueManager, contrôleurs désabonnement/préférences)
- Races TOCTOU (StatsManager::recordClick, WebhookManager::processQueue)
- Clés de dédup incompatibles entre émetteurs (first_anniversary vs
  relationship_anniversary, cron auto vs envoi manuel)
- Tri erroné (CustomerEmailHistoryManager::computeAlerts)
- Lecture de colonne jamais sélectionnée (SegmentManager::preflightCheck)
- Calcul de date `YEAR()-YEAR()` vs `TIMESTAMPDIFF` (SeasonalCampaignManager)
- Erreur statistique (StatsManager::computeSignificance, sélection du
  gagnant A/B)
- Fenêtres cron mal calées / chevauchantes (BehavioralCronManager)
- Traitement partiel d'un batch webhook (BounceManager/SendGrid)
- Application des préférences RGPD (le bug le plus significatif de la
  session — centralisation dans le hook universel + complétude de
  `TEMPLATE_CAT`)
- Persistance du gagnant A/B (`is_custom`)
- Renforcement du Watchdog traduction (détection des valeurs vides, pas
  seulement des clés absentes)

## Limites

Cette suite ne remplace pas un audit de code. Elle ne détecte que la
régression d'un comportement déjà identifié et corrigé une fois. Un nouveau
bug de logique métier, jamais rencontré, ne sera pas détecté ici.
