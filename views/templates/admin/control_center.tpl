{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — control_center.tpl
 * Centre de contrôle : afficher/masquer les liens de menu des features,
 * sans jamais toucher à leur état actif/inactif réel (cf. ConfigManager::
 * CONTROL_CENTER_REGISTRY). Pastille = statut réel (lecture seule),
 * bouton = visibilité du lien de menu (action).
 *}

<div class="neria-section" id="neria-control-center">
  <h2 class="neria-section__title">
    {neria_admin key='control_center.title'}
  </h2>
  <p class="neria-section__desc">
    {neria_admin key='control_center.desc'}
  </p>

  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-top:16px;">
    <input type="text" id="neria-cc-search"
           placeholder="{neria_admin key='control_center.search_placeholder'}"
           style="flex:1; min-width:220px; max-width:360px; padding:8px 12px;
                  border:1px solid #e8d5b0; border-radius:4px; font-size:13px;">

    <div style="display:flex; gap:8px;">
      <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
        <input type="hidden" name="neria_action" value="menu_visibility_bulk">
        <input type="hidden" name="neria_tab"    value="control_center">
        <input type="hidden" name="mode"         value="show">
        <button type="submit"
                style="padding:8px 16px; background:#1a7a40; color:#fff; border:none;
                       border-radius:4px; font-size:12px; font-weight:700; cursor:pointer; letter-spacing:.04em;">
          {neria_admin key='control_center.show_all'}
        </button>
      </form>
      <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
        <input type="hidden" name="neria_action" value="menu_visibility_bulk">
        <input type="hidden" name="neria_tab"    value="control_center">
        <input type="hidden" name="mode"         value="hide">
        <button type="submit"
                style="padding:8px 16px; background:#c0392b; color:#fff; border:none;
                       border-radius:4px; font-size:12px; font-weight:700; cursor:pointer; letter-spacing:.04em;">
          {neria_admin key='control_center.hide_all'}
        </button>
      </form>
    </div>
  </div>

  <table class="neria-table" style="margin-top:12px;">
    <thead>
      <tr>
        <th>{neria_admin key='control_center.col_feature'}</th>
        <th style="text-align:center;">{neria_admin key='control_center.col_status'}</th>
        <th style="text-align:center;">{neria_admin key='control_center.col_menu'}</th>
      </tr>
    </thead>
    <tbody id="neria-cc-tbody">
      {foreach $control_center_items as $item}
      <tr data-neria-cc-label="{$item.label|escape:'html'|lower}">
        <td>{$item.label|escape:'html'}</td>
        <td style="text-align:center;">
          <span class="neria-badge {if $item.active}neria-badge--on{else}neria-badge--off{/if}">
            {if $item.active}{neria_admin key='common.active'}{else}{neria_admin key='common.inactive'}{/if}
          </span>
        </td>
        <td style="text-align:center;">
          <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html'}" style="display:inline;">
            <input type="hidden" name="neria_action" value="menu_visibility_toggle">
            <input type="hidden" name="neria_tab"    value="control_center">
            <input type="hidden" name="item"         value="{$item.key|escape:'html'}">
            <button type="submit"
                    style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
                           background:{if $item.visible}#1a7a40{else}#c0392b{/if};
                           color:#fff; border:none; border-radius:4px; font-size:12px;
                           font-weight:700; cursor:pointer; letter-spacing:.04em;">
              {if $item.visible}● {neria_admin key='control_center.hide'}{else}○ {neria_admin key='control_center.show'}{/if}
            </button>
          </form>
        </td>
      </tr>
      {/foreach}
    </tbody>
  </table>

  <p id="neria-cc-no-results" style="display:none; text-align:center; color:var(--neria-muted); padding:20px 0;">
    {neria_admin key='control_center.no_results'}
  </p>
</div>

{literal}
<script>
(function () {
  var input = document.getElementById('neria-cc-search');
  var rows  = document.querySelectorAll('#neria-cc-tbody tr');
  var empty = document.getElementById('neria-cc-no-results');
  if (!input) { return; }

  input.addEventListener('input', function () {
    var term = input.value.trim().toLowerCase();
    var visibleCount = 0;
    rows.forEach(function (row) {
      var match = row.getAttribute('data-neria-cc-label').indexOf(term) !== -1;
      row.style.display = match ? '' : 'none';
      if (match) { visibleCount++; }
    });
    empty.style.display = visibleCount === 0 ? '' : 'none';
  });
})();
</script>
{/literal}
