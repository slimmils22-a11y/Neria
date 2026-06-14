{**
 * NERIA — help.tpl
 * Onglet Aide — Documentation, diagnostic et support
 *}

{* ── Diagnostic ─────────────────────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section__title">
    {l s='Diagnostic du module' mod='neria'}
    <span class="neria-score neria-score--{$diagnostic.score.status}">
      {$diagnostic.score.score}/100
    </span>
  </h2>

  <div class="neria-diag-grid">

    {* PHP *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">PHP</h3>
      <ul class="neria-diag-list">
        <li class="{if $diagnostic.php.version_ok}neria-diag--ok{else}neria-diag--err{/if}">
          PHP {$diagnostic.php.version}
          {if !$diagnostic.php.version_ok}
            <span class="neria-diag-note">{l s='Requis: PHP 8.0+' mod='neria'}</span>
          {/if}
        </li>
        <li class="{if $diagnostic.php.gd_available}neria-diag--ok{else}neria-diag--warn{/if}">
          GD (signatures)
          {if !$diagnostic.php.gd_available}
            <span class="neria-diag-note">{l s='Requis pour les signatures manuscrites' mod='neria'}</span>
          {/if}
        </li>
        <li class="{if $diagnostic.php.mbstring}neria-diag--ok{else}neria-diag--err{/if}">
          mbstring
        </li>
        <li class="{if $diagnostic.php.openssl}neria-diag--ok{else}neria-diag--warn{/if}">
          OpenSSL
        </li>
      </ul>
    </div>

    {* Base de données *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">{l s='Base de données' mod='neria'}</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.database as $table => $data}
          <li class="{if $data.exists}neria-diag--ok{else}neria-diag--err{/if}">
            {$table}
            {if $data.exists}
              <span class="neria-diag-count">{$data.rows} {l s='lignes' mod='neria'}</span>
            {else}
              <span class="neria-diag-note">{l s='Table manquante' mod='neria'}</span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

    {* Hooks *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">Hooks</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.hooks as $hook => $registered}
          <li class="{if $registered}neria-diag--ok{else}neria-diag--err{/if}">
            {$hook}
            {if !$registered}
              <span class="neria-diag-note">{l s='Non enregistré' mod='neria'}</span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

    {* Fichiers *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">{l s='Fichiers' mod='neria'}</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.files as $label => $data}
          <li class="{if $data.exists}neria-diag--ok{else}neria-diag--err{/if}">
            {$label}
            {if $data.exists}
              <span class="neria-diag-count">{$data.size}</span>
            {else}
              <span class="neria-diag-note">{l s='Introuvable' mod='neria'}</span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

    {* Polices TTF *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">{l s='Polices TTF' mod='neria'}</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.fonts as $font => $present}
          <li class="{if $present}neria-diag--ok{else}neria-diag--warn{/if}">
            {$font}
            {if !$present}
              <span class="neria-diag-note">
                <a href="https://fonts.google.com" target="_blank">
                  {l s='Télécharger sur Google Fonts' mod='neria'}
                </a>
              </span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

    {* Permissions *}
    <div class="neria-diag-block">
      <h3 class="neria-diag-block__title">{l s='Permissions dossiers' mod='neria'}</h3>
      <ul class="neria-diag-list">
        {foreach $diagnostic.permissions as $dir => $data}
          <li class="{if $data.exists && $data.writable}neria-diag--ok{elseif $data.exists}neria-diag--warn{else}neria-diag--err{/if}">
            {$dir}
            {if !$data.exists}
              <span class="neria-diag-note">{l s='Dossier manquant' mod='neria'}</span>
            {elseif !$data.writable}
              <span class="neria-diag-note">{l s='Non accessible en écriture (chmod 755)' mod='neria'}</span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </div>

  </div>
</div>

{* ── Journal des événements ─────────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section__title">
    {l s='Journal des événements' mod='neria'}
  </h2>

  {* Réglage : inclure ou non les emails internes (administrateur) *}
  <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-bottom:18px;">
    <input type="hidden" name="neria_action"       value="save_log_internal">
    <input type="hidden" name="neria_tab"          value="help">
    <input type="hidden" name="neria_log_internal" value="0">
    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:13px; color:var(--neria-text);">
      <input type="checkbox" name="neria_log_internal" value="1"
             style="width:16px; height:16px; cursor:pointer;"
             onchange="this.form.submit()"
             {if $log_internal_enabled}checked{/if}>
      <span>{l s='Inclure les emails internes (administrateur) dans le journal' mod='neria'}
        <span style="color:#999;">— {l s='les erreurs et critiques restent toujours enregistrées' mod='neria'}</span>
      </span>
    </label>
  </form>

  {* Résumé par niveau *}
  <div class="neria-kpi-grid" style="margin-bottom:20px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$log_counts.info|default:0}</div>
      <div class="neria-kpi__label">Info</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value" style="color:#BA7517;">{$log_counts.warning|default:0}</div>
      <div class="neria-kpi__label">Warnings</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value" style="color:#A32D2D;">{$log_counts.error|default:0}</div>
      <div class="neria-kpi__label">Erreurs</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value" style="color:#7a0000;">{$log_counts.critical|default:0}</div>
      <div class="neria-kpi__label">Critiques</div>
    </div>
  </div>

  {* Filtres *}
  <div class="neria-trad-selectors" style="margin-bottom:16px;">
    <select id="neria-log-level" class="neria-select neria-select--sm">
      <option value="">{l s='Tous les niveaux' mod='neria'}</option>
      <option value="info">Info</option>
      <option value="warning">Warning</option>
      <option value="error">Erreur</option>
      <option value="critical">Critique</option>
    </select>

    <select id="neria-log-template" class="neria-select neria-select--sm">
      <option value="">{l s='Tous les templates' mod='neria'}</option>
      {foreach $log_templates as $tpl}
        <option value="{$tpl}">{$tpl}</option>
      {/foreach}
    </select>

    <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline">
      <input type="hidden" name="neria_action" value="clear_logs">
      <input type="hidden" name="neria_tab" value="help">
      <button type="submit"
              class="neria-btn neria-btn--danger neria-btn--sm"
              onclick="return confirm('{l s='Vider tout le journal ?' mod='neria'}')">
        {l s='Vider le journal' mod='neria'}
      </button>
    </form>
  </div>

  {* Tableau des logs *}
  {if isset($logs) && $logs}
    <div class="neria-table-wrap">
      <table class="neria-table" id="neria-log-table">
        <thead>
          <tr>
            <th>{l s='Date' mod='neria'}</th>
            <th>{l s='Niveau' mod='neria'}</th>
            <th>{l s='Classe' mod='neria'}</th>
            <th>{l s='Template' mod='neria'}</th>
            <th>{l s='Message' mod='neria'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $logs as $log}
            <tr class="neria-log-row neria-log-row--{$log.level}">
              <td style="white-space:nowrap; font-size:12px;">{$log.date_add}</td>
              <td>
                <span class="neria-badge neria-badge--{if $log.level === 'info'}neutral{elseif $log.level === 'warning'}warn{else}err{/if}">
                  {$log.level}
                </span>
              </td>
              <td style="font-size:12px; color:var(--neria-text-light);">{$log.class}</td>
              <td style="font-size:12px;">{$log.template|default:'—'}</td>
              <td style="font-size:13px;">{$log.message|escape:'html'}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <div class="neria-empty-state">
      <span class="neria-empty-state__icon">✓</span>
      <p>{l s='Aucun événement enregistré — tout fonctionne parfaitement.' mod='neria'}</p>
    </div>
  {/if}
</div>

{* ── Documentation rapide ───────────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section__title">{l s='Guide rapide' mod='neria'}</h2>

  <div class="neria-doc-grid">

    <div class="neria-doc-card">
      <h3 class="neria-doc-card__title">◈&nbsp;{l s='Variables dans les textes' mod='neria'}</h3>
      <p>{l s='Utilisez ces variables dans vos traductions pour personnaliser chaque email :' mod='neria'}</p>
      <ul class="neria-doc-vars">
        <li><code>{literal}{maison_name}{/literal}</code> — {l s='Nom de votre maison' mod='neria'}</li>
        <li><code>{literal}{slogan}{/literal}</code> — {l s='Votre slogan' mod='neria'}</li>
        <li><code>{literal}{founder_name}{/literal}</code> — {l s='Nom du fondateur' mod='neria'}</li>
        <li><code>{literal}{founder_title}{/literal}</code> — {l s='Titre du fondateur' mod='neria'}</li>
        <li><code>{literal}{signature_closing}{/literal}</code> — {l s='Formule de clôture' mod='neria'}</li>
        <li><code>{literal}{shop_name}{/literal}</code> — {l s='Nom de la boutique (PrestaShop)' mod='neria'}</li>
        <li><code>{literal}{firstname}{/literal}</code> — {l s='Prénom du client' mod='neria'}</li>
        <li><code>{literal}{lastname}{/literal}</code> — {l s='Nom du client' mod='neria'}</li>
      </ul>
    </div>

    <div class="neria-doc-card">
      <h3 class="neria-doc-card__title">⇋&nbsp;{l s='A/B Testing — conseils' mod='neria'}</h3>
      <ul class="neria-doc-list">
        <li>{l s='Laissez un test tourner au moins 2 semaines avant de conclure.' mod='neria'}</li>
        <li>{l s='Commencez par les paniers abandonnés — plus faciles à mesurer.' mod='neria'}</li>
        <li>{l s='Ne testez qu\'une seule variable à la fois (ton OU accroche, pas les deux).' mod='neria'}</li>
        <li>{l s='Un taux d\'ouverture >35% ou de clic >5% est excellent.' mod='neria'}</li>
      </ul>
    </div>

    <div class="neria-doc-card">
      <h3 class="neria-doc-card__title">◫&nbsp;{l s='Calendrier — mise à jour des dates' mod='neria'}</h3>
      <p>{l s='Les dates des fêtes islamiques et du Nouvel An lunaire sont pré-calculées jusqu\'en 2035 et recalculées automatiquement au-delà. En cas d\'erreur, utilisez l\'override manuel dans l\'onglet Accueil.' mod='neria'}</p>
    </div>

    <div class="neria-doc-card">
      <h3 class="neria-doc-card__title">?&nbsp;{l s='Support' mod='neria'}</h3>
      <p>{l s='Pour toute question technique, consultez la documentation complète ou contactez le support Neria.' mod='neria'}</p>
      <a href="https://www.neria.io/docs" target="_blank"
         class="neria-btn neria-btn--ghost neria-btn--sm">
        {l s='Documentation' mod='neria'}
      </a>
    </div>

  </div>
</div>

{* ── Fermeture du wrapper principal (ouvert dans navigation.tpl) *}
  </div>{* .neria-bo-content *}
</div>{* .neria-bo-wrap *}
