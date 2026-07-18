{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — stats.tpl
 * Onglet Statistiques — KPIs, rapports par template/langue/pays
 * i18n : libellés via {neria_admin key='...'} (19 langues, AdminTranslator)
 *}

{* ══════════════════════════════════════════════════════════════
   SCORE DE SANTÉ GLOBAL + BANDEAU KPI TENDANCES
   ══════════════════════════════════════════════════════════════ *}
<div class="neria-section" id="neria-health-kpi-banner" style="padding:20px 24px;">

  {* ── Score de santé ── *}
  {assign var="hs" value=$health_score}
  {assign var="hs_total" value=$hs.total|default:0}
  {assign var="hs_pct"   value=$hs.score_pct|default:0}
  {if $hs_pct >= 90}{assign var="hs_color" value='#16a34a'}{assign var="hs_grade" value='A'}
  {elseif $hs_pct >= 70}{assign var="hs_color" value='#d97706'}{assign var="hs_grade" value='B'}
  {else}{assign var="hs_color" value='#dc2626'}{assign var="hs_grade" value='C'}{/if}

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:16px;">
      <div style="position:relative;flex-shrink:0;">
        <svg width="64" height="64" viewBox="0 0 64 64">
          <circle cx="32" cy="32" r="26" fill="none" stroke="var(--neria-border)" stroke-width="7"/>
          <circle cx="32" cy="32" r="26" fill="none" stroke="{$hs_color}" stroke-width="7"
                  stroke-dasharray="{math equation='163 * pct / 100' pct=$hs_pct} 163"
                  stroke-dashoffset="41" stroke-linecap="round"
                  transform="rotate(-90 32 32)"/>
          <text x="32" y="29" text-anchor="middle" font-size="14" font-weight="700" fill="{$hs_color}">{$hs_grade}</text>
          <text x="32" y="43" text-anchor="middle" font-size="9" fill="{$hs_color}">{$hs_pct}%</text>
        </svg>
      </div>
      <div>
        <div style="font-size:16px;font-weight:700;color:var(--neria-dark);">{neria_admin key='stats.health_title'}</div>
        <div style="font-size:12px;color:var(--neria-muted);margin-top:2px;">
          {$hs.ok|default:0} {neria_admin key='stats.health_ok_suffix'}
          {if ($hs.warning|default:0) > 0} · <span style="color:#d97706;">{$hs.warning} {neria_admin key='stats.health_alerts_suffix'}</span>{/if}
          {if ($hs.error|default:0)   > 0} · <span style="color:#dc2626;">{$hs.error} {neria_admin key='stats.health_errors_suffix'}</span>{/if}
          / {$hs_total} {neria_admin key='stats.chart_total_btn'}
        </div>
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=help"
           style="font-size:11px;color:var(--neria-accent);text-decoration:none;margin-top:4px;display:inline-block;">
          → {neria_admin key='stats.health_diag_link'}
        </a>
      </div>
    </div>

    <div style="font-size:11px;color:var(--neria-muted);text-align:right;">
      {neria_admin key='stats.trends_subtitle'}
    </div>
  </div>

  {* ── Bandeau KPI tendances ── *}
  {assign var="tr" value=$kpi_trends}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;">

    {capture name="lbl_sent"}{neria_admin key='common.emails_sent'}{/capture}
    {capture name="lbl_open_rate"}{neria_admin key='stats.kpi_open_rate'}{/capture}
    {capture name="lbl_click_rate"}{neria_admin key='stats.kpi_click_rate'}{/capture}
    {capture name="lbl_unsubs"}{neria_admin key='stats.kpi_unsubs'}{/capture}
    {capture name="lbl_revenue"}{neria_admin key='stats.revenue_total'}{/capture}
    {foreach [
      ['key'=>'sent',       'label'=>$smarty.capture.lbl_sent,       'format'=>'int',     'icon'=>'✉'],
      ['key'=>'open_rate',  'label'=>$smarty.capture.lbl_open_rate,   'format'=>'pct',     'icon'=>'◉'],
      ['key'=>'click_rate', 'label'=>$smarty.capture.lbl_click_rate,        'format'=>'pct',     'icon'=>'↗'],
      ['key'=>'unsubs',     'label'=>$smarty.capture.lbl_unsubs,   'format'=>'int',     'icon'=>'✕'],
      ['key'=>'revenue',    'label'=>$smarty.capture.lbl_revenue,      'format'=>'money',   'icon'=>'◈']
    ] as $kpit}
      {assign var="kd" value=$tr[$kpit.key]|default:[]}
      {assign var="delta"  value=$kd.delta|default:null}
      {assign var="isGood" value=$kd.good|default:null}
      {assign var="cur"    value=$kd.current|default:0}

      <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:14px 16px;">
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-muted);margin-bottom:6px;">
          {$kpit.icon} {$kpit.label}
        </div>
        <div style="font-size:22px;font-weight:700;color:var(--neria-dark);line-height:1;">
          {if $kpit.format == 'money'}{$cur|string_format:"%.2f"} {$currency_symbol}
          {elseif $kpit.format == 'pct'}{$cur}%
          {else}{$cur|number_format:0:',':' '}{/if}
        </div>
        {if $delta !== null}
          <div style="font-size:11px;font-weight:600;color:{if $isGood}#16a34a{else}#dc2626{/if};margin-top:5px;">
            {if $delta > 0}▲{else}▼{/if} {$delta|abs}% {neria_admin key='stats.vs_last_week'}
          </div>
        {else}
          <div style="font-size:11px;color:var(--neria-muted);margin-top:5px;">— {neria_admin key='stats.no_prev_week_data'}</div>
        {/if}
      </div>
    {/foreach}

  </div>
</div>

{* ── Revenus Attribués — graphique + KPIs + tableau ─────────── *}
<div class="neria-section" id="neria-revenue-attribution">

  {* Bloc explicatif last-click *}
  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.revenue_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.revenue_howto_window'}
    </div>
  </div>

  {* KPIs attribution 90j *}
  {if isset($revenue) && $revenue.total_orders > 0}
  <div class="neria-kpi-grid neria-kpi-grid--large" style="margin-bottom:24px;">
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$revenue.total_revenue|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.revenue_total'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$revenue.total_orders}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.revenue_orders'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$revenue.avg_order|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.revenue_avg_order'}</div>
    </div>
  </div>
  {/if}

  {* Graphique CA par catégorie *}
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0;">{neria_admin key='stats.revenue_chart_title'} ◈</h2>
      <p class="neria-section__desc" style="margin:4px 0 0;">{neria_admin key='stats.revenue_chart_desc'}</p>
      <div style="margin-top:10px;display:flex;align-items:baseline;gap:8px;">
        <span id="neria-chart-total-amount" style="font-size:28px;font-weight:700;color:var(--neria-dark);letter-spacing:-.5px;">—</span>
        <span style="font-size:12px;color:#999;" id="neria-chart-total-label">{neria_admin key='stats.revenue_total_period_label'}</span>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <div class="neria-chart-type-nav">
        <button class="neria-chart-arrow" id="neria-chart-prev" title="{neria_admin key='stats.chart_type_prev'}">&#9664;</button>
        <span id="neria-chart-type-label" style="min-width:80px;text-align:center;font-size:12px;font-weight:600;color:var(--neria-dark);">{neria_admin key='stats.chart_type_line'}</span>
        <button class="neria-chart-arrow" id="neria-chart-next" title="{neria_admin key='stats.chart_type_next'}">&#9654;</button>
      </div>
      <button id="neria-total-toggle" class="neria-chart-arrow" style="border:1px solid var(--neria-border);border-radius:4px;padding:3px 10px;font-size:11px;font-weight:600;color:var(--neria-dark);background:#fff;">{neria_admin key='stats.chart_total_btn'} ◉</button>
      <div class="neria-period-tabs" id="neria-chart-period">
        <button class="neria-period-tab" data-period="7">7{neria_admin key='common.days_unit_short'}</button>
        <button class="neria-period-tab neria-period-tab--active" data-period="30">30{neria_admin key='common.days_unit_short'}</button>
        <button class="neria-period-tab" data-period="90">90{neria_admin key='common.days_unit_short'}</button>
        <button class="neria-period-tab" data-period="365">12 {neria_admin key='gdpr.months_unit'}</button>
      </div>
    </div>
  </div>
  <div style="position:relative;height:340px;">
    <canvas id="neriaRevenueChart"></canvas>
  </div>
  <div id="neria-chart-legend" style="display:flex;flex-wrap:wrap;gap:14px;margin-top:16px;font-size:12px;"></div>
  <p style="margin:10px 0 0;font-size:11px;color:#6b6459;font-style:italic;">
    &#9432; {neria_admin key='stats.chart_isolate_hint'}
  </p>

  {* Tableau par template *}
  {if isset($revenue) && $revenue.total_orders > 0}
  <hr style="border:none;border-top:1px solid rgba(0,0,0,.07);margin:24px 0;">
  <div class="neria-table-wrap">
    <table class="neria-table">
      <colgroup>
        <col style="width:200px">
        <col style="width:120px">
        <col style="width:120px">
      </colgroup>
      <thead>
        <tr>
          <th>{neria_admin key='stats.revenue_col_template'}</th>
          <th style="text-align:right">{neria_admin key='stats.revenue_col_orders'}</th>
          <th style="text-align:right">{neria_admin key='stats.revenue_col_revenue'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $revenue.by_template as $tpl => $data}
        <tr>
          <td><span class="neria-tpl-name">{$tpl|escape:'html'}</span></td>
          <td style="text-align:right">{$data.orders}</td>
          <td style="text-align:right"><strong>{$data.revenue|string_format:"%.2f"} {$currency_symbol}</strong></td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {elseif !isset($revenue) || $revenue.total_orders == 0}
  <hr style="border:none;border-top:1px solid rgba(0,0,0,.07);margin:24px 0;">
  <div class="neria-empty-state" style="margin:0;">
    <span class="neria-empty-state__icon">◈</span>
    <p class="neria-empty-state__text">{neria_admin key='stats.revenue_empty'}</p>
  </div>
  {/if}
  <p class="neria-hint" style="margin-top:8px;">{neria_admin key='stats.revenue_hint'}</p>
</div>

<script>
// Assigné hors des blocs Smarty "literal" plus bas (les variables Smarty n'y
// sont pas interprétées) — seul moyen de transmettre ce chemin aux scripts
// de chargement de Chart.js à l'intérieur de ces blocs.
window.NERIA_MODULE_DIR = '{$neria_module_dir|escape:'javascript'}';
var _nrc = {
  d7:   {$revenue_chart_7|default:'null'},
  d30:  {$revenue_chart_30|default:'null'},
  d90:  {$revenue_chart_90|default:'null'},
  d365: {$revenue_chart_365|default:'null'},
  sym:  "{$currency_symbol|escape:'javascript'}",
  lbl: {
    cart:    "{neria_admin key='stats.chart_cat_cart'}",
    post:    "{neria_admin key='stats.chart_cat_post'}",
    loyalty: "{neria_admin key='stats.chart_cat_loyalty'}",
    behav:   "{neria_admin key='stats.chart_cat_behav'}",
    season:  "{neria_admin key='stats.chart_cat_season'}",
    b2b:     "{neria_admin key='stats.chart_cat_b2b'}",
    other:   "{neria_admin key='stats.chart_cat_other'}",
    total:   "{neria_admin key='stats.chart_cat_total'}"
  },
  typeLabels: [
    "{neria_admin key='stats.chart_type_line' esc='javascript'}",
    "{neria_admin key='stats.chart_type_bar' esc='javascript'}",
    "{neria_admin key='stats.chart_type_doughnut' esc='javascript'}"
  ]
};
</script>
{literal}
<script>
(function() {
  var CHART_DATA = { 7: _nrc.d7, 30: _nrc.d30, 90: _nrc.d90, 365: _nrc.d365 };
  var LABELS = _nrc.lbl;
  var CATS = ['cart','post','loyalty','behav','season','b2b','other'];
  var COLORS = {
    cart:    '#b38b59',
    post:    '#7c9e6b',
    loyalty: '#6b7db8',
    behav:   '#c4785a',
    season:  '#b8a030',
    b2b:     '#5faab0',
    other:   '#aaaaaa',
    total:   '#2c2c2c'
  };
  var TYPES = ['line','bar','doughnut'];
  var TYPE_LABELS = _nrc.typeLabels;
  var chart = null;
  var currentPeriod = 30;
  var currentTypeIdx = 0;
  var showTotal = true;

  function formatDate(d, period) {
    var p = d.split('-');
    return period <= 90 ? p[2]+'/'+p[1] : p[1]+'/'+p[0].slice(2);
  }

  function getCatTotals(data) {
    var totals = {};
    CATS.forEach(function(cat) {
      var vals = data.series && data.series[cat] ? data.series[cat] : [];
      var sum = vals.reduce(function(a, b) { return a + b; }, 0);
      if (sum > 0) totals[cat] = Math.round(sum * 100) / 100;
    });
    return totals;
  }

  function getPeriodTotal(data) {
    var total = data.total ? data.total.reduce(function(a, b) { return a + b; }, 0) : 0;
    return Math.round(total * 100) / 100;
  }

  function updateTotalDisplay(data) {
    var el = document.getElementById('neria-chart-total-amount');
    if (!el) return;
    var t = getPeriodTotal(data);
    el.textContent = t.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + _nrc.sym;
    el.style.color = t > 0 ? 'var(--neria-accent)' : '#ccc';
  }

  /* Plugin Chart.js : texte central pour le donut */
  var doughnutCenterPlugin = {
    id: 'neriaCenter',
    afterDraw: function(chart) {
      if (chart.config.type !== 'doughnut') return;
      var ctx = chart.ctx;
      var w = chart.width, h = chart.height;
      var data = CHART_DATA[currentPeriod] || {};
      var t = getPeriodTotal(data);
      var text = t.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' ' + _nrc.sym;
      ctx.save();
      ctx.font = 'bold 16px Cormorant Garamond, Georgia, serif';
      ctx.fillStyle = '#2c2c2c';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(text, w / 2, h / 2);
      ctx.restore();
    }
  };

  function buildLineBarDatasets(data, period, type) {
    var datasets = [];
    CATS.forEach(function(cat) {
      var vals = data.series && data.series[cat] ? data.series[cat] : [];
      if (!vals.some(function(v) { return v > 0; })) return;
      var ds = {
        _neriaKey: cat,
        label: LABELS[cat] || cat,
        data: vals,
        borderColor: COLORS[cat],
        backgroundColor: type === 'bar' ? COLORS[cat] + 'cc' : COLORS[cat] + '22',
        borderWidth: type === 'bar' ? 0 : 2,
        pointRadius: type === 'bar' ? 0 : (period <= 30 ? 3 : 0),
        pointHoverRadius: 5,
        fill: type === 'line',
        tension: 0.4,
        borderRadius: type === 'bar' ? 3 : 0
      };
      datasets.push(ds);
    });
    if (showTotal && data.total) {
      var totalDs = {
        _neriaKey: 'total',
        label: LABELS.total,
        data: data.total,
        borderColor: COLORS.total,
        backgroundColor: 'transparent',
        borderWidth: 2,
        borderDash: [5, 3],
        pointRadius: 0,
        pointHoverRadius: 5,
        fill: false,
        tension: 0.4
      };
      if (type === 'bar') {
        totalDs.type = 'line';
        totalDs.pointRadius = period <= 30 ? 3 : 0;
        totalDs.order = -1;
      }
      datasets.push(totalDs);
    }
    return datasets;
  }

  function buildDoughnutDatasets(data) {
    var totals = getCatTotals(data);
    var cats = Object.keys(totals);
    if (!cats.length) return null;
    var ds = [{
      data: cats.map(function(c) { return totals[c]; }),
      backgroundColor: cats.map(function(c) { return COLORS[c]; }),
      borderColor: '#fff',
      borderWidth: 2,
      hoverOffset: 8
    }];
    if (showTotal) {
      ds.push({
        data: [getPeriodTotal(data)],
        backgroundColor: ['transparent'],
        borderColor: ['#2c2c2c'],
        borderWidth: 3,
        hoverOffset: 0,
        weight: 0.12
      });
    }
    return {
      labels: cats.map(function(c) { return LABELS[c] || c; }),
      datasets: ds
    };
  }

  var soloSeries = null;
  var currentIsDoughnut = false;
  var doughnutCatOrder = [];

  function applySolo() {
    if (!chart) return;
    if (currentIsDoughnut) {
      var ds = chart.data.datasets[0];
      if (!ds) return;
      ds.backgroundColor = doughnutCatOrder.map(function(cat) {
        if (soloSeries === null || soloSeries === cat) return COLORS[cat] || '#999';
        return 'rgba(0,0,0,0.07)';
      });
      ds.hoverOffset = doughnutCatOrder.map(function(cat) {
        return (soloSeries === null || soloSeries === cat) ? 8 : 0;
      });
    } else {
      chart.data.datasets.forEach(function(ds, i) {
        chart.setDatasetVisibility(i, soloSeries === null || ds._neriaKey === soloSeries);
      });
    }
    chart.update();
    updateLegendState();
  }

  function updateLegendState() {
    var el = document.getElementById('neria-chart-legend');
    if (!el) return;
    el.querySelectorAll('[data-neria-cat]').forEach(function(span) {
      var cat = span.dataset.neriaCat;
      var active = soloSeries === null || soloSeries === cat;
      span.style.opacity = active ? '1' : '0.35';
      span.style.textDecoration = active ? 'none' : 'line-through';
      span.style.borderColor = soloSeries === cat ? (COLORS[cat] || '#999') : '#e8d5b0';
      span.style.boxShadow = soloSeries === cat ? ('0 0 0 2px ' + (COLORS[cat] || '#999') + '44') : 'none';
    });
  }

  function renderLegend(cats, isDoughnut) {
    var el = document.getElementById('neria-chart-legend');
    if (!el) return;
    currentIsDoughnut = isDoughnut;
    doughnutCatOrder = isDoughnut ? cats.filter(function(c) { return c !== 'total'; }) : [];
    el.innerHTML = cats.map(function(cat) {
      var color = COLORS[cat] || '#999';
      var label = LABELS[cat] || cat;
      return '<span data-neria-cat="' + cat + '" style="display:flex;align-items:center;gap:6px;padding:3px 10px;background:#fff;border:1px solid #e8d5b0;border-radius:4px;cursor:pointer;transition:opacity .15s,border-color .15s,box-shadow .15s;user-select:none;">'
        + '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:' + color + ';flex-shrink:0;"></span>'
        + label + '</span>';
    }).join('');
    el.querySelectorAll('[data-neria-cat]').forEach(function(span) {
      span.addEventListener('click', function() {
        var cat = span.dataset.neriaCat;
        soloSeries = (soloSeries === cat) ? null : cat;
        applySolo();
      });
    });
    updateLegendState();
  }

  function getActiveCats(data) {
    return CATS.filter(function(cat) {
      var vals = data.series && data.series[cat] ? data.series[cat] : [];
      return vals.some(function(v) { return v > 0; });
    });
  }

  function renderChart(period, typeIdx) {
    var data = CHART_DATA[period] || {};
    var type = TYPES[typeIdx];
    var maxTicks = period <= 30 ? 15 : 12;

    updateTotalDisplay(data);

    if (type === 'doughnut') {
      var dData = buildDoughnutDatasets(data);
      if (!dData) { dData = { labels: [], datasets: [{ data: [], backgroundColor: [], borderColor: '#fff', borderWidth: 2 }] }; }
      if (chart) {
        chart.destroy();
        chart = null;
      }
      var ctx2 = document.getElementById('neriaRevenueChart');
      if (!ctx2) return;
      chart = new Chart(ctx2, {
        type: 'doughnut',
        data: dData,
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function(c) {
                  return ' ' + c.label + ' : ' + c.parsed.toFixed(2) + ' ' + _nrc.sym;
                }
              }
            }
          }
        }
      });
      var dCats = dData.labels.map(function(l) {
        return Object.keys(LABELS).find(function(k) { return LABELS[k] === l; }) || l;
      }).concat(['total']);
      renderLegend(dCats, true);
      return;
    }

    var dates = (data.dates || []).map(function(d) { return formatDate(d, period); });
    var datasets = buildLineBarDatasets(data, period, type);
    var activeCats = getActiveCats(data);

    if (chart && chart.config.type === type) {
      chart.data.labels = dates;
      chart.data.datasets = datasets;
      chart.update();
    } else {
      if (chart) { chart.destroy(); chart = null; }
      var ctx = document.getElementById('neriaRevenueChart');
      if (!ctx) return;
      chart = new Chart(ctx, {
        type: type,
        data: { labels: dates, datasets: datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function(c) {
                  return ' ' + c.dataset.label + ' : ' + c.parsed.y.toFixed(2) + ' ' + _nrc.sym;
                }
              }
            }
          },
          scales: {
            x: {
              stacked: type === 'bar',
              grid: { color: '#e8d5b015' },
              ticks: { font: { size: 11 }, color: '#999', maxTicksLimit: maxTicks }
            },
            y: {
              stacked: type === 'bar',
              beginAtZero: true,
              grid: { color: '#e8d5b030' },
              ticks: {
                font: { size: 11 },
                color: '#999',
                callback: function(v) { return v.toFixed(0) + ' ' + _nrc.sym; }
              }
            }
          }
        }
      });
    }
    renderLegend(activeCats.concat(['total']), false);
  }

  function updateTypeLabel() {
    var el = document.getElementById('neria-chart-type-label');
    if (el) el.textContent = TYPE_LABELS[currentTypeIdx];
  }

  function loadChartJs(cb) {
    if (window.Chart) { cb(); return; }
    var s = document.createElement('script');
    s.src = window.NERIA_MODULE_DIR + 'views/js/vendor/chart.umd.min.js';
    s.onload = cb;
    document.head.appendChild(s);
  }

  document.addEventListener('DOMContentLoaded', function() {
    loadChartJs(function() {
      Chart.register(doughnutCenterPlugin);
      renderChart(currentPeriod, currentTypeIdx);
    });

    document.getElementById('neria-chart-period').addEventListener('click', function(e) {
      var btn = e.target.closest('.neria-period-tab');
      if (!btn) return;
      document.querySelectorAll('.neria-period-tab').forEach(function(b) { b.classList.remove('neria-period-tab--active'); });
      btn.classList.add('neria-period-tab--active');
      currentPeriod = parseInt(btn.dataset.period, 10);
      soloSeries = null;
      renderChart(currentPeriod, currentTypeIdx);
    });

    document.getElementById('neria-chart-prev').addEventListener('click', function() {
      currentTypeIdx = (currentTypeIdx - 1 + TYPES.length) % TYPES.length;
      soloSeries = null;
      updateTypeLabel();
      renderChart(currentPeriod, currentTypeIdx);
    });

    document.getElementById('neria-chart-next').addEventListener('click', function() {
      currentTypeIdx = (currentTypeIdx + 1) % TYPES.length;
      soloSeries = null;
      updateTypeLabel();
      renderChart(currentPeriod, currentTypeIdx);
    });

    var toggleBtn = document.getElementById('neria-total-toggle');
    function updateToggleBtn() {
      if (showTotal) {
        toggleBtn.style.background = '#2c2c2c';
        toggleBtn.style.color = '#fff';
        toggleBtn.style.borderColor = '#2c2c2c';
      } else {
        toggleBtn.style.background = '#fff';
        toggleBtn.style.color = '#aaa';
        toggleBtn.style.borderColor = 'var(--neria-border)';
      }
    }
    updateToggleBtn();
    toggleBtn.addEventListener('click', function() {
      showTotal = !showTotal;
      updateToggleBtn();
      renderChart(currentPeriod, currentTypeIdx);
    });
  });
})();
</script>
{/literal}

{* ══════════════════════════════════════════════════════════════
   GRAPHIQUE ENGAGEMENT EMAIL — Envois / Ouvertures / Clics
   ══════════════════════════════════════════════════════════════ *}
<div class="neria-section" id="neria-engagement-chart-section">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0;">{neria_admin key='stats.engagement_title'} ◉</h2>
      <p class="neria-section__desc" style="margin:4px 0 0;">{neria_admin key='stats.engagement_desc'}</p>
    </div>
    <div class="neria-period-tabs" id="neria-eng-period">
      <button class="neria-period-tab neria-period-tab--active" data-period="30">30{neria_admin key='common.days_unit_short'}</button>
      <button class="neria-period-tab" data-period="90">90{neria_admin key='common.days_unit_short'}</button>
    </div>
  </div>
  <div style="position:relative;height:280px;">
    <canvas id="neriaEngagementChart"></canvas>
  </div>
</div>

<script>
var _nec = {
  d30: {$engagement_chart_30|default:'null'},
  d90: {$engagement_chart_90|default:'null'},
  lbl: {
    sent:   "{neria_admin key='common.emails_sent' esc='javascript'}",
    opens:  "{neria_admin key='common.opens' esc='javascript'}",
    clicks: "{neria_admin key='common.clicks' esc='javascript'}"
  }
};
</script>
{literal}
<script>
(function() {
  var DATA = { 30: _nec.d30, 90: _nec.d90 };
  var echart = null;
  var ecPeriod = 30;

  function fmtDate(d, period) {
    var p = d.split('-');
    return period <= 30 ? p[2]+'/'+p[1] : p[1]+'/'+p[0].slice(2);
  }

  function renderEng(period) {
    var data = DATA[period] || {};
    var labels = (data.dates || []).map(function(d){ return fmtDate(d, period); });
    var maxTick = period <= 30 ? 15 : 12;

    var datasets = [
      { label:_nec.lbl.sent,   data: data.sent   || [], borderColor:'#b0a090', backgroundColor:'#b0a09020', borderWidth:1.5, pointRadius:0, fill:true, tension:0.3 },
      { label:_nec.lbl.opens,  data: data.opens  || [], borderColor:'#b38b59', backgroundColor:'#b38b5930', borderWidth:2,   pointRadius:0, fill:true, tension:0.3 },
      { label:_nec.lbl.clicks, data: data.clicks || [], borderColor:'#5f8b4a', backgroundColor:'#5f8b4a40', borderWidth:2,   pointRadius:period<=30?3:0, fill:true, tension:0.3 }
    ];

    if (echart) {
      echart.data.labels = labels;
      echart.data.datasets = datasets;
      echart.update();
      return;
    }

    var ctx = document.getElementById('neriaEngagementChart');
    if (!ctx) return;

    function loadChartJs(cb) {
      if (window.Chart) { cb(); return; }
      var s = document.createElement('script');
      s.src = window.NERIA_MODULE_DIR + 'views/js/vendor/chart.umd.min.js';
      s.onload = cb;
      document.head.appendChild(s);
    }

    loadChartJs(function() {
      echart = new Chart(ctx, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode:'index', intersect:false },
          plugins: { legend: { position:'bottom', labels:{ font:{size:11}, boxWidth:12, padding:16 } } },
          scales: {
            x: { grid:{color:'#e8d5b015'}, ticks:{font:{size:11},color:'#999',maxTicksLimit:maxTick} },
            y: { beginAtZero:true, grid:{color:'#e8d5b030'}, ticks:{font:{size:11},color:'#999'} }
          }
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function() {
    renderEng(ecPeriod);
    var tabs = document.getElementById('neria-eng-period');
    if (tabs) {
      tabs.addEventListener('click', function(e) {
        var btn = e.target.closest('.neria-period-tab');
        if (!btn) return;
        tabs.querySelectorAll('.neria-period-tab').forEach(function(b){b.classList.remove('neria-period-tab--active');});
        btn.classList.add('neria-period-tab--active');
        ecPeriod = parseInt(btn.dataset.period, 10);
        renderEng(ecPeriod);
      });
    }
  });
})();
</script>
{/literal}

{* ══════════════════════════════════════════════════════════════
   HEATMAP HORAIRE DES OUVERTURES (7j × 24h)
   ══════════════════════════════════════════════════════════════ *}
<div class="neria-section" id="neria-heatmap-section">
  <h2 class="neria-section__title" style="margin:0 0 6px;">{neria_admin key='stats.heatmap_title'} ◈</h2>
  <p class="neria-section__desc" style="margin:0 0 20px;">{neria_admin key='stats.heatmap_desc'}</p>

  <div id="neria-heatmap-wrap" style="overflow-x:auto;">
    <table id="neria-heatmap-table" style="border-collapse:separate;border-spacing:3px;font-size:11px;">
      <thead id="neria-heatmap-head"></thead>
      <tbody id="neria-heatmap-body"></tbody>
    </table>
  </div>
  <div style="margin-top:14px;display:flex;align-items:center;gap:8px;font-size:11px;color:var(--neria-muted);">
    <span>{neria_admin key='stats.heatmap_less'}</span>
    <span id="neria-heatmap-legend" style="display:flex;gap:2px;"></span>
    <span>{neria_admin key='stats.heatmap_more'}</span>
  </div>
</div>

<script>
var _nhm = {$open_heatmap|default:'null'};
var _nhmLbl = {
  days: [
    "{neria_admin key='stats.day_abbr_mon' esc='javascript'}",
    "{neria_admin key='stats.day_abbr_tue' esc='javascript'}",
    "{neria_admin key='stats.day_abbr_wed' esc='javascript'}",
    "{neria_admin key='stats.day_abbr_thu' esc='javascript'}",
    "{neria_admin key='stats.day_abbr_fri' esc='javascript'}",
    "{neria_admin key='stats.day_abbr_sat' esc='javascript'}",
    "{neria_admin key='stats.day_abbr_sun' esc='javascript'}"
  ],
  openSingular: "{neria_admin key='stats.heatmap_open_singular' esc='javascript'}",
  openPlural:   "{neria_admin key='stats.heatmap_open_plural' esc='javascript'}"
};
</script>
{literal}
<script>
(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var hm = _nhm;
    if (!hm || !hm.grid || !hm.max) return;

    var grid = hm.grid;
    var maxV = hm.max;
    var days = _nhmLbl.days;
    var colors = ['#f5f0ea','#e8d5b0','#d4a96a','#c07830','#8b4a10','#5c2a05'];

    function getColor(cnt) {
      if (maxV === 0 || cnt === 0) return colors[0];
      var idx = Math.ceil(cnt / maxV * (colors.length - 2));
      return colors[Math.min(idx, colors.length - 1)];
    }

    // En-tête heures
    var thead = document.getElementById('neria-heatmap-head');
    if (!thead) return;
    var headRow = '<tr><th style="width:36px;"></th>';
    for (var h = 0; h < 24; h++) {
      headRow += '<th style="width:22px;text-align:center;font-size:9px;color:#aaa;padding:0 1px;">' + (h % 3 === 0 ? h + 'h' : '') + '</th>';
    }
    headRow += '</tr>';
    thead.innerHTML = headRow;

    // Corps
    var tbody = document.getElementById('neria-heatmap-body');
    if (!tbody) return;
    var bodyHtml = '';
    for (var d = 0; d < 7; d++) {
      bodyHtml += '<tr><td style="font-size:10px;font-weight:600;color:#888;padding-right:6px;white-space:nowrap;">' + days[d] + '</td>';
      for (var hr = 0; hr < 24; hr++) {
        var cnt = (grid[d] && grid[d][hr]) ? grid[d][hr] : 0;
        var bg  = getColor(cnt);
        var tip = days[d] + ' ' + hr + 'h : ' + cnt + ' ' + (cnt > 1 ? _nhmLbl.openPlural : _nhmLbl.openSingular);
        bodyHtml += '<td title="' + tip + '" style="width:22px;height:20px;background:' + bg + ';border-radius:3px;cursor:default;"></td>';
      }
      bodyHtml += '</tr>';
    }
    tbody.innerHTML = bodyHtml;

    // Légende
    var leg = document.getElementById('neria-heatmap-legend');
    if (leg) {
      leg.innerHTML = colors.map(function(c) {
        return '<span style="display:inline-block;width:16px;height:12px;background:' + c + ';border-radius:2px;"></span>';
      }).join('');
    }
  });
})();
</script>
{/literal}

{* ── Tests A/B en cours (section Stats) ──────────────────────── *}
{assign var="has_active_tests" value=false}
{foreach $tests_status as $tplKey => $tplStatus}
  {if $tplStatus === 'active'}{assign var="has_active_tests" value=true}{/if}
{/foreach}

{if $has_active_tests}
<div class="neria-section" id="neria-abtest-focus">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:20px;">
    <h2 class="neria-section__title" style="margin:0;">{neria_admin key='nav.abtest'}</h2>
    <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&abtest_template=[^&]*/':''}&neria_tab=abtest"
       class="neria-btn neria-btn--ghost neria-btn--sm" style="margin-left:auto;">
      ← {neria_admin key='nav.abtest'}
    </a>
  </div>

  {foreach $tests_status as $tplKey => $tplStatus}
    {if $tplStatus === 'active'}
      {assign var="td"  value=$tests_data[$tplKey]|default:[]}
      {assign var="fr"  value=$ab_reports[$tplKey]|default:[]}
      {assign var="sig" value=$fr.significance|default:[]}
      {assign var="winner" value=$sig.overall_winner|default:''}
      {assign var="isFocus" value=($abtest_focus_key === $tplKey)}

      <div id="neria-ab-{$tplKey}"
           style="margin-bottom:16px; padding:16px; border:1px solid var(--neria-border); border-radius:6px;">

        <div style="font-size:13px; font-weight:700; color:var(--neria-text); margin-bottom:12px;">
          {$template_labels[$tplKey]|default:$tplKey}
          <span class="neria-badge neria-badge--accent" style="margin-left:8px; font-size:10px;">● {neria_admin key='abtest.status_active'}</span>
        </div>

        {capture name="default_variant_a"}{neria_admin key='abtest.default_variant_a'}{/capture}
        {capture name="default_variant_b"}{neria_admin key='abtest.default_variant_b'}{/capture}
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          {* Variante A *}
          <div style="padding:12px; background:var(--neria-bg); border-radius:4px; {if $winner === 'A'}border-left:3px solid var(--neria-accent);{/if}">
            <div style="font-size:10px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--neria-muted); margin-bottom:6px;">
              A — {$td.a.variant_name|default:$smarty.capture.default_variant_a|escape:'html'}{if $winner === 'A'} ↑{/if}
            </div>
            <div style="font-size:24px; font-weight:700; color:var(--neria-text);">{$fr.A.total_sent|default:0}</div>
            <div style="font-size:11px; color:var(--neria-muted); margin-bottom:6px;">{neria_admin key='common.sent'}</div>
            <div style="font-size:12px; color:var(--neria-accent); font-weight:600;">
              {neria_admin key='common.open_rate_short'} {$fr.A.rate_open|default:0}%
              &nbsp;·&nbsp;
              {neria_admin key='common.click_rate_short'} {$fr.A.rate_click|default:0}%
            </div>
          </div>

          {* Variante B *}
          <div style="padding:12px; background:var(--neria-bg); border-radius:4px; {if $winner === 'B'}border-left:3px solid var(--neria-accent);{/if}">
            <div style="font-size:10px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--neria-muted); margin-bottom:6px;">
              B — {$td.b.variant_name|default:$smarty.capture.default_variant_b|escape:'html'}{if $winner === 'B'} ↑{/if}
            </div>
            <div style="font-size:24px; font-weight:700; color:var(--neria-text);">{$fr.B.total_sent|default:0}</div>
            <div style="font-size:11px; color:var(--neria-muted); margin-bottom:6px;">{neria_admin key='common.sent'}</div>
            <div style="font-size:12px; color:var(--neria-accent); font-weight:600;">
              {neria_admin key='common.open_rate_short'} {$fr.B.rate_open|default:0}%
              &nbsp;·&nbsp;
              {neria_admin key='common.click_rate_short'} {$fr.B.rate_click|default:0}%
            </div>
          </div>
        </div>

        {* Badge significance *}
        {if !empty($sig)}
          {assign var="conf" value=0}
          {if isset($sig.open.confidence)  && $sig.open.confidence  > $conf}{assign var="conf" value=$sig.open.confidence}{/if}
          {if isset($sig.click.confidence) && $sig.click.confidence > $conf}{assign var="conf" value=$sig.click.confidence}{/if}

          {if !($sig.open.sufficient|default:false)}
            <div class="neria-sig-badge neria-sig-badge--pending" style="margin-top:0;">
              ◌ {neria_admin key='abtest.sig_running'} — {$sig.sent_a|default:0}/{$sig.min_sample|default:100} {neria_admin key='abtest.sig_needed'}
            </div>
          {elseif $conf >= 95}
            <div class="neria-sig-badge neria-sig-badge--sig" style="margin-top:0;">
              ✓ {neria_admin key='abtest.sig_at'} {$conf}%{if $winner} — {$winner} {neria_admin key='abtest.sig_wins'}{/if}
            </div>
          {elseif $conf >= 90}
            <div class="neria-sig-badge neria-sig-badge--marginal" style="margin-top:0;">
              ~ {neria_admin key='abtest.sig_at'} {$conf}%{if $winner} — {$winner} {neria_admin key='abtest.sig_wins'}{/if}
            </div>
          {else}
            <div class="neria-sig-badge neria-sig-badge--pending" style="margin-top:0;">
              ◌ {neria_admin key='abtest.sig_running'}
            </div>
          {/if}
        {/if}

      </div>
    {/if}
  {/foreach}
</div>
{/if}

{* ── Réputation de domaine ──────────────────────────────────── *}
<div class="neria-section" id="neria-domain-rep">

  <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
    <h2 class="neria-section__title" style="margin:0;border:none;padding:0;flex:1;">
      {neria_admin key='stats.domainrep_title'}
    </h2>
    <div class="neria-stats-filters" style="margin:0;">
      {foreach [7, 30, 90] as $period}
        <a href="{$smarty.server.REQUEST_URI}&neria_tab=stats&stats_days={$period}"
           class="neria-period-btn {if $stats_days == $period}neria-period-btn--active{/if}">
          {$period} {neria_admin key='common.days'}
        </a>
      {/foreach}
      <span class="neria-stats-computed">
        {neria_admin key='stats.computed_on'} {$stats.computed_at|default:'—'}
      </span>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI}#neria-domain-rep" style="flex-shrink:0;">
      <input type="hidden" name="neria_action" value="refresh_domain_reputation">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit" id="neria-domain-rep-btn"
              style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;
                     background:#1a1a1a;color:#fff;border:none;border-radius:4px;
                     font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.04em;"
              onmouseover="this.style.background='#b8975a'"
              onmouseout="this.style.background='#1a1a1a'">
        ↻ {neria_admin key='common.refresh'}
      </button>
    </form>
  </div>
  <div style="border-bottom:1px solid var(--neria-border);margin-bottom:24px;"></div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.domainrep_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.domainrep_howto_score'}
    </div>
  </div>

  {if $domain_reputation}
    {assign var="dr" value=$domain_reputation}
    {assign var="dr_hits" value=$dr.blacklists.hits|default:[]}

    {* ── Bandeau score principal ── *}
    <div style="display:flex;align-items:center;gap:32px;margin-bottom:28px;flex-wrap:wrap;">

      {* Cercle score *}
      <div style="position:relative;flex-shrink:0;">
        <svg width="100" height="100" viewBox="0 0 100 100">
          <circle cx="50" cy="50" r="42" fill="none" stroke="var(--neria-border)" stroke-width="10"/>
          <circle cx="50" cy="50" r="42" fill="none" stroke="{$dr.color}" stroke-width="10"
                  stroke-dasharray="{math equation='264 * score / 100' score=$dr.score} 264"
                  stroke-dashoffset="66" stroke-linecap="round"
                  transform="rotate(-90 50 50)"/>
          <text x="50" y="46" text-anchor="middle" font-size="22" font-weight="700" fill="{$dr.color}">{$dr.score}</text>
          <text x="50" y="62" text-anchor="middle" font-size="13" font-weight="600" fill="{$dr.color}">{$dr.grade}</text>
        </svg>
      </div>

      {* Infos domaine + résumé *}
      <div style="flex:1;min-width:200px;">
        <div style="font-size:18px;font-weight:600;color:var(--neria-dark);margin-bottom:4px;">
          {$dr.domain|escape:'html'}
          {if $dr.ip}
            <span style="font-size:12px;font-weight:400;color:var(--neria-text-light);margin-left:8px;">{$dr.ip|escape:'html'}</span>
          {/if}
        </div>
        <div style="font-size:13px;color:var(--neria-text-light);margin-bottom:12px;">
          {neria_admin key='stats.domainrep_last_check'} {$dr.checked_at|escape:'html'}
        </div>

        {* Barre de progression *}
        <div style="background:var(--neria-border);border-radius:4px;height:8px;overflow:hidden;max-width:320px;">
          <div style="width:{$dr.score}%;height:100%;background:{$dr.color};border-radius:4px;transition:width .6s;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--neria-text-light);margin-top:4px;max-width:320px;">
          <span>0 — {neria_admin key='stats.grade_critical'}</span>
          <span>50 — {neria_admin key='stats.grade_correct'}</span>
          <span>100 — {neria_admin key='stats.grade_excellent'}</span>
        </div>
      </div>

    </div>

    {* ── 4 indicateurs SPF / DKIM / DMARC / Blacklists ── *}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px;">

      {* SPF *}
      {assign var="spf" value=$dr.spf}
      <div style="border:1px solid {if $spf.found}#c3e6cb{else}#f5c6cb{/if};
                  background:{if $spf.found}#f0faf3{else}#fdf0ee{/if};
                  border-radius:6px;padding:16px 18px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <span style="font-size:18px;">{if $spf.found}✅{else}❌{/if}</span>
          <span style="font-size:13px;font-weight:700;color:var(--neria-dark);">SPF</span>
          {if $spf.found}
            <span class="neria-badge" style="margin-left:auto;font-size:10px;background:{if $spf.policy === 'reject'}#eaf5ec{else}#faf3ea{/if};color:{if $spf.policy === 'reject'}var(--neria-success){else}var(--neria-accent){/if};border:1px solid {if $spf.policy === 'reject'}#c3e6cb{else}#e8d5b0{/if};">
              {if $spf.policy === 'reject'}-all{elseif $spf.policy === 'softfail'}~all{else}?all{/if}
            </span>
          {/if}
        </div>
        <div style="font-size:12px;color:var(--neria-text-light);">
          {if $spf.found}
            {neria_admin key='stats.spf_configured'}{if $spf.policy === 'reject'} · {neria_admin key='stats.policy_strict'}{elseif $spf.policy === 'softfail'} · {neria_admin key='stats.policy_permissive'}{/if}
          {else}
            <span style="color:#c0392b;">{neria_admin key='stats.spf_absent'}</span>
          {/if}
        </div>
        {if $spf.record}
          <div style="margin-top:8px;font-size:10px;font-family:monospace;color:var(--neria-text-light);
                      background:rgba(0,0,0,.04);border-radius:3px;padding:4px 6px;
                      overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
               title="{$spf.record|escape:'html'}">
            {$spf.record|truncate:55:'…'|escape:'html'}
          </div>
        {/if}
      </div>

      {* DKIM *}
      {assign var="dkim" value=$dr.dkim}
      <div style="border:1px solid {if $dkim.found}#c3e6cb{else}#f5c6cb{/if};
                  background:{if $dkim.found}#f0faf3{else}#fdf0ee{/if};
                  border-radius:6px;padding:16px 18px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <span style="font-size:18px;">{if $dkim.found}✅{else}❌{/if}</span>
          <span style="font-size:13px;font-weight:700;color:var(--neria-dark);">DKIM</span>
          {if $dkim.selector}
            <span class="neria-badge neria-badge--neutral" style="margin-left:auto;font-size:10px;">
              {$dkim.selector|escape:'html'}
            </span>
          {/if}
        </div>
        <div style="font-size:12px;color:var(--neria-text-light);">
          {if $dkim.found}
            {neria_admin key='stats.dkim_selector_prefix'} {$dkim.selector|escape:'html'} {neria_admin key='stats.dkim_selector_suffix'}
          {else}
            <span style="color:#c0392b;">{neria_admin key='stats.dkim_absent'}</span>
          {/if}
        </div>
      </div>

      {* DMARC *}
      {assign var="dmarc" value=$dr.dmarc}
      <div style="border:1px solid {if $dmarc.found && $dmarc.policy !== 'none'}#c3e6cb{elseif $dmarc.found}#ffe082{else}#f5c6cb{/if};
                  background:{if $dmarc.found && $dmarc.policy !== 'none'}#f0faf3{elseif $dmarc.found}#fffde7{else}#fdf0ee{/if};
                  border-radius:6px;padding:16px 18px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <span style="font-size:18px;">{if $dmarc.found && $dmarc.policy !== 'none'}✅{elseif $dmarc.found}⚠️{else}❌{/if}</span>
          <span style="font-size:13px;font-weight:700;color:var(--neria-dark);">DMARC</span>
          {if $dmarc.found}
            <span class="neria-badge" style="margin-left:auto;font-size:10px;
              background:{if $dmarc.policy === 'reject'}#eaf5ec{elseif $dmarc.policy === 'quarantine'}#faf3ea{else}#fffde7{/if};
              color:{if $dmarc.policy === 'reject'}var(--neria-success){elseif $dmarc.policy === 'quarantine'}var(--neria-accent){else}#f57f17{/if};
              border:1px solid {if $dmarc.policy === 'reject'}#c3e6cb{elseif $dmarc.policy === 'quarantine'}#e8d5b0{else}#ffe082{/if};">
              p={$dmarc.policy|escape:'html'}
            </span>
          {/if}
        </div>
        <div style="font-size:12px;color:var(--neria-text-light);">
          {if !$dmarc.found}
            <span style="color:#c0392b;">{neria_admin key='stats.dmarc_absent'}</span>
          {elseif $dmarc.policy === 'reject'}
            {neria_admin key='stats.dmarc_policy_reject'}
          {elseif $dmarc.policy === 'quarantine'}
            {neria_admin key='stats.dmarc_policy_quarantine'}
          {else}
            {neria_admin key='stats.dmarc_policy_none'}
          {/if}
        </div>
      </div>

      {* PTR / rDNS *}
      {assign var="ptr" value=$dr.ptr}
      <div style="border:1px solid {if $ptr.found || $ptr.skipped}#c3e6cb{else}#ffe082{/if};
                  background:{if $ptr.found || $ptr.skipped}#f0faf3{elseif $ptr.skipped}#f0faf3{else}#fffde7{/if};
                  border-radius:6px;padding:16px 18px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <span style="font-size:18px;">{if $ptr.found || $ptr.skipped}✅{else}⚠️{/if}</span>
          <span style="font-size:13px;font-weight:700;color:var(--neria-dark);">PTR / rDNS</span>
          {if $ptr.found && isset($ptr.valid)}
            <span class="neria-badge" style="margin-left:auto;font-size:10px;
              background:{if $ptr.valid}#eaf5ec{else}#fef9ee{/if};
              color:{if $ptr.valid}var(--neria-success){else}var(--neria-accent){/if};
              border:1px solid {if $ptr.valid}#c3e6cb{else}#e8d5b0{/if};">
              {if $ptr.valid}{neria_admin key='stats.ptr_verified'}{else}{neria_admin key='stats.ptr_incomplete'}{/if}
            </span>
          {/if}
        </div>
        <div style="font-size:12px;color:var(--neria-text-light);">
          {if $ptr.skipped}
            {neria_admin key='stats.na_local_ip'}
          {elseif $ptr.found}
            {$ptr.hostname|escape:'html'}
          {else}
            <span style="color:#a07820;">{neria_admin key='stats.ptr_absent'}</span>
          {/if}
        </div>
      </div>

      {* Blacklists *}
      {assign var="bl" value=$dr.blacklists}
      {assign var="bl_hits_count" value=$dr_hits|count}
      <div style="border:1px solid {if $bl_hits_count === 0}#c3e6cb{elseif $bl_hits_count <= 2}#ffe082{else}#f5c6cb{/if};
                  background:{if $bl_hits_count === 0}#f0faf3{elseif $bl_hits_count <= 2}#fffde7{else}#fdf0ee{/if};
                  border-radius:6px;padding:16px 18px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <span style="font-size:18px;">{if $bl_hits_count === 0}✅{elseif $bl_hits_count <= 2}⚠️{else}❌{/if}</span>
          <span style="font-size:13px;font-weight:700;color:var(--neria-dark);">{neria_admin key='stats.blacklists_label'}</span>
        </div>
        <div style="font-size:12px;color:var(--neria-text-light);">
          {if isset($bl.skipped) && $bl.skipped}
            {neria_admin key='stats.na_local_ip'}
          {elseif $bl_hits_count === 0}
            ✓ {neria_admin key='stats.blacklist_clean_prefix'} {$bl.checked} {neria_admin key='stats.blacklist_clean_suffix'}
          {else}
            <span style="color:#c0392b;">{neria_admin key='stats.blacklist_hit_prefix'} {$bl_hits_count} {neria_admin key='stats.blacklist_hit_middle'} {$bl.checked} {neria_admin key='stats.blacklist_hit_suffix'}</span>
          {/if}
        </div>
        <div style="font-size:10px;letter-spacing:.04em;color:var(--neria-text-light);margin-top:4px;">
          {$bl.checked} {neria_admin key='stats.blacklist_rbl_suffix'}
        </div>
      </div>

    </div>{* /grid 4 indicateurs *}

    {* ── Détail blacklists si hits ── *}
    {if $dr_hits|count > 0}
      <div style="background:#fdf0ee;border:1px solid #f5c6cb;border-radius:6px;padding:16px 18px;margin-bottom:16px;">
        <div style="font-size:12px;font-weight:700;color:#c0392b;margin-bottom:8px;">
          ❌ {neria_admin key='stats.blacklist_active_prefix'}{$dr_hits|count}{neria_admin key='stats.blacklist_active_suffix'}
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
          {foreach $dr_hits as $blName}
            <span style="font-size:11px;font-family:monospace;background:#fff;border:1px solid #f5c6cb;
                         border-radius:3px;padding:2px 7px;color:#c0392b;">
              {$blName|escape:'html'}
            </span>
          {/foreach}
        </div>
        <div style="margin-top:10px;font-size:12px;color:#7a1c1c;line-height:1.6;">
          {neria_admin key='stats.blacklist_delist_howto'}
          {neria_admin key='stats.blacklist_spamhaus_label'} <code style="font-size:11px;">lookup.mxtoolbox.com</code>
        </div>
      </div>
    {/if}

    {* ── Recommandations ── *}
    {assign var="has_recs" value=false}
    {if !$dr.spf.found || !$dr.dkim.found || !$dr.dmarc.found || $dr.dmarc.policy === 'none' || $dr_hits|count > 0}
      {assign var="has_recs" value=true}
    {/if}

    {* ── BIMI ── *}
    {assign var="bimi" value=$dr.bimi}
    <div style="margin-bottom:16px;padding:14px 18px;border-radius:6px;
         border:1px solid {if $bimi.found}#c3e6cb{elseif $bimi.eligible}#ffe082{else}#e8d5b0{/if};
         background:{if $bimi.found}#f0faf3{elseif $bimi.eligible}#fffde7{else}#f9f6f1{/if};">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="font-size:18px;">{if $bimi.found}✅{elseif $bimi.eligible}💡{else}○{/if}</span>
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--neria-dark);">
            {neria_admin key='stats.bimi_title'}
          </div>
          <div style="font-size:12px;color:var(--neria-text-light);margin-top:2px;">
            {if $bimi.found}
              {neria_admin key='stats.bimi_configured'}
            {elseif $bimi.eligible}
              {neria_admin key='stats.bimi_eligible_prefix'} <code>default._bimi.{$dr.domain|escape:'html'}</code> {neria_admin key='stats.bimi_eligible_suffix'}
            {else}
              {neria_admin key='stats.bimi_not_eligible'}
            {/if}
          </div>
        </div>
      </div>
    </div>

    {if $has_recs}
    <div style="margin-top:4px;">
      <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--neria-text-light);margin-bottom:10px;">{neria_admin key='stats.recommendations'}</div>
      {if !$dr.spf.found}
        <div style="padding:10px 14px;margin-bottom:6px;border-left:3px solid #c0392b;background:#fdf0ee;font-size:13px;line-height:1.6;">
          {neria_admin key='stats.rec_spf_missing'}
        </div>
      {/if}
      {if !$dr.dkim.found}
        <div style="padding:10px 14px;margin-bottom:6px;border-left:3px solid #c0392b;background:#fdf0ee;font-size:13px;line-height:1.6;">
          {neria_admin key='stats.rec_dkim_missing'}
        </div>
      {/if}
      {if !$dr.dmarc.found}
        <div style="padding:10px 14px;margin-bottom:6px;border-left:3px solid #e67e22;background:#fef9ee;font-size:13px;line-height:1.6;">
          {neria_admin key='stats.rec_dmarc_missing_prefix'}{$dr.domain|escape:'html'}{neria_admin key='stats.rec_dmarc_missing_middle'}{$dr.domain|escape:'html'}{neria_admin key='stats.rec_dmarc_missing_suffix'}
        </div>
      {elseif $dr.dmarc.policy === 'none'}
        <div style="padding:10px 14px;margin-bottom:6px;border-left:3px solid #f0ad0a;background:#fffde7;font-size:13px;line-height:1.6;">
          {neria_admin key='stats.rec_dmarc_permissive'}
        </div>
      {/if}
    </div>
    {/if}

  {else}
    {* Aucun cache — premier lancement *}
    <div style="text-align:center;padding:32px 20px;">
      <div style="font-size:40px;color:var(--neria-border);margin-bottom:12px;">◎</div>
      <p style="font-size:14px;color:var(--neria-text-light);margin:0 0 20px;">
        {neria_admin key='stats.domainrep_empty_line1'}<br>
        {neria_admin key='stats.domainrep_empty_line2_prefix'} <strong>{neria_admin key='common.refresh'}</strong> {neria_admin key='stats.domainrep_empty_line2_suffix'}
      </p>
      <p style="font-size:12px;color:var(--neria-text-light);">
        {neria_admin key='stats.domainrep_empty_footer'}
      </p>
    </div>
  {/if}

</div>

{* ══════════════════════════════════════════════════════════════
   VISIBILITÉ BOUTIQUE — PageSpeed + Search Console + SEO API
   ══════════════════════════════════════════════════════════════ *}
<div class="neria-section" id="neria-visibility-section">
  <h2 class="neria-section__title">🌐 {neria_admin key='stats.visibility_title'}</h2>
  <p class="neria-section__desc">
    {neria_admin key='stats.visibility_desc'}
  </p>

  {* ── 1. PAGESPEED INSIGHTS ────────────────────────────────── *}
  <div style="border:1px solid var(--neria-border);border-radius:8px;padding:20px 24px;margin-bottom:20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:20px;">⚡</span>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--neria-dark);">Google PageSpeed Insights</div>
          <div style="font-size:11px;color:var(--neria-muted);">{neria_admin key='stats.pagespeed_subtitle'}</div>
        </div>
      </div>
      {if $pagespeed_configured}
        <div style="display:flex;gap:8px;align-items:center;">
          {if $pagespeed_cache_age !== null}
            <span style="font-size:11px;color:var(--neria-muted);">{neria_admin key='stats.cache_age_prefix'} {$pagespeed_cache_age} {neria_admin key='stats.cache_age_suffix'}</span>
          {/if}
          <form method="post" action="{$smarty.server.REQUEST_URI}#neria-visibility-section" style="display:inline;">
            <input type="hidden" name="neria_action" value="refresh_pagespeed">
            <input type="hidden" name="neria_tab"    value="stats">
            <button type="submit" style="padding:5px 12px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;"
                    onmouseover="this.style.background='#b8975a'" onmouseout="this.style.background='#1a1a1a'">
              ↻ {neria_admin key='common.refresh'}
            </button>
          </form>
        </div>
      {/if}
    </div>

    {* Notice explicative *}
    <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:16px;font-size:13px;line-height:1.75;color:#4a3f35;">
      <div style="font-weight:700;margin-bottom:10px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
      {neria_admin key='stats.pagespeed_howto_scores'}
      <div style="font-weight:700;margin:14px 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Core Web Vitals</div>
      {neria_admin key='stats.pagespeed_howto_cwv_body'}
      <ul style="margin:8px 0 0 18px;padding:0;">
        <li style="margin-bottom:4px;"><strong>LCP</strong> — {neria_admin key='stats.pagespeed_lcp_desc'}</li>
        <li style="margin-bottom:4px;"><strong>CLS</strong> — {neria_admin key='stats.pagespeed_cls_desc'}</li>
        <li><strong>TBT</strong> — {neria_admin key='stats.pagespeed_tbt_desc'}</li>
      </ul>
      <div style="margin-top:12px;font-size:12px;opacity:.75;">{neria_admin key='stats.pagespeed_howto_footer'}</div>
    </div>

    {* Configuration : saisie clé API + URL cible *}
    <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:16px 20px;margin-bottom:16px;">
      {if !$pagespeed_configured}
      <div style="font-size:12px;color:#5c3d1e;line-height:1.6;margin-bottom:12px;">
        <strong>{neria_admin key='stats.pagespeed_getkey_title'}</strong><br>
        1. <a href="https://console.cloud.google.com/" target="_blank" style="color:#1a7a40;">console.cloud.google.com</a>
        {neria_admin key='stats.pagespeed_getkey_step1'}<br>
        2. {neria_admin key='stats.pagespeed_getkey_step2'}
      </div>
      {/if}
      <form method="post" action="{$smarty.server.REQUEST_URI}#neria-visibility-section">
        <input type="hidden" name="neria_action" value="save_pagespeed_key">
        <input type="hidden" name="neria_tab"    value="stats">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='stats.pagespeed_api_key_label'}</label>
            <input type="text" name="pagespeed_api_key" value="{$pagespeed_api_key|escape:'html'}"
                   style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                   placeholder="AIzaSy…">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">
              {neria_admin key='stats.pagespeed_url_label'} <span style="font-weight:400;color:#7a6a5a;">{neria_admin key='stats.pagespeed_url_hint'}</span>
            </label>
            <input type="url" name="pagespeed_target_url" value="{$pagespeed_target_url|escape:'html'}"
                   style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                   placeholder="https://ma-boutique.com/">
          </div>
        </div>
        <button type="submit" class="neria-btn neria-btn--primary" style="font-size:12px;padding:8px 16px;">
          {neria_admin key='common.save'}
        </button>
      </form>
      {if $pagespeed_last_error}
      <div style="margin-top:12px;padding:10px 14px;background:#fdf0ee;border-left:3px solid #dc2626;border-radius:4px;font-size:12px;color:#7a1c1c;">
        ⚠ {$pagespeed_last_error|escape:'html'}
      </div>
      {/if}
    </div>

    {* Résultats PageSpeed *}
    {if $pagespeed_report}
      {assign var="ps" value=$pagespeed_report}
      {capture name="lbl_perf"}{neria_admin key='stats.score_performance'}{/capture}
      {capture name="lbl_access"}{neria_admin key='stats.score_accessibility'}{/capture}
      {capture name="lbl_best"}{neria_admin key='stats.score_best_practices'}{/capture}

      {* 4 scores — Mobile *}
      {if $ps.mobile}
      {assign var="psm" value=$ps.mobile}
      <div style="margin-bottom:20px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:12px;">📱 {neria_admin key='stats.mobile_label'}</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:10px;margin-bottom:16px;">
          {foreach [
            ['label'=>$smarty.capture.lbl_perf, 'val'=>$psm.perf,   'color'=>$psm.perf_color],
            ['label'=>$smarty.capture.lbl_access,'val'=>$psm.access, 'color'=>$psm.access_color],
            ['label'=>'SEO',          'val'=>$psm.seo,    'color'=>$psm.seo_color],
            ['label'=>$smarty.capture.lbl_best, 'val'=>$psm.best,   'color'=>$psm.best_color]
          ] as $sc}
          <div style="text-align:center;background:var(--neria-bg);border-radius:6px;padding:12px 8px;">
            <svg width="48" height="48" viewBox="0 0 48 48">
              <circle cx="24" cy="24" r="18" fill="none" stroke="var(--neria-border)" stroke-width="5"/>
              {if $sc.val !== null}
              <circle cx="24" cy="24" r="18" fill="none" stroke="{$sc.color}" stroke-width="5"
                      stroke-dasharray="{math equation='113 * v / 100' v=$sc.val} 113"
                      stroke-dashoffset="28" stroke-linecap="round" transform="rotate(-90 24 24)"/>
              <text x="24" y="28" text-anchor="middle" font-size="11" font-weight="700" fill="{$sc.color}">{$sc.val}</text>
              {else}
              <text x="24" y="28" text-anchor="middle" font-size="11" fill="#ccc">—</text>
              {/if}
            </svg>
            <div style="font-size:10px;color:var(--neria-muted);margin-top:4px;">{$sc.label}</div>
          </div>
          {/foreach}
        </div>

        {* Core Web Vitals *}
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
          {foreach [
            ['key'=>'lcp','label'=>'LCP','val'=>$psm.lcp,'status'=>$psm.lcp_status,'hint'=>'Largest Contentful Paint'],
            ['key'=>'cls','label'=>'CLS','val'=>$psm.cls,'status'=>$psm.cls_status,'hint'=>'Cumulative Layout Shift'],
            ['key'=>'tbt','label'=>'TBT','val'=>$psm.tbt,'status'=>$psm.tbt_status,'hint'=>'Total Blocking Time']
          ] as $cwv}
          <div style="padding:10px 12px;border-radius:6px;
                      background:{if $cwv.status == 'good'}#f0faf3{elseif $cwv.status == 'needs-improvement'}#fffde7{else}#fdf0ee{/if};
                      border:1px solid {if $cwv.status == 'good'}#c3e6cb{elseif $cwv.status == 'needs-improvement'}#ffe082{else}#f5c6cb{/if};">
            <div style="font-size:10px;font-weight:700;color:var(--neria-muted);text-transform:uppercase;margin-bottom:3px;"
                 title="{$cwv.hint}">{$cwv.label}</div>
            <div style="font-size:14px;font-weight:700;color:{if $cwv.status == 'good'}#16a34a{elseif $cwv.status == 'needs-improvement'}#d97706{else}#dc2626{/if};">
              {$cwv.val}
            </div>
          </div>
          {/foreach}
        </div>
      </div>
      {/if}

      {* Desktop *}
      {if $ps.desktop}
      {assign var="psd" value=$ps.desktop}
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:12px;">🖥 {neria_admin key='stats.desktop_label'}</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:10px;margin-bottom:16px;">
          {foreach [
            ['label'=>$smarty.capture.lbl_perf, 'val'=>$psd.perf,   'color'=>$psd.perf_color],
            ['label'=>$smarty.capture.lbl_access,'val'=>$psd.access, 'color'=>$psd.access_color],
            ['label'=>'SEO',          'val'=>$psd.seo,    'color'=>$psd.seo_color],
            ['label'=>$smarty.capture.lbl_best, 'val'=>$psd.best,   'color'=>$psd.best_color]
          ] as $sc}
          <div style="text-align:center;background:var(--neria-bg);border-radius:6px;padding:12px 8px;">
            <svg width="48" height="48" viewBox="0 0 48 48">
              <circle cx="24" cy="24" r="18" fill="none" stroke="var(--neria-border)" stroke-width="5"/>
              {if $sc.val !== null}
              <circle cx="24" cy="24" r="18" fill="none" stroke="{$sc.color}" stroke-width="5"
                      stroke-dasharray="{math equation='113 * v / 100' v=$sc.val} 113"
                      stroke-dashoffset="28" stroke-linecap="round" transform="rotate(-90 24 24)"/>
              <text x="24" y="28" text-anchor="middle" font-size="11" font-weight="700" fill="{$sc.color}">{$sc.val}</text>
              {else}
              <text x="24" y="28" text-anchor="middle" font-size="11" fill="#ccc">—</text>
              {/if}
            </svg>
            <div style="font-size:10px;color:var(--neria-muted);margin-top:4px;">{$sc.label}</div>
          </div>
          {/foreach}
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
          {foreach [
            ['label'=>'LCP','val'=>$psd.lcp,'status'=>$psd.lcp_status],
            ['label'=>'CLS','val'=>$psd.cls,'status'=>$psd.cls_status],
            ['label'=>'TBT','val'=>$psd.tbt,'status'=>$psd.tbt_status]
          ] as $cwv}
          <div style="padding:10px 12px;border-radius:6px;
                      background:{if $cwv.status == 'good'}#f0faf3{elseif $cwv.status == 'needs-improvement'}#fffde7{else}#fdf0ee{/if};
                      border:1px solid {if $cwv.status == 'good'}#c3e6cb{elseif $cwv.status == 'needs-improvement'}#ffe082{else}#f5c6cb{/if};">
            <div style="font-size:10px;font-weight:700;color:var(--neria-muted);text-transform:uppercase;margin-bottom:3px;">{$cwv.label}</div>
            <div style="font-size:14px;font-weight:700;color:{if $cwv.status == 'good'}#16a34a{elseif $cwv.status == 'needs-improvement'}#d97706{else}#dc2626{/if};">
              {$cwv.val}
            </div>
          </div>
          {/foreach}
        </div>
      </div>
      {/if}

      <p style="font-size:11px;color:var(--neria-muted);margin:12px 0 0;font-style:italic;">
        {neria_admin key='stats.analyzed_on_prefix'} {$ps.checked_at} · {neria_admin key='stats.url_label'} {$ps.url|escape:'html'}
      </p>

    {elseif $pagespeed_configured}
      <div style="text-align:center;padding:24px;color:var(--neria-muted);font-size:13px;">
        <div style="font-size:32px;margin-bottom:8px;">⚡</div>
        {neria_admin key='stats.pagespeed_ready_prompt_prefix'} <strong>{neria_admin key='common.refresh'}</strong> {neria_admin key='stats.pagespeed_ready_prompt_suffix'}
      </div>
    {/if}
  </div>

  {* ── 2. GOOGLE SEARCH CONSOLE ─────────────────────────────── *}
  <div id="neria-search-console-section" style="border:1px solid var(--neria-border);border-radius:8px;padding:20px 24px;margin-bottom:20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:20px;">🔍</span>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--neria-dark);">Google Search Console</div>
          <div style="font-size:11px;color:var(--neria-muted);">{neria_admin key='stats.searchconsole_subtitle'}</div>
        </div>
      </div>
      {if $searchconsole_connected}
        <div style="display:flex;gap:8px;align-items:center;">
          {if $searchconsole_cache_age !== null}
            <span style="font-size:11px;color:var(--neria-muted);">{neria_admin key='stats.cache_age_prefix'} {$searchconsole_cache_age} {neria_admin key='stats.cache_age_suffix'}</span>
          {/if}
          <form method="post" action="{$smarty.server.REQUEST_URI}#neria-search-console-section" style="display:inline;">
            <input type="hidden" name="neria_action" value="refresh_searchconsole">
            <input type="hidden" name="neria_tab"    value="stats">
            <button type="submit" style="padding:5px 12px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;"
                    onmouseover="this.style.background='#b8975a'" onmouseout="this.style.background='#1a1a1a'">
              ↻ {neria_admin key='common.refresh'}
            </button>
          </form>
          <form method="post" action="{$smarty.server.REQUEST_URI}#neria-search-console-section" style="display:inline;">
            <input type="hidden" name="neria_action" value="disconnect_searchconsole">
            <input type="hidden" name="neria_tab"    value="stats">
            <button type="submit" class="neria-btn neria-btn--danger neria-btn--sm">
              {neria_admin key='common.disconnect'}
            </button>
          </form>
        </div>
      {/if}
    </div>

    {* Notice explicative *}
    <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:16px;font-size:13px;line-height:1.75;color:#4a3f35;">
      <div style="font-weight:700;margin-bottom:10px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
      {neria_admin key='stats.searchconsole_howto_intro'}
      <div style="font-weight:700;margin:14px 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.searchconsole_howto_gettitle'}</div>
      <ul style="margin:0 0 0 18px;padding:0;">
        <li style="margin-bottom:4px;"><strong>{neria_admin key='common.clicks'}</strong> — {neria_admin key='stats.sc_li_clicks'}</li>
        <li style="margin-bottom:4px;"><strong>Impressions</strong> — {neria_admin key='stats.sc_li_impressions'}</li>
        <li style="margin-bottom:4px;"><strong>CTR</strong> — {neria_admin key='stats.sc_li_ctr'}</li>
        <li style="margin-bottom:4px;"><strong>{neria_admin key='stats.sc_avgposition_label'}</strong> — {neria_admin key='stats.sc_li_position'}</li>
        <li style="margin-bottom:4px;"><strong>{neria_admin key='stats.sc_top10_queries_label'}</strong> — {neria_admin key='stats.sc_li_topqueries'}</li>
        <li><strong>{neria_admin key='stats.sc_top10_pages_label'}</strong> — {neria_admin key='stats.sc_li_toppages'}</li>
      </ul>
      <div style="margin-top:12px;font-size:12px;opacity:.75;">{neria_admin key='stats.searchconsole_howto_footer'}</div>
    </div>

    {* État 1 : non configuré *}
    {if !$searchconsole_configured}
    <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;">

      {* Guide pas-à-pas *}
      <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#4a3f35;opacity:.6;margin-bottom:14px;">
        {neria_admin key='stats.sc_guide_title'}
      </div>

      <div style="counter-reset:step;display:flex;flex-direction:column;gap:14px;margin-bottom:20px;">

        {* Étape 1 *}
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <span style="flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#4a3f35;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;">1</span>
          <div style="font-size:12px;color:#4a3f35;line-height:1.65;">
            <a href="https://console.cloud.google.com/" target="_blank" rel="noopener" style="color:#1a7a40;font-weight:600;">console.cloud.google.com</a>
            {neria_admin key='stats.sc_step1'}
          </div>
        </div>

        {* Étape 2 *}
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <span style="flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#4a3f35;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;">2</span>
          <div style="font-size:12px;color:#4a3f35;line-height:1.65;">
            {neria_admin key='stats.sc_step2'}
          </div>
        </div>

        {* Étape 3 *}
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <span style="flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#4a3f35;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;">3</span>
          <div style="font-size:12px;color:#4a3f35;line-height:1.65;">
            {neria_admin key='stats.sc_step3'}
          </div>
        </div>

        {* Étape 4 *}
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <span style="flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#4a3f35;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;">4</span>
          <div style="font-size:12px;color:#4a3f35;line-height:1.65;">
            {neria_admin key='stats.sc_step4'}
            <div style="margin-top:8px;display:flex;align-items:center;gap:8px;">
              <code id="neria-sc-redirect-uri"
                    style="flex:1;font-size:11px;background:#fff;border:1px solid #d4c5a9;padding:7px 10px;border-radius:4px;word-break:break-all;color:#1a1a1a;">
                {$sc_redirect_uri|escape:'html'}
              </code>
              <button type="button" id="neria-sc-copy-btn"
                      style="flex-shrink:0;padding:6px 12px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;">
                📋 {neria_admin key='common.copy'}
              </button>
            </div>
            <span style="display:block;margin-top:6px;font-size:11px;opacity:.7;">{neria_admin key='stats.sc_step4_warning'}</span>
          </div>
        </div>

        {* Étape 5 *}
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <span style="flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#4a3f35;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;">5</span>
          <div style="font-size:12px;color:#4a3f35;line-height:1.65;">
            {neria_admin key='stats.sc_step5'}
          </div>
        </div>

      </div>

      <div style="border-top:1px solid #e8d5b0;padding-top:16px;margin-bottom:16px;">
      <form method="post" action="{$smarty.server.REQUEST_URI}#neria-search-console-section">
        <input type="hidden" name="neria_action" value="save_searchconsole_config">
        <input type="hidden" name="neria_tab"    value="stats">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='stats.label_client_id'}</label>
            <input type="text" name="sc_client_id" value="{$searchconsole_client_id|escape:'html'}"
                   style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                   placeholder="12345…googleusercontent.com">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='stats.label_client_secret'}</label>
            <input type="password" name="sc_client_secret"
                   style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                   placeholder="GOCSPX-…">
          </div>
        </div>
        <button type="submit" class="neria-btn neria-btn--primary" style="font-size:12px;padding:8px 16px;">
          {neria_admin key='common.save_credentials'}
        </button>
      </form>
    </div>
    {/if}

    {* État 2 : configuré mais non connecté *}
    {if $searchconsole_configured && !$searchconsole_connected}
    <div style="background:#fff;border:1px solid #e8d5b0;border-radius:6px;padding:16px 20px;">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
        <div style="width:8px;height:8px;border-radius:50%;background:#e67e22;flex-shrink:0;"></div>
        <div style="font-weight:700;font-size:13px;color:#5c3d1e;">{neria_admin key='stats.creds_saved_authreq'}</div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="post" action="{$smarty.server.REQUEST_URI}#neria-search-console-section">
          <input type="hidden" name="neria_action" value="connect_searchconsole">
          <input type="hidden" name="neria_tab"    value="stats">
          <button type="submit" style="background:#1a7a40;color:#fff;border:none;border-radius:5px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;">
            🔗 {neria_admin key='stats.connect_google_btn'}
          </button>
        </form>
        <form method="post" action="{$smarty.server.REQUEST_URI}#neria-search-console-section">
          <input type="hidden" name="neria_action" value="save_searchconsole_config">
          <input type="hidden" name="neria_tab"    value="stats">
          <input type="hidden" name="sc_client_id"     value="">
          <input type="hidden" name="sc_client_secret" value="">
          <button type="submit" style="background:#fff;color:#7a6a5a;border:1px solid #d4c5a9;border-radius:5px;padding:9px 16px;font-size:12px;cursor:pointer;">
            {neria_admin key='stats.edit_credentials_btn'}
          </button>
        </form>
      </div>
    </div>
    {/if}

    {* État 3 : connecté — données *}
    {if $searchconsole_connected}
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;background:#f0faf3;border:1px solid #c3e6cb;border-radius:6px;padding:10px 14px;">
      <div style="width:8px;height:8px;border-radius:50%;background:#16a34a;flex-shrink:0;"></div>
      <span style="font-size:12px;font-weight:700;color:#16a34a;">{neria_admin key='stats.sc_connected_label'}</span>
    </div>

    {if $searchconsole_stats}
      {assign var="sc" value=$searchconsole_stats}
      <div style="font-size:11px;color:var(--neria-muted);margin-bottom:12px;">{$sc.site_url|escape:'html'} · {$sc.period}</div>

      {* 4 KPIs *}
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:20px;">
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:18px;margin-bottom:4px;">👁</div>
          <div style="font-size:20px;font-weight:700;color:var(--neria-dark);">{$sc.impressions|number_format:0:',':' '}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">{neria_admin key='stats.impressions_label'}</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:18px;margin-bottom:4px;">↗</div>
          <div style="font-size:20px;font-weight:700;color:var(--neria-dark);">{$sc.clicks|number_format:0:',':' '}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">{neria_admin key='common.clicks'}</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:18px;margin-bottom:4px;">%</div>
          <div style="font-size:20px;font-weight:700;color:var(--neria-dark);">{$sc.ctr}%</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">CTR</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:18px;margin-bottom:4px;">#</div>
          <div style="font-size:20px;font-weight:700;color:var(--neria-dark);">{$sc.position}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">{neria_admin key='stats.sc_avgposition_label'}</div>
        </div>
      </div>

      {* Top requêtes + Top pages côte à côte *}
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

        {* Top 10 requêtes *}
        {if $sc.queries}
        <div>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:8px;">{neria_admin key='stats.top_queries_label'}</div>
          <table class="neria-table" style="font-size:12px;">
            <thead><tr>
              <th>{neria_admin key='stats.query_label'}</th>
              <th class="neria-table__num">{neria_admin key='common.clicks'}</th>
              <th class="neria-table__num">{neria_admin key='stats.position_short'}</th>
            </tr></thead>
            <tbody>
              {foreach $sc.queries as $q}
              <tr>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$q.label|escape:'html'}">{$q.label|escape:'html'}</td>
                <td class="neria-table__num">{$q.clicks}</td>
                <td class="neria-table__num" style="color:{if $q.position <= 3}#16a34a{elseif $q.position <= 10}#d97706{else}#dc2626{/if};font-weight:700;">{$q.position}</td>
              </tr>
              {/foreach}
            </tbody>
          </table>
        </div>
        {/if}

        {* Top 10 pages *}
        {if $sc.pages}
        <div>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:8px;">{neria_admin key='stats.top_pages_label'}</div>
          <table class="neria-table" style="font-size:12px;">
            <thead><tr>
              <th>{neria_admin key='stats.page_label'}</th>
              <th class="neria-table__num">{neria_admin key='common.clicks'}</th>
              <th class="neria-table__num">{neria_admin key='stats.position_short'}</th>
            </tr></thead>
            <tbody>
              {foreach $sc.pages as $p}
              <tr>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$p.label|escape:'html'}">{$p.short|escape:'html'}</td>
                <td class="neria-table__num">{$p.clicks}</td>
                <td class="neria-table__num" style="color:{if $p.position <= 3}#16a34a{elseif $p.position <= 10}#d97706{else}#dc2626{/if};font-weight:700;">{$p.position}</td>
              </tr>
              {/foreach}
            </tbody>
          </table>
        </div>
        {/if}

      </div>
      <p style="font-size:11px;color:var(--neria-muted);margin:12px 0 0;font-style:italic;">{neria_admin key='stats.sc_data_footer'} {$sc.checked_at} {neria_admin key='stats.sc_latency_note'}</p>

    {else}
      <div style="text-align:center;padding:20px;color:var(--neria-muted);font-size:13px;">
        {neria_admin key='stats.sc_empty_prompt'}
      </div>
    {/if}
    {/if}
  </div>

  {* ── 3. API SEO PAYANTE (Semrush / Moz) ──────────────────── *}
  <div id="neria-seo-api-section" style="border:1px solid var(--neria-border);border-radius:8px;padding:20px 24px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:20px;">📊</span>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--neria-dark);">{neria_admin key='stats.seo_api_title'} <span style="font-size:11px;font-weight:400;color:var(--neria-muted);">{neria_admin key='stats.optional_label'}</span></div>
          <div style="font-size:11px;color:var(--neria-muted);">{neria_admin key='stats.seo_api_subtitle'}</div>
        </div>
      </div>
      {if $seo_configured}
        <div style="display:flex;gap:8px;align-items:center;">
          {if $seo_cache_age !== null}
            <span style="font-size:11px;color:var(--neria-muted);">{neria_admin key='stats.cache_age_prefix'} {$seo_cache_age} {neria_admin key='stats.cache_age_suffix'}</span>
          {/if}
          <form method="post" action="{$smarty.server.REQUEST_URI}#neria-seo-api-section" style="display:inline;">
            <input type="hidden" name="neria_action" value="refresh_seo_api">
            <input type="hidden" name="neria_tab"    value="stats">
            <button type="submit" style="padding:5px 12px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;"
                    onmouseover="this.style.background='#b8975a'" onmouseout="this.style.background='#1a1a1a'">
              ↻ {neria_admin key='common.refresh'}
            </button>
          </form>
        </div>
      {/if}
    </div>

    {* Notice explicative *}
    <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:16px;font-size:13px;line-height:1.75;color:#4a3f35;">
      <div style="font-weight:700;margin-bottom:10px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
      {neria_admin key='stats.seo_howto_intro'}
      <div style="font-weight:700;margin:14px 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Semrush</div>
      <ul style="margin:0 0 0 18px;padding:0;">
        <li style="margin-bottom:4px;"><strong>{neria_admin key='stats.seo_semrush_traffic_label'}</strong> — {neria_admin key='stats.seo_semrush_li1'}</li>
        <li style="margin-bottom:4px;"><strong>{neria_admin key='stats.seo_semrush_positioned_label'}</strong> — {neria_admin key='stats.seo_semrush_li2'}</li>
        <li><strong>{neria_admin key='stats.seo_top_keywords_label'}</strong> — {neria_admin key='stats.seo_semrush_li3'}</li>
      </ul>
      <div style="font-weight:700;margin:14px 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Moz</div>
      <ul style="margin:0 0 0 18px;padding:0;">
        <li style="margin-bottom:4px;"><strong>{neria_admin key='stats.seo_da_label'}</strong> — {neria_admin key='stats.seo_moz_li1'}</li>
        <li style="margin-bottom:4px;"><strong>{neria_admin key='stats.seo_pa_label'}</strong> — {neria_admin key='stats.seo_moz_li2'}</li>
        <li style="margin-bottom:4px;"><strong>{neria_admin key='stats.seo_spamscore_label'}</strong> — {neria_admin key='stats.seo_moz_li3'}</li>
        <li><strong>{neria_admin key='stats.seo_backlinks_label'}</strong> — {neria_admin key='stats.seo_moz_li4'}</li>
      </ul>
      <div style="margin-top:12px;font-size:12px;opacity:.75;">{neria_admin key='stats.seo_howto_footer'}</div>
    </div>

    {* Formulaire de configuration *}
    <form method="post" action="{$smarty.server.REQUEST_URI}#neria-seo-api-section">
      <input type="hidden" name="neria_action" value="save_seo_config">
      <input type="hidden" name="neria_tab"    value="stats">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        {* Choix du fournisseur *}
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:var(--neria-dark);margin-bottom:6px;">{neria_admin key='stats.label_provider'}</label>
          <select name="seo_provider" id="neria-seo-provider"
                  style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;">
            <option value="">{neria_admin key='stats.provider_none_option'}</option>
            <option value="semrush" {if $seo_provider == 'semrush'}selected{/if}>Semrush</option>
            <option value="moz"     {if $seo_provider == 'moz'}selected{/if}>Moz</option>
          </select>
        </div>

        {* Champs Semrush *}
        <div id="neria-seo-semrush" style="display:{if $seo_provider == 'semrush'}block{else}none{/if};">
          <label style="display:block;font-size:11px;font-weight:600;color:var(--neria-dark);margin-bottom:6px;">{neria_admin key='stats.label_semrush_key'}</label>
          <input type="text" name="seo_semrush_key" value="{$seo_semrush_key|escape:'html'}"
                 style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                 placeholder="{neria_admin key='stats.semrush_key_placeholder'}">
          <div style="font-size:10px;color:var(--neria-muted);margin-top:4px;">
            <a href="https://www.semrush.com/api-documentation/" target="_blank" style="color:var(--neria-accent);">{neria_admin key='stats.seo_semrush_doc_link'}</a>
          </div>
        </div>

        {* Champs Moz *}
        <div id="neria-seo-moz" style="display:{if $seo_provider == 'moz'}block{else}none{/if};">
          <label style="display:block;font-size:11px;font-weight:600;color:var(--neria-dark);margin-bottom:4px;">{neria_admin key='stats.label_moz_access'}</label>
          <input type="text" name="seo_moz_access" value="{$seo_moz_access|escape:'html'}"
                 style="width:100%;padding:7px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;margin-bottom:8px;"
                 placeholder="mozscape-…">
          <label style="display:block;font-size:11px;font-weight:600;color:var(--neria-dark);margin-bottom:4px;">{neria_admin key='stats.label_moz_secret'}</label>
          <input type="password" name="seo_moz_secret"
                 style="width:100%;padding:7px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                 placeholder="…">
          <div style="font-size:10px;color:var(--neria-muted);margin-top:4px;">
            <a href="https://moz.com/products/api" target="_blank" style="color:var(--neria-accent);">{neria_admin key='stats.seo_moz_doc_link'}</a>
          </div>
        </div>
      </div>

      <button type="submit" class="neria-btn neria-btn--primary" style="font-size:12px;padding:8px 16px;">
        {neria_admin key='common.save'}
      </button>
    </form>

    {* Résultats Semrush *}
    {if $seo_report && $seo_report.provider == 'semrush'}
      {assign var="sr" value=$seo_report}
      <hr style="border:none;border-top:1px solid var(--neria-border);margin:20px 0;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:12px;">Semrush — {$sr.domain|escape:'html'} · {$sr.checked_at}</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:20px;">
        {capture name="lbl_score_auto"}{neria_admin key='stats.seo_score_auto'}{/capture}
        {capture name="lbl_kw_org"}{neria_admin key='stats.seo_keywords_org'}{/capture}
        {capture name="lbl_traffic_org"}{neria_admin key='stats.seo_traffic_org'}{/capture}
        {capture name="lbl_kw_paid"}{neria_admin key='stats.seo_keywords_paid'}{/capture}
        {foreach [
          ['label'=>$smarty.capture.lbl_score_auto,'val'=>$sr.authority_score,'icon'=>'★'],
          ['label'=>$smarty.capture.lbl_kw_org,'val'=>$sr.organic_keywords|number_format:0:',':' ','icon'=>'🔑'],
          ['label'=>$smarty.capture.lbl_traffic_org,'val'=>$sr.organic_traffic|number_format:0:',':' ','icon'=>'📈'],
          ['label'=>$smarty.capture.lbl_kw_paid,'val'=>$sr.paid_keywords|number_format:0:',':' ','icon'=>'💰']
        ] as $kpi}
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:16px;margin-bottom:4px;">{$kpi.icon}</div>
          <div style="font-size:18px;font-weight:700;color:var(--neria-dark);">{$kpi.val}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">{$kpi.label}</div>
        </div>
        {/foreach}
      </div>
      {if $sr.keywords}
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:8px;">{neria_admin key='stats.top_keywords_org_label'}</div>
      <table class="neria-table" style="font-size:12px;">
        <thead><tr>
          <th>{neria_admin key='stats.keyword_label'}</th>
          <th class="neria-table__num">{neria_admin key='stats.position_label'}</th>
          <th class="neria-table__num">{neria_admin key='stats.volume_month_label'}</th>
        </tr></thead>
        <tbody>
          {foreach $sr.keywords as $kw}
          <tr>
            <td>{$kw.keyword|escape:'html'}</td>
            <td class="neria-table__num" style="font-weight:700;color:{if $kw.position <= 3}#16a34a{elseif $kw.position <= 10}#d97706{else}#dc2626{/if};">#{$kw.position}</td>
            <td class="neria-table__num">{$kw.volume|number_format:0:',':' '}</td>
          </tr>
          {/foreach}
        </tbody>
      </table>
      {/if}
    {/if}

    {* Résultats Moz *}
    {if $seo_report && $seo_report.provider == 'moz'}
      {assign var="mr" value=$seo_report}
      <hr style="border:none;border-top:1px solid var(--neria-border);margin:20px 0;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:12px;">Moz — {$mr.domain|escape:'html'} · {$mr.checked_at}</div>

      {assign var="da" value=$mr.domain_authority}
      {if $da >= 60}{assign var="da_color" value='#16a34a'}
      {elseif $da >= 30}{assign var="da_color" value='#d97706'}
      {else}{assign var="da_color" value='#dc2626'}{/if}

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;">
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:14px;text-align:center;">
          <div style="font-size:32px;font-weight:700;color:{$da_color};">{$da}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">{neria_admin key='stats.seo_da_label'}</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:14px;text-align:center;">
          <div style="font-size:32px;font-weight:700;color:var(--neria-dark);">{$mr.page_authority}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">{neria_admin key='stats.seo_pa_label'}</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:14px;text-align:center;">
          <div style="font-size:32px;font-weight:700;color:var(--neria-dark);">{$mr.links_to_root|number_format:0:',':' '}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">{neria_admin key='stats.seo_backlinks_label'}</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:14px;text-align:center;">
          {assign var="spam" value=$mr.spam_score}
          <div style="font-size:32px;font-weight:700;color:{if $spam < 30}#16a34a{elseif $spam < 60}#d97706{else}#dc2626{/if};">{$spam}%</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">{neria_admin key='stats.seo_spamscore_label'}</div>
        </div>
      </div>
    {/if}

    {if !$seo_provider}
    <div style="margin-top:14px;padding:12px 16px;background:#fef9f0;border:1px solid #e8d5b0;border-radius:6px;font-size:12px;color:var(--neria-muted);line-height:1.6;">
      💡 {neria_admin key='stats.seo_no_provider_hint'}
    </div>
    {/if}
  </div>
</div>

<script>
var _nCopyLbl = {
  copy:   "📋 {neria_admin key='common.copy' esc='javascript'}",
  copied: "✓ {neria_admin key='common.copied' esc='javascript'}"
};
</script>
{literal}
<script>
(function() {
  // Toggle Semrush / Moz
  var sel = document.getElementById('neria-seo-provider');
  if (sel) {
    function toggle() {
      var v = sel.value;
      var sm = document.getElementById('neria-seo-semrush');
      var mz = document.getElementById('neria-seo-moz');
      if (sm) sm.style.display = (v === 'semrush') ? 'block' : 'none';
      if (mz) mz.style.display = (v === 'moz')     ? 'block' : 'none';
    }
    sel.addEventListener('change', toggle);
  }

  // Bouton copier l'URI de redirection Postmaster Tools
  var pmCopyBtn = document.getElementById('neria-pm-copy-btn');
  if (pmCopyBtn) {
    pmCopyBtn.addEventListener('mouseover', function() { this.style.background = '#b8975a'; });
    pmCopyBtn.addEventListener('mouseout',  function() { this.style.background = '#1a1a1a'; });
    pmCopyBtn.addEventListener('click', function() {
      var uri = document.getElementById('neria-pm-redirect-uri');
      if (!uri) { return; }
      navigator.clipboard.writeText(uri.textContent.trim()).then(function() {
        pmCopyBtn.textContent = _nCopyLbl.copied;
        setTimeout(function() { pmCopyBtn.textContent = _nCopyLbl.copy; }, 2000);
      });
    });
  }

  // Bouton copier l'URI de redirection Search Console
  var copyBtn = document.getElementById('neria-sc-copy-btn');
  if (copyBtn) {
    copyBtn.addEventListener('mouseover', function() { this.style.background = '#b8975a'; });
    copyBtn.addEventListener('mouseout',  function() { this.style.background = '#1a1a1a'; });
    copyBtn.addEventListener('click', function() {
      var uri = document.getElementById('neria-sc-redirect-uri');
      if (!uri) { return; }
      navigator.clipboard.writeText(uri.textContent.trim()).then(function() {
        copyBtn.textContent = _nCopyLbl.copied;
        setTimeout(function() { copyBtn.textContent = _nCopyLbl.copy; }, 2000);
      });
    });
  }
})();
</script>
{/literal}

{* ── Google Postmaster Tools — intégration OAuth ────────────── *}
<div class="neria-section" id="neria-postmaster-tools">
  <h2 class="neria-section__title">🔭 Google Postmaster Tools</h2>
  <p class="neria-section__desc">
    {neria_admin key='stats.postmaster_desc'}
  </p>

  {* ═══ ÉTAT 1 : Non configuré — saisie des credentials ══════ *}
  {if !$postmaster_configured}
  <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:24px;margin-top:16px;">
    <div style="font-weight:700;font-size:13px;color:#5c3d1e;margin-bottom:8px;">⚙️ {neria_admin key='stats.oauth_config_title'}</div>
    <p style="font-size:12px;color:#7a6a5a;line-height:1.6;margin:0 0 16px;">
      {neria_admin key='stats.pm_intro'}
    </p>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
      <code id="neria-pm-redirect-uri"
            style="flex:1;font-size:11px;background:#f9f6f1;border:1px solid #d4c5a9;padding:7px 10px;border-radius:4px;word-break:break-all;color:#1a1a1a;">
        {$pm_redirect_uri|escape:'html'}
      </code>
      <button type="button" id="neria-pm-copy-btn"
              style="flex-shrink:0;padding:6px 12px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;">
        📋 {neria_admin key='common.copy'}
      </button>
    </div>
    <div style="font-size:12px;background:#fef9f0;border:1px solid #e8d5b0;border-radius:6px;padding:14px 16px;color:#5c3d1e;line-height:1.7;margin-bottom:20px;">
      <strong>{neria_admin key='stats.pm_config_steps_title'}</strong><br>
      1. <a href="https://console.cloud.google.com/" target="_blank" rel="noopener" style="color:#1a7a40;font-weight:600;">console.cloud.google.com</a>
         {neria_admin key='stats.pm_step1'}<br>
      2. {neria_admin key='stats.pm_step2'}<br>
      3. {neria_admin key='stats.pm_step3'}<br>
      4. {neria_admin key='stats.pm_step4'}<br>
      5. {neria_admin key='stats.pm_step5'}<br>
      <span style="display:block;margin-top:8px;font-size:11px;opacity:.75;">{neria_admin key='stats.pm_domain_verified_warning'}</span>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI}#neria-postmaster-tools">
      <input type="hidden" name="neria_action" value="save_postmaster_config">
      <input type="hidden" name="neria_tab"    value="stats">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='stats.label_client_id'}</label>
          <input type="text" name="postmaster_client_id" value="{$postmaster_client_id|escape:'html'}"
                 style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                 placeholder="12345...apps.googleusercontent.com">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">{neria_admin key='stats.label_client_secret'}</label>
          <input type="password" name="postmaster_client_secret"
                 style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                 placeholder="GOCSPX-…">
        </div>
      </div>
      <button type="submit" class="neria-btn neria-btn--primary" style="font-size:12px;padding:8px 18px;">
        {neria_admin key='common.save_credentials'}
      </button>
    </form>
  </div>
  {/if}

  {* ═══ ÉTAT 2 : Configuré mais non connecté ══════════════════ *}
  {if $postmaster_configured && !$postmaster_connected}
  <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:24px;margin-top:16px;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <div style="width:10px;height:10px;border-radius:50%;background:#e67e22;flex-shrink:0;"></div>
      <div>
        <div style="font-weight:700;font-size:13px;color:#5c3d1e;">{neria_admin key='stats.creds_saved_authreq'}</div>
        <div style="font-size:11px;color:#7a6a5a;margin-top:2px;">{neria_admin key='stats.label_client_id'} : {$postmaster_client_id|escape:'html'|truncate:40:'…':true}</div>
      </div>
    </div>
    <p style="font-size:12px;color:#7a6a5a;line-height:1.6;margin:0 0 16px;">
      {neria_admin key='stats.pm_authorize_body'}
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <form method="post" action="{$smarty.server.REQUEST_URI}#neria-postmaster-tools">
        <input type="hidden" name="neria_action" value="connect_postmaster">
        <input type="hidden" name="neria_tab"    value="stats">
        <button type="submit" style="background:#1a7a40;color:#fff;border:none;border-radius:5px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;">
          🔗 {neria_admin key='stats.connect_google_btn'}
        </button>
      </form>
      <form method="post" action="{$smarty.server.REQUEST_URI}#neria-postmaster-tools">
        <input type="hidden" name="neria_action" value="save_postmaster_config">
        <input type="hidden" name="neria_tab"    value="stats">
        <input type="hidden" name="postmaster_client_id"     value="">
        <input type="hidden" name="postmaster_client_secret" value="">
        <button type="submit" style="background:#fff;color:#7a6a5a;border:1px solid #d4c5a9;border-radius:5px;padding:9px 16px;font-size:12px;cursor:pointer;">
          {neria_admin key='stats.edit_credentials_btn'}
        </button>
      </form>
    </div>
  </div>
  {/if}

  {* ═══ ÉTAT 3 : Connecté — affichage des données ═════════════ *}
  {if $postmaster_connected}
  <div style="margin-top:16px;">

    {* Barre de statut *}
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#fff;border:1px solid #c8e6c9;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:10px;height:10px;border-radius:50%;background:#16a34a;flex-shrink:0;"></div>
        <div>
          <span style="font-weight:700;font-size:13px;color:#16a34a;">{neria_admin key='stats.pm_connected_label'}</span>
          {if $postmaster_cache_age !== null}
          <span style="font-size:11px;color:#7a6a5a;margin-left:8px;">{neria_admin key='stats.pm_data_refreshed_prefix'} {$postmaster_cache_age} {neria_admin key='stats.cache_age_suffix'}</span>
          {/if}
        </div>
      </div>
      <div style="display:flex;gap:8px;">
        <form method="post" action="{$smarty.server.REQUEST_URI}#neria-postmaster-tools" style="display:inline;">
          <input type="hidden" name="neria_action" value="refresh_postmaster">
          <input type="hidden" name="neria_tab"    value="stats">
          <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
            ↺ {neria_admin key='common.refresh'}
          </button>
        </form>
        <form method="post" action="{$smarty.server.REQUEST_URI}#neria-postmaster-tools" style="display:inline;">
          <input type="hidden" name="neria_action" value="disconnect_postmaster">
          <input type="hidden" name="neria_tab"    value="stats">
          <button type="submit" class="neria-btn neria-btn--danger neria-btn--sm">
            {neria_admin key='common.disconnect'}
          </button>
        </form>
      </div>
    </div>

    {* Données par domaine *}
    {if $postmaster_stats && $postmaster_stats|count > 0}
      {foreach $postmaster_stats as $ps}
      <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:20px;margin-bottom:14px;">

        {* En-tête domaine *}
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <div>
            <div style="font-weight:700;font-size:15px;color:#5c3d1e;">{$ps.domain|escape:'html'}</div>
            <div style="font-size:11px;color:#7a6a5a;">{neria_admin key='stats.domain_data_of'} {$ps.date|escape:'html'}</div>
          </div>
          {* Badge réputation domaine *}
          {assign var="drep" value=$ps.domain_reputation}
          {if $drep === 'HIGH'}
            <div style="background:#d4edda;color:#155724;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;">✅ {neria_admin key='stats.rep_high'}</div>
          {elseif $drep === 'MEDIUM'}
            <div style="background:#fff3cd;color:#856404;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;">⚠️ {neria_admin key='stats.rep_medium'}</div>
          {elseif $drep === 'LOW'}
            <div style="background:#f8d7da;color:#721c24;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;">🔴 {neria_admin key='stats.rep_low'}</div>
          {elseif $drep === 'BAD'}
            <div style="background:#721c24;color:#fff;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;">💀 {neria_admin key='stats.rep_bad'}</div>
          {else}
            <div style="background:#f9f6f1;color:#7a6a5a;border-radius:20px;padding:5px 14px;font-size:12px;">○ {neria_admin key='stats.rep_insufficient'}</div>
          {/if}
        </div>

        {* Taux de spam *}
        {if $ps.spam_rate !== null}
          {assign var="spRate" value=$ps.spam_rate}
          {capture name="zone_green"}{neria_admin key='stats.zone_green'}{/capture}
          {capture name="zone_attention"}{neria_admin key='stats.zone_attention'}{/capture}
          {capture name="zone_danger"}{neria_admin key='stats.zone_danger'}{/capture}
          {if $spRate < 0.1}
            {assign var="spColor" value="#16a34a"}
            {assign var="spLabel" value=$smarty.capture.zone_green}
          {elseif $spRate < 0.3}
            {assign var="spColor" value="#d97706"}
            {assign var="spLabel" value=$smarty.capture.zone_attention}
          {else}
            {assign var="spColor" value="#dc2626"}
            {assign var="spLabel" value=$smarty.capture.zone_danger}
          {/if}
          <div style="background:#f9f6f1;border-radius:8px;padding:14px 16px;margin-bottom:14px;">
            <div style="font-size:11px;font-weight:600;color:#7a6a5a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">{neria_admin key='stats.spam_rate_label'}</div>
            <div style="display:flex;align-items:baseline;gap:8px;">
              <span style="font-size:32px;font-weight:700;color:{$spColor};">{$spRate|string_format:"%.4f"}%</span>
              <span style="font-size:12px;font-weight:600;color:{$spColor};">{$spLabel}</span>
            </div>
            <div style="margin-top:8px;height:6px;background:#e8d5b0;border-radius:3px;overflow:hidden;">
              {assign var="spPct" value=($spRate/0.5*100)}
              {if $spPct > 100}{assign var="spPct" value=100}{/if}
              <div style="height:100%;width:{$spPct}%;background:{$spColor};border-radius:3px;transition:width .4s;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:10px;color:#7a6a5a;margin-top:3px;">
              <span>0%</span><span style="color:#16a34a;">0,1%</span><span style="color:#d97706;">0,3%</span><span>0,5%+</span>
            </div>
          </div>
        {/if}

        {* Grille SPF / DKIM / DMARC / TLS *}
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px;">
          {foreach ['SPF'=>$ps.spf_success,'DKIM'=>$ps.dkim_success,'DMARC'=>$ps.dmarc_success,'TLS'=>$ps.tls_outbound] as $mlabel=>$mval}
            <div style="text-align:center;background:#f9f6f1;border-radius:6px;padding:10px 6px;">
              <div style="font-size:10px;font-weight:600;color:#7a6a5a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">{$mlabel}</div>
              {if $mval !== null}
                {if $mval >= 95}
                  <div style="font-size:20px;font-weight:700;color:#16a34a;">{$mval}%</div>
                {elseif $mval >= 80}
                  <div style="font-size:20px;font-weight:700;color:#d97706;">{$mval}%</div>
                {else}
                  <div style="font-size:20px;font-weight:700;color:#dc2626;">{$mval}%</div>
                {/if}
              {else}
                <div style="font-size:18px;color:#b0a090;">—</div>
              {/if}
            </div>
          {/foreach}
        </div>

        {* IP Reputations *}
        {if $ps.ip_reputations && $ps.ip_reputations|count > 0}
        <div style="margin-top:10px;">
          <div style="font-size:11px;font-weight:600;color:#7a6a5a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">{neria_admin key='stats.ip_reputations_label'}</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            {foreach $ps.ip_reputations as $ipr}
              {assign var="ipRep" value=$ipr.reputation|default:'UNKNOWN'}
              {if $ipRep === 'HIGH'}
                <span style="background:#d4edda;color:#155724;padding:3px 10px;border-radius:12px;font-size:11px;">✅ HIGH</span>
              {elseif $ipRep === 'MEDIUM'}
                <span style="background:#fff3cd;color:#856404;padding:3px 10px;border-radius:12px;font-size:11px;">⚠️ MEDIUM</span>
              {elseif $ipRep === 'LOW'}
                <span style="background:#f8d7da;color:#721c24;padding:3px 10px;border-radius:12px;font-size:11px;">🔴 LOW</span>
              {elseif $ipRep === 'BAD'}
                <span style="background:#721c24;color:#fff;padding:3px 10px;border-radius:12px;font-size:11px;">💀 BAD</span>
              {else}
                <span style="background:#f9f6f1;color:#7a6a5a;padding:3px 10px;border-radius:12px;font-size:11px;">○ {$ipRep}</span>
              {/if}
            {/foreach}
          </div>
        </div>
        {/if}

        {* Erreurs de livraison *}
        {if $ps.delivery_errors && $ps.delivery_errors|count > 0}
        <div style="margin-top:12px;padding:10px 14px;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;">
          <div style="font-size:11px;font-weight:600;color:#856404;margin-bottom:4px;">⚠️ {neria_admin key='stats.delivery_errors_label'}</div>
          {foreach $ps.delivery_errors as $de}
          <div style="font-size:11px;color:#856404;line-height:1.5;">
            {$de.errorClass|default:'UNKNOWN'} — {$de.errorType|default:''} ({$de.errorRatio|default:0|string_format:"%.3f"}%)
          </div>
          {/foreach}
        </div>
        {/if}

      </div>
      {/foreach}

    {elseif $postmaster_stats !== null}
      <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:8px;padding:20px;text-align:center;color:#7a6a5a;font-size:13px;">
        <div style="font-size:24px;margin-bottom:8px;">📭</div>
        {neria_admin key='stats.pm_no_data_7d'}<br>
        <small>{neria_admin key='stats.pm_no_data_hint'}</small>
      </div>
    {else}
      <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:8px;padding:20px;text-align:center;color:#7a6a5a;font-size:13px;">
        <div style="font-size:24px;margin-bottom:8px;">⏳</div>
        {neria_admin key='stats.pm_empty_prompt'}
      </div>
    {/if}

  </div>
  {/if}

  {* ── Microsoft SNDS — guide statique ──────────────────────── *}
  <div id="neria-snds-section" style="margin-top:20px;background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:20px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
      <span style="font-size:24px;">🪟</span>
      <div>
        <div style="font-weight:700;font-size:14px;color:#5c3d1e;">Microsoft SNDS</div>
        <div style="font-size:11px;color:#7a6a5a;">sendersupport.olc.protection.outlook.com/snds</div>
      </div>
    </div>
    <p style="font-size:12px;color:#7a6a5a;line-height:1.6;margin:0 0 14px;">
      {neria_admin key='stats.snds_desc'}
    </p>
    <div style="font-size:12px;background:#f9f6f1;border-radius:6px;padding:10px 12px;color:#5c3d1e;line-height:1.6;">
      <strong>{neria_admin key='stats.snds_howto_title'}</strong><br>
      1. {neria_admin key='stats.snds_step1'}<br>
      2. {neria_admin key='stats.snds_step2'}<br>
      3. {neria_admin key='stats.snds_step3'}<br>
      4. {neria_admin key='stats.snds_step4'}
    </div>
  </div>

  <div style="margin-top:14px;padding:12px 16px;background:#fef9f0;border:1px solid #e8d5b0;border-radius:6px;font-size:12px;color:#7a6a5a;line-height:1.6;">
    💡 <strong>{neria_admin key='seasonal.tip_label'}</strong> {neria_admin key='stats.pm_tip'}
  </div>
</div>

{* ── Score de délivrabilité ─────────────────────────────────── *}
<div class="neria-section" id="neria-score-panel">

  <h2 class="neria-section__title">{neria_admin key='stats.score_title'}</h2>
  <p class="neria-section__desc">
    {neria_admin key='stats.score_desc'}
  </p>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.score_howto_body_pre'} <strong>{neria_admin key='stats.analyze_btn'}</strong>. {neria_admin key='stats.score_howto_body_post'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>{neria_admin key='stats.score_label'}</strong> {neria_admin key='stats.score_howto_scale'}
    </div>
  </div>

  <form method="post" action="{$smarty.server.REQUEST_URI}#neria-score-panel">
    <input type="hidden" name="neria_action" value="deliverability_score">
    <input type="hidden" name="neria_tab"    value="stats">

    <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
      <div class="neria-form-group" style="flex:1; min-width:240px;">
        <label class="neria-label" for="score_template">{neria_admin key='stats.score_template_label'}</label>
        <select id="score_template" name="score_template" class="neria-select">
          {foreach $template_labels as $key => $label}
            <option value="{$key}"{if isset($smarty.request.score_template) && $smarty.request.score_template == $key} selected{/if}>{$label}</option>
          {/foreach}
        </select>
      </div>

      <div class="neria-form-group" style="min-width:180px;">
        <label class="neria-label" for="score_lang">{neria_admin key='common.language'}</label>
        <select id="score_lang" name="score_lang" class="neria-select">
          {foreach $lang_labels as $code => $name}
            <option value="{$code}"{if isset($smarty.request.score_lang) && $smarty.request.score_lang == $code} selected{/if}>{$lang_flags[$code]|default:''} {$name}</option>
          {/foreach}
        </select>
      </div>

      <button type="submit" class="neria-btn neria-btn--primary" id="neria-score-btn">
        {neria_admin key='stats.analyze_btn'}
      </button>
    </div>
  </form>

  {* Résultats — affichés après analyse *}
  {if isset($neria_deliverability)}
    {assign var="d" value=$neria_deliverability}
    <div style="margin-top:32px; padding-top:24px; border-top:1px solid #e3d7c7;">

      {* Jauge principale *}
      <div style="display:flex; align-items:center; gap:24px; margin-bottom:28px;">
        <div style="text-align:center; min-width:100px;">
          <div style="font-size:56px; font-weight:700; line-height:1; color:{$d.color};">{$d.score}</div>
          <div style="font-size:24px; font-weight:600; color:{$d.color}; letter-spacing:0.1em;">{$d.grade}</div>
          <div style="font-size:13px; color:#6f6a62; margin-top:4px;">{$d.label}</div>
        </div>
        <div style="flex:1;">
          <div style="background:#f0e7db; border-radius:4px; height:12px; overflow:hidden;">
            <div style="width:{$d.score}%; height:100%; background:{$d.color}; border-radius:4px; transition:width 0.6s ease;"></div>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:11px; color:#6b6459; margin-top:4px;">
            <span>0 — {neria_admin key='stats.gauge_critical'}</span>
            <span>50 — {neria_admin key='stats.gauge_acceptable'}</span>
            <span>100 — {neria_admin key='stats.gauge_excellent'}</span>
          </div>
        </div>
      </div>

      {* Détail par critère *}
      <table style="width:100%; font-size:13px; border-collapse:collapse; margin-bottom:24px;">
        <thead>
          <tr style="border-bottom:1px solid #e3d7c7;">
            <th style="text-align:left; padding:8px 4px; font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:#8c857e;">{neria_admin key='stats.col_criterion'}</th>
            <th style="text-align:left; padding:8px 4px; font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:#8c857e;">{neria_admin key='stats.col_result'}</th>
            <th style="text-align:right; padding:8px 4px; font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:#8c857e;">{neria_admin key='stats.col_impact'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $d.criteria as $c}
            <tr style="border-bottom:1px solid #f0e7db;">
              <td style="padding:10px 4px; font-weight:600; color:#2b2520;">
                {if $c.type == 'success'}✓{elseif $c.type == 'error'}✕{elseif $c.type == 'warning'}⚠{else}ℹ{/if}
                &nbsp;{$c.name|escape:'html'}
              </td>
              <td style="padding:10px 4px; color:#5a5450;">{$c.detail|escape:'html'}</td>
              <td style="padding:10px 4px; text-align:right; font-weight:600; color:{if $c.penalty < 0}#c0392b{else}#1a7a40{/if};">
                {if $c.penalty < 0}{$c.penalty}{else}+0{/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>

      {* Recommandations *}
      {if $d.recommendations}
        <div style="font-size:12px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:#8c857e; margin-bottom:10px;">
          {neria_admin key='stats.recommendations'}
        </div>
        <ul style="margin:0; padding:0; list-style:none;">
          {foreach $d.recommendations as $rec}
            <li style="padding:10px 14px; margin-bottom:8px; border-left:3px solid {if $rec.type == 'error'}#c0392b{elseif $rec.type == 'warning'}#a0520d{elseif $rec.type == 'success'}#1a7a40{else}#b38b59{/if}; background:{if $rec.type == 'error'}#fdf0ee{elseif $rec.type == 'warning'}#fef8ee{elseif $rec.type == 'success'}#eff8f2{else}#faf6f0{/if}; font-size:13px; color:#2b2520; line-height:1.6;">
              {$rec.message|escape:'html'}
            </li>
          {/foreach}
        </ul>
      {/if}

    </div>
  {/if}

  {if isset($neria_deliverability_error)}
    <p style="color:#c0392b; margin-top:16px; font-size:13px;">{$neria_deliverability_error|escape:'html'}</p>
  {/if}

  {* ── Bannière MPP ─────────────────────────────────────────── *}
  <hr style="border:none; border-top:1px solid rgba(0,0,0,.07); margin:28px 0;" />
  <div style="display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;background:linear-gradient(135deg,#f5f0fb 0%,#faf8f5 100%);border:1px solid #c9b8f0;border-radius:6px;padding:16px 20px;">
    <div style="display:flex;align-items:center;gap:14px;flex:1;min-width:260px;">
      <span style="font-size:22px;color:#5b3fa8;flex-shrink:0;">⊘</span>
      <div>
        <div style="font-size:13px;font-weight:700;color:#3d2878;">{neria_admin key='stats.mpp_title'}</div>
        <div style="font-size:11px;color:#7a6a95;margin-top:3px;line-height:1.5;">
          {neria_admin key='stats.mpp_desc'}
        </div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:20px;flex-shrink:0;">
      <div style="text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#3d2878;line-height:1;">{$stats.kpis.total_open|default:0|number_format:0:',':' '}</div>
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9b89c0;margin-top:3px;">{neria_admin key='stats.mpp_real_opens'}</div>
      </div>
      <div style="width:1px;height:36px;background:#c9b8f0;"></div>
      <div style="text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#5b3fa8;line-height:1;">{$stats.kpis.mpp_open|default:0|number_format:0:',':' '}</div>
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9b89c0;margin-top:3px;">{neria_admin key='stats.mpp_excluded'}</div>
      </div>
    </div>
  </div>

  {* ── KPIs ─────────────────────────────────────────────────── *}
  <hr style="border:none; border-top:1px solid rgba(0,0,0,.07); margin:28px 0;" />
  <div class="neria-kpi-grid neria-kpi-grid--large">

    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$stats.kpis.total_sent|default:0|number_format:0:',':' '}</div>
      <div class="neria-kpi__label">{neria_admin key='common.emails_sent'}</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.total_open|default:0|number_format:0:',':' '}</div>
      <div class="neria-kpi__label">
        {neria_admin key='common.opens'}
        {if isset($stats.kpis.mpp_open) && $stats.kpis.mpp_open > 0}
        <span class="neria-badge neria-badge--mpp" title="{neria_admin key='stats.mpp_tooltip_excluded'}">
          +{$stats.kpis.mpp_open} MPP
        </span>
        {/if}
      </div>
      <div class="neria-kpi__rate">{$stats.kpis.rate_open|default:0}%</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.total_click|default:0|number_format:0:',':' '}</div>
      <div class="neria-kpi__label">{neria_admin key='common.clicks'}</div>
      <div class="neria-kpi__rate">{$stats.kpis.rate_click|default:0}%</div>
    </div>

    <div class="neria-kpi" title="{neria_admin key='stats.ctor_tooltip'}">
      <div class="neria-kpi__value">{$stats.kpis.ctor|default:0}%</div>
      <div class="neria-kpi__label">CTOR <span style="font-size:9px;color:var(--neria-text-muted,#aaa);font-weight:400;">{neria_admin key='stats.ctor_ratio_label'}</span></div>
      <div class="neria-kpi__rate" style="font-size:10px;color:var(--neria-text-muted,#aaa);">{neria_admin key='stats.ctor_excluding_mpp'}</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.active_langs|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.active_langs'}</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.active_countries|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.countries'}</div>
    </div>

    <div class="neria-kpi">
      <div class="neria-kpi__value">{$stats.kpis.active_templates|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.active_templates'}</div>
    </div>

  </div>

  {* ── Rapport par template ─────────────────────────────────── *}
  {if isset($stats.global_30) && $stats.global_30}
  <hr style="border:none; border-top:1px solid rgba(0,0,0,.07); margin:28px 0;" />
  <h3 style="font-size:13px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.5; margin:0 0 16px 0;">{neria_admin key='stats.by_template'}</h3>

  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>{neria_admin key='common.template'}</th>
          <th class="neria-table__num">{neria_admin key='common.sent'}</th>
          <th class="neria-table__num">{neria_admin key='common.opens'}</th>
          <th class="neria-table__num">{neria_admin key='common.open_rate_short'}</th>
          <th class="neria-table__num">{neria_admin key='common.clicks'}</th>
          <th class="neria-table__num">{neria_admin key='common.click_rate_short'}</th>
          <th class="neria-table__num" title="{neria_admin key='stats.ctor_col_tooltip'}">CTOR</th>
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
            <td class="neria-table__num">
              {$row.total_open|number_format:0:',':' '}
              {if isset($row.mpp_open) && $row.mpp_open > 0}
              <span class="neria-badge neria-badge--mpp" title="+{$row.mpp_open} {neria_admin key='stats.mpp_row_tooltip_suffix'}">MPP</span>
              {/if}
            </td>
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
            <td class="neria-table__num">
              {if isset($row.ctor) && $row.ctor > 0}
              <span class="neria-rate {if $row.ctor > 20}neria-rate--good{elseif $row.ctor > 10}neria-rate--ok{else}neria-rate--low{/if}">
                {$row.ctor}%
              </span>
              {else}
              <span style="color:var(--neria-text-muted,#ccc);">—</span>
              {/if}
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {/if}

  {* ── Top 10 templates — classement ──────────────────────── *}
  <hr style="border:none; border-top:1px solid rgba(0,0,0,.07); margin:28px 0;" />
  <h3 style="font-size:13px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.5; margin:0 0 16px 0;">{neria_admin key='stats.template_ranking_title'}</h3>

  {* Onglets de tri *}
  <div style="display:flex;gap:8px;margin-bottom:16px;" id="neria-top10-tabs">
    <button class="neria-period-tab neria-period-tab--active" data-top10="open">{neria_admin key='stats.top_open_tab'}</button>
    <button class="neria-period-tab" data-top10="click">{neria_admin key='stats.top_click_tab'}</button>
    <button class="neria-period-tab" data-top10="revenue">{neria_admin key='stats.top_revenue_tab'}</button>
  </div>

  {* Top ouverture *}
  <div id="neria-top10-open">
  {if $top_templates_open}
  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead><tr>
        <th>#</th>
        <th>{neria_admin key='common.template'}</th>
        <th class="neria-table__num">{neria_admin key='common.sent'}</th>
        <th class="neria-table__num">{neria_admin key='common.open_rate_short'}</th>
        <th class="neria-table__num">{neria_admin key='common.click_rate_short'}</th>
      </tr></thead>
      <tbody>
        {foreach $top_templates_open as $i => $row}
        <tr>
          <td style="font-size:16px;font-weight:700;color:{if $i==0}#b8975a{elseif $i==1}#aaa{elseif $i==2}#a07060{else}var(--neria-muted){/if};width:32px;">
            {if $i==0}🥇{elseif $i==1}🥈{elseif $i==2}🥉{else}{$i+1}{/if}
          </td>
          <td><span class="neria-template-label">{$template_labels[$row.template]|default:$row.template}</span></td>
          <td class="neria-table__num">{$row.sent|number_format:0:',':' '}</td>
          <td class="neria-table__num">
            <span class="neria-rate {if $row.rate_open > 30}neria-rate--good{elseif $row.rate_open > 15}neria-rate--ok{else}neria-rate--low{/if}">
              {$row.rate_open}%
            </span>
          </td>
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
  {/if}
  </div>

  {* Top clic *}
  <div id="neria-top10-click" style="display:none;">
  {if $top_templates_click}
  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead><tr>
        <th>#</th>
        <th>{neria_admin key='common.template'}</th>
        <th class="neria-table__num">{neria_admin key='common.sent'}</th>
        <th class="neria-table__num">{neria_admin key='common.open_rate_short'}</th>
        <th class="neria-table__num">{neria_admin key='common.click_rate_short'}</th>
      </tr></thead>
      <tbody>
        {foreach $top_templates_click as $i => $row}
        <tr>
          <td style="font-size:16px;font-weight:700;color:{if $i==0}#b8975a{elseif $i==1}#aaa{elseif $i==2}#a07060{else}var(--neria-muted){/if};width:32px;">
            {if $i==0}🥇{elseif $i==1}🥈{elseif $i==2}🥉{else}{$i+1}{/if}
          </td>
          <td><span class="neria-template-label">{$template_labels[$row.template]|default:$row.template}</span></td>
          <td class="neria-table__num">{$row.sent|number_format:0:',':' '}</td>
          <td class="neria-table__num">{$row.rate_open}%</td>
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
  {/if}
  </div>

  {* Top CA *}
  <div id="neria-top10-revenue" style="display:none;">
  {if $top_templates_revenue}
  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead><tr>
        <th>#</th>
        <th>{neria_admin key='common.template'}</th>
        <th class="neria-table__num">{neria_admin key='stats.orders_label'}</th>
        <th class="neria-table__num">{neria_admin key='stats.revenue_total'}</th>
      </tr></thead>
      <tbody>
        {foreach $top_templates_revenue as $i => $row}
        <tr>
          <td style="font-size:16px;font-weight:700;color:{if $i==0}#b8975a{elseif $i==1}#aaa{elseif $i==2}#a07060{else}var(--neria-muted){/if};width:32px;">
            {if $i==0}🥇{elseif $i==1}🥈{elseif $i==2}🥉{else}{$i+1}{/if}
          </td>
          <td><span class="neria-template-label">{$template_labels[$row.template]|default:$row.template}</span></td>
          <td class="neria-table__num">{$row.orders}</td>
          <td class="neria-table__num" style="font-weight:700;color:var(--neria-accent);">
            {$row.revenue|string_format:"%.2f"} {$currency_symbol}
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
    <p style="font-size:13px;color:var(--neria-muted);margin:0;">{neria_admin key='stats.no_revenue_30d'}</p>
  {/if}
  </div>

  {literal}
  <script>
  (function() {
    var tabs = document.getElementById('neria-top10-tabs');
    if (!tabs) return;
    tabs.addEventListener('click', function(e) {
      var btn = e.target.closest('[data-top10]');
      if (!btn) return;
      tabs.querySelectorAll('[data-top10]').forEach(function(b){ b.classList.remove('neria-period-tab--active'); });
      btn.classList.add('neria-period-tab--active');
      ['open','click','revenue'].forEach(function(k) {
        var el = document.getElementById('neria-top10-' + k);
        if (el) el.style.display = (k === btn.dataset.top10) ? '' : 'none';
      });
    });
  })();
  </script>
  {/literal}

  {* ── Rapport par langue ───────────────────────────────────── *}
  {if isset($stats.by_lang_30) && $stats.by_lang_30}
  <hr style="border:none; border-top:1px solid rgba(0,0,0,.07); margin:28px 0;" />
  <h3 style="font-size:13px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.5; margin:0 0 16px 0;">{neria_admin key='stats.by_lang'}</h3>

  <table style="width:100%; border-collapse:collapse; font-size:13px;">
    <thead>
      <tr style="border-bottom:2px solid rgba(0,0,0,.08);">
        <th style="text-align:left; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_language'}</th>
        <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_sends'}</th>
        <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_open_rate'}</th>
        <th style="text-align:left; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px; width:200px;">{neria_admin key='stats.col_open_bar'}</th>
        <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_click_rate'}</th>
        <th style="text-align:left; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px; width:200px;">{neria_admin key='stats.col_click_bar'}</th>
      </tr>
    </thead>
    <tbody>
    {foreach $stats.by_lang_30 as $row}
      <tr style="border-bottom:1px solid rgba(0,0,0,.05);">
        <td style="padding:12px 16px;">
          <span style="font-size:16px; margin-right:8px;">{$lang_flags[$row.lang]|default:'🌐'}</span>
          <strong>{$lang_labels[$row.lang]|default:$row.lang}</strong>
          <span style="font-size:11px; opacity:.45; margin-left:6px; text-transform:uppercase;">{$row.lang}</span>
        </td>
        <td style="padding:12px 16px; text-align:center; font-weight:600;">{$row.total_sent}</td>
        <td style="padding:12px 16px; text-align:center; font-weight:700;
            color:{if $row.rate_open >= 40}#1a7a40{elseif $row.rate_open >= 20}#b8975a{else}#c0392b{/if};">
          {$row.rate_open}%
        </td>
        <td style="padding:12px 16px;">
          <div style="background:rgba(0,0,0,.08); border-radius:4px; height:8px; width:100%; overflow:hidden;">
            <div style="height:100%; border-radius:4px; background:#1a7a40;
                 width:{if $row.rate_open > 100}100{else}{$row.rate_open}{/if}%;
                 transition:width .4s ease;"></div>
          </div>
        </td>
        <td style="padding:12px 16px; text-align:center; font-weight:700;
            color:{if $row.rate_click >= 10}#1a7a40{elseif $row.rate_click >= 3}#b8975a{else}#c0392b{/if};">
          {$row.rate_click}%
        </td>
        <td style="padding:12px 16px;">
          <div style="background:rgba(0,0,0,.08); border-radius:4px; height:8px; width:100%; overflow:hidden;">
            <div style="height:100%; border-radius:4px; background:#b8975a;
                 width:{if $row.rate_click > 100}100{else}{$row.rate_click}{/if}%;
                 transition:width .4s ease;"></div>
          </div>
        </td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  {/if}

  {* ── Rapport par pays ─────────────────────────────────────── *}
  {if isset($stats.by_country_30) && $stats.by_country_30}
  <hr style="border:none; border-top:1px solid rgba(0,0,0,.07); margin:28px 0;" />
  <h3 style="font-size:13px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.5; margin:0 0 16px 0;">{neria_admin key='stats.top_countries'}</h3>

  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>{neria_admin key='common.country'}</th>
          <th class="neria-table__num">{neria_admin key='common.sent'}</th>
          <th class="neria-table__num">{neria_admin key='common.open_rate_short'}</th>
          <th class="neria-table__num">{neria_admin key='common.click_rate_short'}</th>
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
  {/if}

</div>

{* ── L'Heure d'Or ───────────────────────────────────────────── *}
{if isset($golden_hour) && $golden_hour|@count > 0}
<div class="neria-section" id="neria-golden-hour-section">
  <h2 class="neria-section__title">{neria_admin key='stats.golden_hour_title'} ✦</h2>
  <p class="neria-section__desc">{neria_admin key='stats.golden_hour_desc'}</p>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.golden_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.golden_howto_data'}
    </div>
  </div>

  <div class="neria-table-wrap">
    <table class="neria-table neria-golden-table">
      <colgroup>
        <col style="width:100px;">
        <col style="width:160px;">
        <col style="width:130px;">
        <col style="width:110px;">
        <col style="width:100px;">
      </colgroup>
      <thead>
        <tr>
          <th>{neria_admin key='common.language'}</th>
          <th>{neria_admin key='stats.golden_best_day'}</th>
          <th>{neria_admin key='stats.golden_best_hour'}</th>
          <th class="neria-table__num">{neria_admin key='stats.golden_opens'}</th>
          <th>{neria_admin key='stats.golden_confidence'}</th>
        </tr>
      </thead>
      <tbody>
        {assign var="day_names" value=['', 'stats.day_sun','stats.day_mon','stats.day_tue','stats.day_wed','stats.day_thu','stats.day_fri','stats.day_sat']}
        {foreach $golden_hour as $rec}
          <tr>
            <td>
              {$lang_flags[$rec.lang]|default:''} <strong>{$lang_labels[$rec.lang]|default:$rec.lang}</strong>
            </td>
            <td>
              {neria_admin key=$day_names[$rec.best_day]}
            </td>
            {assign var="ghNextHour" value=($rec.best_hour+1)%24}
            <td>
              <span class="neria-golden-hour">
                {if $rec.best_hour < 10}0{/if}{$rec.best_hour}h — {if $ghNextHour < 10}0{/if}{$ghNextHour}h
              </span>
            </td>
            <td class="neria-table__num">{$rec.total_opens}</td>
            <td>
              <span class="neria-badge neria-badge--{if $rec.confidence === 'high'}success{elseif $rec.confidence === 'medium'}warn{else}neutral{/if}">
                {neria_admin key="stats.golden_conf_{$rec.confidence}"}
              </span>
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  <p class="neria-hint" style="margin-top:8px;">{neria_admin key='stats.golden_hour_note'}</p>
</div>
{/if}

{* Revenue Attribution fusionné dans #neria-revenue-attribution en tête du fichier *}

{* ── Abandon de caisse ─────────────────────────────────────── *}
<div class="neria-section" id="neria-checkout-abandonment-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.checkout_title'} ✦</h2>
      <p class="neria-section__desc" style="margin:0;">
        {neria_admin key='stats.checkout_desc'}
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-checkout-abandonment-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="checkout_abandonment_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $checkout_abandonment_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $checkout_abandonment_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px,1fr)); gap:12px; margin-bottom:24px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$checkout_abandonment_stats.emails_sent|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.emails_sent'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$checkout_abandonment_stats.orders_recovered|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_orders_recovered'}</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$checkout_abandonment_stats.revenue_recovered|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_revenue_recovered'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$checkout_abandonment_stats.conversion_rate|default:0} %</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_conversion_rate'}</div>
    </div>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.checkout_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.checkout_howto_dedup'}
    </div>
  </div>
</div>

{* ── Anniversaire de la relation client ─────────────────────── *}
<div class="neria-section" id="neria-relationship-anniversary-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.anniversary_title'} ✦</h2>
      <p class="neria-section__desc" style="margin:0;">
        {neria_admin key='stats.anniversary_desc'}
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-relationship-anniversary-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="relationship_anniversary_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $relationship_anniversary_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $relationship_anniversary_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  {* Alerte doublon first_anniversary *}
  <div style="display:flex; align-items:flex-start; gap:10px; padding:12px 16px; margin-bottom:20px;
              background:#fff8e1; border-left:3px solid #f59e0b; border-radius:4px;
              font-size:12px; color:#78350f; line-height:1.6;">
    <span style="font-size:16px; flex-shrink:0;">⚠</span>
    <span>
      {neria_admin key='stats.anniversary_dup_warning'}
    </span>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:12px; margin-bottom:24px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$relationship_anniversary_stats.emails_sent|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.emails_sent'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$relationship_anniversary_stats.orders_attributed|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_orders_attributed'}</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$relationship_anniversary_stats.revenue_attributed|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.revenue_total'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$relationship_anniversary_stats.avg_order_value|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_avg_order'}</div>
    </div>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.anniversary_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.anniversary_howto_attribution'}
    </div>
  </div>
</div>

{* ── Upsell Intelligent ────────────────────────────────────── *}
<div class="neria-section" id="neria-upsell-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.upsell_title'} ✦</h2>
      <p class="neria-section__desc" style="margin:0;">
        {neria_admin key='stats.upsell_desc'}
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-upsell-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="upsell_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $upsell_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $upsell_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  {* ── Notice explicative ──────────────────────────────────── *}
  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_purpose_title'}</div>
    {neria_admin key='stats.upsell_howto_body'}
    <div style="margin:10px 0;">
      <strong style="color:#1a7a40;">{neria_admin key='stats.upsell_criteria1'}</strong> {neria_admin key='stats.upsell_criteria1_desc'} ·
      <strong style="color:#2563a8;">{neria_admin key='stats.upsell_criteria2'}</strong> {neria_admin key='stats.upsell_criteria2_desc'} ·
      <strong style="color:#a0520d;">{neria_admin key='stats.upsell_criteria3'}</strong> {neria_admin key='stats.upsell_criteria3_desc'}
    </div>
    {neria_admin key='stats.upsell_howto_footer'}
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px,1fr)); gap:12px; margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$upsell_stats.total_sent|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.upsell_kpi_suggestions'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$upsell_stats.total_clicked|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.clicks'}</div>
      <div class="neria-kpi__rate">{$upsell_stats.ctr|default:0}%</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$upsell_stats.total_converted|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.upsell_kpi_conversions'}</div>
      <div class="neria-kpi__rate">{$upsell_stats.conv_rate|default:0}%</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$upsell_stats.total_revenue|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.upsell_kpi_revenue'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$upsell_stats.avg_order|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_avg_order'}</div>
    </div>
  </div>

  <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:28px;">
    <span style="padding:4px 10px; background:#f0f8f4; color:#1a7a40; border-radius:20px; font-size:11px; font-weight:600;">
      ✦ {neria_admin key='stats.upsell_badge_accessory'} : {$upsell_stats.cnt_accessory|default:0}
    </span>
    <span style="padding:4px 10px; background:#f0f4f8; color:#2563a8; border-radius:20px; font-size:11px; font-weight:600;">
      ✦ {neria_admin key='stats.upsell_badge_co_purchase'} : {$upsell_stats.cnt_co_purchase|default:0}
    </span>
    <span style="padding:4px 10px; background:#faf6f0; color:#a0520d; border-radius:20px; font-size:11px; font-weight:600;">
      ✦ {neria_admin key='stats.upsell_badge_category'} : {$upsell_stats.cnt_bestseller|default:0}
    </span>
  </div>

  <div style="padding:18px; background:var(--neria-bg); border-radius:6px; margin-bottom:28px;">
    <p style="font-size:12px; font-weight:700; color:var(--neria-text); margin:0 0 6px 0; text-transform:uppercase; letter-spacing:.06em;">
      {neria_admin key='stats.upsell_preview_title'}
    </p>
    <p style="font-size:12px; color:var(--neria-muted); margin:0 0 14px 0; line-height:1.6;">
      {neria_admin key='stats.upsell_preview_howto'}
    </p>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <input type="text" id="neria-upsell-order-id" placeholder="{neria_admin key='stats.order_ref_placeholder'}"
             autocomplete="off"
             style="padding:8px 12px; border:1px solid var(--neria-border); border-radius:4px;
                    font-size:13px; width:240px; background:var(--neria-container);">
      <button type="button" class="neria-btn neria-btn--primary" onclick="neriaPreviewUpsell()">
        {neria_admin key='stats.preview_btn'}
      </button>
    </div>
    <div id="neria-upsell-preview" style="margin-top:18px; display:none;">
      <div style="font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:var(--neria-muted); margin-bottom:8px;">
        ↓ {neria_admin key='stats.upsell_preview_seenote'}
      </div>
      <div style="background:#ffffff; border:1px solid var(--neria-border); border-radius:6px; padding:8px 28px 24px; max-width:560px;">
        <div id="neria-upsell-block"></div>
      </div>
    </div>
    <p id="neria-upsell-msg" style="display:none; font-size:12px; margin:14px 0 0 0;"></p>
  </div>

  {if $upsell_log}
  <div style="overflow-x:auto;">
    <table class="neria-table" style="min-width:700px;">
      <thead>
        <tr>
          <th>{neria_admin key='stats.col_client'}</th>
          <th>{neria_admin key='stats.col_suggested_product'}</th>
          <th>{neria_admin key='stats.col_level'}</th>
          <th>{neria_admin key='stats.col_sent_on'}</th>
          <th>{neria_admin key='stats.col_clicked'}</th>
          <th>{neria_admin key='stats.col_converted'}</th>
          <th>{neria_admin key='stats.col_amount'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $upsell_log as $urow}
        <tr>
          <td>
            <span style="font-size:13px; font-weight:600;">{$urow.firstname|escape:'html'} {$urow.lastname|escape:'html'}</span><br>
            <span style="font-size:11px; color:var(--neria-muted);">{$urow.email|escape:'html'}</span><br>
            <span style="font-size:10px; color:var(--neria-muted);">{neria_admin key='stats.order_ref_prefix'}{$urow.order_ref|escape:'html'}</span>
          </td>
          <td>
            <div style="display:flex; align-items:center; gap:10px;">
              {if $urow.thumb_url}<img src="{$urow.thumb_url|escape:'html'}" width="36" style="border-radius:3px; display:block; flex-shrink:0;">{/if}
              <a href="{$urow.product_url|escape:'html'}" target="_blank"
                 style="font-size:13px; color:var(--neria-text); text-decoration:none;">{$urow.product_name|escape:'html'}</a>
            </div>
          </td>
          <td>
            {if $urow.tier == 'accessory'}<span style="padding:3px 8px; background:#f0f8f4; color:#1a7a40; border-radius:20px; font-size:10px; font-weight:700;">{neria_admin key='stats.upsell_badge_accessory'}</span>
            {elseif $urow.tier == 'co_purchase'}<span style="padding:3px 8px; background:#f0f4f8; color:#2563a8; border-radius:20px; font-size:10px; font-weight:700;">{neria_admin key='stats.upsell_badge_co_purchase'}</span>
            {else}<span style="padding:3px 8px; background:#faf6f0; color:#a0520d; border-radius:20px; font-size:10px; font-weight:700;">{neria_admin key='stats.upsell_badge_category'}</span>{/if}
          </td>
          <td style="font-size:12px; color:var(--neria-muted); white-space:nowrap;">{$urow.sent_at|date_format:'%d/%m/%Y'}</td>
          <td style="text-align:center;">
            {if $urow.clicked_at}<span style="color:#1a7a40; font-weight:700; font-size:15px;" title="{$urow.clicked_at|escape:'html'}">✓</span>
            {else}<span style="color:var(--neria-muted);">—</span>{/if}
          </td>
          <td style="text-align:center;">
            {if $urow.id_order_converted}<span style="color:#1a7a40; font-weight:700; font-size:15px;" title="{neria_admin key='stats.order_ref_prefix'}{$urow.id_order_converted}">✓</span>
            {else}<span style="color:var(--neria-muted);">—</span>{/if}
          </td>
          <td style="font-size:13px; font-weight:700; white-space:nowrap; color:{if $urow.conversion_amount > 0}var(--neria-accent){else}var(--neria-muted){/if};">
            {if $urow.conversion_amount > 0}{$urow.conversion_amount|string_format:"%.2f"} {$currency_symbol}{else}—{/if}
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p style="font-size:13px; color:var(--neria-muted); margin:0;">
    {neria_admin key='stats.upsell_no_suggestions'}
  </p>
  {/if}
</div>

{* ── Rappel fin de vie produit ─────────────────────────────── *}
{* ── Score de propension à l'achat ─────────────────────────── *}
<div class="neria-section" id="neria-propensity-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.propensity_title'} 🎯</h2>
      <p class="neria-text" style="margin:0; font-size:13px; opacity:.7;">
        {neria_admin key='stats.propensity_desc' n=$propensity_threshold}
      </p>
    </div>
    <form method="post" style="margin:0;">
      <input type="hidden" name="neria_action" value="propensity_toggle" />
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $propensity_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $propensity_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1; border:1px solid #e8d5b0; border-radius:6px; padding:16px 20px; margin-bottom:24px; font-size:13px; line-height:1.7; color:#4a3f35;">
    <div style="font-weight:700; margin-bottom:8px; font-size:12px; letter-spacing:.06em; text-transform:uppercase; opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.propensity_howto_body'}
    <ul style="margin:10px 0 10px 18px; padding:0;">
      <li><strong>{neria_admin key='stats.propensity_factor_recency'}</strong> — {neria_admin key='stats.propensity_factor_recency_desc'}</li>
      <li><strong>{neria_admin key='stats.propensity_factor_frequency'}</strong> — {neria_admin key='stats.propensity_factor_frequency_desc'}</li>
      <li><strong>{neria_admin key='stats.propensity_factor_engagement'}</strong> — {neria_admin key='stats.propensity_factor_engagement_desc'}</li>
      <li><strong>{neria_admin key='stats.propensity_factor_seasonality'}</strong> — {neria_admin key='stats.propensity_factor_seasonality_desc'}</li>
    </ul>
    {neria_admin key='stats.propensity_howto_footer' n=$propensity_threshold}
  </div>

  {if $propensity_alerts}

    <table style="width:100%; border-collapse:collapse; font-size:13px;">
      <thead>
        <tr style="border-bottom:2px solid rgba(0,0,0,.08);">
          <th style="text-align:left; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_client_caps'}</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_score'}</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_recency'}</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_frequency'}</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_engagement'}</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_seasonality'}</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_last_order'}</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">{neria_admin key='stats.col_action'}</th>
        </tr>
      </thead>
      <tbody>
      {foreach from=$propensity_alerts item=p}
        <tr style="border-bottom:1px solid rgba(0,0,0,.05);">
          <td style="padding:12px 16px;">
            <strong style="display:block;">{$p.customer_name|escape:'html'}</strong>
            <span style="font-size:11px; opacity:.5;">{$p.email|escape:'html'}</span>
          </td>
          <td style="padding:12px 16px; text-align:center;">
            <span style="display:inline-flex; align-items:center; justify-content:center;
                         width:44px; height:44px; border-radius:50%; font-weight:800; font-size:15px;
                         background:{if $p.score >= 90}#1a7a40{elseif $p.score >= 80}#2e8b57{else}#b8975a{/if};
                         color:#fff;">
              {$p.score}
            </span>
          </td>
          <td style="padding:12px 16px; text-align:center;">
            <div style="font-size:12px; font-weight:600;">{$p.score_recency}/40</div>
            <div style="background:rgba(0,0,0,.08); border-radius:3px; height:4px; width:60px; margin:4px auto 0; overflow:hidden;">
              <div style="height:100%; background:#1a7a40; border-radius:3px; width:{math equation='score/40*100' score=$p.score_recency}%;"></div>
            </div>
          </td>
          <td style="padding:12px 16px; text-align:center;">
            <div style="font-size:12px; font-weight:600;">{$p.score_frequency}/25</div>
            <div style="background:rgba(0,0,0,.08); border-radius:3px; height:4px; width:60px; margin:4px auto 0; overflow:hidden;">
              <div style="height:100%; background:#b8975a; border-radius:3px; width:{math equation='score/25*100' score=$p.score_frequency}%;"></div>
            </div>
          </td>
          <td style="padding:12px 16px; text-align:center;">
            <div style="font-size:12px; font-weight:600;">{$p.score_engagement}/25</div>
            <div style="background:rgba(0,0,0,.08); border-radius:3px; height:4px; width:60px; margin:4px auto 0; overflow:hidden;">
              <div style="height:100%; background:#5b3fa8; border-radius:3px; width:{math equation='score/25*100' score=$p.score_engagement}%;"></div>
            </div>
          </td>
          <td style="padding:12px 16px; text-align:center;">
            <div style="font-size:12px; font-weight:600;">{$p.score_seasonality}/10</div>
            <div style="background:rgba(0,0,0,.08); border-radius:3px; height:4px; width:60px; margin:4px auto 0; overflow:hidden;">
              <div style="height:100%; background:#e67e22; border-radius:3px; width:{math equation='score/10*100' score=$p.score_seasonality}%;"></div>
            </div>
          </td>
          <td style="padding:12px 16px; text-align:center; font-size:12px; opacity:.6;">
            {if $p.last_order_date}{$p.last_order_date|date_format:'%d/%m/%Y'}{else}—{/if}
          </td>
          <td style="padding:12px 16px; text-align:center;">
            <a href="{$link->getAdminLink('AdminModules')}&configure=neria&neria_tab=send&prefill_customer={$p.id_customer}"
               style="display:inline-flex; align-items:center; padding:6px 12px;
                      background:#1a1a1a; color:#fff; border-radius:4px; font-size:11px;
                      font-weight:700; text-decoration:none; letter-spacing:.04em;"
               onmouseover="this.style.background='#b8975a'"
               onmouseout="this.style.background='#1a1a1a'">
              {neria_admin key='stats.send_offer_btn'}
            </a>
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>

  {else}
    <div style="text-align:center; padding:32px 20px; opacity:.5;">
      <div style="font-size:36px; margin-bottom:12px;">🎯</div>
      <p style="font-size:13px; margin:0;">{neria_admin key='stats.propensity_empty'}</p>
    </div>
  {/if}
</div>

<div class="neria-section" id="neria-purchase-window-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.purchasewindow_title'} ⏰</h2>
      <p class="neria-text" style="margin:0; font-size:13px; opacity:.7;">
        {neria_admin key='stats.purchasewindow_desc'}
      </p>
    </div>
    <form method="post" style="margin:0;">
      <input type="hidden" name="neria_action" value="purchase_window_toggle" />
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $purchase_window_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $purchase_window_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1; border:1px solid #e8d5b0; border-radius:6px; padding:16px 20px; margin-bottom:24px; font-size:13px; line-height:1.7; color:#4a3f35;">
    <div style="font-weight:700; margin-bottom:8px; font-size:12px; letter-spacing:.06em; text-transform:uppercase; opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.purchasewindow_howto_body'}
    <br><br>
    {neria_admin key='stats.purchasewindow_howto_footer'}
  </div>

  {* KPIs *}
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:16px; margin-bottom:24px;">
    <div style="padding:18px; background:var(--neria-bg); border:1px solid var(--neria-border); border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:var(--neria-text);">{$purchase_window_stats.pending}</div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">{neria_admin key='stats.pw_kpi_pending'}</div>
    </div>
    <div style="padding:18px; background:var(--neria-bg); border:1px solid var(--neria-border); border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:#1a7a40;">{$purchase_window_stats.sent_30d}</div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">{neria_admin key='stats.pw_kpi_sent_30d'}</div>
    </div>
    <div style="padding:18px; background:var(--neria-bg); border:1px solid var(--neria-border); border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:var(--neria-accent);">
        {if $purchase_window_stats.avg_delay_min !== null}
          {if $purchase_window_stats.avg_delay_min >= 60}
            {math equation="round(m/60)" m=$purchase_window_stats.avg_delay_min}h
          {else}
            {$purchase_window_stats.avg_delay_min}min
          {/if}
        {else}—{/if}
      </div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">{neria_admin key='stats.pw_kpi_avg_delay'}</div>
    </div>
    <div style="padding:18px; background:var(--neria-bg); border:1px solid var(--neria-border); border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:#5b3fa8;">{$purchase_window_stats.coverage_pct}%</div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">{neria_admin key='stats.pw_kpi_coverage'}</div>
    </div>
    <div style="padding:18px; background:var(--neria-bg); border:1px solid var(--neria-border); border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:var(--neria-text);">
        {if $purchase_window_stats.peak_hour !== null}{$purchase_window_stats.peak_hour}h{else}—{/if}
      </div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">{neria_admin key='stats.pw_kpi_peak_hour'}</div>
    </div>
    {if $purchase_window_stats.failed_30d > 0}
    <div style="padding:18px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:#dc2626;">{$purchase_window_stats.failed_30d}</div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">{neria_admin key='stats.pw_kpi_failed'}</div>
    </div>
    {/if}
  </div>

  {if $purchase_window_stats.coverage_pct === 0 && $purchase_window_stats.sent_30d === 0}
    <div style="text-align:center; padding:32px 20px; opacity:.5;">
      <div style="font-size:36px; margin-bottom:12px;">⏰</div>
      <p style="font-size:13px; margin:0;">
        {neria_admin key='stats.pw_empty'}
      </p>
    </div>
  {/if}
</div>

<div class="neria-section" id="neria-lifespan-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.lifespan_title'} ⏳</h2>
      <p class="neria-text" style="margin:0; font-size:13px; opacity:.7;">
        {neria_admin key='stats.lifespan_desc'}
      </p>
    </div>
    <form method="post" style="margin:0;">
      <input type="hidden" name="neria_action" value="lifespan_toggle" />
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $lifespan_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $lifespan_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.lifespan_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.lifespan_howto_tip'}
    </div>
  </div>

  {* Formulaire d'ajout *}
  <form method="post" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:24px;">
    <input type="hidden" name="neria_action" value="lifespan_add" />
    <div style="display:flex; flex-direction:column; gap:4px;">
      <label style="font-size:12px; opacity:.7;">{neria_admin key='stats.label_product_id'}</label>
      <input type="text" name="lifespan_id_product" required placeholder="ex: 42"
             pattern="[0-9]+" class="neria-input" style="width:120px;" />
    </div>
    <div style="display:flex; flex-direction:column; gap:4px;">
      <label style="font-size:12px; opacity:.7;">{neria_admin key='stats.label_lifespan_days'}</label>
      <input type="number" name="lifespan_days" min="1" required placeholder="ex: 30"
             class="neria-input" style="width:150px;" />
    </div>
    <div style="display:flex; flex-direction:column; gap:4px;">
      <label style="font-size:12px; opacity:.7;">{neria_admin key='stats.label_alert_days_before'}</label>
      <input type="number" name="lifespan_alert_days" min="1" value="7"
             class="neria-input" style="width:140px;" />
    </div>
    <div style="display:flex; flex-direction:column; gap:4px;">
      <label style="font-size:12px; opacity:0;">&nbsp;</label>
      <button type="submit"
              style="display:inline-flex; align-items:center; justify-content:center;
                     height:36px; padding:0 16px;
                     background:#1a1a1a; color:#fff; border:none; border-radius:4px;
                     font-size:12px; font-weight:700; cursor:pointer; letter-spacing:.04em;"
              onmouseover="this.style.background='#b8975a'"
              onmouseout="this.style.background='#1a1a1a'">
        {neria_admin key='stats.add_btn'}
      </button>
    </div>
  </form>

  {* Liste des produits configurés *}
  {if $lifespan_products}
  <table style="width:100%; border-collapse:collapse; font-size:13px;">
    <thead>
      <tr style="border-bottom:1px solid rgba(255,255,255,.15); opacity:.6;">
        <th style="text-align:left; padding:6px 12px;">{neria_admin key='stats.col_product'}</th>
        <th style="text-align:left; padding:6px 12px;">{neria_admin key='stats.col_reference'}</th>
        <th style="text-align:center; padding:6px 12px;">{neria_admin key='stats.col_lifespan'}</th>
        <th style="text-align:center; padding:6px 12px;">{neria_admin key='stats.col_alert_before'}</th>
        <th style="text-align:center; padding:6px 12px;">{neria_admin key='stats.actions_col'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$lifespan_products item=lp}
      <tr style="border-bottom:1px solid rgba(255,255,255,.07);">
        <td style="padding:8px 12px;">{$lp.product_name|escape:'html':'UTF-8'|default:'—'}</td>
        <td style="padding:8px 12px; opacity:.6;">{$lp.reference|escape:'html':'UTF-8'|default:'—'}</td>
        <td style="padding:8px 12px; text-align:center;">{$lp.lifespan_days} {neria_admin key='common.days_unit_short'}</td>
        <td style="padding:8px 12px; text-align:center;">{$lp.alert_days} {neria_admin key='common.days_unit_short'}</td>
        <td style="padding:8px 12px; text-align:center;">
          <form method="post" style="margin:0;">
            <input type="hidden" name="neria_action" value="lifespan_delete" />
            <input type="hidden" name="lifespan_id" value="{$lp.id_lifespan}" />
            <button type="button" data-confirm="{neria_admin key='stats.confirm_delete_product'}" onclick="neriaConfirmDelete(this);" style="background:none; border:none; cursor:pointer; color:#e74c3c; font-size:16px;" title="{neria_admin key='stats.confirm_delete_product' esc='html'}" aria-label="{neria_admin key='stats.confirm_delete_product' esc='html'}">✕</button>
          </form>
        </td>
      </tr>
      {/foreach}
    </tbody>
  </table>
  {else}
  <p class="neria-text" style="opacity:.5; font-size:13px; text-align:center; padding:20px 0;">
    {neria_admin key='stats.lifespan_empty'}
  </p>
  {/if}
</div>

{* ── Réconciliation post-remboursement ─────────────────────── *}
<div class="neria-section" id="neria-reconciliation-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.reconciliation_title'} ✦</h2>
      <p class="neria-section__subtitle" style="margin:0;">{neria_admin key='stats.reconciliation_subtitle'}</p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-reconciliation-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="reconciliation_toggle">
      <button type="submit" class="neria-btn" style="font-size:12px; padding:6px 14px;
                     background:{if $reconciliation_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; cursor:pointer;">
        {if $reconciliation_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:20px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.reconciliation_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.reconciliation_howto_potential'}
    </div>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px; margin-bottom:24px;">
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.total|default:0}</div>
      <div class="neria-kpi-card__label">{neria_admin key='stats.reconciliation_kpi_scheduled'}</div>
    </div>
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.step1_sent|default:0}</div>
      <div class="neria-kpi-card__label">J+1 {neria_admin key='stats.reconciliation_kpi_step_sent'}</div>
    </div>
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.step2_sent|default:0}</div>
      <div class="neria-kpi-card__label">J+3 {neria_admin key='stats.reconciliation_kpi_step_sent'}</div>
    </div>
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.step3_sent|default:0}</div>
      <div class="neria-kpi-card__label">J+7 {neria_admin key='stats.reconciliation_kpi_step_sent'}</div>
    </div>
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.cancelled|default:0}</div>
      <div class="neria-kpi-card__label" style="color:#1a7a40;">{neria_admin key='stats.reconciliation_kpi_cancelled'}</div>
    </div>
  </div>
</div>

{* ── Devis B2B — Relances automatiques ────────────────────── *}
<div class="neria-section" id="neria-quote-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.quote_title'} ✦</h2>
      <p class="neria-section__desc" style="margin:0;">
        {neria_admin key='stats.quote_desc'}
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-quote-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="quote_reminder_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $quote_reminders_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $quote_reminders_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.quote_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.quote_howto_potential'}
    </div>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px,1fr)); gap:12px; margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$quote_stats.total_quotes|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.quote_kpi_tracked'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$quote_stats.quotes_active|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.quote_kpi_active'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$quote_stats.quotes_won|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.quote_kpi_won'}</div>
      <div class="neria-kpi__rate">{$quote_stats.win_rate|default:0} %</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$quote_stats.revenue_won|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_revenue_recovered'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$quote_stats.quotes_lost|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.quote_kpi_lost_expired'}</div>
    </div>
  </div>

  {* Formulaire d'ajout *}
  <div style="padding:18px; background:var(--neria-bg); border-radius:6px; margin-bottom:24px;">
    <p style="font-size:12px; font-weight:700; color:var(--neria-text); margin:0 0 8px 0; text-transform:uppercase; letter-spacing:.06em;">
      {neria_admin key='stats.quote_add_title'}
    </p>
    <p style="font-size:12px; color:var(--neria-muted); line-height:1.7; margin:0 0 16px 0;">
      <strong style="color:var(--neria-text);">{neria_admin key='stats.quote_manual_entry'}</strong>
      {neria_admin key='stats.quote_manual_body'}
    </p>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-quote-section"
          style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
      <input type="hidden" name="neria_action" value="quote_add">
      <input type="hidden" name="neria_tab"    value="stats">
      <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:var(--neria-muted);">{neria_admin key='stats.label_customer_id_email'}</label>
        <input type="text" name="quote_id_customer" placeholder="Ex : 42 ou client@email.com" required
               style="padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px; font-size:13px; width:220px; background:var(--neria-container);">
      </div>
      <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:var(--neria-muted);">{neria_admin key='stats.label_quote_ref'}</label>
        <input type="text" name="quote_ref" placeholder="Ex : DEVIS-2026-042" required
               style="padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px; font-size:13px; width:180px; background:var(--neria-container);">
      </div>
      <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:var(--neria-muted);">{neria_admin key='stats.label_amount_excl_tax'}</label>
        <input type="text" name="quote_total" placeholder="Ex : 1250.00"
               style="padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px; font-size:13px; width:120px; background:var(--neria-container);">
      </div>
      <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:var(--neria-muted);">{neria_admin key='stats.label_expiry_date'}</label>
        <input type="date" name="quote_expiry_date" required
               style="padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px; font-size:13px; background:var(--neria-container);">
      </div>
      <button type="submit" class="neria-btn neria-btn--primary" style="align-self:flex-end;">
        {neria_admin key='stats.add_btn'}
      </button>
    </form>
  </div>

  {* Liste des devis *}
  {if $quote_list}
  <div style="overflow-x:auto;">
    <table class="neria-table" style="min-width:700px;">
      <thead>
        <tr>
          <th>{neria_admin key='stats.label_quote_ref'}</th>
          <th>{neria_admin key='stats.col_client'}</th>
          <th>{neria_admin key='stats.col_amount2'}</th>
          <th>{neria_admin key='stats.col_expiry'}</th>
          <th>{neria_admin key='stats.status_col'}</th>
          <th>{neria_admin key='stats.col_reminders'}</th>
          <th>{neria_admin key='stats.actions_col'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $quote_list as $q}
        <tr>
          <td style="font-weight:600; font-size:13px;">{$q.quote_ref|escape:'html'}</td>
          <td style="font-size:12px;">
            {$q.customer_name|escape:'html'}<br>
            <span style="color:var(--neria-muted); font-size:11px;">{$q.email|escape:'html'}</span>
          </td>
          <td style="font-weight:700; color:var(--neria-accent);">{$q.quote_total|string_format:"%.2f"} {$currency_symbol}</td>
          <td style="font-size:12px; {if $q.expiry_date < $smarty.now|date_format:'%Y-%m-%d'}color:#c0392b; font-weight:600;{/if}">
            {$q.expiry_date|date_format:'%d/%m/%Y'}
          </td>
          <td>
            {if $q.status === 'won'}<span style="color:#1a7a40; font-weight:700;">✓ {neria_admin key='stats.status_won'}</span>
            {elseif $q.status === 'lost'}<span style="color:#c0392b; font-weight:700;">✗ {neria_admin key='stats.status_lost'}</span>
            {elseif $q.status === 'expired'}<span style="color:#a0520d; font-weight:600;">{neria_admin key='stats.status_expired'}</span>
            {elseif $q.status === 'extended'}<span style="color:#2563a8; font-weight:600;">{neria_admin key='stats.status_extended'}</span>
            {else}<span style="color:#1a7a40;">{neria_admin key='stats.status_in_progress'}</span>{/if}
          </td>
          <td style="font-size:11px; color:var(--neria-muted); text-align:center;">
            {if $q.sent_48h}48h ✓{else}48h —{/if}<br>
            {if $q.sent_day}J ✓{else}J —{/if}<br>
            {if $q.sent_extension}Ext ✓{else}Ext —{/if}
          </td>
          <td style="white-space:nowrap;">
            {if $q.status === 'active'}
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-quote-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="quote_mark_won">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="id_quote"     value="{$q.id_quote}">
              <button type="submit" style="padding:4px 8px; background:#1a7a40; color:#fff; border:none; border-radius:3px; font-size:11px; cursor:pointer; margin-right:4px;">{neria_admin key='stats.quote_mark_won_btn'}</button>
            </form>
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-quote-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="quote_mark_lost">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="id_quote"     value="{$q.id_quote}">
              <button type="submit" style="padding:4px 8px; background:#c0392b; color:#fff; border:none; border-radius:3px; font-size:11px; cursor:pointer; margin-right:4px;">{neria_admin key='stats.quote_mark_lost_btn'}</button>
            </form>
            {/if}
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-quote-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="quote_delete">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="id_quote"     value="{$q.id_quote}">
              <button type="button" data-confirm="{neria_admin key='stats.confirm_delete_quote'}" onclick="neriaConfirmDelete(this);" style="padding:4px 8px; background:var(--neria-border); color:var(--neria-text); border:none; border-radius:3px; font-size:11px; cursor:pointer;">{neria_admin key='stats.delete_btn'}</button>
            </form>
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p style="font-size:13px; color:var(--neria-muted); margin:0;">
    {neria_admin key='stats.quote_empty'}
  </p>
  {/if}
</div>

{* ── Complétion de collection ───────────────────────────────── *}
<div class="neria-section" id="neria-collection-section">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.collection_title'} ◎</h2>
      <p class="neria-text" style="margin:0;font-size:13px;opacity:.7;">
        {neria_admin key='stats.collection_desc'}
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-collection-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="collection_completion_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if isset($collection_completion_enabled) && $collection_completion_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if isset($collection_completion_enabled) && $collection_completion_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>


  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.collection_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.collection_howto_dedup'}
    </div>
  </div>

  {* KPIs *}
  {if isset($collection_stats)}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$collection_stats.total|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_collections'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$collection_stats.active|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_active'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$collection_stats.sent|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.emails_sent'}</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$collection_stats.sentLast30|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_last30days'}</div>
    </div>
  </div>
  {/if}

  {* Formulaire d'ajout *}
  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-collection-section" style="margin-bottom:24px;">
    <input type="hidden" name="neria_action" value="collection_add">
    <input type="hidden" name="neria_tab"    value="stats">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <div style="flex:1;min-width:200px;">
        <label class="neria-label">{neria_admin key='stats.label_collection_name'}</label>
        <input type="text" name="collection_name" class="neria-input" placeholder="ex : Trio soin visage" style="width:100%;">
      </div>
      <div style="flex:2;min-width:260px;">
        <label class="neria-label">{neria_admin key='stats.label_product_ids_comma'}</label>
        <input type="text" name="collection_product_ids" class="neria-input" placeholder="ex : 12, 47, 83" style="width:100%;">
      </div>
      <div>
        <button type="submit" class="neria-btn neria-btn--primary">{neria_admin key='stats.add_btn'}</button>
      </div>
    </div>
  </form>

  {* Liste des collections *}
  {if isset($collections) && $collections|@count > 0}
  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>{neria_admin key='stats.col_name'}</th>
          <th>{neria_admin key='stats.col_products'}</th>
          <th style="text-align:center;">{neria_admin key='stats.col_pieces'}</th>
          <th style="text-align:center;">{neria_admin key='stats.status_col'}</th>
          <th style="text-align:center;">{neria_admin key='stats.actions_col'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $collections as $col}
        {assign var="colPids" value=$col.product_ids|json_decode}
        <tr>
          <td style="font-weight:600;">{$col.name|escape:'html'}</td>
          <td style="font-size:12px;color:#7a6a5a;">{$col.product_ids|escape:'html'}</td>
          <td style="text-align:center;">
            <span class="neria-badge">{$colPids|@count}</span>
          </td>
          <td style="text-align:center;">
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-collection-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="collection_toggle">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="collection_id" value="{$col.id_neria_collection}">
              <button type="submit"
                style="padding:4px 12px;border-radius:12px;border:none;cursor:pointer;font-size:11px;font-weight:700;
                       background:{if $col.active}#16a34a{else}#dc2626{/if};color:#fff;">
                {if $col.active}● {neria_admin key='stats.short_active'}{else}○ {neria_admin key='stats.short_inactive'}{/if}
              </button>
            </form>
          </td>
          <td style="text-align:center;">
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-collection-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="collection_delete">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="collection_id" value="{$col.id_neria_collection}">
              <button type="button" class="neria-btn neria-btn--danger neria-btn--sm"
                      data-confirm="{neria_admin key='stats.confirm_delete_collection'}" onclick="neriaConfirmDelete(this);">✕</button>
            </form>
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p class="neria-empty-state__text" style="font-size:13px;color:#7a6a5a;margin:0;">
    {neria_admin key='stats.collection_empty'}
  </p>
  {/if}
</div>

{* ── Complétez votre look ───────────────────────────────────── *}
<div class="neria-section" id="neria-look-section">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.look_title'} ✦</h2>
      <p class="neria-text" style="margin:0;font-size:13px;opacity:.7;">
        {neria_admin key='stats.look_desc'}
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-look-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="look_completion_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if isset($look_completion_enabled) && $look_completion_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if isset($look_completion_enabled) && $look_completion_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</div>
    {neria_admin key='stats.look_howto_body'}
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      {neria_admin key='stats.look_howto_key_moment'}
    </div>
  </div>

  {* KPIs *}
  {if isset($look_stats)}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$look_stats.rules|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_rules'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$look_stats.active|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_active'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$look_stats.sent|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='common.emails_sent'}</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$look_stats.sent30|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.kpi_last30days'}</div>
    </div>
  </div>
  {/if}

  {* Formulaire d'ajout *}
  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-look-section" style="margin-bottom:24px;">
    <input type="hidden" name="neria_action" value="look_rule_add">
    <input type="hidden" name="neria_tab"    value="stats">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <div style="min-width:200px;">
        <label class="neria-label">{neria_admin key='stats.label_trigger_category'}</label>
        <select name="look_category_id" class="neria-select" style="width:100%;">
          <option value="">{neria_admin key='stats.choose_category_option'}</option>
          {if isset($look_categories)}
            {foreach $look_categories as $cat}
              <option value="{$cat.id_category}">{$cat.name}</option>
            {/foreach}
          {/if}
        </select>
      </div>
      <div style="flex:1;min-width:260px;">
        <label class="neria-label">{neria_admin key='stats.label_suggested_product_ids'}</label>
        <input type="text" name="look_product_ids" class="neria-input" placeholder="ex : 12, 47, 83" style="width:100%;">
      </div>
      <div>
        <button type="submit" class="neria-btn neria-btn--primary">{neria_admin key='stats.add_btn'}</button>
      </div>
    </div>
  </form>

  {* Liste des règles *}
  {if isset($look_rules) && $look_rules|@count > 0}
  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>{neria_admin key='stats.col_category'}</th>
          <th>{neria_admin key='stats.col_suggested_products'}</th>
          <th style="text-align:center;">{neria_admin key='stats.status_col'}</th>
          <th style="text-align:center;">{neria_admin key='stats.actions_col'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $look_rules as $rule}
        <tr>
          <td style="font-weight:600;">{$rule.category_name|default:'—'|escape:'html'}</td>
          <td style="font-size:12px;color:#7a6a5a;">{$rule.product_ids|escape:'html'}</td>
          <td style="text-align:center;">
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-look-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="look_rule_toggle">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="look_rule_id" value="{$rule.id_neria_look_rule}">
              <button type="submit"
                style="padding:4px 12px;border-radius:12px;border:none;cursor:pointer;font-size:11px;font-weight:700;
                       background:{if $rule.active}#16a34a{else}#dc2626{/if};color:#fff;">
                {if $rule.active}● {neria_admin key='stats.short_active'}{else}○ {neria_admin key='stats.short_inactive'}{/if}
              </button>
            </form>
          </td>
          <td style="text-align:center;">
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-look-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="look_rule_delete">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="look_rule_id" value="{$rule.id_neria_look_rule}">
              <button type="button" class="neria-btn neria-btn--danger neria-btn--sm"
                      data-confirm="{neria_admin key='stats.confirm_delete_rule'}" onclick="neriaConfirmDelete(this);">✕</button>
            </form>
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p style="font-size:13px;color:#7a6a5a;margin:0;">
    {neria_admin key='stats.look_empty'}
  </p>
  {/if}
</div>

{* ── Liste d'attente produits ───────────────────────────────── *}
<div class="neria-section" id="neria-waitlist-section">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.waitlist_title'} 🔔</h2>
      <p class="neria-text" style="margin:0;font-size:13px;opacity:.7;">
        {neria_admin key='stats.waitlist_desc'}
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-waitlist-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="waitlist_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if isset($waitlist_enabled) && $waitlist_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if isset($waitlist_enabled) && $waitlist_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;font-size:13px;line-height:1.75;color:#4a3f35;margin-bottom:24px;">
    <p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</p>
    {neria_admin key='stats.waitlist_howto_body'}
    <p style="margin:12px 0 0;border-top:1px solid #e8d5b0;padding-top:10px;font-size:12px;opacity:.75;">
      {neria_admin key='stats.waitlist_howto_reservation'}
    </p>
  </div>

  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="margin-bottom:24px;">
    <input type="hidden" name="neria_action" value="waitlist_reservation_save">
    <input type="hidden" name="neria_tab"    value="stats">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <label style="font-size:13px;font-weight:600;color:#4a3f35;white-space:nowrap;">
        {neria_admin key='stats.waitlist_priority_label'}
      </label>
      <input type="number" name="waitlist_reservation_hours"
             value="{$waitlist_reservation_hours|intval}"
             min="1" max="72"
             style="width:70px;padding:7px 10px;border:1px solid #e8d5b0;border-radius:4px;
                    font-size:13px;font-weight:600;color:#1a1a1a;text-align:center;">
      <span style="font-size:13px;color:#4a3f35;">{neria_admin key='stats.hours_unit'}</span>
      <button type="submit"
              style="padding:8px 16px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;
                     font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.04em;">
        {neria_admin key='common.save'}
      </button>
      <span style="font-size:12px;color:#7a6a5a;opacity:.8;">{neria_admin key='stats.between_1_72_hours'}</span>
    </div>
  </form>

  {if isset($waitlist_stats)}
  <div class="neria-kpi-row" style="margin-bottom:24px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$waitlist_stats.subscribers|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.waitlist_kpi_pending'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$waitlist_stats.products|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.waitlist_kpi_watched'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$waitlist_stats.notified|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.waitlist_kpi_notified_total'}</div>
    </div>
    <div class="neria-kpi" style="border:2px solid var(--neria-accent);">
      <div class="neria-kpi__value">{$waitlist_stats.notified30|default:0}</div>
      <div class="neria-kpi__label">{neria_admin key='stats.waitlist_kpi_last30days'}</div>
    </div>
  </div>
  {/if}

  {if isset($waitlist_top_products) && $waitlist_top_products|@count > 0}
  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>{neria_admin key='stats.col_product'}</th>
          <th style="text-align:center;">{neria_admin key='stats.col_subscribers'}</th>
          <th style="text-align:center;">{neria_admin key='stats.col_max_wait'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach $waitlist_top_products as $wp}
        <tr>
          <td style="font-weight:600;">{$wp.product_name|default:'#'|cat:$wp.id_product|escape:'html'}</td>
          <td style="text-align:center;">{$wp.nb}</td>
          <td style="text-align:center;">{$wp.max_wait_days} {neria_admin key='common.days_unit_short'}</td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p style="font-size:13px;color:#7a6a5a;margin:0;">
    {neria_admin key='stats.waitlist_empty'}
  </p>
  {/if}
</div>

{* ── Panier fantôme récurrent ────────────────────────────────── *}
<div class="neria-section" id="neria-ghost-cart-section">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">{neria_admin key='stats.ghostcart_title'} 👻</h2>
      <p class="neria-text" style="margin:0;font-size:13px;opacity:.7;">
        {neria_admin key='stats.ghostcart_desc'}
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-ghost-cart-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="ghost_cart_toggle">
      <input type="hidden" name="neria_tab" value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if isset($ghost_cart_enabled) && $ghost_cart_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if isset($ghost_cart_enabled) && $ghost_cart_enabled}{neria_admin key='stats.toggle_active_off'}{else}{neria_admin key='stats.toggle_inactive_on'}{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:16px 20px;">
    <p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">{neria_admin key='stats.howto_title'}</p>
    {neria_admin key='stats.ghostcart_howto_body'}
    <p style="margin:12px 0 0;border-top:1px solid #e8d5b0;padding-top:10px;font-size:12px;opacity:.75;">
      {neria_admin key='stats.ghostcart_howto_nodiscount'}
    </p>
  </div>
</div>

<script>
var _nUpsellMsg = {
  notFound:    "{neria_admin key='stats.js_order_not_found' esc='javascript'}",
  simError:    "{neria_admin key='stats.js_simulation_error' esc='javascript'}",
  noRelevant:  "{neria_admin key='stats.js_no_relevant_product' esc='javascript'}",
  unreachable: "{neria_admin key='stats.js_server_unreachable' esc='javascript'}"
};
</script>
<script>
{literal}
function neriaPreviewUpsell() {
  var q = (document.getElementById('neria-upsell-order-id').value || '').replace(/^\s+|\s+$/g, '');
  if (!q) return;
  var preview = document.getElementById('neria-upsell-preview');
  var msg     = document.getElementById('neria-upsell-msg');
  preview.style.display = 'none';
  msg.style.display     = 'none';
  // URL construite depuis la page courante (token + controller déjà présents),
  // sans dépendre de neriaConfig qui peut être bloqué par le CSP back-office.
  var base = window.location.href.split('#')[0]
             .replace(/&(neria_action|order_q)=[^&]*/g, '');
  var url = base + (base.indexOf('?') === -1 ? '?' : '&')
          + 'neria_action=upsell_preview&order_q=' + encodeURIComponent(q);

  function showMsg(text, warn) {
    msg.textContent   = text;
    msg.style.color   = warn ? '#b0392b' : 'var(--neria-muted)';
    msg.style.display = '';
  }

  fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.status === 'found') {
        document.getElementById('neria-upsell-block').innerHTML = d.html || '';
        preview.style.display = '';
      } else if (d.status === 'not_found') {
        showMsg(_nUpsellMsg.notFound, true);
      } else if (d.status === 'error') {
        showMsg(_nUpsellMsg.simError, true);
      } else {
        showMsg(_nUpsellMsg.noRelevant, false);
      }
    })
    .catch(function() {
      showMsg(_nUpsellMsg.unreachable, true);
    });
}
{/literal}
</script>

{* ══════════════════════════════════════════════════════════════
   COMPARATIF MENSUEL — M vs M-1
   ══════════════════════════════════════════════════════════════ *}
{assign var="mc" value=$monthly_comparison}
{if $mc && isset($mc.current)}
<div class="neria-section" id="neria-monthly-comparison">
  <h2 class="neria-section__title" style="margin:0 0 6px;">{neria_admin key='stats.monthly_comparison_title'} ◫</h2>
  <p class="neria-section__desc" style="margin:0 0 20px;">
    {$mc.labels.current|default:''} {neria_admin key='stats.monthly_comparison_desc'} {$mc.labels.previous|default:''} {neria_admin key='stats.monthly_comparison_desc_suffix'}
  </p>

  <div class="neria-table-wrap">
    <table class="neria-table" style="min-width:500px;">
      <thead>
        <tr>
          <th>{neria_admin key='stats.mc_indicator_label'}</th>
          <th class="neria-table__num">{$mc.labels.previous|default:''}</th>
          <th class="neria-table__num">{$mc.labels.current|default:''}</th>
          <th class="neria-table__num">{neria_admin key='stats.mc_evolution'}</th>
        </tr>
      </thead>
      <tbody>
        {capture name="mc_lbl_sent"}{neria_admin key='stats.mc_row_sent'}{/capture}
        {capture name="mc_lbl_opens"}{neria_admin key='stats.mc_row_opens'}{/capture}
        {capture name="mc_lbl_open_rate"}{neria_admin key='stats.mc_row_open_rate'}{/capture}
        {capture name="mc_lbl_clicks"}{neria_admin key='stats.mc_row_clicks'}{/capture}
        {capture name="mc_lbl_click_rate"}{neria_admin key='stats.mc_row_click_rate'}{/capture}
        {capture name="mc_lbl_unsubs"}{neria_admin key='stats.mc_row_unsubs'}{/capture}
        {capture name="mc_lbl_revenue"}{neria_admin key='stats.revenue_total'}{/capture}
        {foreach [
          ['key'=>'sent',       'label'=>$smarty.capture.mc_lbl_sent,      'format'=>'int',   'good_up'=>true],
          ['key'=>'opens',      'label'=>$smarty.capture.mc_lbl_opens,  'format'=>'int',   'good_up'=>true],
          ['key'=>'rate_open',  'label'=>$smarty.capture.mc_lbl_open_rate,   'format'=>'pct',   'good_up'=>true],
          ['key'=>'clicks',     'label'=>$smarty.capture.mc_lbl_clicks,               'format'=>'int',   'good_up'=>true],
          ['key'=>'rate_click', 'label'=>$smarty.capture.mc_lbl_click_rate,        'format'=>'pct',   'good_up'=>true],
          ['key'=>'unsubs',     'label'=>$smarty.capture.mc_lbl_unsubs,      'format'=>'int',   'good_up'=>false],
          ['key'=>'revenue',    'label'=>$smarty.capture.mc_lbl_revenue,         'format'=>'money', 'good_up'=>true]
        ] as $mrow}
          {assign var="prev"  value=$mc.previous[$mrow.key]|default:0}
          {assign var="cur"   value=$mc.current[$mrow.key]|default:0}
          {assign var="delta" value=$mc.delta[$mrow.key]|default:null}

          <tr>
            <td style="font-weight:600;">{$mrow.label}</td>
            <td class="neria-table__num" style="color:var(--neria-muted);">
              {if $mrow.format == 'money'}{$prev|string_format:"%.2f"} {$currency_symbol}
              {elseif $mrow.format == 'pct'}{$prev}%
              {else}{$prev|number_format:0:',':' '}{/if}
            </td>
            <td class="neria-table__num" style="font-weight:700;">
              {if $mrow.format == 'money'}{$cur|string_format:"%.2f"} {$currency_symbol}
              {elseif $mrow.format == 'pct'}{$cur}%
              {else}{$cur|number_format:0:',':' '}{/if}
            </td>
            <td class="neria-table__num">
              {if $delta !== null}
                {if $delta > 0}{assign var="up" value=true}{else}{assign var="up" value=false}{/if}
                {if $mrow.good_up}{assign var="isGood" value=$up}{else}{assign var="isGood" value=!$up}{/if}
                <span style="font-weight:700;color:{if $isGood}#16a34a{else}#dc2626{/if};">
                  {if $up}▲{else}▼{/if} {$delta|abs}%
                </span>
              {else}
                <span style="color:var(--neria-muted);">—</span>
              {/if}
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  <p class="neria-hint" style="margin-top:8px;">{neria_admin key='stats.mc_partial_note'}</p>
</div>
{/if}

{if !isset($stats.global_30) || !$stats.global_30}
  <div class="neria-empty-state">
    <span class="neria-empty-state__icon">◫</span>
    <p>{neria_admin key='stats.empty'}</p>
  </div>
{/if}
