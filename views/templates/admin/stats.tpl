{**
 * NERIA — stats.tpl
 * Onglet Statistiques — KPIs, rapports par template/langue/pays
 *}

{* ── Filtre période ─────────────────────────────────────────── *}
<div class="neria-section">
  <div class="neria-stats-filters">
    {foreach [7, 30, 90] as $period}
      <a href="{$smarty.server.REQUEST_URI}&neria_tab=stats&stats_days={$period}"
         class="neria-period-btn {if $stats_days == $period}neria-period-btn--active{/if}">
        {$period} {l s='jours' mod='neria'}
      </a>
    {/foreach}
    <span class="neria-stats-computed">
      {l s='Calculé le' mod='neria'} {$stats.computed_at|default:'—'}
    </span>
  </div>
</div>

{* ── KPIs ───────────────────────────────────────────────────── *}
<div class="neria-section">
  <div class="neria-kpi-grid neria-kpi-grid--large">

    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$stats.kpis.total_sent|default:0|number_format:0:',':' '}</div>
      <div class="neria-kpi__label">{l s='Emails envoyés' mod='neria'}</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.total_open|default:0|number_format:0:',':' '}</div>
      <div class="neria-kpi__label">{l s='Ouvertures' mod='neria'}</div>
      <div class="neria-kpi__rate">{$stats.kpis.rate_open|default:0}%</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.total_click|default:0|number_format:0:',':' '}</div>
      <div class="neria-kpi__label">{l s='Clics' mod='neria'}</div>
      <div class="neria-kpi__rate">{$stats.kpis.rate_click|default:0}%</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.active_langs|default:0}</div>
      <div class="neria-kpi__label">{l s='Langues actives' mod='neria'}</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.active_countries|default:0}</div>
      <div class="neria-kpi__label">{l s='Pays' mod='neria'}</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.active_templates|default:0}</div>
      <div class="neria-kpi__label">{l s='Templates actifs' mod='neria'}</div>
    </div>

  </div>
</div>

{* ── Rapport par template ───────────────────────────────────── *}
{if isset($stats.global_30) && $stats.global_30}
<div class="neria-section">
  <h2 class="neria-section__title">{l s='Par template' mod='neria'}</h2>

  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>{l s='Template' mod='neria'}</th>
          <th class="neria-table__num">{l s='Envoyés' mod='neria'}</th>
          <th class="neria-table__num">{l s='Ouvertures' mod='neria'}</th>
          <th class="neria-table__num">{l s='Taux ouv.' mod='neria'}</th>
          <th class="neria-table__num">{l s='Clics' mod='neria'}</th>
          <th class="neria-table__num">{l s='Taux clic' mod='neria'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $stats.global_30 as $row}
          <tr>
            <td>
              <span class="neria-template-label">
                {$template_labels[$row.template]|default:$row.template}
              </span>
            </td>
            <td class="neria-table__num">{$row.total_sent|number_format:0:',':' '}</td>
            <td class="neria-table__num">{$row.total_open|number_format:0:',':' '}</td>
            <td class="neria-table__num">
              <span class="neria-rate {if $row.rate_open > 30}neria-rate--good{elseif $row.rate_open > 15}neria-rate--ok{else}neria-rate--low{/if}">
                {$row.rate_open}%
              </span>
            </td>
            <td class="neria-table__num">{$row.total_click|number_format:0:',':' '}</td>
            <td class="neria-table__num">
              <span class="neria-rate {if $row.rate_click > 5}neria-rate--good{elseif $row.rate_click > 2}neria-rate--ok{else}neria-rate--low{/if}">
                {$row.rate_click}%
              </span>
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
</div>
{/if}

{* ── Rapport par langue ─────────────────────────────────────── *}
{if isset($stats.by_lang_30) && $stats.by_lang_30}
<div class="neria-section">
  <h2 class="neria-section__title">{l s='Par langue' mod='neria'}</h2>

  <div class="neria-lang-stats">
    {foreach $stats.by_lang_30 as $row}
      <div class="neria-lang-stat-card">
        <div class="neria-lang-stat-card__flag">
          {$lang_flags[$row.lang]|default:'🌐'}
        </div>
        <div class="neria-lang-stat-card__lang">
          {$lang_labels[$row.lang]|default:$row.lang}
        </div>
        <div class="neria-lang-stat-card__sent">
          {$row.total_sent} {l s='envois' mod='neria'}
        </div>
        <div class="neria-lang-stat-card__bar">
          <div class="neria-bar">
            <div class="neria-bar__fill neria-bar__fill--open"
                 style="width:{$row.rate_open}%"
                 title="{l s='Ouvertures' mod='neria'}: {$row.rate_open}%"></div>
          </div>
          <div class="neria-bar">
            <div class="neria-bar__fill neria-bar__fill--click"
                 style="width:{$row.rate_click}%"
                 title="{l s='Clics' mod='neria'}: {$row.rate_click}%"></div>
          </div>
        </div>
        <div class="neria-lang-stat-card__rates">
          <span title="{l s='Taux d\'ouverture' mod='neria'}">{$row.rate_open}%</span>
          <span title="{l s='Taux de clic' mod='neria'}">{$row.rate_click}%</span>
        </div>
      </div>
    {/foreach}
  </div>
</div>
{/if}

{* ── Rapport par pays ───────────────────────────────────────── *}
{if isset($stats.by_country_30) && $stats.by_country_30}
<div class="neria-section">
  <h2 class="neria-section__title">{l s='Top pays' mod='neria'}</h2>

  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>{l s='Pays' mod='neria'}</th>
          <th class="neria-table__num">{l s='Envoyés' mod='neria'}</th>
          <th class="neria-table__num">{l s='Taux ouv.' mod='neria'}</th>
          <th class="neria-table__num">{l s='Taux clic' mod='neria'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $stats.by_country_30 as $row}
          <tr>
            <td><strong>{$row.country_code}</strong></td>
            <td class="neria-table__num">{$row.total_sent|number_format:0:',':' '}</td>
            <td class="neria-table__num">{$row.rate_open}%</td>
            <td class="neria-table__num">{$row.rate_click}%</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
</div>
{/if}

{if !isset($stats.global_30) || !$stats.global_30}
  <div class="neria-empty-state">
    <span class="neria-empty-state__icon">◫</span>
    <p>{l s='Aucune statistique disponible pour le moment. Les données apparaîtront après le premier envoi d\'email.' mod='neria'}</p>
  </div>
{/if}
