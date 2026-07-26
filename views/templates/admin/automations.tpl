{*
 * © 2026 Neria.software - All rights reserved
 *
 * Onglet Automatisations comportementales — back-office Neria
 *}

{* ── En-tête ────────────────────────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section-title">⚙ {neria_admin key='auto.title'}</h2>
  <p class="neria-section-desc">{neria_admin key='auto.desc'}</p>

  {* Dernier passage global *}
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
    <div style="background:#f0e8d8;border:1px solid #e8d5b0;border-radius:8px;padding:12px 20px;font-size:13px;">
      <span style="color:#888;margin-right:6px;">⏱ {neria_admin key='auto.last_run'} :</span>
      <strong style="color:#1a1a2e;">
        {if $auto_last_run}{$auto_last_run}{else}{neria_admin key='auto.never'}{/if}
      </strong>
    </div>

    {* Forcer l'exécution *}
    <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html':'UTF-8'}">
      <input type="hidden" name="neria_action" value="auto_force_run">
      <input type="hidden" name="neria_tab" value="automations">
      <button type="submit" class="neria-btn"
              onclick="return confirm('{neria_admin key='auto.force_confirm' esc='javascript'}');">
        ▶ {neria_admin key='auto.force_run'}
      </button>
    </form>
  </div>

  {* ── Tableau des automatisations ─────────────────────────── *}
  <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="background:#f0e8d8;text-align:left;">
          <th style="padding:10px 14px;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;letter-spacing:.05em;">{neria_admin key='auto.col_name'}</th>
          <th style="padding:10px 14px;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;letter-spacing:.05em;">{neria_admin key='auto.col_trigger'}</th>
          <th style="padding:10px 14px;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;letter-spacing:.05em;text-align:right;">{neria_admin key='auto.col_today'}</th>
          <th style="padding:10px 14px;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;letter-spacing:.05em;text-align:right;">{neria_admin key='auto.col_total'}</th>
          <th style="padding:10px 14px;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;letter-spacing:.05em;text-align:center;">{neria_admin key='auto.col_status'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$auto_crons item=cron key=k}
        <tr style="border-bottom:1px solid #f0e0cc;{if $k % 2 == 1}background:#fffdf9;{/if}">
          <td style="padding:10px 14px;">
            <span style="margin-right:6px;font-size:16px;">{$cron.icon}</span>
            <strong style="color:#1a1a2e;">{$cron.label|escape:'html':'UTF-8'}</strong>
            {if $cron.desc}
              <div style="font-size:11px;color:#888;margin-top:2px;">{$cron.desc|escape:'html':'UTF-8'}</div>
            {/if}
          </td>
          <td style="padding:10px 14px;color:#666;font-size:12px;">{$cron.trigger|escape:'html':'UTF-8'}</td>
          <td style="padding:10px 14px;text-align:right;font-variant-numeric:tabular-nums;font-weight:600;color:#1a1a2e;">
            {if $cron.calc_only}
              <span style="font-size:11px;color:#888;font-weight:400;">—</span>
            {else}
              {$cron.today|default:0}
            {/if}
          </td>
          <td style="padding:10px 14px;text-align:right;font-variant-numeric:tabular-nums;color:#555;">
            {if $cron.calc_only}
              <span style="font-size:11px;color:#888;">—</span>
            {else}
              {$cron.total|default:0}
            {/if}
          </td>
          <td style="padding:10px 14px;text-align:center;">
            {if $cron.calc_only}
              <span style="font-size:11px;color:#888;background:#f0e8d8;border-radius:10px;padding:3px 10px;">⚙ calcul</span>
            {elseif $cron.config_key}
              <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html':'UTF-8'}" style="display:inline;">
                <input type="hidden" name="neria_action" value="auto_toggle">
                <input type="hidden" name="neria_tab" value="automations">
                <input type="hidden" name="auto_key" value="{$cron.config_key|escape:'html':'UTF-8'}">
                <button type="submit"
                        style="min-width:76px;height:28px;padding:0 12px;border:none;border-radius:14px;font-size:11px;font-weight:700;cursor:pointer;letter-spacing:.04em;
                               {if $cron.enabled}background:#16a34a;color:#fff;{else}background:#dc2626;color:#fff;{/if}">
                  {if $cron.enabled}● {neria_admin key='common.active'}{else}○ {neria_admin key='common.inactive'}{/if}
                </button>
              </form>
            {else}
              <span style="font-size:11px;color:#27ae60;font-weight:700;">● toujours actif</span>
            {/if}
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  </div>

  {* Note pied *}
  <p style="margin-top:16px;font-size:12px;color:#aaa;">
    {neria_admin key='auto.note'}
  </p>
</div>
