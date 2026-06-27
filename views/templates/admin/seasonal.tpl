{**
 * NERIA — seasonal.tpl
 * Campagnes saisonnières automatiques
 *}

{assign var="base_url" value=$smarty.server.REQUEST_URI|regex_replace:'/&neria_action=[^&]*/':''}
{assign var="base_url" value=$base_url|regex_replace:'/&id_campaign=[^&]*/':''}
{assign var="base_url" value=$base_url|regex_replace:'/&edit_campaign=[^&]*/':''}
{assign var="base_url" value=$base_url|regex_replace:'/&neria_tab=[^&]*/':''}
{assign var="tab_url"  value="{$base_url}&neria_tab=seasonal"}

{assign var="month_names" value=['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre']}
{assign var="month_abbr"  value=['','Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc']}

{assign var="is_edit" value=false}
{if isset($seasonal_edit) && $seasonal_edit}{assign var="is_edit" value=true}{/if}

<style>
.ns-pill {
  display:inline-flex; align-items:center; gap:5px;
  font-size:12px; padding:5px 11px; border-radius:20px; cursor:pointer;
  border:1px solid var(--neria-border); background:var(--neria-light-bg);
  color:var(--neria-text); transition:all .15s; user-select:none;
}
.ns-pill input[type=checkbox] { display:none; }
.ns-pill:has(input:checked) {
  background:var(--neria-dark); color:#fff; border-color:var(--neria-dark);
}
.ns-pill-accent:has(input:checked) {
  background:var(--neria-accent); border-color:var(--neria-accent);
}
.ns-field { margin-bottom:18px; }
.ns-field .neria-label { margin-bottom:7px; display:block; }
.ns-row { display:grid; gap:20px; }
.ns-row--2 { grid-template-columns:1fr 1fr; }
.ns-row--3 { grid-template-columns:1fr 1fr 1fr; }
.ns-status-dot { display:inline-block; width:7px; height:7px; border-radius:50%; }
.ns-status-dot--on  { background:var(--neria-success); }
.ns-status-dot--off { background:var(--neria-border); }

/* Carte option (Actif / Mode cadeaux) */
.ns-option-card {
  flex:1; min-width:220px; border:1px solid var(--neria-border);
  border-radius:8px; padding:16px 20px;
}
.ns-option-card__label {
  font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
  color:var(--neria-text-light); margin-bottom:10px;
}
.ns-option-card--gift {
  border-color:var(--neria-accent); background:var(--neria-light-bg);
}
.ns-option-card--gift .ns-option-card__label { color:var(--neria-accent); }

/* Calendrier */
.ns-calendar-grid {
  display:grid; grid-template-columns:repeat(3,1fr); gap:14px;
}
.ns-calendar-month {
  background:var(--neria-light-bg,#faf8f5); border:1px solid var(--neria-border);
  border-radius:8px; padding:16px 16px 12px;
  display:flex; flex-direction:column;
}
.ns-calendar-month__name {
  font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
  color:var(--neria-accent); margin-bottom:12px; padding-bottom:10px;
  border-bottom:2px solid var(--neria-border); flex-shrink:0;
}
.ns-calendar-month__body {
  height:140px; overflow-y:auto; flex:1;
}
.ns-empty-month {
  display:flex; align-items:center; justify-content:center;
  height:100%; font-size:22px; color:var(--neria-border); letter-spacing:.1em;
}
.ns-campaign-badge {
  display:flex; align-items:flex-start; gap:7px; margin-bottom:8px;
}
.ns-campaign-badge__day {
  font-size:11px; font-weight:700; color:#fff; border-radius:4px;
  padding:2px 7px; background:var(--neria-accent); white-space:nowrap; flex-shrink:0; margin-top:1px;
}
.ns-campaign-badge__day--inactive { background:#ccc; }
.ns-campaign-badge__name {
  font-size:12px; color:var(--neria-text); line-height:1.35;
}
.ns-campaign-badge__name--inactive { color:var(--neria-text-light); }
.ns-campaign-badge__gift {
  font-size:10px; color:var(--neria-accent); font-weight:600;
}
.ns-empty-month { font-size:12px; color:var(--neria-border); }
</style>

{* ══════════════════════════════════════════════════════════════
   SECTION 1 — FORMULAIRE
══════════════════════════════════════════════════════════════ *}
<div class="neria-section">

  <h2 class="neria-section__title">◑ Campagnes saisonnières</h2>
  <p class="neria-section__desc">
    Définissez vos campagnes une fois — soldes, Black Friday, Noël, anniversaire boutique…
    Neria les envoie automatiquement chaque année aux clients éligibles, via le cron quotidien.
    Aucune action de votre part n'est requise après la configuration.
  </p>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:12px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Chaque jour, le cron Neria compare la date du jour avec vos campagnes configurées. Si une campagne est programmée pour aujourd'hui (ou dans N jours selon votre délai), les emails partent automatiquement aux clients éligibles. La déduplication est <strong>annuelle</strong> : un client ne reçoit jamais deux fois la même campagne la même année.
    <div style="font-weight:700;margin:16px 0 8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Mode d'emploi</div>
    <ol style="margin:0 0 0 18px;padding:0;">
      <li style="margin-bottom:6px;"><strong>Nom</strong> — un nom interne pour vous repérer dans la liste (non visible par le client).</li>
      <li style="margin-bottom:6px;"><strong>Template email</strong> — le design et le contenu envoyé. Choisissez <em>special_offer</em> pour une promotion, ou activez le <em>Mode Idées cadeaux</em> pour un email "offrir à quelqu'un".</li>
      <li style="margin-bottom:6px;"><strong>Date annuelle</strong> — format MM-JJ. Exemples : <em>12-25</em> = Noël · <em>11-29</em> = Black Friday · <em>05-25</em> = Fête des mères · <em>06-21</em> = Fête de la musique.</li>
      <li style="margin-bottom:6px;"><strong>N jours avant</strong> — anticiper l'envoi. <em>7</em> = une semaine avant · <em>0</em> = le jour J.</li>
      <li style="margin-bottom:6px;"><strong>Ciblage</strong> — affinez par genre, tranche d'âge, segment (Ambassador, Loyal…) et langue. Tout coché = tous les clients actifs.</li>
      <li><strong>Mode Idées cadeaux</strong> — email au ton "offrir à quelqu'un", réservé aux segments Ambassador + Loyal, avec une suggestion de produit personnalisée selon l'historique d'achat.</li>
    </ol>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e8d5b0;font-size:12px;opacity:.75;">
      <strong>Conseil :</strong> créez une campagne par occasion — ne fusionnez pas Noël et Black Friday dans la même campagne, vous perdriez la flexibilité du ciblage et du délai.
    </div>
  </div>

  <form method="post" action="{$tab_url|escape:'html'}&neria_action=save_seasonal_campaign">
    {if $is_edit}
      <input type="hidden" name="id_campaign" value="{$seasonal_edit.id_campaign|intval}">
    {/if}

    {* ── Rangée 1 : Nom + Template ── *}
    <div class="ns-row ns-row--2">
      <div class="ns-field">
        <label class="neria-label" for="ns-name">
          Nom de la campagne
          <span class="neria-hint">affiché dans le BO uniquement</span>
        </label>
        <input id="ns-name" type="text" name="seasonal_name" class="neria-input" required
               placeholder="Black Friday, Noël, Soldes d'été…"
               value="{if $is_edit}{$seasonal_edit.name|escape:'html'}{/if}">
      </div>
      <div class="ns-field">
        <label class="neria-label" for="ns-template">
          Template email
          <span class="neria-hint">sera envoyé aux clients éligibles</span>
        </label>
        <select id="ns-template" name="seasonal_template" class="neria-select" style="width:100%;" required>
          <option value="">— Choisir un template —</option>
          {foreach $seasonal_templates as $tplKey => $tplLabel}
            <option value="{$tplKey|escape:'html'}"
              {if $is_edit && $seasonal_edit.template === $tplKey} selected{/if}>
              {$tplLabel|escape:'html'}
            </option>
          {/foreach}
        </select>
      </div>
    </div>

    {* ── Rangée 2 : Date + Délai + Genre ── *}
    <div class="ns-row ns-row--3" style="align-items:start;">
      <div class="ns-field">
        <label class="neria-label" for="ns-date">Date annuelle (MM-JJ)</label>
        <input id="ns-date" type="text" name="seasonal_annual_date" class="neria-input" required
               pattern="(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])"
               placeholder="12-25" title="Format MM-JJ"
               value="{if $is_edit}{$seasonal_edit.annual_date|escape:'html'}{else}12-25{/if}">
        <span class="neria-hint">Ex : 11-25 = 25 nov · 12-25 = Noël · 07-14 = Fête nat.</span>
      </div>
      <div class="ns-field">
        <label class="neria-label" for="ns-days">Envoyer N jours avant</label>
        <div style="display:flex;align-items:center;gap:10px;">
          <input id="ns-days" type="number" name="seasonal_days_before" class="neria-input"
                 style="width:80px;" min="0" max="30"
                 value="{if $is_edit}{$seasonal_edit.days_before|intval}{else}0{/if}">
          <span class="neria-hint">0 = le jour J<br>3 = 3 jours avant</span>
        </div>
      </div>
      <div class="ns-field">
        <label class="neria-label" for="ns-gender">Genre cible</label>
        <select id="ns-gender" name="seasonal_gender" class="neria-select" style="width:100%;">
          <option value="0" {if !$is_edit || $seasonal_edit.target_gender == 0} selected{/if}>Tous (hommes + femmes)</option>
          <option value="1" {if $is_edit && $seasonal_edit.target_gender == 1} selected{/if}>Hommes uniquement (M.)</option>
          <option value="2" {if $is_edit && $seasonal_edit.target_gender == 2} selected{/if}>Femmes uniquement (Mme.)</option>
        </select>
      </div>
    </div>

    {* ── Rangée 3 : Tranche d'âge + Segments ── *}
    <div class="ns-row ns-row--2">
      <div class="ns-field">
        <label class="neria-label">
          Tranche d'âge
          <span class="neria-hint">0 = sans limite</span>
        </label>
        <div style="display:flex;align-items:center;gap:12px;">
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="font-size:12px;color:var(--neria-text-light);white-space:nowrap;">de</span>
            <input type="number" name="seasonal_min_age" class="neria-input"
                   style="width:72px;" min="0" max="120" placeholder="0"
                   value="{if $is_edit}{$seasonal_edit.min_age|intval}{else}0{/if}">
          </div>
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="font-size:12px;color:var(--neria-text-light);white-space:nowrap;">à</span>
            <input type="number" name="seasonal_max_age" class="neria-input"
                   style="width:72px;" min="0" max="120" placeholder="0"
                   value="{if $is_edit}{$seasonal_edit.max_age|intval}{else}0{/if}">
            <span style="font-size:12px;color:var(--neria-text-light);">ans</span>
          </div>
        </div>
      </div>
      <div class="ns-field">
        <label class="neria-label">
          Segments ciblés
          <span class="neria-hint">aucun coché = tous les clients</span>
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
          {foreach $seasonal_segments as $segKey => $segLabel}
            <label class="ns-pill">
              <input type="checkbox" name="seasonal_segments[]" value="{$segKey|escape:'html'}"
                {if $is_edit && isset($seasonal_edit_seg_map[$segKey])} checked
                {elseif !$is_edit} checked
                {/if}>
              {$segLabel|escape:'html'}
            </label>
          {/foreach}
        </div>
      </div>
    </div>

    {* ── Langues ── *}
    <div class="ns-field">
      <label class="neria-label">
        Langues ciblées
        <span class="neria-hint">aucune cochée = toutes les langues</span>
      </label>
      <div style="display:flex;flex-wrap:wrap;gap:6px;">
        {foreach $lang_labels as $code => $name}
          <label class="ns-pill ns-pill-accent">
            <input type="checkbox" name="seasonal_langs[]" value="{$code|escape:'html'}"
              {if $is_edit && isset($seasonal_edit_lang_map[$code])} checked
              {elseif !$is_edit} checked
              {/if}>
            {if isset($lang_flags[$code])}{$lang_flags[$code]}{/if} {$name|escape:'html'}
          </label>
        {/foreach}
      </div>
    </div>

    {* ── Options : Actif + Mode cadeaux ── *}
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:4px;margin-bottom:24px;">

      {* Statut *}
      <div class="ns-option-card">
        <div class="ns-option-card__label">Statut de la campagne</div>
        <input type="hidden" id="ns-is-active-val" name="seasonal_is_active" value="{if !$is_edit || $seasonal_edit.is_active}1{else}0{/if}">
        <button type="button" id="ns-btn-active"
          onclick="nsToggleBtn(this,'ns-is-active-val','Active','Inactive','#16a34a','#dc2626')"
          style="padding:8px 20px;border-radius:20px;border:none;cursor:pointer;font-size:13px;font-weight:700;
                 background:{if !$is_edit || $seasonal_edit.is_active}#16a34a{else}#dc2626{/if};color:#fff;">
          {if !$is_edit || $seasonal_edit.is_active}● Active{else}○ Inactive{/if}
        </button>
        <p class="neria-hint" style="margin-top:8px;">La campagne est exécutée automatiquement par le cron.</p>
      </div>

      {* Mode cadeaux *}
      <div class="ns-option-card ns-option-card--gift">
        <div class="ns-option-card__label">Mode Idées cadeaux</div>
        <input type="hidden" id="ns-gift-mode-val" name="seasonal_gift_mode" value="{if $is_edit && $seasonal_edit.gift_mode}1{else}0{/if}">
        <button type="button" id="ns-btn-gift"
          onclick="nsToggleBtn(this,'ns-gift-mode-val','Activé','Désactivé','#16a34a','#dc2626')"
          style="padding:8px 20px;border-radius:20px;border:none;cursor:pointer;font-size:13px;font-weight:700;
                 background:{if $is_edit && $seasonal_edit.gift_mode}#16a34a{else}#dc2626{/if};color:#fff;">
          {if $is_edit && $seasonal_edit.gift_mode}● Activé{else}○ Désactivé{/if}
        </button>
        <p class="neria-hint" style="margin-top:8px;">
          Template <strong>gift_ideas</strong> (ton "offrir") · segments
          <strong>Ambassador + Loyal</strong> automatiques · suggestion produit selon l'historique.
        </p>
      </div>

    </div>

    {* ── Boutons ── *}
    <div style="display:flex;align-items:center;gap:12px;padding-top:20px;border-top:1px solid var(--neria-border);">
      <button type="submit" class="neria-btn neria-btn--primary">
        {if $is_edit}✓ Enregistrer les modifications{else}＋ Créer la campagne{/if}
      </button>
      {if $is_edit}
        <a href="{$tab_url|escape:'html'}" class="neria-btn neria-btn--ghost">✕ Annuler</a>
      {/if}
    </div>

  </form>
</div>

{* ══════════════════════════════════════════════════════════════
   SECTION 2 — LISTE DES CAMPAGNES
══════════════════════════════════════════════════════════════ *}
{if $seasonal_campaigns|count > 0}
<div class="neria-section">
  <h2 class="neria-section__title">
    {$seasonal_campaigns|count} campagne{if $seasonal_campaigns|count > 1}s{/if} configurée{if $seasonal_campaigns|count > 1}s{/if}
  </h2>

  <div class="neria-bo-wrap">
  <div class="neria-table-wrap">
  <table class="neria-table">
    <thead>
      <tr>
        <th>Nom</th>
        <th>Template</th>
        <th style="text-align:center;">Date</th>
        <th style="text-align:center;">Envoi</th>
        <th>Ciblage</th>
        <th style="text-align:center;">Statut</th>
        <th style="text-align:center;">Actions</th>
      </tr>
    </thead>
    <tbody>
      {foreach $seasonal_campaigns as $c}
        {assign var="m_idx" value=$c.annual_date|substr:0:2|intval}
        {assign var="d_idx" value=$c.annual_date|substr:3:2|intval}
        <tr>
          <td>
            <strong>{$c.name|escape:'html'}</strong>
          </td>
          <td>
            {if $c.gift_mode}
              <span class="neria-var-tag" style="background:var(--neria-accent);color:#fff;">Idées cadeaux</span>
            {else}
              <span class="neria-var-tag">{$c.template|escape:'html'}</span>
            {/if}
          </td>
          <td style="text-align:center;white-space:nowrap;">
            <span class="neria-badge neria-badge--accent" style="font-size:11px;padding:4px 10px;">
              {$month_abbr[$m_idx]} {$d_idx}
            </span>
          </td>
          <td style="text-align:center;font-size:12px;color:var(--neria-text-light);">
            {if $c.days_before > 0}J–{$c.days_before|intval}{else}Jour J{/if}
          </td>
          <td style="font-size:12px;line-height:1.7;">
            {if $c.target_segment neq ''}
              <div style="color:var(--neria-text);">{$c.target_segment|escape:'html'}</div>
            {else}
              <div style="color:var(--neria-text-light);">Tous segments</div>
            {/if}
            {if $c.target_gender == 1}<div>♂ Hommes</div>
            {elseif $c.target_gender == 2}<div>♀ Femmes</div>
            {/if}
            {if $c.target_lang neq ''}
              <div style="color:var(--neria-text-light);font-size:11px;">{$c.target_lang|escape:'html'}</div>
            {/if}
            {if $c.min_age > 0 || $c.max_age > 0}
              <div style="color:var(--neria-text-light);">
                {$c.min_age|intval}–{if $c.max_age > 0}{$c.max_age|intval}{else}∞{/if} ans
              </div>
            {/if}
          </td>
          <td style="text-align:center;">
            <form method="post" action="{$tab_url|escape:'html'}&neria_action=toggle_seasonal_campaign"
                  style="display:inline;">
              <input type="hidden" name="id_campaign" value="{$c.id_campaign|intval}">
              <button type="submit"
                class="neria-btn neria-btn--sm"
                style="{if $c.is_active}background:var(--neria-success);color:#fff;border-color:var(--neria-success);{else}background:var(--neria-danger,#dc2626);color:#fff;border-color:var(--neria-danger,#dc2626);{/if}font-weight:700;">
                {if $c.is_active}● Actif{else}○ Inactif{/if}
              </button>
            </form>
          </td>
          <td style="text-align:center;white-space:nowrap;">
            <a href="{$tab_url|escape:'html'}&edit_campaign={$c.id_campaign|intval}"
               class="neria-btn neria-btn--sm neria-btn--ghost" title="Modifier">
              ✏ Modifier
            </a>
            <form method="post"
                  action="{$tab_url|escape:'html'}&neria_action=delete_seasonal_campaign"
                  style="display:inline;margin-left:4px;"
                  onsubmit="return confirm('Supprimer la campagne « {$c.name|escape:'javascript'} » ?');">
              <input type="hidden" name="id_campaign" value="{$c.id_campaign|intval}">
              <button type="submit" class="neria-btn neria-btn--sm neria-btn--danger"
                      title="Supprimer définitivement">✕</button>
            </form>
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>
  </div>
  </div>
</div>
{else}
<div class="neria-section" style="text-align:center;padding:40px 28px;">
  <div style="font-size:32px;color:var(--neria-border);margin-bottom:12px;">◑</div>
  <p style="font-size:14px;color:var(--neria-text-light);margin:0 0 20px;">
    Aucune campagne saisonnière configurée.<br>
    Utilisez le formulaire ci-dessus pour créer votre première campagne.
  </p>
  <div style="font-size:12px;color:var(--neria-text-light);line-height:1.8;">
    Exemples : Noël → template <em>special_offer</em> · date 12-25<br>
    Black Friday → template <em>special_offer</em> · date 11-25 · J–3<br>
    Soldes d'été → template <em>special_offer</em> · date 06-28
  </div>
</div>
{/if}

{* ══════════════════════════════════════════════════════════════
   SECTION 3 — CALENDRIER ANNUEL
══════════════════════════════════════════════════════════════ *}
<div class="neria-section">
  <h2 class="neria-section__title">Calendrier annuel</h2>

  <div class="ns-calendar-grid">
    {foreach from=$seasonal_calendar key=mNum item=mCampaigns}
      <div class="ns-calendar-month">
        <div class="ns-calendar-month__name">{$month_names[$mNum]}</div>
        <div class="ns-calendar-month__body">
          {if $mCampaigns|count > 0}
            {foreach $mCampaigns as $mc}
              <div class="ns-campaign-badge">
                <span class="ns-campaign-badge__day{if !$mc.is_active} ns-campaign-badge__day--inactive{/if}">
                  {$mc.day|intval}{if $mc.days_before > 0}<span style="font-weight:400;opacity:.8;font-size:10px;"> J-{$mc.days_before|intval}</span>{/if}
                </span>
                <span class="ns-campaign-badge__name{if !$mc.is_active} ns-campaign-badge__name--inactive{/if}">
                  {$mc.name|truncate:24:'…'|escape:'html'}
                  {if $mc.gift_mode}<br><span class="ns-campaign-badge__gift">Idées cadeaux</span>{/if}
                </span>
              </div>
            {/foreach}
          {else}
            <div class="ns-empty-month">—</div>
          {/if}
        </div>
      </div>
    {/foreach}
  </div>

  <p style="margin-top:14px;font-size:12px;color:var(--neria-text-light);line-height:1.8;">
    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--neria-accent);vertical-align:middle;margin-right:4px;"></span> Campagne active &nbsp;·&nbsp;
    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#ccc;vertical-align:middle;margin-right:4px;"></span> Campagne inactive &nbsp;·&nbsp;
    J–N = envoi N jours avant la date &nbsp;·&nbsp;
    Le cron quotidien déclenche automatiquement.
  </p>
</div>

<script>
function nsToggleBtn(btn, inputId, labelOn, labelOff, colorOn, colorOff) {
  var input = document.getElementById(inputId);
  var isOn  = input.value === '1';
  input.value       = isOn ? '0' : '1';
  btn.textContent   = isOn ? '○ ' + labelOff : '● ' + labelOn;
  btn.style.background = isOn ? colorOff : colorOn;
}

(function(){
  document.querySelectorAll('.ns-pill input[type=checkbox]').forEach(function(cb){
    cb.addEventListener('change', function(){
      var pill = this.closest('.ns-pill');
      var isAccent = pill.classList.contains('ns-pill-accent');
      pill.style.background = this.checked ? (isAccent ? 'var(--neria-accent)' : 'var(--neria-dark)') : '';
      pill.style.color  = this.checked ? '#fff' : '';
      pill.style.borderColor = this.checked ? (isAccent ? 'var(--neria-accent)' : 'var(--neria-dark)') : '';
    });
    if(cb.checked){
      var pill = cb.closest('.ns-pill');
      var isAccent = pill.classList.contains('ns-pill-accent');
      pill.style.background = isAccent ? 'var(--neria-accent)' : 'var(--neria-dark)';
      pill.style.color = '#fff';
      pill.style.borderColor = isAccent ? 'var(--neria-accent)' : 'var(--neria-dark)';
    }
  });
})();
</script>
