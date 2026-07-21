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

  <table class="neria-table" style="margin-top:12px;">
    <thead>
      <tr>
        <th>{neria_admin key='control_center.col_feature'}</th>
        <th style="text-align:center;">{neria_admin key='control_center.col_status'}</th>
        <th style="text-align:center;">{neria_admin key='control_center.col_menu'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach $control_center_items as $item}
      <tr>
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
</div>
