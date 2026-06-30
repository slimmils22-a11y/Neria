{**
 * NERIA — stats.tpl
 * Onglet Statistiques — KPIs, rapports par template/langue/pays
 * i18n : libellés via {neria_admin key='...'} (18 langues, AdminTranslator)
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
        <div style="font-size:16px;font-weight:700;color:var(--neria-dark);">Santé du module</div>
        <div style="font-size:12px;color:var(--neria-muted);margin-top:2px;">
          {$hs.ok|default:0} contrôles OK
          {if ($hs.warning|default:0) > 0} · <span style="color:#d97706;">{$hs.warning} alertes</span>{/if}
          {if ($hs.error|default:0)   > 0} · <span style="color:#dc2626;">{$hs.error} erreurs</span>{/if}
          / {$hs_total} total
        </div>
        <a href="{$smarty.server.REQUEST_URI|regex_replace:'/&neria_tab=[^&]*/':''}&neria_tab=help"
           style="font-size:11px;color:var(--neria-accent);text-decoration:none;margin-top:4px;display:inline-block;">
          → Voir le diagnostic complet
        </a>
      </div>
    </div>

    <div style="font-size:11px;color:var(--neria-muted);text-align:right;">
      Tendances · semaine courante vs semaine précédente
    </div>
  </div>

  {* ── Bandeau KPI tendances ── *}
  {assign var="tr" value=$kpi_trends}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;">

    {foreach [
      ['key'=>'sent',       'label'=>'Envois',           'format'=>'int',     'icon'=>'✉'],
      ['key'=>'open_rate',  'label'=>'Taux ouverture',   'format'=>'pct',     'icon'=>'◉'],
      ['key'=>'click_rate', 'label'=>'Taux clic',        'format'=>'pct',     'icon'=>'↗'],
      ['key'=>'unsubs',     'label'=>'Désabonnements',   'format'=>'int',     'icon'=>'✕'],
      ['key'=>'revenue',    'label'=>'CA attribué',      'format'=>'money',   'icon'=>'◈']
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
            {if $delta > 0}▲{else}▼{/if} {$delta|abs}% vs sem. préc.
          </div>
        {else}
          <div style="font-size:11px;color:var(--neria-muted);margin-top:5px;">— sem. préc. vide</div>
        {/if}
      </div>
    {/foreach}

  </div>
</div>

{* ── Revenus Attribués — graphique + KPIs + tableau ─────────── *}
<div class="neria-section" id="neria-revenue-attribution">

  {* Bloc explicatif last-click *}
  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Neria utilise un modèle <strong>last-click sur 24h</strong> : dès qu'un client clique sur un lien dans un email Neria, un cookie <code>neria_ref</code> est posé. Si une commande payée est enregistrée dans les 24 heures suivantes, la vente est automatiquement attribuée à ce template.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Fenêtre d'analyse :</strong> les 90 derniers jours. Un même client peut générer plusieurs conversions sur des templates différents. Les commandes annulées ou remboursées sont exclues du calcul.
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
        <span style="font-size:12px;color:#999;" id="neria-chart-total-label">CA total sur la période</span>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <div class="neria-chart-type-nav">
        <button class="neria-chart-arrow" id="neria-chart-prev" title="Type précédent">&#9664;</button>
        <span id="neria-chart-type-label" style="min-width:80px;text-align:center;font-size:12px;font-weight:600;color:var(--neria-dark);">Courbes</span>
        <button class="neria-chart-arrow" id="neria-chart-next" title="Type suivant">&#9654;</button>
      </div>
      <button id="neria-total-toggle" class="neria-chart-arrow" style="border:1px solid var(--neria-border);border-radius:4px;padding:3px 10px;font-size:11px;font-weight:600;color:var(--neria-dark);background:#fff;">Total ◉</button>
      <div class="neria-period-tabs" id="neria-chart-period">
        <button class="neria-period-tab" data-period="7">7j</button>
        <button class="neria-period-tab neria-period-tab--active" data-period="30">30j</button>
        <button class="neria-period-tab" data-period="90">90j</button>
        <button class="neria-period-tab" data-period="365">12 mois</button>
      </div>
    </div>
  </div>
  <div style="position:relative;height:340px;">
    <canvas id="neriaRevenueChart"></canvas>
  </div>
  <div id="neria-chart-legend" style="display:flex;flex-wrap:wrap;gap:14px;margin-top:16px;font-size:12px;"></div>
  <p style="margin:10px 0 0;font-size:11px;color:#a09990;font-style:italic;">
    &#9432; Cliquez sur une catégorie pour l'isoler — recliquez pour tout réafficher.
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
  }
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
  var TYPE_LABELS = ['Courbes', 'Colonnes', 'Camembert'];
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
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
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
      <h2 class="neria-section__title" style="margin:0;">Engagement email ◉</h2>
      <p class="neria-section__desc" style="margin:4px 0 0;">Envois, ouvertures et clics jour par jour (hors MPP Apple).</p>
    </div>
    <div class="neria-period-tabs" id="neria-eng-period">
      <button class="neria-period-tab neria-period-tab--active" data-period="30">30j</button>
      <button class="neria-period-tab" data-period="90">90j</button>
    </div>
  </div>
  <div style="position:relative;height:280px;">
    <canvas id="neriaEngagementChart"></canvas>
  </div>
</div>

<script>
var _nec = {
  d30: {$engagement_chart_30|default:'null'},
  d90: {$engagement_chart_90|default:'null'}
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
      { label:'Envois',      data: data.sent   || [], borderColor:'#b0a090', backgroundColor:'#b0a09020', borderWidth:1.5, pointRadius:0, fill:true, tension:0.3 },
      { label:'Ouvertures',  data: data.opens  || [], borderColor:'#b38b59', backgroundColor:'#b38b5930', borderWidth:2,   pointRadius:0, fill:true, tension:0.3 },
      { label:'Clics',       data: data.clicks || [], borderColor:'#5f8b4a', backgroundColor:'#5f8b4a40', borderWidth:2,   pointRadius:period<=30?3:0, fill:true, tension:0.3 }
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
      s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
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
  <h2 class="neria-section__title" style="margin:0 0 6px;">Heatmap des ouvertures ◈</h2>
  <p class="neria-section__desc" style="margin:0 0 20px;">Quand vos clients lisent leurs emails — 90 derniers jours (hors MPP). Plus la case est foncée, plus les ouvertures sont nombreuses.</p>

  <div id="neria-heatmap-wrap" style="overflow-x:auto;">
    <table id="neria-heatmap-table" style="border-collapse:separate;border-spacing:3px;font-size:11px;">
      <thead id="neria-heatmap-head"></thead>
      <tbody id="neria-heatmap-body"></tbody>
    </table>
  </div>
  <div style="margin-top:14px;display:flex;align-items:center;gap:8px;font-size:11px;color:var(--neria-muted);">
    <span>Moins</span>
    <span id="neria-heatmap-legend" style="display:flex;gap:2px;"></span>
    <span>Plus d'ouvertures</span>
  </div>
</div>

<script>
var _nhm = {$open_heatmap|default:'null'};
</script>
{literal}
<script>
(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var hm = _nhm;
    if (!hm || !hm.grid || !hm.max) return;

    var grid = hm.grid;
    var maxV = hm.max;
    var days = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
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
        var tip = days[d] + ' ' + hr + 'h : ' + cnt + ' ouverture' + (cnt > 1 ? 's' : '');
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

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          {* Variante A *}
          <div style="padding:12px; background:var(--neria-bg); border-radius:4px; {if $winner === 'A'}border-left:3px solid var(--neria-accent);{/if}">
            <div style="font-size:10px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--neria-muted); margin-bottom:6px;">
              A — {$td.a.variant_name|default:'Variante A'}{if $winner === 'A'} ↑{/if}
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
              B — {$td.b.variant_name|default:'Variante B'}{if $winner === 'B'} ↑{/if}
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
      Réputation de domaine d'envoi
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
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="flex-shrink:0;">
      <input type="hidden" name="neria_action" value="refresh_domain_reputation">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit" id="neria-domain-rep-btn"
              style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;
                     background:#1a1a1a;color:#fff;border:none;border-radius:4px;
                     font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.04em;"
              onmouseover="this.style.background='#b8975a'"
              onmouseout="this.style.background='#1a1a1a'">
        ↻ Actualiser
      </button>
    </form>
  </div>
  <div style="border-bottom:1px solid var(--neria-border);margin-bottom:24px;"></div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Neria vérifie chaque jour la réputation de votre domaine d'envoi sur trois critères fondamentaux : <strong>SPF</strong> (autorisation de vos serveurs d'envoi), <strong>DKIM</strong> (signature cryptographique des emails) et <strong>DMARC</strong> (politique en cas d'usurpation). Un domaine bien configuré arrive en boîte de réception ; un domaine mal configuré finit en spam — ou est rejeté silencieusement.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Score :</strong> 0–49 Critique · 50–74 Correct · 75–100 Excellent. Cliquez sur <strong>Actualiser</strong> après avoir modifié vos DNS pour voir le nouveau score immédiatement.
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
          Dernière vérification : {$dr.checked_at|escape:'html'}
        </div>

        {* Barre de progression *}
        <div style="background:var(--neria-border);border-radius:4px;height:8px;overflow:hidden;max-width:320px;">
          <div style="width:{$dr.score}%;height:100%;background:{$dr.color};border-radius:4px;transition:width .6s;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--neria-text-light);margin-top:4px;max-width:320px;">
          <span>0 — Critique</span>
          <span>50 — Correct</span>
          <span>100 — Excellent</span>
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
            Configuré{if $spf.policy === 'reject'} · Politique stricte{elseif $spf.policy === 'softfail'} · Politique souple{/if}
          {else}
            <span style="color:#c0392b;">Absent — risque de rejet</span>
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
            Sélecteur « {$dkim.selector|escape:'html'} » détecté
          {else}
            <span style="color:#c0392b;">Absent — signature manquante</span>
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
            <span style="color:#c0392b;">Absent — risque de spam élevé</span>
          {elseif $dmarc.policy === 'reject'}
            Politique stricte · Protection maximale
          {elseif $dmarc.policy === 'quarantine'}
            Politique quarantaine · Bonne protection
          {else}
            Configuré mais permissif (p=none)
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
              {if $ptr.valid}Vérifié{else}Incomplet{/if}
            </span>
          {/if}
        </div>
        <div style="font-size:12px;color:var(--neria-text-light);">
          {if $ptr.skipped}
            Non applicable (IP locale)
          {elseif $ptr.found}
            {$ptr.hostname|escape:'html'}
          {else}
            <span style="color:#a07820;">Absent — certains serveurs rejettent les IPs sans PTR</span>
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
          <span style="font-size:13px;font-weight:700;color:var(--neria-dark);">Listes noires</span>
        </div>
        <div style="font-size:12px;color:var(--neria-text-light);">
          {if isset($bl.skipped) && $bl.skipped}
            Non applicable (IP locale)
          {elseif $bl_hits_count === 0}
            ✓ Non listé sur {$bl.checked} listes vérifiées
          {else}
            <span style="color:#c0392b;">Listé sur {$bl_hits_count} liste(s) / {$bl.checked} vérifiées</span>
          {/if}
        </div>
        <div style="font-size:10px;letter-spacing:.04em;color:var(--neria-text-light);margin-top:4px;">
          {$bl.checked} RBL analysées
        </div>
      </div>

    </div>{* /grid 4 indicateurs *}

    {* ── Détail blacklists si hits ── *}
    {if $dr_hits|count > 0}
      <div style="background:#fdf0ee;border:1px solid #f5c6cb;border-radius:6px;padding:16px 18px;margin-bottom:16px;">
        <div style="font-size:12px;font-weight:700;color:#c0392b;margin-bottom:8px;">
          ❌ Listes noires actives ({$dr_hits|count}) — action requise
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
          Pour être retiré d'une liste : accédez au site de la liste et effectuez une demande de délistage.
          Spamhaus : <code style="font-size:11px;">lookup.mxtoolbox.com</code>
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
            BIMI — Affichage du logo dans la boîte mail
          </div>
          <div style="font-size:12px;color:var(--neria-text-light);margin-top:2px;">
            {if $bimi.found}
              Logo configuré · Votre logo apparaît dans les boîtes mail compatibles (Gmail, Yahoo, Apple Mail)
            {elseif $bimi.eligible}
              DMARC éligible · Votre domaine peut activer BIMI — ajoutez un enregistrement DNS <code>default._bimi.{$dr.domain|escape:'html'}</code> avec votre logo SVG
            {else}
              Non éligible · BIMI nécessite DMARC en <code>p=quarantine</code> ou <code>p=reject</code>
            {/if}
          </div>
        </div>
      </div>
    </div>

    {if $has_recs}
    <div style="margin-top:4px;">
      <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--neria-text-light);margin-bottom:10px;">Recommandations</div>
      {if !$dr.spf.found}
        <div style="padding:10px 14px;margin-bottom:6px;border-left:3px solid #c0392b;background:#fdf0ee;font-size:13px;line-height:1.6;">
          <strong>SPF manquant :</strong> Ajoutez un enregistrement TXT <code>v=spf1 include:votrehebergeur.com -all</code> dans vos DNS.
        </div>
      {/if}
      {if !$dr.dkim.found}
        <div style="padding:10px 14px;margin-bottom:6px;border-left:3px solid #c0392b;background:#fdf0ee;font-size:13px;line-height:1.6;">
          <strong>DKIM manquant :</strong> Activez la signature DKIM dans votre hébergeur email ou votre ESP (OVH, Ionos, Infomaniak…).
        </div>
      {/if}
      {if !$dr.dmarc.found}
        <div style="padding:10px 14px;margin-bottom:6px;border-left:3px solid #e67e22;background:#fef9ee;font-size:13px;line-height:1.6;">
          <strong>DMARC absent :</strong> Ajoutez <code>_dmarc.{$dr.domain|escape:'html'} TXT "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$dr.domain|escape:'html'}"</code>
        </div>
      {elseif $dr.dmarc.policy === 'none'}
        <div style="padding:10px 14px;margin-bottom:6px;border-left:3px solid #f0ad0a;background:#fffde7;font-size:13px;line-height:1.6;">
          <strong>DMARC trop permissif :</strong> Changez <code>p=none</code> en <code>p=quarantine</code> ou <code>p=reject</code> pour une protection maximale.
        </div>
      {/if}
    </div>
    {/if}

  {else}
    {* Aucun cache — premier lancement *}
    <div style="text-align:center;padding:32px 20px;">
      <div style="font-size:40px;color:var(--neria-border);margin-bottom:12px;">◎</div>
      <p style="font-size:14px;color:var(--neria-text-light);margin:0 0 20px;">
        Aucune vérification effectuée.<br>
        Cliquez sur <strong>Actualiser</strong> pour analyser la réputation de votre domaine d'envoi.
      </p>
      <p style="font-size:12px;color:var(--neria-text-light);">
        Vérifie : SPF · DKIM · DMARC · 42 listes noires RBL
      </p>
    </div>
  {/if}

</div>

{* ══════════════════════════════════════════════════════════════
   VISIBILITÉ BOUTIQUE — PageSpeed + Search Console + SEO API
   ══════════════════════════════════════════════════════════════ *}
<div class="neria-section" id="neria-visibility-section">
  <h2 class="neria-section__title">🌐 Visibilité sur le web</h2>
  <p class="neria-section__desc">
    Mesurez la présence organique de votre boutique : performance technique, trafic Search Console
    et autorité de domaine. Ces métriques complètent la délivrabilité email pour une vision à 360°.
  </p>

  {* ── 1. PAGESPEED INSIGHTS ────────────────────────────────── *}
  <div style="border:1px solid var(--neria-border);border-radius:8px;padding:20px 24px;margin-bottom:20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:20px;">⚡</span>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--neria-dark);">Google PageSpeed Insights</div>
          <div style="font-size:11px;color:var(--neria-muted);">Performance · Accessibilité · SEO · Core Web Vitals — clé API gratuite</div>
        </div>
      </div>
      {if $pagespeed_configured}
        <div style="display:flex;gap:8px;align-items:center;">
          {if $pagespeed_cache_age !== null}
            <span style="font-size:11px;color:var(--neria-muted);">actualisé il y a {$pagespeed_cache_age} min</span>
          {/if}
          <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
            <input type="hidden" name="neria_action" value="refresh_pagespeed">
            <input type="hidden" name="neria_tab"    value="stats">
            <button type="submit" style="padding:5px 12px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;"
                    onmouseover="this.style.background='#b8975a'" onmouseout="this.style.background='#1a1a1a'">
              ↻ Actualiser
            </button>
          </form>
        </div>
      {/if}
    </div>

    {* Notice explicative *}
    <div style="background:#f0f7ff;border-left:3px solid #4a90d9;border-radius:0 6px 6px 0;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#1e3a5f;line-height:1.8;">
      <strong>À quoi ça sert ?</strong><br>
      Google PageSpeed Insights analyse la <strong>qualité technique</strong> de votre boutique et lui attribue quatre scores de 0 à 100 :
      <strong>Performance</strong> (vitesse de chargement), <strong>Accessibilité</strong> (lisibilité pour tous),
      <strong>SEO</strong> (signaux techniques de référencement) et <strong>Bonnes pratiques</strong> (sécurité, standards web).<br>
      Il mesure également les <strong>Core Web Vitals</strong> imposés par Google depuis 2021 :
      <em>LCP</em> (temps avant que le contenu principal s'affiche — idéal &lt; 2,5 s),
      <em>CLS</em> (stabilité visuelle de la page — idéal &lt; 0,1) et
      <em>TBT</em> (temps pendant lequel la page est non interactive — idéal &lt; 200 ms).<br>
      Un mauvais score PageSpeed peut <strong>pénaliser votre classement dans Google Search</strong> et augmenter le taux de rebond.
      L'analyse est gratuite et se met à jour toutes les 24 heures.
    </div>

    {* Configuration : saisie clé API + URL cible *}
    <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:16px 20px;margin-bottom:16px;">
      {if !$pagespeed_configured}
      <div style="font-size:12px;color:#5c3d1e;line-height:1.6;margin-bottom:12px;">
        <strong>Comment obtenir une clé gratuite :</strong><br>
        1. <a href="https://console.cloud.google.com/" target="_blank" style="color:#1a7a40;">console.cloud.google.com</a>
        → API &amp; services → Bibliothèque → Activez <strong>PageSpeed Insights API</strong><br>
        2. Identifiants → Créer une clé API → copiez la clé
      </div>
      {/if}
      <form method="post" action="{$smarty.server.REQUEST_URI}">
        <input type="hidden" name="neria_action" value="save_pagespeed_key">
        <input type="hidden" name="neria_tab"    value="stats">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">Clé API Google</label>
            <input type="text" name="pagespeed_api_key" value="{$pagespeed_api_key|escape:'html'}"
                   style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                   placeholder="AIzaSy…">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">
              URL à analyser <span style="font-weight:400;color:#7a6a5a;">(optionnel — URL publique si boutique locale)</span>
            </label>
            <input type="url" name="pagespeed_target_url" value="{$pagespeed_target_url|escape:'html'}"
                   style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                   placeholder="https://ma-boutique.com/">
          </div>
        </div>
        <button type="submit" class="neria-btn neria-btn--primary" style="font-size:12px;padding:8px 16px;">
          Enregistrer
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

      {* 4 scores — Mobile *}
      {if $ps.mobile}
      {assign var="psm" value=$ps.mobile}
      <div style="margin-bottom:20px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:12px;">📱 Mobile</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:10px;margin-bottom:16px;">
          {foreach [
            ['label'=>'Performance', 'val'=>$psm.perf,   'color'=>$psm.perf_color],
            ['label'=>'Accessibilité','val'=>$psm.access, 'color'=>$psm.access_color],
            ['label'=>'SEO',          'val'=>$psm.seo,    'color'=>$psm.seo_color],
            ['label'=>'Bonnes prat.', 'val'=>$psm.best,   'color'=>$psm.best_color]
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
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:12px;">🖥 Desktop</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:10px;margin-bottom:16px;">
          {foreach [
            ['label'=>'Performance', 'val'=>$psd.perf,   'color'=>$psd.perf_color],
            ['label'=>'Accessibilité','val'=>$psd.access, 'color'=>$psd.access_color],
            ['label'=>'SEO',          'val'=>$psd.seo,    'color'=>$psd.seo_color],
            ['label'=>'Bonnes prat.', 'val'=>$psd.best,   'color'=>$psd.best_color]
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
        Analysé le {$ps.checked_at} · URL : {$ps.url|escape:'html'}
      </p>

    {elseif $pagespeed_configured}
      <div style="text-align:center;padding:24px;color:var(--neria-muted);font-size:13px;">
        <div style="font-size:32px;margin-bottom:8px;">⚡</div>
        Clé API configurée — cliquez sur <strong>Actualiser</strong> pour lancer l'analyse.
      </div>
    {/if}
  </div>

  {* ── 2. GOOGLE SEARCH CONSOLE ─────────────────────────────── *}
  <div style="border:1px solid var(--neria-border);border-radius:8px;padding:20px 24px;margin-bottom:20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:20px;">🔍</span>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--neria-dark);">Google Search Console</div>
          <div style="font-size:11px;color:var(--neria-muted);">Impressions · Clics · CTR · Position moyenne · Top requêtes — OAuth gratuit</div>
        </div>
      </div>
      {if $searchconsole_connected}
        <div style="display:flex;gap:8px;align-items:center;">
          {if $searchconsole_cache_age !== null}
            <span style="font-size:11px;color:var(--neria-muted);">actualisé il y a {$searchconsole_cache_age} min</span>
          {/if}
          <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
            <input type="hidden" name="neria_action" value="refresh_searchconsole">
            <input type="hidden" name="neria_tab"    value="stats">
            <button type="submit" style="padding:5px 12px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;"
                    onmouseover="this.style.background='#b8975a'" onmouseout="this.style.background='#1a1a1a'">
              ↻ Actualiser
            </button>
          </form>
          <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
            <input type="hidden" name="neria_action" value="disconnect_searchconsole">
            <input type="hidden" name="neria_tab"    value="stats">
            <button type="submit" style="padding:5px 12px;background:#fff;color:#c0392b;border:1px solid #f5c6cb;border-radius:4px;font-size:11px;cursor:pointer;">
              Déconnecter
            </button>
          </form>
        </div>
      {/if}
    </div>

    {* Notice explicative *}
    <div style="background:#f0f7ff;border-left:3px solid #4a90d9;border-radius:0 6px 6px 0;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#1e3a5f;line-height:1.8;">
      <strong>À quoi ça sert ?</strong><br>
      Google Search Console vous donne accès aux <strong>données officielles de Google</strong> sur la présence de votre boutique dans les résultats de recherche :
      nombre de <strong>clics</strong> réels depuis Google, <strong>impressions</strong> (combien de fois votre boutique apparaît),
      <strong>taux de clics (CTR)</strong> et <strong>position moyenne</strong> de vos pages dans les résultats.<br>
      Vous voyez aussi les <strong>10 requêtes</strong> qui génèrent le plus de trafic (les mots-clés tapés par vos visiteurs)
      et les <strong>10 pages</strong> les plus visitées depuis Google — idéal pour repérer ce qui fonctionne et ce qui mérite d'être amélioré.<br>
      La connexion est <strong>entièrement gratuite</strong> via votre compte Google.
      Les données couvrent les 28 derniers jours avec 2 à 3 jours de latence (délai normal imposé par Google).
    </div>

    {* État 1 : non configuré *}
    {if !$searchconsole_configured}
    <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:16px 20px;">
      <div style="font-size:12px;color:#5c3d1e;line-height:1.6;margin-bottom:12px;">
        <strong>Configuration OAuth 2.0 :</strong><br>
        1. <a href="https://console.cloud.google.com/" target="_blank" style="color:#1a7a40;">console.cloud.google.com</a>
        → Nouveau projet → API &amp; services → Bibliothèque<br>
        2. Activez <strong>Google Search Console API</strong><br>
        3. Identifiants → OAuth 2.0 → Application Web → URI de redirection :<br>
        <code style="font-size:11px;background:#fff;padding:2px 6px;border-radius:3px;">
          {$smarty.server.REQUEST_SCHEME|default:'https'}://{$smarty.server.HTTP_HOST}/index.php?fc=module&amp;module=neria&amp;controller=oauthsc
        </code>
      </div>
      <form method="post" action="{$smarty.server.REQUEST_URI}">
        <input type="hidden" name="neria_action" value="save_searchconsole_config">
        <input type="hidden" name="neria_tab"    value="stats">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">Client ID</label>
            <input type="text" name="sc_client_id" value="{$searchconsole_client_id|escape:'html'}"
                   style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                   placeholder="12345…googleusercontent.com">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">Client Secret</label>
            <input type="password" name="sc_client_secret"
                   style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                   placeholder="GOCSPX-…">
          </div>
        </div>
        <button type="submit" class="neria-btn neria-btn--primary" style="font-size:12px;padding:8px 16px;">
          Enregistrer les identifiants
        </button>
      </form>
    </div>
    {/if}

    {* État 2 : configuré mais non connecté *}
    {if $searchconsole_configured && !$searchconsole_connected}
    <div style="background:#fff;border:1px solid #e8d5b0;border-radius:6px;padding:16px 20px;">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
        <div style="width:8px;height:8px;border-radius:50%;background:#e67e22;flex-shrink:0;"></div>
        <div style="font-weight:700;font-size:13px;color:#5c3d1e;">Identifiants enregistrés — autorisation requise</div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="post" action="{$smarty.server.REQUEST_URI}">
          <input type="hidden" name="neria_action" value="connect_searchconsole">
          <input type="hidden" name="neria_tab"    value="stats">
          <button type="submit" style="background:#1a7a40;color:#fff;border:none;border-radius:5px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;">
            🔗 Connecter avec Google
          </button>
        </form>
        <form method="post" action="{$smarty.server.REQUEST_URI}">
          <input type="hidden" name="neria_action" value="save_searchconsole_config">
          <input type="hidden" name="neria_tab"    value="stats">
          <input type="hidden" name="sc_client_id"     value="">
          <input type="hidden" name="sc_client_secret" value="">
          <button type="submit" style="background:#fff;color:#7a6a5a;border:1px solid #d4c5a9;border-radius:5px;padding:9px 16px;font-size:12px;cursor:pointer;">
            Modifier les identifiants
          </button>
        </form>
      </div>
    </div>
    {/if}

    {* État 3 : connecté — données *}
    {if $searchconsole_connected}
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;background:#f0faf3;border:1px solid #c3e6cb;border-radius:6px;padding:10px 14px;">
      <div style="width:8px;height:8px;border-radius:50%;background:#16a34a;flex-shrink:0;"></div>
      <span style="font-size:12px;font-weight:700;color:#16a34a;">Connecté à Google Search Console</span>
    </div>

    {if $searchconsole_stats}
      {assign var="sc" value=$searchconsole_stats}
      <div style="font-size:11px;color:var(--neria-muted);margin-bottom:12px;">{$sc.site_url|escape:'html'} · {$sc.period}</div>

      {* 4 KPIs *}
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:20px;">
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:18px;margin-bottom:4px;">👁</div>
          <div style="font-size:20px;font-weight:700;color:var(--neria-dark);">{$sc.impressions|number_format:0:',':' '}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">Impressions</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:18px;margin-bottom:4px;">↗</div>
          <div style="font-size:20px;font-weight:700;color:var(--neria-dark);">{$sc.clicks|number_format:0:',':' '}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">Clics</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:18px;margin-bottom:4px;">%</div>
          <div style="font-size:20px;font-weight:700;color:var(--neria-dark);">{$sc.ctr}%</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">CTR</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:18px;margin-bottom:4px;">#</div>
          <div style="font-size:20px;font-weight:700;color:var(--neria-dark);">{$sc.position}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">Position</div>
        </div>
      </div>

      {* Top requêtes + Top pages côte à côte *}
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

        {* Top 10 requêtes *}
        {if $sc.queries}
        <div>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:8px;">Top requêtes</div>
          <table class="neria-table" style="font-size:12px;">
            <thead><tr>
              <th>Requête</th>
              <th class="neria-table__num">Clics</th>
              <th class="neria-table__num">Pos.</th>
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
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:8px;">Top pages</div>
          <table class="neria-table" style="font-size:12px;">
            <thead><tr>
              <th>Page</th>
              <th class="neria-table__num">Clics</th>
              <th class="neria-table__num">Pos.</th>
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
      <p style="font-size:11px;color:var(--neria-muted);margin:12px 0 0;font-style:italic;">Données du {$sc.checked_at} — latence Google : 3 jours</p>

    {else}
      <div style="text-align:center;padding:20px;color:var(--neria-muted);font-size:13px;">
        Cliquez sur <strong>Actualiser</strong> pour charger les données Search Console.
      </div>
    {/if}
    {/if}
  </div>

  {* ── 3. API SEO PAYANTE (Semrush / Moz) ──────────────────── *}
  <div style="border:1px solid var(--neria-border);border-radius:8px;padding:20px 24px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:20px;">📊</span>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--neria-dark);">API SEO avancée <span style="font-size:11px;font-weight:400;color:var(--neria-muted);">(optionnelle)</span></div>
          <div style="font-size:11px;color:var(--neria-muted);">Semrush ou Moz — autorité, backlinks, mots-clés organiques</div>
        </div>
      </div>
      {if $seo_configured}
        <div style="display:flex;gap:8px;align-items:center;">
          {if $seo_cache_age !== null}
            <span style="font-size:11px;color:var(--neria-muted);">actualisé il y a {$seo_cache_age} min</span>
          {/if}
          <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
            <input type="hidden" name="neria_action" value="refresh_seo_api">
            <input type="hidden" name="neria_tab"    value="stats">
            <button type="submit" style="padding:5px 12px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;"
                    onmouseover="this.style.background='#b8975a'" onmouseout="this.style.background='#1a1a1a'">
              ↻ Actualiser
            </button>
          </form>
        </div>
      {/if}
    </div>

    {* Notice explicative *}
    <div style="background:#f0f7ff;border-left:3px solid #4a90d9;border-radius:0 6px 6px 0;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#1e3a5f;line-height:1.8;">
      <strong>À quoi ça sert ?</strong><br>
      Les APIs SEO avancées complètent PageSpeed et Search Console en ajoutant la <strong>dimension concurrentielle</strong> :
      où se positionne votre boutique face à vos concurrents ?<br>
      <strong>Semrush</strong> fournit une estimation du trafic organique mensuel, le nombre de mots-clés sur lesquels votre boutique est positionnée
      et les requêtes qui génèrent le plus de visibilité — utile pour identifier des opportunités SEO inexploitées.<br>
      <strong>Moz</strong> mesure le <em>Domain Authority</em> (DA, score 0-100 de l'autorité globale de votre domaine),
      le <em>Page Authority</em> (PA), le <em>Spam Score</em> (risque de pénalité Google) et le nombre de <em>backlinks</em>
      (liens entrants depuis d'autres sites).<br>
      Cette section est <strong>optionnelle</strong> et nécessite un abonnement payant chez Semrush ou Moz.
      Sans API payante, PageSpeed et Search Console couvrent déjà l'essentiel de votre visibilité.
    </div>

    {* Formulaire de configuration *}
    <form method="post" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="neria_action" value="save_seo_config">
      <input type="hidden" name="neria_tab"    value="stats">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        {* Choix du fournisseur *}
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:var(--neria-dark);margin-bottom:6px;">Fournisseur</label>
          <select name="seo_provider" id="neria-seo-provider"
                  style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;">
            <option value="">— Aucun —</option>
            <option value="semrush" {if $seo_provider == 'semrush'}selected{/if}>Semrush</option>
            <option value="moz"     {if $seo_provider == 'moz'}selected{/if}>Moz</option>
          </select>
        </div>

        {* Champs Semrush *}
        <div id="neria-seo-semrush" style="display:{if $seo_provider == 'semrush'}block{else}none{/if};">
          <label style="display:block;font-size:11px;font-weight:600;color:var(--neria-dark);margin-bottom:6px;">Clé API Semrush</label>
          <input type="text" name="seo_semrush_key" value="{$seo_semrush_key|escape:'html'}"
                 style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                 placeholder="votre clé Semrush API">
          <div style="font-size:10px;color:var(--neria-muted);margin-top:4px;">
            <a href="https://www.semrush.com/api-documentation/" target="_blank" style="color:var(--neria-accent);">Documentation Semrush API →</a>
          </div>
        </div>

        {* Champs Moz *}
        <div id="neria-seo-moz" style="display:{if $seo_provider == 'moz'}block{else}none{/if};">
          <label style="display:block;font-size:11px;font-weight:600;color:var(--neria-dark);margin-bottom:4px;">Moz Access ID</label>
          <input type="text" name="seo_moz_access" value="{$seo_moz_access|escape:'html'}"
                 style="width:100%;padding:7px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;margin-bottom:8px;"
                 placeholder="mozscape-…">
          <label style="display:block;font-size:11px;font-weight:600;color:var(--neria-dark);margin-bottom:4px;">Moz Secret Key</label>
          <input type="password" name="seo_moz_secret"
                 style="width:100%;padding:7px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                 placeholder="…">
          <div style="font-size:10px;color:var(--neria-muted);margin-top:4px;">
            <a href="https://moz.com/products/api" target="_blank" style="color:var(--neria-accent);">Documentation Moz API →</a>
          </div>
        </div>
      </div>

      <button type="submit" class="neria-btn neria-btn--primary" style="font-size:12px;padding:8px 16px;">
        Enregistrer
      </button>
    </form>

    {* Résultats Semrush *}
    {if $seo_report && $seo_report.provider == 'semrush'}
      {assign var="sr" value=$seo_report}
      <hr style="border:none;border-top:1px solid var(--neria-border);margin:20px 0;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:12px;">Semrush — {$sr.domain|escape:'html'} · {$sr.checked_at}</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:20px;">
        {foreach [
          ['label'=>'Score auto.','val'=>$sr.authority_score,'icon'=>'★'],
          ['label'=>'Mots-clés org.','val'=>$sr.organic_keywords|number_format:0:',':' ','icon'=>'🔑'],
          ['label'=>'Trafic org.','val'=>$sr.organic_traffic|number_format:0:',':' ','icon'=>'📈'],
          ['label'=>'Mots-clés payants','val'=>$sr.paid_keywords|number_format:0:',':' ','icon'=>'💰']
        ] as $kpi}
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:12px 14px;text-align:center;">
          <div style="font-size:16px;margin-bottom:4px;">{$kpi.icon}</div>
          <div style="font-size:18px;font-weight:700;color:var(--neria-dark);">{$kpi.val}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">{$kpi.label}</div>
        </div>
        {/foreach}
      </div>
      {if $sr.keywords}
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-bottom:8px;">Top mots-clés organiques</div>
      <table class="neria-table" style="font-size:12px;">
        <thead><tr>
          <th>Mot-clé</th>
          <th class="neria-table__num">Position</th>
          <th class="neria-table__num">Volume/mois</th>
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
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">Domain Authority</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:14px;text-align:center;">
          <div style="font-size:32px;font-weight:700;color:var(--neria-dark);">{$mr.page_authority}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">Page Authority</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:14px;text-align:center;">
          <div style="font-size:32px;font-weight:700;color:var(--neria-dark);">{$mr.links_to_root|number_format:0:',':' '}</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">Backlinks</div>
        </div>
        <div style="background:var(--neria-bg);border:1px solid var(--neria-border);border-radius:6px;padding:14px;text-align:center;">
          {assign var="spam" value=$mr.spam_score}
          <div style="font-size:32px;font-weight:700;color:{if $spam < 30}#16a34a{elseif $spam < 60}#d97706{else}#dc2626{/if};">{$spam}%</div>
          <div style="font-size:10px;color:var(--neria-muted);text-transform:uppercase;letter-spacing:.05em;">Spam Score</div>
        </div>
      </div>
    {/if}

    {if !$seo_provider}
    <div style="margin-top:14px;padding:12px 16px;background:#fef9f0;border:1px solid #e8d5b0;border-radius:6px;font-size:12px;color:var(--neria-muted);line-height:1.6;">
      💡 Sans API payante, vous pouvez quand même mesurer votre visibilité via PageSpeed et Search Console ci-dessus.
      Les APIs payantes ajoutent la dimension <strong>concurrentielle</strong> : où vous positionnez-vous par rapport à vos concurrents ?
    </div>
    {/if}
  </div>
</div>

{literal}
<script>
(function() {
  var sel = document.getElementById('neria-seo-provider');
  if (!sel) return;
  function toggle() {
    var v = sel.value;
    var sm = document.getElementById('neria-seo-semrush');
    var mz = document.getElementById('neria-seo-moz');
    if (sm) sm.style.display = (v === 'semrush') ? 'block' : 'none';
    if (mz) mz.style.display = (v === 'moz')     ? 'block' : 'none';
  }
  sel.addEventListener('change', toggle);
})();
</script>
{/literal}

{* ── Google Postmaster Tools — intégration OAuth ────────────── *}
<div class="neria-section" id="neria-postmaster-tools">
  <h2 class="neria-section__title">🔭 Google Postmaster Tools</h2>
  <p class="neria-section__desc">
    Connectez votre compte Google pour afficher directement dans Neria le taux de spam signalé,
    la réputation de domaine et les ratios SPF/DKIM/DMARC mesurés par Gmail.
  </p>

  {* ═══ ÉTAT 1 : Non configuré — saisie des credentials ══════ *}
  {if !$postmaster_configured}
  <div style="background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:24px;margin-top:16px;">
    <div style="font-weight:700;font-size:13px;color:#5c3d1e;margin-bottom:8px;">⚙️ Configuration OAuth 2.0</div>
    <p style="font-size:12px;color:#7a6a5a;line-height:1.6;margin:0 0 16px;">
      Créez un projet Google Cloud, activez l'API <strong>Gmail Postmaster Tools</strong>,
      puis créez des identifiants OAuth 2.0 (type « Application Web ») avec l'URI de redirection suivante :
    </p>
    <div style="background:#f9f6f1;border-radius:6px;padding:10px 14px;font-size:12px;font-family:monospace;color:#5c3d1e;margin-bottom:16px;word-break:break-all;">
      {$smarty.server.REQUEST_SCHEME|default:'https'}://{$smarty.server.HTTP_HOST}/index.php?fc=module&module=neria&controller=oauth
    </div>
    <div style="font-size:12px;background:#fef9f0;border:1px solid #e8d5b0;border-radius:6px;padding:10px 14px;color:#5c3d1e;line-height:1.6;margin-bottom:20px;">
      <strong>Étapes rapides :</strong><br>
      1. <a href="https://console.cloud.google.com/" target="_blank" style="color:#1a7a40;">console.cloud.google.com</a> → Nouveau projet → API &amp; services → Bibliothèque<br>
      2. Activez « <strong>Gmail Postmaster Tools API</strong> »<br>
      3. Identifiants → Créer des identifiants → ID client OAuth → Application Web<br>
      4. Ajoutez l'URI de redirection ci-dessus → copiez Client ID et Secret
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="neria_action" value="save_postmaster_config">
      <input type="hidden" name="neria_tab"    value="stats">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">Client ID</label>
          <input type="text" name="postmaster_client_id" value="{$postmaster_client_id|escape:'html'}"
                 style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                 placeholder="12345...apps.googleusercontent.com">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#5c3d1e;margin-bottom:4px;">Client Secret</label>
          <input type="password" name="postmaster_client_secret"
                 style="width:100%;padding:8px 10px;border:1px solid #d4c5a9;border-radius:5px;font-size:12px;"
                 placeholder="GOCSPX-…">
        </div>
      </div>
      <button type="submit" class="neria-btn neria-btn--primary" style="font-size:12px;padding:8px 18px;">
        Enregistrer les identifiants
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
        <div style="font-weight:700;font-size:13px;color:#5c3d1e;">Identifiants enregistrés — autorisation requise</div>
        <div style="font-size:11px;color:#7a6a5a;margin-top:2px;">Client ID : {$postmaster_client_id|escape:'html'|truncate:40:'…':true}</div>
      </div>
    </div>
    <p style="font-size:12px;color:#7a6a5a;line-height:1.6;margin:0 0 16px;">
      Cliquez sur le bouton ci-dessous pour autoriser Neria à lire vos données Postmaster Tools.
      Vous serez redirigé vers Google, puis ramené automatiquement ici.
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <form method="post" action="{$smarty.server.REQUEST_URI}">
        <input type="hidden" name="neria_action" value="connect_postmaster">
        <input type="hidden" name="neria_tab"    value="stats">
        <button type="submit" style="background:#1a7a40;color:#fff;border:none;border-radius:5px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;">
          🔗 Connecter avec Google
        </button>
      </form>
      <form method="post" action="{$smarty.server.REQUEST_URI}">
        <input type="hidden" name="neria_action" value="save_postmaster_config">
        <input type="hidden" name="neria_tab"    value="stats">
        <input type="hidden" name="postmaster_client_id"     value="">
        <input type="hidden" name="postmaster_client_secret" value="">
        <button type="submit" style="background:#fff;color:#7a6a5a;border:1px solid #d4c5a9;border-radius:5px;padding:9px 16px;font-size:12px;cursor:pointer;">
          Modifier les identifiants
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
          <span style="font-weight:700;font-size:13px;color:#16a34a;">Connecté à Google Postmaster Tools</span>
          {if $postmaster_cache_age !== null}
          <span style="font-size:11px;color:#7a6a5a;margin-left:8px;">— données actualisées il y a {$postmaster_cache_age} min</span>
          {/if}
        </div>
      </div>
      <div style="display:flex;gap:8px;">
        <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
          <input type="hidden" name="neria_action" value="refresh_postmaster">
          <input type="hidden" name="neria_tab"    value="stats">
          <button type="submit" style="background:#fff;color:#5c3d1e;border:1px solid #d4c5a9;border-radius:5px;padding:6px 12px;font-size:11px;cursor:pointer;">
            ↺ Actualiser
          </button>
        </form>
        <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
          <input type="hidden" name="neria_action" value="disconnect_postmaster">
          <input type="hidden" name="neria_tab"    value="stats">
          <button type="submit" style="background:#fff;color:#c0392b;border:1px solid #f5c6cb;border-radius:5px;padding:6px 12px;font-size:11px;cursor:pointer;">
            Déconnecter
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
            <div style="font-size:11px;color:#7a6a5a;">Données du {$ps.date|escape:'html'}</div>
          </div>
          {* Badge réputation domaine *}
          {assign var="drep" value=$ps.domain_reputation}
          {if $drep === 'HIGH'}
            <div style="background:#d4edda;color:#155724;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;">✅ HIGH — Excellent</div>
          {elseif $drep === 'MEDIUM'}
            <div style="background:#fff3cd;color:#856404;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;">⚠️ MEDIUM — Moyen</div>
          {elseif $drep === 'LOW'}
            <div style="background:#f8d7da;color:#721c24;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;">🔴 LOW — Dégradé</div>
          {elseif $drep === 'BAD'}
            <div style="background:#721c24;color:#fff;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;">💀 BAD — Bloqué</div>
          {else}
            <div style="background:#f9f6f1;color:#7a6a5a;border-radius:20px;padding:5px 14px;font-size:12px;">○ Insuffisant (trop peu d'envois)</div>
          {/if}
        </div>

        {* Taux de spam *}
        {if $ps.spam_rate !== null}
          {assign var="spRate" value=$ps.spam_rate}
          {if $spRate < 0.1}
            {assign var="spColor" value="#16a34a"}
            {assign var="spLabel" value="Zone verte"}
          {elseif $spRate < 0.3}
            {assign var="spColor" value="#d97706"}
            {assign var="spLabel" value="Attention"}
          {else}
            {assign var="spColor" value="#dc2626"}
            {assign var="spLabel" value="Danger"}
          {/if}
          <div style="background:#f9f6f1;border-radius:8px;padding:14px 16px;margin-bottom:14px;">
            <div style="font-size:11px;font-weight:600;color:#7a6a5a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">Taux de spam signalé</div>
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
          <div style="font-size:11px;font-weight:600;color:#7a6a5a;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">Réputations IP</div>
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
          <div style="font-size:11px;font-weight:600;color:#856404;margin-bottom:4px;">⚠️ Erreurs de livraison détectées</div>
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
        Aucune donnée disponible pour les 7 derniers jours.<br>
        <small>Cela peut indiquer un volume d'envoi insuffisant ou que votre domaine n'est pas encore vérifié dans Postmaster Tools.</small>
      </div>
    {else}
      <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:8px;padding:20px;text-align:center;color:#7a6a5a;font-size:13px;">
        <div style="font-size:24px;margin-bottom:8px;">⏳</div>
        Cliquez sur <strong>Actualiser</strong> pour charger les données Postmaster Tools.
      </div>
    {/if}

  </div>
  {/if}

  {* ── Microsoft SNDS — guide statique ──────────────────────── *}
  <div style="margin-top:20px;background:#fff;border:1px solid #e8d5b0;border-radius:8px;padding:20px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
      <span style="font-size:24px;">🪟</span>
      <div>
        <div style="font-weight:700;font-size:14px;color:#5c3d1e;">Microsoft SNDS</div>
        <div style="font-size:11px;color:#7a6a5a;">sendersupport.olc.protection.outlook.com/snds</div>
      </div>
    </div>
    <p style="font-size:12px;color:#7a6a5a;line-height:1.6;margin:0 0 14px;">
      Smart Network Data Services : réputation de votre <strong>IP d'envoi</strong> auprès de
      Outlook et Hotmail. Affiche le taux de plaintes spam et l'état de filtrage de votre IP.
      Essentiel pour les clients Outlook/Hotmail (très répandu en entreprise).
    </p>
    <div style="font-size:12px;background:#f9f6f1;border-radius:6px;padding:10px 12px;color:#5c3d1e;line-height:1.6;">
      <strong>Comment s'inscrire :</strong><br>
      1. Relevez l'IP de votre serveur d'envoi<br>
      2. Connectez-vous sur le site SNDS<br>
      3. Demandez l'accès pour votre IP<br>
      4. Vous recevrez un email de confirmation
    </div>
  </div>

  <div style="margin-top:14px;padding:12px 16px;background:#fef9f0;border:1px solid #e8d5b0;border-radius:6px;font-size:12px;color:#7a6a5a;line-height:1.6;">
    💡 <strong>Conseil :</strong> Un taux de spam >0,3% sur Google Postmaster entraîne une dégradation immédiate de votre délivrabilité. En dessous de 0,1%, vous êtes dans la zone verte.
  </div>
</div>

{* ── Score de délivrabilité ─────────────────────────────────── *}
<div class="neria-section" id="neria-score-panel">

  <h2 class="neria-section__title">{neria_admin key='stats.score_title'}</h2>
  <p class="neria-section__desc">
    {neria_admin key='stats.score_desc'}
  </p>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Sélectionnez un template et une langue, puis cliquez sur <strong>Analyser la délivrabilité</strong>. Neria inspecte l'email selon <strong>8 critères anti-spam</strong> : objet (longueur, mots déclencheurs), ratio texte/HTML, poids total, lien de désabonnement, domaine d'envoi, images sans texte alternatif, et cohérence de l'expéditeur.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Score :</strong> 90–100 Excellent · 75–89 Bon · 60–74 Correct · &lt; 60 Risque spam. Chaque critère en échec est accompagné d'une recommandation concrète pour corriger le problème.
    </div>
  </div>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
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
          <div style="font-size:13px; color:#88837c; margin-top:4px;">{$d.label}</div>
        </div>
        <div style="flex:1;">
          <div style="background:#f0e7db; border-radius:4px; height:12px; overflow:hidden;">
            <div style="width:{$d.score}%; height:100%; background:{$d.color}; border-radius:4px; transition:width 0.6s ease;"></div>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:11px; color:#a09990; margin-top:4px;">
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
        <div style="font-size:13px;font-weight:700;color:#3d2878;">Protection Apple MPP active</div>
        <div style="font-size:11px;color:#7a6a95;margin-top:3px;line-height:1.5;">
          Les ouvertures automatiques d'Apple Mail (iOS 15+) sont détectées et exclues —
          vos taux d'ouverture reflètent les vraies lectures humaines.
        </div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:20px;flex-shrink:0;">
      <div style="text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#3d2878;line-height:1;">{$stats.kpis.total_open|default:0|number_format:0:',':' '}</div>
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9b89c0;margin-top:3px;">Ouvertures réelles</div>
      </div>
      <div style="width:1px;height:36px;background:#c9b8f0;"></div>
      <div style="text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#5b3fa8;line-height:1;">{$stats.kpis.mpp_open|default:0|number_format:0:',':' '}</div>
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9b89c0;margin-top:3px;">Exclues MPP</div>
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
        <span class="neria-badge neria-badge--mpp" title="Ouvertures Apple MPP exclues des statistiques">
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

    <div class="neria-kpi" title="Click-to-Open Rate : parmi les lecteurs ayant vraiment ouvert, combien ont cliqué ?">
      <div class="neria-kpi__value">{$stats.kpis.ctor|default:0}%</div>
      <div class="neria-kpi__label">CTOR <span style="font-size:9px;color:var(--neria-text-muted,#aaa);font-weight:400;">clics / ouv. réelles</span></div>
      <div class="neria-kpi__rate" style="font-size:10px;color:var(--neria-text-muted,#aaa);">hors MPP</div>
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
          <th class="neria-table__num" title="Click-to-Open Rate : clics ÷ ouvertures réelles (hors MPP)">CTOR</th>
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
              <span class="neria-badge neria-badge--mpp" title="+{$row.mpp_open} ouvertures Apple MPP exclues">MPP</span>
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
  <h3 style="font-size:13px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.5; margin:0 0 16px 0;">Classement des templates</h3>

  {* Onglets de tri *}
  <div style="display:flex;gap:8px;margin-bottom:16px;" id="neria-top10-tabs">
    <button class="neria-period-tab neria-period-tab--active" data-top10="open">Top ouverture</button>
    <button class="neria-period-tab" data-top10="click">Top clic</button>
    <button class="neria-period-tab" data-top10="revenue">Top CA</button>
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
        <th class="neria-table__num">Commandes</th>
        <th class="neria-table__num">CA attribué</th>
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
    <p style="font-size:13px;color:var(--neria-muted);margin:0;">Aucune attribution de CA enregistrée sur 30 jours.</p>
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
        <th style="text-align:left; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">LANGUE</th>
        <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">ENVOIS</th>
        <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">TAUX OUV.</th>
        <th style="text-align:left; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px; width:200px;">BARRE OUV.</th>
        <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">TAUX CLIC</th>
        <th style="text-align:left; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px; width:200px;">BARRE CLIC</th>
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
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Neria analyse les <strong>90 derniers jours</strong> d'ouvertures pour identifier, par langue, le jour et la tranche horaire où vos clients lisent le plus leurs emails. Ces données alimentent automatiquement la fonctionnalité <strong>Fenêtre d'achat</strong> : les emails comportementaux (relances, anniversaires, upsell…) sont programmés à l'heure préférée de chaque client.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Données :</strong> le statut <em>Correcte</em> indique une fiabilité suffisante (50+ ouvertures). En dessous, les données sont marquées <em>Insuffisantes</em> et Neria utilise une tranche par défaut (10h–11h). Le pixel de tracking doit être actif pour accumuler des données.
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
            <td>
              <span class="neria-golden-hour">
                {if $rec.best_hour < 10}0{/if}{$rec.best_hour}h — {if $rec.best_hour < 9}{if $rec.best_hour+1 < 10}0{/if}{$rec.best_hour+1}{else}{$rec.best_hour+1}{/if}h
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
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Abandon de caisse ✦</h2>
      <p class="neria-section__desc" style="margin:0;">
        Clients ayant atteint la page de paiement (transporteur + adresses sélectionnés) mais n'ayant pas finalisé.
        Email rassurant envoyé automatiquement <strong>1h après l'abandon</strong>, sans promotion.
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="checkout_abandonment_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $checkout_abandonment_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $checkout_abandonment_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px,1fr)); gap:12px; margin-bottom:24px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$checkout_abandonment_stats.emails_sent|default:0}</div>
      <div class="neria-kpi__label">Emails envoyés</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$checkout_abandonment_stats.orders_recovered|default:0}</div>
      <div class="neria-kpi__label">Commandes récupérées</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$checkout_abandonment_stats.revenue_recovered|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">CA récupéré</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$checkout_abandonment_stats.conversion_rate|default:0} %</div>
      <div class="neria-kpi__label">Taux de conversion</div>
    </div>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Neria détecte les paniers dont le transporteur et les deux adresses ont été sélectionnés mais sans commande finalisée.
    Un email rassurant (ton "problème technique ?") est envoyé une seule fois par panier, 1h après l'abandon.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Déduplication :</strong> un client qui reçoit cet email ne recevra aucune des 3 relances panier abandonné pour le même panier, et vice-versa.
    </div>
  </div>
</div>

{* ── Anniversaire de la relation client ─────────────────────── *}
<div class="neria-section" id="neria-relationship-anniversary-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Anniversaire de la relation client ✦</h2>
      <p class="neria-section__desc" style="margin:0;">
        Chaque année, à la date exacte de leur premier achat, vos clients reçoivent un email personnel.
        "Il y a deux ans, vous nous avez accordé votre confiance pour la première fois."
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="relationship_anniversary_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $relationship_anniversary_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $relationship_anniversary_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  {* Alerte doublon first_anniversary *}
  <div style="display:flex; align-items:flex-start; gap:10px; padding:12px 16px; margin-bottom:20px;
              background:#fff8e1; border-left:3px solid #f59e0b; border-radius:4px;
              font-size:12px; color:#78350f; line-height:1.6;">
    <span style="font-size:16px; flex-shrink:0;">⚠</span>
    <span>
      <strong>Attention doublon :</strong> le template <em>Premier anniversaire client</em> (first_anniversary)
      envoie également un email à J+365 du 1er achat. Si les deux sont actifs, vos clients recevront
      deux emails le même jour pour leur première année. Nous recommandons de <strong>désactiver
      first_anniversary</strong> dans l'onglet <em>Envoi manuel</em> si vous utilisez cette fonctionnalité.
    </span>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:12px; margin-bottom:24px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$relationship_anniversary_stats.emails_sent|default:0}</div>
      <div class="neria-kpi__label">Emails envoyés</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$relationship_anniversary_stats.orders_attributed|default:0}</div>
      <div class="neria-kpi__label">Commandes attribuées</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$relationship_anniversary_stats.revenue_attributed|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">CA attribué</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$relationship_anniversary_stats.avg_order_value|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">Panier moyen</div>
    </div>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Chaque jour, le CRON détecte les clients dont la date du premier achat correspond à aujourd'hui
    (même jour, même mois), avec au moins 1 an d'ancienneté. L'email s'adapte automatiquement à l'année :
    "Il y a un an…", "Il y a deux ans…", "Il y a trois ans…" — dans la langue du client.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Attribution :</strong> toute commande passée dans les <strong>48 heures</strong> suivant l'envoi est comptabilisée dans le CA attribué.
    </div>
  </div>
</div>

{* ── Upsell Intelligent ────────────────────────────────────── *}
<div class="neria-section" id="neria-upsell-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Upsell Intelligent ✦</h2>
      <p class="neria-section__desc" style="margin:0;">
        Produit complémentaire suggéré automatiquement dans l'email post-achat (J+14 après livraison).
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="upsell_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $upsell_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $upsell_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  {* ── Notice explicative ──────────────────────────────────── *}
  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">À quoi ça sert</div>
    14 jours après la livraison, Neria glisse dans l'email de suivi <strong>un seul produit complémentaire</strong>,
    choisi automatiquement selon 3 critères, dans cet ordre de priorité :
    <div style="margin:10px 0;">
      <strong style="color:#1a7a40;">1. Accessoire</strong> que vous avez associé au produit acheté ·
      <strong style="color:#2563a8;">2. Souvent acheté ensemble</strong> (déduit de vos commandes) ·
      <strong style="color:#a0520d;">3. Meilleure vente</strong> de la même catégorie.
    </div>
    Les produits déjà achetés par le client (ou déjà dans sa commande) sont exclus, et seuls les articles
    <strong>en stock</strong> sont proposés. Si le client clique puis commande sous 7 jours, la vente est
    comptabilisée dans « CA généré » ci-dessous — c'est votre retour sur investissement, automatique et sans effort.
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px,1fr)); gap:12px; margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$upsell_stats.total_sent|default:0}</div>
      <div class="neria-kpi__label">Suggestions</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$upsell_stats.total_clicked|default:0}</div>
      <div class="neria-kpi__label">Clics</div>
      <div class="neria-kpi__rate">{$upsell_stats.ctr|default:0}%</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$upsell_stats.total_converted|default:0}</div>
      <div class="neria-kpi__label">Conversions</div>
      <div class="neria-kpi__rate">{$upsell_stats.conv_rate|default:0}%</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$upsell_stats.total_revenue|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">CA généré</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$upsell_stats.avg_order|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">Panier moyen</div>
    </div>
  </div>

  <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:28px;">
    <span style="padding:4px 10px; background:#f0f8f4; color:#1a7a40; border-radius:20px; font-size:11px; font-weight:600;">
      ✦ Accessoire : {$upsell_stats.cnt_accessory|default:0}
    </span>
    <span style="padding:4px 10px; background:#f0f4f8; color:#2563a8; border-radius:20px; font-size:11px; font-weight:600;">
      ✦ Co-achat : {$upsell_stats.cnt_co_purchase|default:0}
    </span>
    <span style="padding:4px 10px; background:#faf6f0; color:#a0520d; border-radius:20px; font-size:11px; font-weight:600;">
      ✦ Catégorie : {$upsell_stats.cnt_bestseller|default:0}
    </span>
  </div>

  <div style="padding:18px; background:var(--neria-bg); border-radius:6px; margin-bottom:28px;">
    <p style="font-size:12px; font-weight:700; color:var(--neria-text); margin:0 0 6px 0; text-transform:uppercase; letter-spacing:.06em;">
      Prévisualiser l'email que recevra votre client
    </p>
    <p style="font-size:12px; color:var(--neria-muted); margin:0 0 14px 0; line-height:1.6;">
      <strong>Mode d'emploi :</strong> saisissez le numéro ou la référence d'une commande livrée, puis cliquez sur
      « Prévisualiser ». Vous verrez le <strong>bloc exact</strong> qui sera inséré dans l'email de suivi de ce client —
      image, prix et bouton compris. <strong>Aucun email n'est envoyé</strong> : c'est une simulation pour vérifier la
      pertinence de la suggestion avant qu'elle ne parte réellement.
    </p>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <input type="text" id="neria-upsell-order-id" placeholder="N° ou réf. (ex : 12 ou NER-000123)"
             autocomplete="off"
             style="padding:8px 12px; border:1px solid var(--neria-border); border-radius:4px;
                    font-size:13px; width:240px; background:var(--neria-container);">
      <button type="button" class="neria-btn neria-btn--primary" onclick="neriaPreviewUpsell()">
        Prévisualiser
      </button>
    </div>
    <div id="neria-upsell-preview" style="margin-top:18px; display:none;">
      <div style="font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:var(--neria-muted); margin-bottom:8px;">
        ↓ Aperçu tel que votre client le verra dans son email
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
          <th>Client</th>
          <th>Produit suggéré</th>
          <th>Niveau</th>
          <th>Envoyé le</th>
          <th>Cliqué</th>
          <th>Converti</th>
          <th>Montant</th>
        </tr>
      </thead>
      <tbody>
        {foreach $upsell_log as $urow}
        <tr>
          <td>
            <span style="font-size:13px; font-weight:600;">{$urow.firstname|escape:'html'} {$urow.lastname|escape:'html'}</span><br>
            <span style="font-size:11px; color:var(--neria-muted);">{$urow.email|escape:'html'}</span><br>
            <span style="font-size:10px; color:var(--neria-muted);">Cde #{$urow.order_ref|escape:'html'}</span>
          </td>
          <td>
            <div style="display:flex; align-items:center; gap:10px;">
              {if $urow.thumb_url}<img src="{$urow.thumb_url|escape:'html'}" width="36" style="border-radius:3px; display:block; flex-shrink:0;">{/if}
              <a href="{$urow.product_url|escape:'html'}" target="_blank"
                 style="font-size:13px; color:var(--neria-text); text-decoration:none;">{$urow.product_name|escape:'html'}</a>
            </div>
          </td>
          <td>
            {if $urow.tier == 'accessory'}<span style="padding:3px 8px; background:#f0f8f4; color:#1a7a40; border-radius:20px; font-size:10px; font-weight:700;">Accessoire</span>
            {elseif $urow.tier == 'co_purchase'}<span style="padding:3px 8px; background:#f0f4f8; color:#2563a8; border-radius:20px; font-size:10px; font-weight:700;">Co-achat</span>
            {else}<span style="padding:3px 8px; background:#faf6f0; color:#a0520d; border-radius:20px; font-size:10px; font-weight:700;">Catégorie</span>{/if}
          </td>
          <td style="font-size:12px; color:var(--neria-muted); white-space:nowrap;">{$urow.sent_at|date_format:'%d/%m/%Y'}</td>
          <td style="text-align:center;">
            {if $urow.clicked_at}<span style="color:#1a7a40; font-weight:700; font-size:15px;" title="{$urow.clicked_at|escape:'html'}">✓</span>
            {else}<span style="color:var(--neria-muted);">—</span>{/if}
          </td>
          <td style="text-align:center;">
            {if $urow.id_order_converted}<span style="color:#1a7a40; font-weight:700; font-size:15px;" title="Cde #{$urow.id_order_converted}">✓</span>
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
    Aucune suggestion envoyée pour l'instant. Les emails <em>post_purchase_review</em> (J+14 après livraison) déclencheront les suggestions automatiquement.
  </p>
  {/if}
</div>

{* ── Rappel fin de vie produit ─────────────────────────────── *}
{* ── Score de propension à l'achat ─────────────────────────── *}
<div class="neria-section" id="neria-propensity-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Fenêtres d'achat optimales 🎯</h2>
      <p class="neria-text" style="margin:0; font-size:13px; opacity:.7;">
        Clients dont le score de propension dépasse {$propensity_threshold}/100 en ce moment — moment idéal pour leur envoyer une offre ciblée.
      </p>
    </div>
    <form method="post" style="margin:0;">
      <input type="hidden" name="neria_action" value="propensity_toggle" />
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $propensity_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $propensity_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1; border:1px solid #e8d5b0; border-radius:6px; padding:16px 20px; margin-bottom:24px; font-size:13px; line-height:1.7; color:#4a3f35;">
    <div style="font-weight:700; margin-bottom:8px; font-size:12px; letter-spacing:.06em; text-transform:uppercase; opacity:.6;">Comment ça fonctionne</div>
    Neria calcule chaque nuit un score de propension à l'achat (0–100) pour chaque client ayant déjà commandé, selon <strong>4 facteurs</strong> :
    <ul style="margin:10px 0 10px 18px; padding:0;">
      <li><strong>Récence (0–40 pts)</strong> — Score plein si achat &lt; 7 jours, nul à 90 jours, décroissance linéaire entre les deux.</li>
      <li><strong>Fréquence (0–25 pts)</strong> — Nombre de commandes par mois sur l'historique complet.</li>
      <li><strong>Engagement email (0–25 pts)</strong> — Ouvertures et clics sur les 30 derniers jours (1 pt/ouverture, 2 pts/clic).</li>
      <li><strong>Saisonnalité personnelle (0–10 pts)</strong> — Ce client achète-t-il historiquement plus durant ce mois-ci ?</li>
    </ul>
    Les clients atteignant <strong>{$propensity_threshold}/100</strong> apparaissent ici comme étant en <em>fenêtre d'achat optimale</em>. Cliquez sur <strong>Envoyer offre</strong> pour accéder directement au formulaire d'envoi manuel pré-rempli avec ce client.
  </div>

  {if $propensity_alerts}

    <table style="width:100%; border-collapse:collapse; font-size:13px;">
      <thead>
        <tr style="border-bottom:2px solid rgba(0,0,0,.08);">
          <th style="text-align:left; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">CLIENT</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">SCORE</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">RÉCENCE</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">FRÉQUENCE</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">ENGAGEMENT</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">SAISONNALITÉ</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">DERNIER ACHAT</th>
          <th style="text-align:center; padding:10px 16px; font-weight:600; opacity:.55; letter-spacing:.04em; font-size:11px;">ACTION</th>
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
              Envoyer offre
            </a>
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>

  {else}
    <div style="text-align:center; padding:32px 20px; opacity:.5;">
      <div style="font-size:36px; margin-bottom:12px;">🎯</div>
      <p style="font-size:13px; margin:0;">Aucun client en fenêtre d'achat optimale pour le moment.<br>
      Les scores sont recalculés chaque nuit par le cron.</p>
    </div>
  {/if}
</div>

<div class="neria-section" id="neria-purchase-window-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Fenêtre d'achat individuelle ⏰</h2>
      <p class="neria-text" style="margin:0; font-size:13px; opacity:.7;">
        Neria détecte l'heure naturelle d'achat de chaque client et programme automatiquement
        les emails comportementaux pour arriver dans cette fenêtre — pas une heure globale, une heure par client.
      </p>
    </div>
    <form method="post" style="margin:0;">
      <input type="hidden" name="neria_action" value="purchase_window_toggle" />
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $purchase_window_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $purchase_window_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1; border:1px solid #e8d5b0; border-radius:6px; padding:16px 20px; margin-bottom:24px; font-size:13px; line-height:1.7; color:#4a3f35;">
    <div style="font-weight:700; margin-bottom:8px; font-size:12px; letter-spacing:.06em; text-transform:uppercase; opacity:.6;">Comment ça fonctionne</div>
    Neria analyse l'historique des commandes validées de chaque client pour détecter l'heure à laquelle il achète naturellement.
    À partir de <strong>2 achats à la même heure</strong>, un pattern est considéré comme fiable.
    Les emails comportementaux (anniversaire, win-back, relance panier…) ne sont plus envoyés immédiatement
    mais <strong>mis en file d'attente</strong> et délivrés à l'heure préférée du client — le même jour si possible, sinon le lendemain.
    <br><br>
    Les clients sans fenêtre détectée (premier achat ou achats trop dispersés) reçoivent toujours leurs emails immédiatement.
    Cette feature est <strong>différente et complémentaire</strong> de la tranche horaire globale par langue.
  </div>

  {* KPIs *}
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:16px; margin-bottom:24px;">
    <div style="padding:18px; background:var(--neria-bg); border:1px solid var(--neria-border); border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:var(--neria-text);">{$purchase_window_stats.pending}</div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">Emails en attente</div>
    </div>
    <div style="padding:18px; background:var(--neria-bg); border:1px solid var(--neria-border); border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:#1a7a40;">{$purchase_window_stats.sent_30d}</div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">Envoyés (30 j)</div>
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
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">Délai moyen</div>
    </div>
    <div style="padding:18px; background:var(--neria-bg); border:1px solid var(--neria-border); border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:#5b3fa8;">{$purchase_window_stats.coverage_pct}%</div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">Clients avec fenêtre</div>
    </div>
    <div style="padding:18px; background:var(--neria-bg); border:1px solid var(--neria-border); border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:var(--neria-text);">
        {if $purchase_window_stats.peak_hour !== null}{$purchase_window_stats.peak_hour}h{else}—{/if}
      </div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">Heure de pointe</div>
    </div>
    {if $purchase_window_stats.failed_30d > 0}
    <div style="padding:18px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; text-align:center;">
      <div style="font-size:28px; font-weight:800; color:#dc2626;">{$purchase_window_stats.failed_30d}</div>
      <div style="font-size:11px; opacity:.55; margin-top:4px; letter-spacing:.04em; text-transform:uppercase;">Échecs (30 j)</div>
    </div>
    {/if}
  </div>

  {if $purchase_window_stats.coverage_pct === 0 && $purchase_window_stats.sent_30d === 0}
    <div style="text-align:center; padding:32px 20px; opacity:.5;">
      <div style="font-size:36px; margin-bottom:12px;">⏰</div>
      <p style="font-size:13px; margin:0;">
        Aucune fenêtre détectée pour l'instant.<br>
        Les patterns apparaissent dès qu'un client a commandé au moins 2 fois à la même heure.
        Les statistiques se rempliront après le premier cron nocturne.
      </p>
    </div>
  {/if}
</div>

<div class="neria-section" id="neria-lifespan-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Rappel fin de vie produit ⏳</h2>
      <p class="neria-text" style="margin:0; font-size:13px; opacity:.7;">
        Définissez la durée de vie estimée de vos produits consommables. Neria envoie automatiquement un email de rappel X jours avant la date estimée d'épuisement.
      </p>
    </div>
    <form method="post" style="margin:0;">
      <input type="hidden" name="neria_action" value="lifespan_toggle" />
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if $lifespan_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if $lifespan_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Associez une <strong>durée de vie en jours</strong> à vos produits consommables (crèmes, capsules, filtres, compléments…). Neria calcule la date d'épuisement estimée à partir de la date d'achat et envoie automatiquement un email de rappel <strong>X jours avant</strong> cette date — au bon moment pour déclencher un réachat.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Conseil :</strong> anticipez de 7 à 14 jours pour laisser le temps de la livraison. Si un client a déjà racheté le produit entre-temps, l'email est annulé automatiquement.
    </div>
  </div>

  {* Formulaire d'ajout *}
  <form method="post" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:24px;">
    <input type="hidden" name="neria_action" value="lifespan_add" />
    <div style="display:flex; flex-direction:column; gap:4px;">
      <label style="font-size:12px; opacity:.7;">ID Produit</label>
      <input type="text" name="lifespan_id_product" required placeholder="ex: 42"
             pattern="[0-9]+" class="neria-input" style="width:120px;" />
    </div>
    <div style="display:flex; flex-direction:column; gap:4px;">
      <label style="font-size:12px; opacity:.7;">Durée de vie (jours)</label>
      <input type="number" name="lifespan_days" min="1" required placeholder="ex: 30"
             class="neria-input" style="width:150px;" />
    </div>
    <div style="display:flex; flex-direction:column; gap:4px;">
      <label style="font-size:12px; opacity:.7;">Alerter X jours avant</label>
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
        Ajouter
      </button>
    </div>
  </form>

  {* Liste des produits configurés *}
  {if $lifespan_products}
  <table style="width:100%; border-collapse:collapse; font-size:13px;">
    <thead>
      <tr style="border-bottom:1px solid rgba(255,255,255,.15); opacity:.6;">
        <th style="text-align:left; padding:6px 12px;">Produit</th>
        <th style="text-align:left; padding:6px 12px;">Référence</th>
        <th style="text-align:center; padding:6px 12px;">Durée vie</th>
        <th style="text-align:center; padding:6px 12px;">Alerte avant</th>
        <th style="text-align:center; padding:6px 12px;">Action</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$lifespan_products item=lp}
      <tr style="border-bottom:1px solid rgba(255,255,255,.07);">
        <td style="padding:8px 12px;">{$lp.product_name|escape:'html':'UTF-8'|default:'—'}</td>
        <td style="padding:8px 12px; opacity:.6;">{$lp.reference|escape:'html':'UTF-8'|default:'—'}</td>
        <td style="padding:8px 12px; text-align:center;">{$lp.lifespan_days} j</td>
        <td style="padding:8px 12px; text-align:center;">{$lp.alert_days} j</td>
        <td style="padding:8px 12px; text-align:center;">
          <form method="post" style="margin:0;" onsubmit="return confirm('Supprimer ce produit ?')">
            <input type="hidden" name="neria_action" value="lifespan_delete" />
            <input type="hidden" name="lifespan_id" value="{$lp.id_lifespan}" />
            <button type="submit" style="background:none; border:none; cursor:pointer; color:#e74c3c; font-size:16px;">✕</button>
          </form>
        </td>
      </tr>
      {/foreach}
    </tbody>
  </table>
  {else}
  <p class="neria-text" style="opacity:.5; font-size:13px; text-align:center; padding:20px 0;">
    Aucun produit configuré. Ajoutez un produit ci-dessus pour commencer.
  </p>
  {/if}
</div>

{* ── Réconciliation post-remboursement ─────────────────────── *}
<div class="neria-section" id="neria-reconciliation-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Réconciliation post-remboursement ✦</h2>
      <p class="neria-section__subtitle" style="margin:0;">Séquence automatique de 3 emails pour reconquérir les clients remboursés.</p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-reconciliation-section" style="display:inline;">
      <input type="hidden" name="neria_action" value="reconciliation_toggle">
      <button type="submit" class="neria-btn" style="font-size:12px; padding:6px 14px;
                     background:{if $reconciliation_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; cursor:pointer;">
        {if $reconciliation_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:20px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Dès qu'un remboursement est enregistré dans PrestaShop, Neria planifie une séquence de 3 emails discrets : <strong>J+1</strong> (confirmation personnelle), <strong>J+3</strong> (attention exclusive), <strong>J+7</strong> (invitation douce au retour). La séquence est automatiquement annulée si le client passe une nouvelle commande entre-temps.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Potentiel :</strong> un client remboursé qui reçoit ce traitement a statistiquement plus de chances de racheter qu'un client sans problème — la résolution crée un lien émotionnel plus fort qu'une transaction sans accroc.
    </div>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:16px; margin-bottom:24px;">
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.total|default:0}</div>
      <div class="neria-kpi-card__label">SÉQUENCES PLANIFIÉES</div>
    </div>
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.step1_sent|default:0}</div>
      <div class="neria-kpi-card__label">J+1 ENVOYÉS</div>
    </div>
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.step2_sent|default:0}</div>
      <div class="neria-kpi-card__label">J+3 ENVOYÉS</div>
    </div>
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.step3_sent|default:0}</div>
      <div class="neria-kpi-card__label">J+7 ENVOYÉS</div>
    </div>
    <div class="neria-kpi-card">
      <div class="neria-kpi-card__value">{$reconciliation_stats.cancelled|default:0}</div>
      <div class="neria-kpi-card__label" style="color:#1a7a40;">ANNULÉES (rachat)</div>
    </div>
  </div>
</div>

{* ── Devis B2B — Relances automatiques ────────────────────── *}
<div class="neria-section" id="neria-quote-section">
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Relances Devis B2B ✦</h2>
      <p class="neria-section__desc" style="margin:0;">
        Séquence automatique de 3 emails pour les devis B2B : rappel 48h avant expiration, rappel le jour J, offre de prolongation après expiration.
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
        {if $quote_reminders_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Ajoutez vos devis ci-dessous (référence, client, montant, date d'expiration). Neria envoie automatiquement
    <strong>3 emails de relance</strong> : le J-2, le jour J, puis une offre de prolongation le lendemain de l'expiration.
    Marquez un devis comme <strong>Gagné</strong> dès que le client confirme — cela arrête la séquence et comptabilise la vente.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Potentiel :</strong> 20 à 40 % des devis expirés le sont par oubli, pas par désintérêt. Un rappel bien tourné les récupère sans effort.
    </div>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px,1fr)); gap:12px; margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$quote_stats.total_quotes|default:0}</div>
      <div class="neria-kpi__label">Devis suivis</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$quote_stats.quotes_active|default:0}</div>
      <div class="neria-kpi__label">En cours</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$quote_stats.quotes_won|default:0}</div>
      <div class="neria-kpi__label">Gagnés</div>
      <div class="neria-kpi__rate">{$quote_stats.win_rate|default:0} %</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$quote_stats.revenue_won|default:0|string_format:"%.2f"} {$currency_symbol}</div>
      <div class="neria-kpi__label">CA récupéré</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$quote_stats.quotes_lost|default:0}</div>
      <div class="neria-kpi__label">Perdus / Expirés</div>
    </div>
  </div>

  {* Formulaire d'ajout *}
  <div style="padding:18px; background:var(--neria-bg); border-radius:6px; margin-bottom:24px;">
    <p style="font-size:12px; font-weight:700; color:var(--neria-text); margin:0 0 8px 0; text-transform:uppercase; letter-spacing:.06em;">
      Ajouter un devis à suivre
    </p>
    <p style="font-size:12px; color:var(--neria-muted); line-height:1.7; margin:0 0 16px 0;">
      <strong style="color:var(--neria-text);">Saisie manuelle :</strong>
      PrestaShop ne génère pas de devis nativement. Enregistrez ici les devis que vous établissez
      par ailleurs (logiciel de gestion, tableur, module tiers) et que vous souhaitez faire relancer
      automatiquement. Indiquez le client par son <strong>ID</strong> ou son <strong>email</strong> —
      il doit déjà exister dans votre boutique.
    </p>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-quote-section"
          style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
      <input type="hidden" name="neria_action" value="quote_add">
      <input type="hidden" name="neria_tab"    value="stats">
      <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:var(--neria-muted);">ID ou email client</label>
        <input type="text" name="quote_id_customer" placeholder="Ex : 42 ou client@email.com" required
               style="padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px; font-size:13px; width:220px; background:var(--neria-container);">
      </div>
      <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:var(--neria-muted);">Référence devis</label>
        <input type="text" name="quote_ref" placeholder="Ex : DEVIS-2026-042" required
               style="padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px; font-size:13px; width:180px; background:var(--neria-container);">
      </div>
      <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:var(--neria-muted);">Montant HT (€)</label>
        <input type="text" name="quote_total" placeholder="Ex : 1250.00"
               style="padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px; font-size:13px; width:120px; background:var(--neria-container);">
      </div>
      <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:var(--neria-muted);">Date d'expiration</label>
        <input type="date" name="quote_expiry_date" required
               style="padding:7px 10px; border:1px solid var(--neria-border); border-radius:4px; font-size:13px; background:var(--neria-container);">
      </div>
      <button type="submit" class="neria-btn neria-btn--primary" style="align-self:flex-end;">
        Ajouter
      </button>
    </form>
  </div>

  {* Liste des devis *}
  {if $quote_list}
  <div style="overflow-x:auto;">
    <table class="neria-table" style="min-width:700px;">
      <thead>
        <tr>
          <th>Référence</th>
          <th>Client</th>
          <th>Montant</th>
          <th>Expiration</th>
          <th>Statut</th>
          <th>Rappels</th>
          <th>Actions</th>
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
            {if $q.status === 'won'}<span style="color:#1a7a40; font-weight:700;">✓ Gagné</span>
            {elseif $q.status === 'lost'}<span style="color:#c0392b; font-weight:700;">✗ Perdu</span>
            {elseif $q.status === 'expired'}<span style="color:#a0520d; font-weight:600;">Expiré</span>
            {elseif $q.status === 'extended'}<span style="color:#2563a8; font-weight:600;">Prolongé</span>
            {else}<span style="color:#1a7a40;">En cours</span>{/if}
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
              <button type="submit" style="padding:4px 8px; background:#1a7a40; color:#fff; border:none; border-radius:3px; font-size:11px; cursor:pointer; margin-right:4px;">Gagné</button>
            </form>
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-quote-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="quote_mark_lost">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="id_quote"     value="{$q.id_quote}">
              <button type="submit" style="padding:4px 8px; background:#c0392b; color:#fff; border:none; border-radius:3px; font-size:11px; cursor:pointer; margin-right:4px;">Perdu</button>
            </form>
            {/if}
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-quote-section" style="display:inline;"
                  onsubmit="return confirm('Supprimer ce devis ?');">
              <input type="hidden" name="neria_action" value="quote_delete">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="id_quote"     value="{$q.id_quote}">
              <button type="submit" style="padding:4px 8px; background:var(--neria-border); color:var(--neria-text); border:none; border-radius:3px; font-size:11px; cursor:pointer;">Supprimer</button>
            </form>
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p style="font-size:13px; color:var(--neria-muted); margin:0;">
    Aucun devis enregistré. Utilisez le formulaire ci-dessus pour ajouter votre premier devis à suivre.
  </p>
  {/if}
</div>

{* ── Complétion de collection ───────────────────────────────── *}
<div class="neria-section" id="neria-collection-section">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Complétion de collection ◎</h2>
      <p class="neria-text" style="margin:0;font-size:13px;opacity:.7;">
        Détecte les clients à une pièce de compléter une collection et leur envoie un email personnalisé.
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="collection_completion_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if isset($collection_completion_enabled) && $collection_completion_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if isset($collection_completion_enabled) && $collection_completion_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>


  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Définissez vos collections ci-dessous (un ensemble de 2 à 6 produits liés). Chaque jour, Neria analyse les commandes de vos clients et détecte ceux qui ont acheté <strong>toutes les pièces sauf une</strong>. Un email "votre collection est presque complète" leur est envoyé automatiquement avec le produit manquant mis en avant.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Déduplication :</strong> un seul email est envoyé par client et par collection, même si le client continue d'acheter d'autres pièces par la suite. L'email est personnalisé avec la photo et le prix du produit manquant.
    </div>
  </div>

  {* KPIs *}
  {if isset($collection_stats)}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$collection_stats.total|default:0}</div>
      <div class="neria-kpi__label">Collections</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$collection_stats.active|default:0}</div>
      <div class="neria-kpi__label">Actives</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$collection_stats.sent|default:0}</div>
      <div class="neria-kpi__label">Emails envoyés</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$collection_stats.sentLast30|default:0}</div>
      <div class="neria-kpi__label">30 derniers jours</div>
    </div>
  </div>
  {/if}

  {* Formulaire d'ajout *}
  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-collection-section" style="margin-bottom:24px;">
    <input type="hidden" name="neria_action" value="collection_add">
    <input type="hidden" name="neria_tab"    value="stats">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <div style="flex:1;min-width:200px;">
        <label class="neria-label">Nom de la collection</label>
        <input type="text" name="collection_name" class="neria-input" placeholder="ex : Trio soin visage" style="width:100%;">
      </div>
      <div style="flex:2;min-width:260px;">
        <label class="neria-label">IDs produits (séparés par des virgules)</label>
        <input type="text" name="collection_product_ids" class="neria-input" placeholder="ex : 12, 47, 83" style="width:100%;">
      </div>
      <div>
        <button type="submit" class="neria-btn neria-btn--primary">Ajouter</button>
      </div>
    </div>
  </form>

  {* Liste des collections *}
  {if isset($collections) && $collections|@count > 0}
  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>Nom</th>
          <th>Produits</th>
          <th style="text-align:center;">Pièces</th>
          <th style="text-align:center;">Statut</th>
          <th style="text-align:center;">Actions</th>
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
                {if $col.active}● Actif{else}○ Inactif{/if}
              </button>
            </form>
          </td>
          <td style="text-align:center;">
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-collection-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="collection_delete">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="collection_id" value="{$col.id_neria_collection}">
              <button type="submit" class="neria-btn neria-btn--danger neria-btn--sm"
                      onclick="return confirm('Supprimer cette collection ?')">✕</button>
            </form>
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p class="neria-empty-state__text" style="font-size:13px;color:#7a6a5a;margin:0;">
    Aucune collection définie. Ajoutez votre première collection ci-dessus.
  </p>
  {/if}
</div>

{* ── Complétez votre look ───────────────────────────────────── *}
<div class="neria-section" id="neria-look-section">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Complétez votre look ✦</h2>
      <p class="neria-text" style="margin:0;font-size:13px;opacity:.7;">
        48h après la livraison, suggère 2-3 produits complémentaires selon les règles que vous définissez par catégorie.
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="look_completion_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if isset($look_completion_enabled) && $look_completion_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if isset($look_completion_enabled) && $look_completion_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;margin-bottom:24px;font-size:13px;line-height:1.75;color:#4a3f35;">
    <div style="font-weight:700;margin-bottom:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</div>
    Définissez des règles d'association : pour chaque catégorie de votre boutique, indiquez 2 à 3 produits complémentaires à suggérer. Le cron détecte chaque jour les commandes passées au statut <strong>Livré</strong> il y a 48h et envoie automatiquement l'email avec les produits définis dans la règle correspondante.
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0;">
      <strong>Moment clé :</strong> l'email arrive quand le client a le produit entre les mains et est dans un état émotionnel positif — le meilleur moment pour une suggestion complémentaire. Un seul email par commande (déduplication automatique).
    </div>
  </div>

  {* KPIs *}
  {if isset($look_stats)}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:28px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$look_stats.rules|default:0}</div>
      <div class="neria-kpi__label">Règles</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$look_stats.active|default:0}</div>
      <div class="neria-kpi__label">Actives</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$look_stats.sent|default:0}</div>
      <div class="neria-kpi__label">Emails envoyés</div>
    </div>
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$look_stats.sent30|default:0}</div>
      <div class="neria-kpi__label">30 derniers jours</div>
    </div>
  </div>
  {/if}

  {* Formulaire d'ajout *}
  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-look-section" style="margin-bottom:24px;">
    <input type="hidden" name="neria_action" value="look_rule_add">
    <input type="hidden" name="neria_tab"    value="stats">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <div style="min-width:200px;">
        <label class="neria-label">Catégorie déclencheur</label>
        <select name="look_category_id" class="neria-select" style="width:100%;">
          <option value="">— Choisir une catégorie —</option>
          {if isset($look_categories)}
            {foreach $look_categories as $cat}
              <option value="{$cat.id_category}">{$cat.name}</option>
            {/foreach}
          {/if}
        </select>
      </div>
      <div style="flex:1;min-width:260px;">
        <label class="neria-label">IDs produits suggérés (2-3, séparés par des virgules)</label>
        <input type="text" name="look_product_ids" class="neria-input" placeholder="ex : 12, 47, 83" style="width:100%;">
      </div>
      <div>
        <button type="submit" class="neria-btn neria-btn--primary">Ajouter</button>
      </div>
    </div>
  </form>

  {* Liste des règles *}
  {if isset($look_rules) && $look_rules|@count > 0}
  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>Catégorie</th>
          <th>Produits suggérés</th>
          <th style="text-align:center;">Statut</th>
          <th style="text-align:center;">Actions</th>
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
                {if $rule.active}● Actif{else}○ Inactif{/if}
              </button>
            </form>
          </td>
          <td style="text-align:center;">
            <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}#neria-look-section" style="display:inline;">
              <input type="hidden" name="neria_action" value="look_rule_delete">
              <input type="hidden" name="neria_tab"    value="stats">
              <input type="hidden" name="look_rule_id" value="{$rule.id_neria_look_rule}">
              <button type="submit" class="neria-btn neria-btn--danger neria-btn--sm"
                      onclick="return confirm('Supprimer cette règle ?')">✕</button>
            </form>
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p style="font-size:13px;color:#7a6a5a;margin:0;">
    Aucune règle définie. Ajoutez votre première association catégorie → produits ci-dessus.
  </p>
  {/if}
</div>

{* ── Liste d'attente produits ───────────────────────────────── *}
<div class="neria-section" id="neria-waitlist-section">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Liste d'attente produits 🔔</h2>
      <p class="neria-text" style="margin:0;font-size:13px;opacity:.7;">
        Quand un produit en rupture revient en stock, Neria notifie automatiquement les clients inscrits.
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="waitlist_toggle">
      <input type="hidden" name="neria_tab"    value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if isset($waitlist_enabled) && $waitlist_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if isset($waitlist_enabled) && $waitlist_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:20px 24px;font-size:13px;line-height:1.75;color:#4a3f35;margin-bottom:24px;">
    <p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</p>
    Sur chaque fiche produit en rupture de stock, un bouton <strong>« 🔔 M'avertir quand disponible »</strong> apparaît. Le client s'inscrit en un clic. Dès que le stock remonte, Neria envoie automatiquement un email unique avec un ton exclusif : <em>« Vous avez attendu X jours. Nous ne l'avons pas oublié. »</em>
    <p style="margin:12px 0 0;border-top:1px solid #e8d5b0;padding-top:10px;font-size:12px;opacity:.75;">
      <strong>Réservation temporelle :</strong> l'email mentionne une durée de priorité — psychologiquement puissant, sans bloquer le stock réellement. Un seul email par client et par produit.
    </p>
  </div>

  <form method="post" action="{$neria_ajax_url|escape:'html'}" style="margin-bottom:24px;">
    <input type="hidden" name="neria_action" value="waitlist_reservation_save">
    <input type="hidden" name="neria_tab"    value="stats">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <label style="font-size:13px;font-weight:600;color:#4a3f35;white-space:nowrap;">
        ⏳ Durée de réservation prioritaire :
      </label>
      <input type="number" name="waitlist_reservation_hours"
             value="{$waitlist_reservation_hours|intval}"
             min="1" max="72"
             style="width:70px;padding:7px 10px;border:1px solid #e8d5b0;border-radius:4px;
                    font-size:13px;font-weight:600;color:#1a1a1a;text-align:center;">
      <span style="font-size:13px;color:#4a3f35;">heures</span>
      <button type="submit"
              style="padding:8px 16px;background:#1a1a1a;color:#fff;border:none;border-radius:4px;
                     font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.04em;">
        Enregistrer
      </button>
      <span style="font-size:12px;color:#7a6a5a;opacity:.8;">Entre 1 et 72 heures</span>
    </div>
  </form>

  {if isset($waitlist_stats)}
  <div class="neria-kpi-row" style="margin-bottom:24px;">
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$waitlist_stats.subscribers|default:0}</div>
      <div class="neria-kpi__label">EN ATTENTE</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$waitlist_stats.products|default:0}</div>
      <div class="neria-kpi__label">PRODUITS SURVEILLÉS</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$waitlist_stats.notified|default:0}</div>
      <div class="neria-kpi__label">NOTIFIÉS (TOTAL)</div>
    </div>
    <div class="neria-kpi" style="border:2px solid var(--neria-accent);">
      <div class="neria-kpi__value">{$waitlist_stats.notified30|default:0}</div>
      <div class="neria-kpi__label">30 DERNIERS JOURS</div>
    </div>
  </div>
  {/if}

  {if isset($waitlist_top_products) && $waitlist_top_products|@count > 0}
  <div class="neria-table-wrap">
    <table class="neria-table">
      <thead>
        <tr>
          <th>Produit</th>
          <th style="text-align:center;">Inscrits</th>
          <th style="text-align:center;">Attente max</th>
        </tr>
      </thead>
      <tbody>
        {foreach $waitlist_top_products as $wp}
        <tr>
          <td style="font-weight:600;">{$wp.product_name|default:'#'|cat:$wp.id_product|escape:'html'}</td>
          <td style="text-align:center;">{$wp.nb}</td>
          <td style="text-align:center;">{$wp.max_wait_days} j</td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
  {else}
  <p style="font-size:13px;color:#7a6a5a;margin:0;">
    Aucun client en liste d'attente pour l'instant.
  </p>
  {/if}
</div>

{* ── Panier fantôme récurrent ────────────────────────────────── *}
<div class="neria-section" id="neria-ghost-cart-section">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
      <h2 class="neria-section__title" style="margin:0 0 4px 0;">Panier fantôme récurrent 👻</h2>
      <p class="neria-text" style="margin:0;font-size:13px;opacity:.7;">
        Détecte les clients qui ajoutent le même produit 3 fois ou plus sans jamais acheter, et leur envoie un email d'ouverture de dialogue.
      </p>
    </div>
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
      <input type="hidden" name="neria_action" value="ghost_cart_toggle">
      <input type="hidden" name="neria_tab" value="stats">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                     background:{if isset($ghost_cart_enabled) && $ghost_cart_enabled}#1a7a40{else}#c0392b{/if};
                     color:#fff; border:none; border-radius:4px; font-size:12px;
                     font-weight:700; cursor:pointer; letter-spacing:.04em;">
        {if isset($ghost_cart_enabled) && $ghost_cart_enabled}● Actif — Désactiver{else}○ Inactif — Activer{/if}
      </button>
    </form>
  </div>

  <div style="background:#f9f6f1;border:1px solid #e8d5b0;border-radius:6px;padding:16px 20px;">
    <p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6;">Comment ça fonctionne</p>
    Chaque nuit, Neria analyse les paniers des 60 derniers jours. Si un client a ajouté le même produit dans <strong>3 paniers distincts</strong> sans jamais l'acheter, il reçoit un email unique au ton humain — <em>« Nous avons remarqué que cette pièce retient particulièrement votre attention. »</em>
    <p style="margin:12px 0 0;border-top:1px solid #e8d5b0;padding-top:10px;font-size:12px;opacity:.75;">
      <strong>Pas de réduction proposée</strong> — une ouverture de dialogue. Le client se sent compris, pas ciblé. Un seul email par produit et par client.
    </p>
  </div>
</div>

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
        showMsg('Commande introuvable — vérifiez le numéro ou la référence saisie.', true);
      } else if (d.status === 'error') {
        showMsg('Une erreur est survenue pendant la simulation.', true);
      } else {
        showMsg('Commande trouvée, mais aucun produit complémentaire pertinent (accessoire, co-achat ou catégorie) pour ce client.', false);
      }
    })
    .catch(function() {
      showMsg('Impossible de contacter le serveur. Réessayez.', true);
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
  <h2 class="neria-section__title" style="margin:0 0 6px;">Comparatif mensuel ◫</h2>
  <p class="neria-section__desc" style="margin:0 0 20px;">
    {$mc.labels.current|default:''} vs {$mc.labels.previous|default:''} — tous les indicateurs clés en un coup d'œil.
  </p>

  <div class="neria-table-wrap">
    <table class="neria-table" style="min-width:500px;">
      <thead>
        <tr>
          <th>Indicateur</th>
          <th class="neria-table__num">{$mc.labels.previous|default:'Mois préc.'}</th>
          <th class="neria-table__num">{$mc.labels.current|default:'Ce mois'}</th>
          <th class="neria-table__num">Évolution</th>
        </tr>
      </thead>
      <tbody>
        {foreach [
          ['key'=>'sent',       'label'=>'Emails envoyés',      'format'=>'int',   'good_up'=>true],
          ['key'=>'opens',      'label'=>'Ouvertures réelles',  'format'=>'int',   'good_up'=>true],
          ['key'=>'rate_open',  'label'=>'Taux d\'ouverture',   'format'=>'pct',   'good_up'=>true],
          ['key'=>'clicks',     'label'=>'Clics',               'format'=>'int',   'good_up'=>true],
          ['key'=>'rate_click', 'label'=>'Taux de clic',        'format'=>'pct',   'good_up'=>true],
          ['key'=>'unsubs',     'label'=>'Désabonnements',      'format'=>'int',   'good_up'=>false],
          ['key'=>'revenue',    'label'=>'CA attribué',         'format'=>'money', 'good_up'=>true]
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
  <p class="neria-hint" style="margin-top:8px;">Les données du mois en cours sont partielles (jusqu'à aujourd'hui).</p>
</div>
{/if}

{if !isset($stats.global_30) || !$stats.global_30}
  <div class="neria-empty-state">
    <span class="neria-empty-state__icon">◫</span>
    <p>{neria_admin key='stats.empty'}</p>
  </div>
{/if}
