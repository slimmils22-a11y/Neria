{**
 * NERIA — configure.tpl
 * Page d'accueil du back-office
 * Fix 2 : id unique sur le select signature
 * Fix 4 : $upcoming_events doit être assignée par neria.php
 *}

{* ── KPIs rapides ───────────────────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section__title">
    {l s='Tableau de bord' mod='neria'}
  </h2>
  <div class="neria-kpi-grid">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.total_sent|default:0}</div>
      <div class="neria-kpi__label">{l s='Emails envoyés' mod='neria'}</div>
      <div class="neria-kpi__period">{l s='30 derniers jours' mod='neria'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.rate_open|default:0}%</div>
      <div class="neria-kpi__label">{l s='Taux d\'ouverture' mod='neria'}</div>
      <div class="neria-kpi__period">{l s='30 derniers jours' mod='neria'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.rate_click|default:0}%</div>
      <div class="neria-kpi__label">{l s='Taux de clic' mod='neria'}</div>
      <div class="neria-kpi__period">{l s='30 derniers jours' mod='neria'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$kpis.active_langs|default:0}</div>
      <div class="neria-kpi__label">{l s='Langues actives' mod='neria'}</div>
      <div class="neria-kpi__period">{l s='sur 18' mod='neria'}</div>
    </div>
  </div>
</div>

{* ── Détection automatique de la langue ─────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section__title">
    {l s='Détection automatique de la langue' mod='neria'}
  </h2>
  <p class="neria-section__desc">
    {l s='Neria choisit la langue de chaque email selon le client : son choix explicite s\'il a sélectionné une langue, sinon le pays de son adresse de livraison. Un client étranger reçoit ainsi l\'email dans sa langue, même si la boutique est configurée en une seule langue.' mod='neria'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action"    value="save_autolang">
    <input type="hidden" name="neria_tab"       value="configure">
    <input type="hidden" name="neria_auto_lang" value="0">

    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:var(--neria-text);">
      <input type="checkbox" name="neria_auto_lang" value="1"
             style="width:16px; height:16px; cursor:pointer;"
             {if $auto_lang_enabled}checked{/if}>
      <span>{l s='Détection automatique de la langue client' mod='neria'}</span>
    </label>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {l s='Enregistrer' mod='neria'}
      </button>
    </div>
  </form>
</div>

{* ── Bons de réduction ──────────────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section__title">{l s='Bons de réduction' mod='neria'}</h2>
  <p class="neria-section__desc">
    {l s='Durée de validité affichée dans les emails de bon (variable {validity_days}). Modifiez-la selon votre politique.' mod='neria'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_voucher_validity">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-group">
      <label class="neria-label" for="neria-voucher-validity">
        {l s='Durée de validité (jours)' mod='neria'}
      </label>
      <input type="number" id="neria-voucher-validity" name="neria_voucher_validity"
             class="neria-input" min="1" max="365" style="max-width:140px;"
             value="{$voucher_validity|default:30}">
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        {l s='Enregistrer' mod='neria'}
      </button>
    </div>
  </form>
</div>

{* ── Prochaines occasions calendaires ───────────────────────── *}
{* $upcoming_events est assignée par neria.php via CalendarManager::getUpcomingDates() *}
{if isset($upcoming_events) && $upcoming_events|@count > 0}
<div class="neria-section">
  <h2 class="neria-section__title">
    {l s='Prochaines occasions' mod='neria'}
  </h2>
  <div class="neria-upcoming">
    {foreach $upcoming_events as $event}
      <div class="neria-upcoming__item {if $event.already_sent}neria-upcoming__item--sent{/if}">
        <div class="neria-upcoming__days">
          {if $event.already_sent}
            <span class="neria-badge neria-badge--success">✓</span>
          {else}
            <strong>J-{$event.days_until}</strong>
          {/if}
        </div>
        <div class="neria-upcoming__info">
          <span class="neria-upcoming__event">{$event.event_key}</span>
          <span class="neria-upcoming__lang">[{$event.lang}]</span>
        </div>
        <div class="neria-upcoming__date">{$event.send_date}</div>
        <div class="neria-upcoming__source">
          <span class="neria-badge neria-badge--{if $event.date_source === 'manuel'}accent{else}neutral{/if}">
            {$event.date_source}
          </span>
        </div>
      </div>
    {/foreach}
  </div>
</div>
{/if}

{* ── Variables personnalisées ───────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section__title">
    {l s='Variables personnalisées' mod='neria'}
  </h2>
  <p class="neria-section__desc">
    {l s='Ces variables sont injectées dans tous vos emails. Utilisez {maison_name}, {slogan}, etc. dans vos textes.' mod='neria'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_custom_vars">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-grid">

      <div class="neria-form-group">
        <label class="neria-label" for="maison_name">
          {l s='Nom de votre maison' mod='neria'}
          <span class="neria-var-tag">{literal}{maison_name}{/literal}</span>
        </label>
        <input type="text" id="maison_name" name="maison_name"
               class="neria-input"
               value="{$custom_vars.maison_name|default:''|escape:'html'}"
               placeholder="{l s='Ex: Maison Dupont' mod='neria'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="slogan">
          {l s='Slogan / devise' mod='neria'}
          <span class="neria-var-tag">{literal}{slogan}{/literal}</span>
        </label>
        <input type="text" id="slogan" name="slogan"
               class="neria-input"
               value="{$custom_vars.slogan|default:''|escape:'html'}"
               placeholder="{l s='Ex: L\'élégance au quotidien' mod='neria'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="founder_name">
          {l s='Nom du fondateur' mod='neria'}
          <span class="neria-var-tag">{literal}{founder_name}{/literal}</span>
        </label>
        <input type="text" id="founder_name" name="founder_name"
               class="neria-input"
               value="{$custom_vars.founder_name|default:''|escape:'html'}"
               placeholder="{l s='Ex: Marie Dupont' mod='neria'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="founder_title">
          {l s='Titre du fondateur' mod='neria'}
          <span class="neria-var-tag">{literal}{founder_title}{/literal}</span>
        </label>
        <input type="text" id="founder_title" name="founder_title"
               class="neria-input"
               value="{$custom_vars.founder_title|default:''|escape:'html'}"
               placeholder="{l s='Ex: Fondatrice & Directrice Artistique' mod='neria'}">
      </div>

      <div class="neria-form-group neria-form-group--full">
        <label class="neria-label" for="signature_closing">
          {l s='Formule de clôture' mod='neria'}
          <span class="neria-var-tag">{literal}{signature_closing}{/literal}</span>
        </label>
        <input type="text" id="signature_closing" name="signature_closing"
               class="neria-input"
               value="{$custom_vars.signature_closing|default:''|escape:'html'}"
               placeholder="{l s='Ex: Avec toute notre estime,' mod='neria'}">
      </div>

      <div class="neria-form-group neria-form-group--full">
        <label class="neria-label" for="return_address">
          {l s='Adresse de retour' mod='neria'}
          <span class="neria-var-tag">{literal}{return_address}{/literal}</span>
        </label>
        <textarea id="return_address" name="return_address"
                  class="neria-input" rows="3"
                  placeholder="{l s='Ex: Neria Returns, 15 rue du Commerce, 75015 Paris' mod='neria'}">{$custom_vars.return_address|default:''|escape:'html'}</textarea>
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="return_deadline_days">
          {l s='Délai de renvoi (jours)' mod='neria'}
          <span class="neria-var-tag">{literal}{return_deadline_days}{/literal}</span>
        </label>
        <input type="text" id="return_deadline_days" name="return_deadline_days"
               class="neria-input"
               value="{$custom_vars.return_deadline_days|default:''|escape:'html'}"
               placeholder="{l s='Ex: 14' mod='neria'}">
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="return_processing_days">
          {l s='Délai d\'examen du retour' mod='neria'}
          <span class="neria-var-tag">{literal}{return_processing_days}{/literal}</span>
        </label>
        <input type="text" id="return_processing_days" name="return_processing_days"
               class="neria-input"
               value="{$custom_vars.return_processing_days|default:''|escape:'html'}"
               placeholder="{l s='Ex: 5-7' mod='neria'}">
      </div>

    </div>

    <div class="neria-form-actions">
      <button type="submit" class="neria-btn neria-btn--primary">
        {l s='Sauvegarder' mod='neria'}
      </button>
    </div>
  </form>
</div>

{* ── Signature manuscrite ───────────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section__title">
    {l s='Signature manuscrite' mod='neria'}
  </h2>
  <p class="neria-section__desc">
    {l s='Générée à partir du nom du fondateur et injectée dans vos emails de confirmation et d\'expédition.' mod='neria'}
  </p>

  <form method="post" action="{$smarty.server.REQUEST_URI}" id="neria-signature-form">
    <input type="hidden" name="neria_action" value="generate_signature">
    <input type="hidden" name="neria_tab"    value="configure">

    <div class="neria-form-grid">

      <div class="neria-form-group">
        <label class="neria-label" for="neria-sig-style">
          {l s='Style de signature' mod='neria'}
        </label>
        {* Fix 2 : un seul id="neria-sig-style", name="sig_style" pour le POST *}
        <select id="neria-sig-style" name="sig_style" class="neria-select">
          {foreach $signature_styles as $key => $label}
            <option value="{$key}"
              {if isset($current_signature.style) && $current_signature.style === $key}selected{/if}>
              {$label}
            </option>
          {/foreach}
        </select>
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="sig_color">
          {l s='Couleur' mod='neria'}
        </label>
        <div class="neria-color-input-wrap">
          <input type="color" id="sig_color" name="sig_color"
                 class="neria-color-picker"
                 value="{$current_signature.color|default:'#b38b59'}">
          <input type="text"
                 class="neria-input neria-input--hex"
                 value="{$current_signature.color|default:'#b38b59'}"
                 data-sync="sig_color">
        </div>
      </div>

    </div>

    <div class="neria-signature-preview" id="neria-sig-preview">
      {if isset($current_signature.url) && $current_signature.url}
        <img src="{$current_signature.url}"
             alt="{l s='Signature' mod='neria'}"
             class="neria-signature-preview__img">
      {else}
        <span class="neria-signature-preview__placeholder">
          {l s='Cliquez sur Aperçu pour visualiser votre signature' mod='neria'}
        </span>
      {/if}
    </div>

    <div class="neria-form-actions">
      <button type="button" class="neria-btn neria-btn--ghost"
              id="neria-sig-preview-btn">
        {l s='Aperçu' mod='neria'}
      </button>
      <button type="submit" class="neria-btn neria-btn--primary">
        {l s='Générer et sauvegarder' mod='neria'}
      </button>
    </div>

  </form>
</div>
