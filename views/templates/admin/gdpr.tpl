{**
 * NERIA — gdpr.tpl
 * Onglet Conformite RGPD — Audit automatique et purge des donnees
 *}

{assign var="grade_color" value=$gdpr_audit.grade_color|default:'#888'}

{* ── Bouton de rapport PDF ─────────────────────────────────── *}
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
  <div>
    <h2 class="neria-section__title" style="margin:0;">Audit RGPD automatique</h2>
    <p class="neria-section__desc" style="margin-top:4px;">
      Analyse en temps réel de la conformité de Neria au Règlement Général sur la Protection des Données.
    </p>
  </div>
  <a href="{$smarty.server.REQUEST_URI}&neria_action=gdpr_pdf"
     target="_blank"
     class="neria-btn neria-btn--secondary neria-btn--sm">
    Télécharger le rapport PDF
  </a>
</div>

{* ── Score global ──────────────────────────────────────────── *}
<div class="neria-section neria-gdpr-score-card">
  <div style="display:flex;align-items:center;gap:20px;">
    <div class="neria-gdpr-grade" style="background:{$grade_color};">
      {$gdpr_audit.score}
    </div>
    <div>
      <div style="font-size:16px;font-weight:700;">
        {if $gdpr_audit.score === 'A'}Excellent — Neria est conforme RGPD{/if}
        {if $gdpr_audit.score === 'B'}Bon niveau — Quelques points d'attention{/if}
        {if $gdpr_audit.score === 'C'}Attention — Plusieurs non-conformités détectées{/if}
        {if $gdpr_audit.score === 'D'}Critique — Action immédiate requise{/if}
      </div>
      <div style="font-size:13px;color:var(--neria-text-muted,#888);margin-top:4px;">
        {$gdpr_audit.issues} point(s) d'attention · Rapport généré le {$gdpr_audit.generated_at}
      </div>
    </div>
  </div>
</div>

{* ── AXE 1 : DÉSABONNEMENT ─────────────────────────────────── *}
<div class="neria-section">
  <h3 class="neria-section__title" style="font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
    1 — Système de désabonnement
  </h3>
  <p class="neria-section__desc">
    Obligation légale pour tout email commercial (RGPD art. 21 + Directive ePrivacy).
  </p>
  <div class="neria-gdpr-checks">
    {foreach $gdpr_audit.unsubscribe.checks as $check}
    <div class="neria-gdpr-check {if isset($check.info)}neria-gdpr-check--info{elseif $check.ok}neria-gdpr-check--ok{else}neria-gdpr-check--fail{/if}">
      <span class="neria-gdpr-check__icon">
        {if isset($check.info)}·{elseif $check.ok}✓{else}✕{/if}
      </span>
      <div>
        <div class="neria-gdpr-check__label">{$check.label|escape:'html'}</div>
        <div class="neria-gdpr-check__detail">{$check.detail|escape:'html'}</div>
      </div>
    </div>
    {/foreach}
  </div>
</div>

{* ── AXE 2 : RÉTENTION ─────────────────────────────────────── *}
<div class="neria-section">
  <h3 class="neria-section__title" style="font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
    2 — Rétention des données
  </h3>
  <p class="neria-section__desc">
    Le RGPD impose une durée de conservation limitée et proportionnée à la finalité du traitement.
    Neria applique 36 mois pour les données commerciales et 12 mois pour les données techniques.
  </p>
  <table class="neria-gdpr-table">
    <thead>
      <tr>
        <th>Données</th>
        <th>Limite</th>
        <th>Plus ancienne</th>
        <th>Hors délai</th>
        <th>Statut</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      {foreach $gdpr_audit.retention.rows as $row}
      <tr>
        <td>
          <strong>{$row.label|escape:'html'}</strong>
          <div class="neria-gdpr-table__note">{$row.note|escape:'html'}</div>
        </td>
        <td class="neria-gdpr-table__num">{$row.months} mois</td>
        <td class="neria-gdpr-table__num">{$row.oldest}</td>
        <td class="neria-gdpr-table__num {if $row.overdue > 0}neria-gdpr-overdue{/if}">
          {$row.overdue}
        </td>
        <td>
          {if $row.ok}
            <span class="neria-badge neria-gdpr-badge--ok">Conforme</span>
          {else}
            <span class="neria-badge neria-gdpr-badge--warn">A purger</span>
          {/if}
        </td>
        <td>
          {if $row.overdue > 0}
          <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;">
            <input type="hidden" name="neria_action"    value="gdpr_purge">
            <input type="hidden" name="neria_tab"        value="gdpr">
            <input type="hidden" name="gdpr_table"       value="{$row.table|escape:'html'}">
            <input type="hidden" name="gdpr_date_col"    value="{$row.date_col|escape:'html'}">
            <input type="hidden" name="gdpr_months"      value="{$row.months|intval}">
            <button type="button" class="neria-btn neria-btn--ghost neria-btn--xs"
                    data-confirm="Supprimer les {$row.overdue} enregistrement(s) de {$row.label|escape:'html'} anterieurs a {$row.months} mois ?"
                    onclick="neriaConfirmDelete(this);">
              Purger
            </button>
          </form>
          {/if}
        </td>
      </tr>
      {/foreach}
    </tbody>
  </table>
</div>

{* ── AXE 3 : DONNÉES PERSONNELLES ──────────────────────────── *}
<div class="neria-section">
  <h3 class="neria-section__title" style="font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
    3 — Cartographie des données personnelles
  </h3>
  <p class="neria-section__desc">
    Inventaire des templates qui utilisent des variables PrestaShop contenant des données personnelles.
    Tous ces templates doivent avoir une base légale (contrat, consentement, intérêt légitime).
  </p>

  {* Mentions légales *}
  <div class="neria-gdpr-check {if $gdpr_audit.pii.legal_in_layout}neria-gdpr-check--ok{else}neria-gdpr-check--fail{/if}" style="margin-bottom:12px;">
    <span class="neria-gdpr-check__icon">{if $gdpr_audit.pii.legal_in_layout}✓{else}✕{/if}</span>
    <div>
      <div class="neria-gdpr-check__label">Mentions légales dans le layout global</div>
      <div class="neria-gdpr-check__detail">
        {if $gdpr_audit.pii.legal_in_layout}
          Un lien vers les mentions légales est présent dans le pied de page de tous les emails.
        {else}
          Aucun lien vers les mentions légales détecté dans layout.html — à corriger.
        {/if}
      </div>
    </div>
  </div>

  {if $gdpr_audit.pii.map}
  <table class="neria-gdpr-table">
    <thead>
      <tr>
        <th>Template</th>
        <th>Données personnelles détectées</th>
        <th>Base légale présumée</th>
      </tr>
    </thead>
    <tbody>
      {foreach $gdpr_audit.pii.map as $row}
      <tr>
        <td><code style="font-size:11px;">{$row.template|escape:'html'}</code></td>
        <td class="neria-gdpr-table__note">{$row.vars_str|escape:'html'}</td>
        <td class="neria-gdpr-table__note" style="color:var(--neria-text-muted,#999);">{$row.legal_basis|escape:'html'}</td>
      </tr>
      {/foreach}
    </tbody>
  </table>
  {else}
  <p class="neria-empty-state" style="margin:0;">Aucune donnée personnelle directement identifiable détectée dans les templates.</p>
  {/if}
</div>

{* ── AXE 4 : CHIFFREMENT ───────────────────────────────────── *}
<div class="neria-section">
  <h3 class="neria-section__title" style="font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
    4 — Chiffrement des données au repos
  </h3>
  <p class="neria-section__desc">
    Les snapshots de variables (prénom, email, montant…) stockés dans la base de données sont chiffrés
    avec {$gdpr_audit.crypto.cipher} — illisibles en cas de fuite chez l'hébergeur.
  </p>

  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">

    <div class="neria-gdpr-check {if $gdpr_audit.crypto.openssl_ok}neria-gdpr-check--ok{else}neria-gdpr-check--fail{/if}" style="flex:1;min-width:200px;">
      <span class="neria-gdpr-check__icon">{if $gdpr_audit.crypto.openssl_ok}✓{else}✕{/if}</span>
      <div>
        <div class="neria-gdpr-check__label">Extension OpenSSL</div>
        <div class="neria-gdpr-check__detail">
          {if $gdpr_audit.crypto.openssl_ok}AES-256-GCM disponible sur ce serveur.{else}OpenSSL non disponible — contactez votre hébergeur.{/if}
        </div>
      </div>
    </div>

    <div class="neria-gdpr-check {if $gdpr_audit.crypto.key_ok}neria-gdpr-check--ok{else}neria-gdpr-check--fail{/if}" style="flex:1;min-width:200px;">
      <span class="neria-gdpr-check__icon">{if $gdpr_audit.crypto.key_ok}✓{else}✕{/if}</span>
      <div>
        <div class="neria-gdpr-check__label">Clé de chiffrement</div>
        <div class="neria-gdpr-check__detail">
          {if $gdpr_audit.crypto.key_ok}Clé 256 bits générée à l'installation — jamais exposée.{else}Clé absente — réinstallez le module pour en générer une.{/if}
        </div>
      </div>
    </div>

  </div>

  {if $gdpr_audit.crypto.total > 0}
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div style="flex:1;min-width:130px;padding:14px 16px;text-align:center;background:var(--neria-bg-hover,#faf8f5);border:1px solid var(--neria-border,#e8e0d5);border-radius:8px;">
      <div style="font-size:24px;font-weight:700;color:#4a9e6b;">{$gdpr_audit.crypto.encrypted}</div>
      <div style="font-size:12px;color:var(--neria-text-muted,#888);margin-top:4px;">Chiffrés</div>
    </div>
    <div style="flex:1;min-width:130px;padding:14px 16px;text-align:center;background:var(--neria-bg-hover,#faf8f5);border:1px solid var(--neria-border,#e8e0d5);border-radius:8px;">
      <div style="font-size:24px;font-weight:700;{if $gdpr_audit.crypto.plain > 0}color:#e05c5c;{else}color:#4a9e6b;{/if}">{$gdpr_audit.crypto.plain}</div>
      <div style="font-size:12px;color:var(--neria-text-muted,#888);margin-top:4px;">En clair</div>
    </div>
    <div style="flex:1;min-width:130px;padding:14px 16px;text-align:center;background:var(--neria-bg-hover,#faf8f5);border:1px solid var(--neria-border,#e8e0d5);border-radius:8px;">
      <div style="font-size:24px;font-weight:700;">{$gdpr_audit.crypto.total}</div>
      <div style="font-size:12px;color:var(--neria-text-muted,#888);margin-top:4px;">Total snapshots</div>
    </div>
  </div>
  {/if}

  {if $gdpr_audit.crypto.active && $gdpr_audit.crypto.plain > 0}
  <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <input type="hidden" name="neria_action" value="gdpr_encrypt_all">
    <input type="hidden" name="neria_tab"    value="gdpr">
    <button type="button" class="neria-btn neria-btn--primary neria-btn--sm"
            data-confirm="Chiffrer {$gdpr_audit.crypto.plain} enregistrement(s) en clair avec AES-256-GCM ?"
            onclick="neriaConfirmDelete(this);">
      Chiffrer les {$gdpr_audit.crypto.plain|intval} enregistrement(s) existant(s)
    </button>
    <span style="font-size:12px;color:var(--neria-text-muted,#888);">
      Les nouveaux envois sont chiffrés automatiquement.
    </span>
  </form>
  {elseif $gdpr_audit.crypto.active && $gdpr_audit.crypto.plain == 0 && $gdpr_audit.crypto.total > 0}
  <p style="font-size:13px;color:#4a9e6b;font-weight:600;">✓ Toutes les données sont chiffrées.</p>
  {elseif !$gdpr_audit.crypto.openssl_ok}
  <p style="font-size:13px;color:var(--neria-text-muted,#888);">Le chiffrement n'est pas disponible sur cet environnement.</p>
  {/if}
</div>

{* ── Avertissement ─────────────────────────────────────────── *}
<div class="neria-section" style="background:var(--neria-bg-hover,#faf8f5);border:1px solid var(--neria-border,#e8e0d5);">
  <p style="font-size:12px;color:var(--neria-text-muted,#888);line-height:1.7;">
    <strong>Avis de limitation :</strong>
    Ce rapport est généré automatiquement par Neria à partir de l'analyse des fichiers et des données stockées.
    Il ne constitue pas un avis juridique et ne remplace pas l'intervention d'un délégué à la protection des données (DPO).
    La conformité RGPD dépend également de votre politique de confidentialité, de votre registre des traitements et de vos contrats sous-traitants.
  </p>
</div>
