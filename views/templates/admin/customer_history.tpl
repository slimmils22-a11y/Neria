{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — customer_history.tpl
 * Onglet "Historique clients" : recherche d'un client (nom/email)
 * puis affichage du bloc « Emails reçus ».
 *}
<div class="neria-section">
  <h2 class="neria-section__title">{neria_admin key='nav.customer_history'}</h2>
  <p class="neria-section__desc">{neria_admin key='history.search_desc'}</p>

  <form method="get" action="" style="display:flex;gap:8px;align-items:center;max-width:560px;">
    {* Conserver tous les paramètres existants de l'URL *}
    {foreach from=$smarty.get key=k item=v}
      {if $k !== 'neria_hist_q' && $k !== 'neria_hist_customer'}
        <input type="hidden" name="{$k|escape:'html'}" value="{$v|escape:'html'}">
      {/if}
    {/foreach}
    <input type="text"
           name="neria_hist_q"
           class="neria-input"
           autocomplete="off"
           placeholder="{neria_admin key='history.search_placeholder'}"
           value="{$neria_hist_q|default:''|escape:'html'}"
           style="flex:1;">
    <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
      {neria_admin key='history.search_btn'}
    </button>
  </form>

  {* Recherches récentes (stockées côté navigateur) *}
  <div id="neria-hist-recent" style="display:none;margin-top:12px;">
    <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--neria-muted);margin-right:8px;">
      {neria_admin key='history.recent_searches'} :
    </span>
    <span id="neria-hist-recent-list"></span>
  </div>

  {* Résultats de recherche *}
  {if isset($neria_hist_search_results)}
    {if $neria_hist_search_results|count > 0}
      <ul style="margin:12px 0 0;padding:0;list-style:none;border:1px solid #e8d5b0;border-radius:6px;overflow:hidden;max-width:560px;">
        {foreach from=$neria_hist_search_results item=c}
          <li style="border-bottom:1px solid #f0e8d8;">
            <a href="{$neria_hist_search_base|escape:'html'}&neria_hist_customer={$c.id}"
               class="neria-hist-result-link">
              {$c.label|escape:'html'}
            </a>
          </li>
        {/foreach}
      </ul>
    {else}
      <p style="margin-top:10px;color:#888;font-size:13px;">Aucun client trouvé pour « {$neria_hist_q|escape:'html'} ».</p>
    {/if}
  {/if}
</div>

{if $neria_hist_selected_customer}
<div class="neria-section neria-customer-history" id="neria-customer-history">
  <div class="panel-heading neria-history__heading">
    <span><i class="icon-envelope"></i> {neria_admin key='history.title'} — {$neria_hist_selected_label|escape:'html'}</span>
    <span class="neria-powered-by">{neria_admin key='history.powered_by'}</span>
  </div>

  {include file="module:neria/views/templates/admin/_customer_history_content.tpl"}
</div>
{/if}

<script>
  var neriaHistCurrentCustomer = {if $neria_hist_selected_customer}{ldelim}id: {$neria_customer_id}, label: {$neria_hist_selected_label|json_encode}{rdelim}{else}null{/if};
  var neriaHistBaseUrl         = {$neria_hist_search_base|default:''|json_encode};
</script>
<script>
{literal}
(function () {
  var STORAGE_KEY = 'neria_recent_customers';
  var MAX_RECENT  = 5;

  function loadRecent() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      var list = raw ? JSON.parse(raw) : [];
      return Array.isArray(list) ? list : [];
    } catch (e) { return []; }
  }

  function saveRecent(list) {
    try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(list)); } catch (e) {}
  }

  // Enregistre le client actuellement affiché en tête de liste
  if (neriaHistCurrentCustomer && neriaHistCurrentCustomer.id) {
    var list = loadRecent().filter(function (c) { return c.id !== neriaHistCurrentCustomer.id; });
    list.unshift(neriaHistCurrentCustomer);
    list = list.slice(0, MAX_RECENT);
    saveRecent(list);
  }

  // Affiche les chips de recherches récentes
  var recent = loadRecent();
  if (recent.length > 0) {
    var wrap = document.getElementById('neria-hist-recent');
    var listEl = document.getElementById('neria-hist-recent-list');
    if (wrap && listEl) {
      recent.forEach(function (c) {
        var sep = neriaHistBaseUrl.indexOf('?') === -1 ? '?' : '&';
        var a = document.createElement('a');
        a.href = neriaHistBaseUrl + sep + 'neria_hist_customer=' + encodeURIComponent(c.id);
        a.textContent = c.label;
        a.style.cssText = 'display:inline-block;margin:2px 6px 2px 0;padding:3px 10px;background:#f9f6f1;'
          + 'border:1px solid #e8d5b0;border-radius:20px;font-size:11px;color:#5c3d1e;text-decoration:none;';
        listEl.appendChild(a);
      });
      wrap.style.display = 'block';
    }
  }
})();
{/literal}
</script>
