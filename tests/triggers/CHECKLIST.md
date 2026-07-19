# Checklist "auto-déclencheurs" — avant packaging Addons

Vérifie que chaque tâche périodique de Neria est réellement raccordée à un
déclencheur automatique (pas seulement accessible via un bouton BO manuel),
et que ce raccordement fonctionne réellement — pas seulement présent dans
le code.

## Pourquoi

Un smoke test classique (appeler chaque méthode directement) ne détecte
**pas** un manager orphelin — une classe entièrement fonctionnelle mais
jamais réellement appelée par le cron ou le hook cœur. Ce type de bug ne se
voit qu'en testant le **déclencheur lui-même**, pas la méthode qu'il est
censé appeler.

## Méthode (validée le 2026-07-19 sur PS9 réel)

Pour un manager dont le déclenchement automatique dépend d'un throttle en
base (`NERIA_CRON_LAST_*`) :

1. Forcer la valeur du throttle à une date passée (au-delà de l'intervalle) :
   ```sql
   UPDATE ps_configuration SET value = DATE_SUB(NOW(), INTERVAL 2 DAY)
   WHERE name = 'NERIA_CRON_LAST_XXX';
   ```
2. Déclencher le point d'entrée réel :
   - `hookDisplayHeader` → charger n'importe quelle page front réelle
     (`curl https://boutique/`)
   - ou le contrôleur cron dédié → `curl .../controller=cron&token=...`
3. Vérifier que le throttle a été remis à l'heure du déclenchement (preuve
   que le code s'est réellement exécuté, pas juste "n'a pas planté").
4. Vérifier dans `ps_neria_log` (Watchdog) que les entrées attendues sont
   apparues (ex. `behavioral_cron_start` → `behavioral_cron_done`).

Preuve que ça détecte un vrai problème : ce test a révélé que `NERIA_CRON_
LAST_BEHAVIORAL` n'était pas encore éligible au déclenchement lors du
premier essai (mis à jour la veille, throttle 24h non écoulé) — sans forcer
la date, ce test n'aurait rien vérifié du tout, juste confirmé un no-op.

## État vérifié (2026-07-19, PS9 réel, melleina.com)

### Point d'entrée unique : `hookDisplayHeader` → `runBackgroundJobs()`

Toutes les tâches suivantes partagent le même déclencheur — un seul test
(`NERIA_CRON_LAST_BEHAVIORAL`) les couvre TOUTES d'un coup, car elles sont
appelées en séquence dans `BehavioralCronManager::run()` :

- sendBirthdays, sendFirstAnniversaries, sendRelationshipAnniversaries
- sendReorderReminders, sendWinBacks, sendRewardExpiryAlerts
- sendWishlistReminders
- sendAbandonedCarts (1/2/3), sendCheckoutAbandonment
- sendQuoteExpiryReminders, sendRefundReconciliations
- sendLifespanReminders, recalculatePropensityScores
- sendPostPurchase (care/review), sendShippedDelayAlerts
- sendCollectionCompletions, sendLookCompletions, sendGhostCarts
- **SegmentManager::recomputeAll()** (recalcul quotidien segments)
- **ChurnScoreManager::recomputeAll()** (recalcul quotidien score churn)

✅ **Testé et confirmé fonctionnel** — voir séquence de log réelle :
`behavioral_cron_start` → `anniversary_none_eligible` → ... →
`behavioral_cron_done`, avec mise à jour du throttle à l'heure exacte du
déclenchement.

Directement dans `runBackgroundJobs()` (pas de sous-throttle séparé,
appelées à chaque passage) :
- `CalendarManager` (occasions calendaires) — ✅ code confirmé dans
  `runBackgroundJobs()`, throttle interne `NERIA_CRON_LAST_CALENDAR`
- `DomainReputationManager::getReport(false)` — ✅ confirmé, throttle
  interne 24h (`NERIA_CRON_LAST_DOMREP`)

### Point d'entrée séparé : contrôleur cron dédié (`controllers/front/cron.php`)

✅ **Testé et confirmé fonctionnel le 2026-07-19** (après correctif de la
liste blanche maintenance, voir [[project_prod_maintenance_cron_fix]]) :
`health_checks`, `queue`, `webhook`, `calendar`, `monthly_report`,
`domain_reputation`, `watchdog_digest` — tous à `true` dans la réponse JSON
réelle du contrôleur.

### Volontairement manuel uniquement (documenté, PAS un manque)

- `BounceManager::checkBounceMailbox()` — ouvre une vraie connexion réseau
  IMAP, jugé trop coûteux/risqué pour un déclenchement silencieux
  automatique (commentaire explicite dans `neria.php`, action
  `repair_bounces_check`). Design intentionnel, pas un bug.

## À refaire avant chaque packaging

Rejouer la méthode ci-dessus pour chaque nouveau manager périodique ajouté
au module — en particulier vérifier qu'il est bien appelé depuis
`runBackgroundJobs()` OU `controllers/front/cron.php`, pas seulement
depuis une action BO manuelle (`Tools::getValue('neria_action') === ...`)
sans commentaire justifiant explicitement ce choix comme volontaire.
