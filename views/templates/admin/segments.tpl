{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — segments.tpl
 * Tableau de bord de segmentation comportementale des clients.
 * 5 segments calculés quotidiennement depuis les stats email.
 *}

{assign var="seg_icons" value=['ambassador'=>'✦','loyal'=>'◎','warm'=>'◷','dormant'=>'◌','ghost'=>'○']}
{assign var="seg_colors" value=['ambassador'=>'#b38b59','loyal'=>'#6a8fb8','warm'=>'#7db87d','dormant'=>'#b8a46a','ghost'=>'#aaaaaa']}

<div class="neria-section">
  <h2 class="neria-section__title">{neria_admin key='seg.title'}</h2>
  <p class="neria-section__desc">{neria_admin key='seg.desc'}</p>

  {* ── Cartes segments ──────────────────────────────────────────── *}
  <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-top:24px;">
    {foreach $segment_all as $seg}
    {assign var="seg_count" value=$segment_counts[$seg]|default:0}
    <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&filter_segment=[^&]*/':''}&neria_tab=segments&filter_segment={$seg}"
       style="text-decoration:none;">
      <div class="neria-card" style="text-align:center;padding:20px 12px;border-top:3px solid {$seg_colors[$seg]};cursor:pointer;{if $segment_filter === $seg}box-shadow:0 0 0 2px {$seg_colors[$seg]};{/if}">
        <div style="font-size:24px;margin-bottom:6px;">{$seg_icons[$seg]}</div>
        <div style="font-size:26px;font-weight:600;color:{$seg_colors[$seg]};">{$seg_count}</div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin-top:4px;color:var(--neria-text-muted,#888);">
          {neria_admin key="seg.label_{$seg}"}
        </div>
      </div>
    </a>
    {/foreach}
  </div>

  {* ── Recalcul manuel ─────────────────────────────────────────── *}
  <div style="margin-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <form method="post" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="neria_action" value="recompute_segments">
      <input type="hidden" name="neria_tab"    value="segments">
      <input type="hidden" name="filter_segment" value="{$segment_filter|escape:'html'}">
      <button type="submit" class="neria-btn neria-btn--sm">
        ↻ {neria_admin key='seg.recompute'}
      </button>
    </form>
    <span class="neria-hint">{neria_admin key='seg.recompute_hint'}</span>
  </div>

  {* ── Liste des clients du segment sélectionné ──────────────── *}
  <div class="neria-card" style="margin-top:28px;">
    <h3 class="neria-card__title">
      {$seg_icons[$segment_filter]}
      {neria_admin key="seg.label_{$segment_filter}"}
      <span class="neria-badge neria-badge--neutral" style="margin-left:8px;font-size:12px;">
        {$segment_counts[$segment_filter]|default:0}
      </span>
    </h3>

    {* Description du segment *}
    <p class="neria-hint" style="margin-bottom:16px;">{neria_admin key="seg.desc_{$segment_filter}"}</p>

    {if $segment_customers|@count > 0}
    <table class="neria-table">
      <thead>
        <tr>
          <th>{neria_admin key='seg.col_name'}</th>
          <th>{neria_admin key='seg.col_email'}</th>
          <th style="text-align:center;">{neria_admin key='seg.col_opens'}</th>
          <th style="text-align:center;">{neria_admin key='seg.col_conversions'}</th>
          <th>{neria_admin key='seg.col_last_open'}</th>
          <th style="text-align:center;">Langue</th>
          <th style="text-align:center;">Tranche horaire</th>
          <th style="text-align:center;" title="Score de risque de désabonnement individuel (0 = pas de risque, 100 = risque maximal). Calculé sur 90 jours d'historique.">
            Risque désab. <span style="font-size:10px;color:var(--neria-text-muted,#aaa);">0–100</span>
          </th>
        </tr>
      </thead>
      <tbody>
        {foreach $segment_customers as $c}
        {assign var="cscore" value=$c.churn_score|intval}
        <tr>
          <td>{$c.firstname|escape:'html'} {$c.lastname|escape:'html'}</td>
          <td style="font-size:12px;color:var(--neria-text-muted,#888);">{$c.email|escape:'html'}</td>
          <td style="text-align:center;">{$c.total_opens|intval}</td>
          <td style="text-align:center;">{$c.total_conversions|intval}</td>
          <td style="font-size:12px;color:var(--neria-text-muted,#888);">
            {if $c.last_open}{$c.last_open|escape:'html'}{else}—{/if}
          </td>
          <td style="text-align:center;font-weight:600;font-size:12px;">
            {if $c.lang_code}{$c.lang_code|upper|escape:'html'}{else}—{/if}
          </td>
          <td style="text-align:center;font-size:12px;color:var(--neria-text-muted,#888);">
            {if $c.preferred_slot}{neria_admin key="seg.slot_{$c.preferred_slot}"}{else}—{/if}
          </td>
          <td style="text-align:center;">
            {if $c.churn_score !== null}
              {assign var="sc" value=$cscore}
              <span style="font-weight:600;color:{if $sc >= 85}#c0392b{elseif $sc >= 70}#b8600a{elseif $sc >= 50}#888{else}#4a9e6b{/if};">
                {$sc}/100
              </span>
            {else}
              <span style="color:var(--neria-text-muted,#ccc);" title="Lancer un recalcul des scores">—</span>
            {/if}
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
    {if $segment_counts[$segment_filter]|default:0 > 50}
    <p class="neria-hint" style="margin-top:8px;">{neria_admin key='seg.truncated'}</p>
    {/if}
    {else}
    <p class="neria-hint">{neria_admin key='seg.no_customers'}</p>
    {/if}
  </div>

  {* ── Séparateur section Prédiction ─────────────────────────── *}
  <div style="margin-top:40px;margin-bottom:24px;display:flex;align-items:center;gap:16px;">
    <div style="flex:1;height:1px;background:var(--neria-border,#e8e0d5);"></div>
    <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--neria-text-muted,#aaa);white-space:nowrap;">
      {neria_admin key='seg.churn_separator'}
    </div>
    <div style="flex:1;height:1px;background:var(--neria-border,#e8e0d5);"></div>
  </div>

  {* ── Clients à risque de désabonnement ─────────────────────── *}
  <div class="neria-card" style="border-top:3px solid #e05c5c;">
    <h3 class="neria-card__title" style="color:#c0392b;">
      ⚠ {neria_admin key='churn.section_title'}
      {if $churn_high_risk|@count > 0}
        <span class="neria-badge" style="background:#e05c5c;color:#fff;margin-left:8px;">{$churn_high_risk|@count}</span>
      {/if}
    </h3>
    <p class="neria-hint" style="margin-bottom:4px;">{neria_admin key='churn.section_desc'}</p>
    <p class="neria-hint" style="margin-bottom:16px;font-style:italic;">{neria_admin key='seg.churn_allcustomers'}</p>

    {* Bouton recalcul *}
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-bottom:16px;">
      <input type="hidden" name="neria_action" value="recompute_churn">
      <input type="hidden" name="neria_tab"    value="segments">
      <button type="submit" class="neria-btn neria-btn--sm">
        ↻ {neria_admin key='churn.recompute'}
      </button>
      <span class="neria-hint" style="margin-left:8px;">{neria_admin key='seg.recompute_hint'}</span>
    </form>

    {if $churn_high_risk|@count > 0}
    <table class="neria-table">
      <thead>
        <tr>
          <th>{neria_admin key='seg.col_name'}</th>
          <th>{neria_admin key='seg.col_email'}</th>
          <th style="text-align:center;">{neria_admin key='churn.col_score'}</th>
          <th style="text-align:center;">{neria_admin key='churn.col_trend'}</th>
          <th>{neria_admin key='churn.col_last_open'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $churn_high_risk as $c}
        {assign var="level" value='high'}
        {if $c.score >= 85}{assign var="level" value='critical'}{/if}
        <tr>
          <td>{$c.firstname|escape:'html'} {$c.lastname|escape:'html'}</td>
          <td style="font-size:12px;color:var(--neria-text-muted,#888);">{$c.email|escape:'html'}</td>
          <td style="text-align:center;">
            <span style="font-size:18px;font-weight:700;color:{if $level === 'critical'}#c0392b{else}#b8600a{/if};">
              {$c.score|intval}
            </span>
            <span style="font-size:10px;color:var(--neria-text-muted,#888);">/100</span>
          </td>
          <td style="text-align:center;font-size:11px;color:var(--neria-text-muted,#888);">
            {($c.rate_p3*100)|number_format:0}%→{($c.rate_p2*100)|number_format:0}%→{($c.rate_p1*100)|number_format:0}%
          </td>
          <td style="font-size:12px;color:var(--neria-text-muted,#888);">
            {if $c.last_open}{$c.last_open|escape:'html'|substr:0:10}{else}—{/if}
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
    {else}
    <p class="neria-hint">{neria_admin key='churn.no_risk'}</p>
    {/if}
  </div>

  {* ── Potentiel client 12 mois ──────────────────────────────── *}
  {if isset($clv_top_customers) && $clv_top_customers|@count > 0}
  <div style="margin-top:40px;margin-bottom:24px;display:flex;align-items:center;gap:16px;">
    <div style="flex:1;height:1px;background:var(--neria-border,#e8e0d5);"></div>
    <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--neria-text-muted,#aaa);white-space:nowrap;">
      {neria_admin key='clv.title'} — Top 20
    </div>
    <div style="flex:1;height:1px;background:var(--neria-border,#e8e0d5);"></div>
  </div>

  <div class="neria-card" style="border-top:3px solid #b38b59;">
    <table class="neria-table">
      <thead>
        <tr>
          <th>#</th>
          <th>{neria_admin key='seg.col_name'}</th>
          <th>{neria_admin key='seg.col_email'}</th>
          <th style="text-align:center;">{neria_admin key='clv.title'}</th>
          <th style="text-align:center;">{neria_admin key='clv.avg_order'}</th>
          <th style="text-align:center;">{neria_admin key='clv.engagement'}</th>
          <th style="text-align:center;">{neria_admin key='clv.segment'}</th>
          <th style="text-align:center;">{neria_admin key='clv.churn_risk'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $clv_top_customers as $c key=i}
        {assign var="clv_color" value='#27ae60'}
        {if $c.clv_label === 'medium'}{assign var="clv_color" value='#b38b59'}{/if}
        {if $c.clv_label === 'low'}{assign var="clv_color" value='#888'}{/if}
        <tr>
          <td style="font-size:12px;color:var(--neria-text-muted,#aaa);">{$i+1}</td>
          <td style="font-weight:600;">{$c.customer_name|escape:'html'}</td>
          <td style="font-size:12px;color:var(--neria-text-muted,#888);">{$c.email|escape:'html'}</td>
          <td style="text-align:center;font-size:16px;font-weight:700;color:{$clv_color};">
            {$c.clv_12m|number_format:0} {$c.currency_symbol}
          </td>
          <td style="text-align:center;font-size:12px;">{$c.avg_order|number_format:0} {$c.currency_symbol}</td>
          <td style="text-align:center;font-size:12px;
              color:{if $c.engagement_label === 'high'}#27ae60{elseif $c.engagement_label === 'medium'}#b38b59{else}#c0392b{/if};">
            {$c.engagement_rate}%
          </td>
          <td style="text-align:center;font-size:12px;">
            {if $c.segment_label === 'ambassador'}🏆{elseif $c.segment_label === 'loyal'}⭐{elseif $c.segment_label === 'warm'}🌱{elseif $c.segment_label === 'dormant'}😴{elseif $c.segment_label === 'ghost'}👻{/if}
          </td>
          <td style="text-align:center;font-size:12px;
              font-weight:600;color:{if $c.churn_label === 'high'}#c0392b{elseif $c.churn_label === 'medium'}#e09c3c{else}#27ae60{/if};">
            {$c.churn_score}/100
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
    <p style="margin-top:8px;font-size:11px;color:var(--neria-text-muted,#aaa);">
      {neria_admin key='clv.formula'} : {neria_admin key='clv.formula_desc'}
      <span style="float:right;color:#b38b59;font-weight:600;">✦ Neria</span>
    </p>
  </div>
  {/if}

  {* ── Lancer une campagne ────────────────────────────────────── *}
  <div class="neria-card" style="margin-top:24px;">
    <h3 class="neria-card__title">{neria_admin key='seg.campaign_title'}</h3>
    <p class="neria-hint" style="margin-bottom:16px;">{neria_admin key='seg.campaign_desc'}</p>

    {* Notice explicative *}
    <div style="background:#faf6f0;border:1px solid #e8dcc8;border-left:3px solid #b38b59;border-radius:6px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:#555;line-height:1.6;">
      <div style="font-weight:700;color:#1a1a2e;margin-bottom:8px;">✦ {neria_admin key='seg.howto_title'}</div>
      <p style="margin:0 0 8px;">{neria_admin key='seg.howto_intro'}</p>
      <ol style="margin:0 0 8px;padding-left:18px;">
        <li style="margin-bottom:4px;">{neria_admin key='seg.howto_step1'}</li>
        <li style="margin-bottom:4px;">{neria_admin key='seg.howto_step2'}</li>
        <li style="margin-bottom:4px;">{neria_admin key='seg.howto_step3'}</li>
        <li>{neria_admin key='seg.howto_step4'}</li>
      </ol>
      <p style="margin:0;color:#888;font-size:12px;">💡 {neria_admin key='seg.howto_tip'}</p>
    </div>

    <form method="post" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="neria_action" value="send_segment_campaign">
      <input type="hidden" name="neria_tab"    value="segments">

      {* Ligne 1 : Segment + Template *}
      <div class="neria-form-grid" style="grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">

        <div class="neria-form-group" style="margin:0;">
          <label class="neria-label">{neria_admin key='seg.campaign_segment'}</label>
          <select name="campaign_segment" id="seg-segment-select" class="neria-select"
                  onchange="neriaUpdateRecommended(this.value)">
            {foreach $segment_all as $seg}
            <option value="{$seg|escape:'html'}" {if $segment_filter === $seg}selected{/if}>
              {$seg_icons[$seg]} {neria_admin key="seg.label_{$seg}"}
            </option>
            {/foreach}
          </select>
        </div>

        <div class="neria-form-group" style="margin:0;">
          <label class="neria-label">{neria_admin key='seg.campaign_template'}</label>
          <select name="campaign_template" id="seg-template-select" class="neria-select">
            {foreach $segment_campaign_templates as $tpl}
            <option value="{$tpl|escape:'html'}"
              data-recommended="{if isset($segment_recommended[$segment_filter]) && $segment_recommended[$segment_filter] === $tpl}1{else}0{/if}">
              {$tpl|escape:'html'}
              {if isset($segment_recommended[$segment_filter]) && $segment_recommended[$segment_filter] === $tpl} ★{/if}
            </option>
            {/foreach}
          </select>
          <span class="neria-hint">{neria_admin key='seg.campaign_template_hint'}</span>
        </div>

      </div>

      {* Ligne 2 : Filtres (optionnels) *}
      <div style="background:var(--neria-bg-soft,#f8f5f0);border:1px solid var(--neria-border,#e8e0d5);border-radius:6px;padding:14px 16px;margin-bottom:14px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-text-muted,#aaa);margin-bottom:10px;">
          {neria_admin key='seg.filter_title'} <span style="font-weight:400;">{neria_admin key='seg.filter_optional'}</span>
        </div>
        <div class="neria-form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:12px;">

          <div class="neria-form-group" style="margin:0;">
            <label class="neria-label">{neria_admin key='seg.filter_slot'}</label>
            <select name="campaign_slot" class="neria-select">
              <option value="">{neria_admin key='seg.filter_all_slots'}</option>
              {foreach $segment_slots as $slot_key => $slot_label}
              <option value="{$slot_key|escape:'html'}">{neria_admin key="seg.slot_{$slot_key}"}</option>
              {/foreach}
            </select>
          </div>

          <div class="neria-form-group" style="margin:0;">
            <label class="neria-label">{neria_admin key='seg.filter_lang'}</label>
            <select name="campaign_lang" class="neria-select">
              <option value="">{neria_admin key='seg.filter_all_langs'}</option>
              {foreach $segment_languages as $lang}
              <option value="{$lang.iso|escape:'html'}">{$lang.name|escape:'html'}</option>
              {/foreach}
            </select>
          </div>

          <div class="neria-form-group" style="margin:0;">
            <label class="neria-label">{neria_admin key='seg.filter_country'}</label>
            <select name="campaign_country" class="neria-select">
              <option value="">{neria_admin key='seg.filter_all_countries'}</option>
              {foreach $segment_countries as $country}
              <option value="{$country.id_country|intval}">{$country.name|escape:'html'}</option>
              {/foreach}
            </select>
          </div>

        </div>
      </div>

      {* Bouton envoi *}
      <div>
        <button type="button" class="neria-btn neria-btn--primary"
                data-confirm="{neria_admin key='seg.campaign_confirm' esc='html'}"
                onclick="neriaConfirmDelete(this);">
          ✉ {neria_admin key='seg.campaign_send'}
        </button>
        <span class="neria-hint" style="margin-left:10px;">{neria_admin key='seg.filter_cumulate'}</span>
      </div>
    </form>
  </div>

</div>

<script>
{assign var="rec_json" value=$segment_recommended|json_encode}
var neriaSegRec = {$rec_json};

function neriaUpdateRecommended(seg) {
  var sel = document.getElementById('seg-template-select');
  var rec = neriaSegRec[seg] || '';
  for (var i = 0; i < sel.options.length; i++) {
    var opt = sel.options[i];
    var tpl = opt.value;
    var star = (tpl === rec) ? ' ★' : '';
    opt.text = tpl + star;
    if (tpl === rec) { sel.selectedIndex = i; }
  }
}
</script>
