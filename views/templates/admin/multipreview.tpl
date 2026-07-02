{**
 * NERIA — multipreview.tpl
 * Prévisualisation multi-client : iframes chargées via getpreview.php
 *}
{literal}<style>
.neria-mp-card__viewport{cursor:zoom-in;position:relative;}
.neria-mp-card__viewport .neria-mp-frame{pointer-events:none;}
#neria-mp-zoom-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:99999;align-items:center;justify-content:center;padding:30px;}
#neria-mp-zoom-overlay.active{display:flex;}
#neria-mp-zoom-modal{background:#fff;border-radius:10px;width:min(920px,100%);height:95vh;box-shadow:0 8px 32px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden;}
#neria-mp-zoom-header{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--neria-border);flex-shrink:0;}
#neria-mp-zoom-icon{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 6px;border-radius:5px;color:#fff;font-size:11px;font-weight:700;}
#neria-mp-zoom-name{font-size:14px;font-weight:700;color:#1a1a1a;flex-grow:1;}
#neria-mp-zoom-dark-btn{background:#fff;border:1px solid #d4c5a9;border-radius:5px;padding:5px 12px;font-size:11px;cursor:pointer;color:#5c3d1e;margin-right:4px;}
#neria-mp-zoom-dark-btn.active{background:#1a1a1a;color:#fff;border-color:#1a1a1a;}
#neria-mp-zoom-close{background:none;border:none;font-size:20px;line-height:1;cursor:pointer;color:#666;padding:4px 8px;}
#neria-mp-zoom-close:hover{color:#1a1a1a;}
#neria-mp-zoom-frame{flex-grow:1;width:100%;border:none;zoom:1.85;overflow:auto;}

.neria-mp-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;}
.neria-mp-toolbar-btn--on{box-shadow:0 0 0 2px #b8975a inset;}
.neria-mp-card__badge{cursor:pointer;}
.neria-mp-card__issue-detail{display:none;background:#fff8ed;border:1px solid #e8c97a;border-radius:6px;padding:10px 12px;margin-top:8px;font-size:11px;line-height:1.6;color:#5c3d1e;}
.neria-mp-card__issue-detail.active{display:block;}
.neria-mp-card__issue-detail ul{margin:0;padding-left:16px;}

@media print {
  .neria-section, #neria-mp-zoom-overlay, .neria-mp-card__viewport { cursor:default !important; }
  .neria-mp-card__viewport .neria-mp-frame { pointer-events:auto; }
  form, .neria-mp-toolbar, #neria-mp-zoom-overlay { display:none !important; }
  .neria-mp-grid { display:block !important; }
  .neria-mp-card { break-inside:avoid; page-break-inside:avoid; margin-bottom:24px; border:1px solid #ccc; }
}
</style>{/literal}

{* Modal zoom prévisualisation *}
<div id="neria-mp-zoom-overlay">
  <div id="neria-mp-zoom-modal">
    <div id="neria-mp-zoom-header">
      <span id="neria-mp-zoom-icon"></span>
      <span id="neria-mp-zoom-name"></span>
      <button type="button" id="neria-mp-zoom-dark-btn">🌙 Mode sombre</button>
      <button type="button" id="neria-mp-zoom-close">✕</button>
    </div>
    <iframe id="neria-mp-zoom-frame" src="about:blank" sandbox="allow-same-origin" title="Aperçu agrandi"></iframe>
  </div>
</div>

{* ── Formulaire de sélection ────────────────────────────────── *}
<div class="neria-section">
  <form method="post" action="{$smarty.server.REQUEST_URI}" id="mp-form">
    <input type="hidden" name="neria_action" value="multipreview_render">
    <input type="hidden" name="neria_tab"    value="multipreview">

    <div class="neria-trad-selectors" style="align-items:flex-end;">

      <div class="neria-form-group">
        <label class="neria-label" for="mp-template">{neria_admin key='common.template'}</label>
        <select id="mp-template" name="mp_template" class="neria-select">
          {foreach $template_labels as $key => $label}
            <option value="{$key}"
              {if isset($mp_selected_template) && $mp_selected_template === $key}selected{/if}>
              {$label}
            </option>
          {/foreach}
        </select>
      </div>

      <div class="neria-form-group">
        <label class="neria-label" for="mp-lang">{neria_admin key='common.language'}</label>
        <select id="mp-lang" name="mp_lang" class="neria-select">
          {foreach $lang_labels as $code => $name}
            <option value="{$code}"
              {if isset($mp_selected_lang) && $mp_selected_lang === $code}selected{/if}>
              {$lang_flags[$code]|default:''} {$name}
            </option>
          {/foreach}
        </select>
      </div>

      <button type="submit" class="neria-btn neria-btn--primary">
        {neria_admin key='multipreview.render_btn'}
      </button>

    </div>
  </form>
  <p class="neria-section__desc" style="margin-top:8px;">
    {neria_admin key='multipreview.desc'}
  </p>
</div>

{* ── Grille de prévisualisations ────────────────────────────── *}
{if isset($mp_token) && $mp_token}

  <div class="neria-mp-toolbar">
    <button type="button" id="neria-mp-dark-toggle" class="neria-btn neria-btn--primary neria-btn--sm">
      🌙 Simuler le mode sombre (tous les clients)
    </button>
    <button type="button" id="neria-mp-export-btn" class="neria-btn neria-btn--primary neria-btn--sm">
      🖨 Exporter en PDF
    </button>
    {if isset($mp_has_litmus) && $mp_has_litmus}
    <button type="button" id="neria-mp-litmus-btn" class="neria-btn neria-btn--secondary neria-btn--sm"
            data-mp-template="{$mp_selected_template|escape:'html'}" data-mp-lang="{$mp_selected_lang|escape:'html'}">
      🧪 {neria_admin key='multipreview.litmus_btn'}
    </button>
    {/if}
    {if isset($mp_has_eoa) && $mp_has_eoa}
    <button type="button" id="neria-mp-eoa-btn" class="neria-btn neria-btn--secondary neria-btn--sm"
            data-mp-template="{$mp_selected_template|escape:'html'}" data-mp-lang="{$mp_selected_lang|escape:'html'}">
      🧪 {neria_admin key='multipreview.eoa_btn'}
    </button>
    {/if}
  </div>

  {if (isset($mp_has_litmus) && $mp_has_litmus) || (isset($mp_has_eoa) && $mp_has_eoa)}
  <div id="neria-mp-api-status" class="neria-hint" style="display:none;margin-bottom:10px;"></div>
  <div id="neria-mp-api-results" class="neria-mp-api-results" style="margin-bottom:20px;"></div>
  {/if}

  <div class="neria-mp-grid">
    {foreach $mp_clients as $clientId => $ci}
      {assign var="meta" value=$mp_previews_meta[$clientId]|default:[]}

      <div class="neria-mp-card">

        <div class="neria-mp-card__header" style="border-left:3px solid {$ci.color};">
          <span class="neria-mp-card__icon" style="background:{$ci.color};">{$ci.icon}</span>
          <span class="neria-mp-card__name">{$ci.name}</span>
          {if ($meta.issues|default:0) > 0}
            <span class="neria-mp-card__badge neria-mp-issue-toggle" style="background:#f57f17;color:#fff;">
              {$meta.issues} ⚠
            </span>
          {/if}
        </div>

        {if ($meta.detail|default:[])|@count > 0}
          <div class="neria-mp-card__issue-detail">
            <ul>
              {foreach $meta.detail as $d}
                <li>{$d|escape:'html'}</li>
              {/foreach}
            </ul>
          </div>
        {/if}

        <div class="neria-mp-card__viewport"
             data-mp-src="{$mp_preview_base}?client={$clientId|escape:'url'}&amp;token={$mp_token|escape:'url'}"
             data-mp-name="{$ci.name|escape:'html'}"
             data-mp-icon="{$ci.icon|escape:'html'}"
             data-mp-color="{$ci.color|escape:'html'}">
          <iframe
            src="{$mp_preview_base}?client={$clientId|escape:'url'}&amp;token={$mp_token|escape:'url'}"
            class="neria-mp-frame"
            sandbox="allow-same-origin"
            title="{$ci.name}"></iframe>
        </div>

        <p class="neria-mp-card__desc">{$ci.support|escape:'html'}</p>

      </div>
    {/foreach}
  </div>

{else}

  <div class="neria-empty-state">
    <span class="neria-empty-state__icon">◩</span>
    <p>{neria_admin key='multipreview.empty'}</p>
  </div>

{/if}

{* ── Section API (optionnelle) ──────────────────────────────── *}
<div class="neria-section" style="margin-top:8px;">
  <h2 class="neria-section__title">{neria_admin key='multipreview.api_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='multipreview.api_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI}">
    <input type="hidden" name="neria_action" value="save_multipreview_keys">
    <input type="hidden" name="neria_tab"    value="multipreview">

    <div class="neria-form-group" style="max-width:480px; margin-bottom:12px;">
      <label class="neria-label" for="mp-litmus-key">
        Litmus API Key
      </label>
      <input type="password"
             id="mp-litmus-key"
             name="litmus_key"
             class="neria-input"
             placeholder="sk_live_…"
             autocomplete="new-password">
    </div>

    <div class="neria-form-group" style="max-width:480px; margin-bottom:20px;">
      <label class="neria-label" for="mp-eoa-key">
        Email on Acid — account_id:api_password
      </label>
      <input type="password"
             id="mp-eoa-key"
             name="eoa_key"
             class="neria-input"
             placeholder="12345:abc…"
             autocomplete="new-password">
    </div>

    <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
      {neria_admin key='translations.save'}
    </button>
  </form>

  {if isset($mp_has_litmus) && $mp_has_litmus}
    <p style="margin-top:12px; font-size:12px; color:var(--neria-success);">
      ✓ Clé Litmus configurée — {neria_admin key='multipreview.litmus_btn'} disponible après prévisualisation.
    </p>
  {/if}
  {if isset($mp_has_eoa) && $mp_has_eoa}
    <p style="margin-top:8px; font-size:12px; color:var(--neria-success);">
      ✓ Clé Email on Acid configurée — {neria_admin key='multipreview.eoa_btn'} disponible après prévisualisation.
    </p>
  {/if}
</div>

{literal}<script>
document.addEventListener('click', function (e) {
  // Badge d'anomalies : bascule le détail sans ouvrir le zoom
  var badge = e.target.closest('.neria-mp-issue-toggle');
  if (badge) {
    var card = badge.closest('.neria-mp-card');
    var detail = card ? card.querySelector('.neria-mp-card__issue-detail') : null;
    if (detail) { detail.classList.toggle('active'); }
    return;
  }

  var vp = e.target.closest('.neria-mp-card__viewport');
  if (!vp) { return; }

  var overlay = document.getElementById('neria-mp-zoom-overlay');
  var frame   = document.getElementById('neria-mp-zoom-frame');
  var icon    = document.getElementById('neria-mp-zoom-icon');
  var name    = document.getElementById('neria-mp-zoom-name');

  icon.textContent   = vp.getAttribute('data-mp-icon') || '';
  icon.style.background = vp.getAttribute('data-mp-color') || '#1a1a1a';
  name.textContent   = vp.getAttribute('data-mp-name') || '';
  frame.src          = vp.getAttribute('data-mp-src') || 'about:blank';

  overlay.classList.add('active');

  var darkBtn = document.getElementById('neria-mp-zoom-dark-btn');
  darkBtn.classList.toggle('active', neriaMpDarkGlobal);
});

// ── Mode sombre simulé ────────────────────────────────────────
// Approche : filtre CSS invert+hue-rotate, comme le fait l'auto-dark
// de nombreux clients webmail sur des emails sans support dark natif.
var neriaMpDarkGlobal = false;

function neriaApplyDarkSim(doc, on) {
  if (!doc || !doc.body) { return; }
  doc.body.style.filter = on ? 'invert(1) hue-rotate(180deg)' : '';
  var imgs = doc.querySelectorAll('img');
  for (var i = 0; i < imgs.length; i++) {
    imgs[i].style.filter = on ? 'invert(1) hue-rotate(180deg)' : '';
  }
}

document.getElementById('neria-mp-dark-toggle').addEventListener('click', function () {
  neriaMpDarkGlobal = !neriaMpDarkGlobal;
  this.classList.toggle('neria-mp-toolbar-btn--on', neriaMpDarkGlobal);

  var frames = document.querySelectorAll('.neria-mp-frame');
  for (var i = 0; i < frames.length; i++) {
    (function (f) {
      try { neriaApplyDarkSim(f.contentDocument, neriaMpDarkGlobal); } catch (err) {}
    })(frames[i]);
  }

  var zoomFrame = document.getElementById('neria-mp-zoom-frame');
  document.getElementById('neria-mp-zoom-dark-btn').classList.toggle('active', neriaMpDarkGlobal);
  try { neriaApplyDarkSim(zoomFrame.contentDocument, neriaMpDarkGlobal); } catch (err) {}
});

document.getElementById('neria-mp-zoom-dark-btn').addEventListener('click', function () {
  neriaMpDarkGlobal = !neriaMpDarkGlobal;
  this.classList.toggle('active', neriaMpDarkGlobal);
  document.getElementById('neria-mp-dark-toggle').classList.toggle('neria-mp-toolbar-btn--on', neriaMpDarkGlobal);

  var zoomFrame = document.getElementById('neria-mp-zoom-frame');
  try { neriaApplyDarkSim(zoomFrame.contentDocument, neriaMpDarkGlobal); } catch (err) {}
});

// ── Export PDF (impression navigateur) ──────────────────────────
var exportBtn = document.getElementById('neria-mp-export-btn');
if (exportBtn) {
  exportBtn.addEventListener('click', function () { window.print(); });
}

// Bloque les liens à l'intérieur de l'aperçu agrandi sans empêcher le scroll
document.getElementById('neria-mp-zoom-frame').addEventListener('load', function () {
  try {
    var doc = this.contentDocument;
    if (!doc) { return; }
    doc.addEventListener('click', function (e) {
      var link = e.target.closest('a');
      if (link) { e.preventDefault(); }
    }, true);
    if (neriaMpDarkGlobal) { neriaApplyDarkSim(doc, true); }
  } catch (err) { /* cross-origin — ignoré */ }
});

function neriaCloseMpZoom() {
  document.getElementById('neria-mp-zoom-overlay').classList.remove('active');
  document.getElementById('neria-mp-zoom-frame').src = 'about:blank';
}

document.getElementById('neria-mp-zoom-close').addEventListener('click', neriaCloseMpZoom);
document.getElementById('neria-mp-zoom-overlay').addEventListener('click', function (e) {
  if (e.target.id === 'neria-mp-zoom-overlay') { neriaCloseMpZoom(); }
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') { neriaCloseMpZoom(); }
});

// ── Tests tiers Litmus / Email on Acid ───────────────────────────
// URL construite depuis la page courante (token + controller déjà présents),
// nettoyée du neria_action précédent — même pattern que le reste du BO.
function neriaMpAjaxUrl(action, extra) {
  var base = window.location.href.split('#')[0].replace(/&neria_action=[^&]*/g, '');
  return base + (base.indexOf('?') === -1 ? '?' : '&') + 'neria_action=' + action + (extra || '');
}

function neriaMpEscHtml(s) {
  return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function neriaMpRunThirdPartyTest(provider) {
  var btn = document.getElementById('neria-mp-' + provider + '-btn');
  var statusEl = document.getElementById('neria-mp-api-status');
  var resultsEl = document.getElementById('neria-mp-api-results');
  if (!btn || !statusEl || !resultsEl) { return; }

  var tpl  = btn.getAttribute('data-mp-template') || 'order_conf';
  var lang = btn.getAttribute('data-mp-lang') || 'fr';

  btn.disabled = true;
  statusEl.style.display = '';
  statusEl.textContent = '⏳ Envoi vers ' + (provider === 'litmus' ? 'Litmus' : 'Email on Acid') + '…';
  resultsEl.innerHTML = '';

  var submitUrl = neriaMpAjaxUrl('multipreview_submit_' + provider,
    '&mp_template=' + encodeURIComponent(tpl) + '&mp_lang=' + encodeURIComponent(lang));

  fetch(submitUrl, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || d.error || !d.id) {
        statusEl.textContent = '⚠ ' + (d && d.error ? d.error : 'Échec de la soumission.');
        btn.disabled = false;
        return;
      }
      statusEl.textContent = '⏳ Test en cours — génération des aperçus…';
      neriaMpPollThirdParty(provider, d.id, btn, statusEl, resultsEl, 0);
    })
    .catch(function () {
      statusEl.textContent = '⚠ Impossible de contacter le serveur.';
      btn.disabled = false;
    });
}

function neriaMpPollThirdParty(provider, testId, btn, statusEl, resultsEl, attempt) {
  var MAX_ATTEMPTS = 15; // ~15 × 4s = 60s max

  var pollUrl = neriaMpAjaxUrl('multipreview_poll_' + provider, '&test_id=' + encodeURIComponent(testId));

  fetch(pollUrl, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var results = (d && d.results) || [];
      var readyCount = results.filter(function (r) { return r.ready; }).length;

      if (results.length) {
        resultsEl.innerHTML = results.map(function (r) {
          if (!r.ready) {
            return '<div class="neria-mp-api-thumb"><div class="neria-mp-api-thumb__label">' + neriaMpEscHtml(r.client) + '</div>'
                 + '<div style="width:120px;height:80px;display:flex;align-items:center;justify-content:center;background:#f5f5f5;border-radius:4px;font-size:11px;color:#aaa;">…</div></div>';
          }
          return '<div class="neria-mp-api-thumb"><div class="neria-mp-api-thumb__label">' + neriaMpEscHtml(r.client) + '</div>'
               + '<img class="neria-mp-api-thumb__img" src="' + neriaMpEscHtml(r.image) + '" alt="' + neriaMpEscHtml(r.client) + '"></div>';
        }).join('');
      }

      if (results.length && readyCount === results.length) {
        statusEl.textContent = '✓ Terminé — ' + readyCount + ' rendu(s) client reçu(s).';
        btn.disabled = false;
        return;
      }

      if (attempt >= MAX_ATTEMPTS) {
        statusEl.textContent = readyCount > 0
          ? '✓ ' + readyCount + '/' + results.length + ' rendu(s) reçu(s) — les autres prennent plus de temps que prévu.'
          : '⚠ Le test prend plus de temps que prévu — réessayez plus tard.';
        btn.disabled = false;
        return;
      }

      setTimeout(function () {
        neriaMpPollThirdParty(provider, testId, btn, statusEl, resultsEl, attempt + 1);
      }, 4000);
    })
    .catch(function () {
      statusEl.textContent = '⚠ Erreur lors de la récupération des résultats.';
      btn.disabled = false;
    });
}

(function () {
  var litmusBtn = document.getElementById('neria-mp-litmus-btn');
  if (litmusBtn) { litmusBtn.addEventListener('click', function () { neriaMpRunThirdPartyTest('litmus'); }); }
  var eoaBtn = document.getElementById('neria-mp-eoa-btn');
  if (eoaBtn) { eoaBtn.addEventListener('click', function () { neriaMpRunThirdPartyTest('eoa'); }); }
})();
</script>{/literal}
