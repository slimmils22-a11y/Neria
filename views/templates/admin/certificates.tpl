{*
 * Onglet Certificats d'Authenticité — back-office Neria
 *}

{* ── Configuration ──────────────────────────────────────────── *}
<div class="neria-section">
  <h2 class="neria-section-title">📜 {neria_admin key='cert.config_title'}</h2>
  <p class="neria-section-desc">{neria_admin key='cert.config_desc'}</p>

  <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html':'UTF-8'}">
    <input type="hidden" name="neria_action" value="cert_save_config">

    {* Toggle activation *}
    <div class="neria-field-row" style="margin-bottom:18px;">
      <label class="neria-toggle-label" style="display:flex;align-items:center;gap:12px;cursor:pointer;">
        <div class="neria-toggle {if $cert_enabled}neria-toggle--on{/if}"
             id="certToggle" style="position:relative;width:44px;height:24px;background:{if $cert_enabled}#1a1a2e{else}#ccc{/if};border-radius:12px;transition:background .2s;cursor:pointer;"
             onclick="var cb=document.getElementById('certEnabledCb');cb.checked=!cb.checked;this.style.background=cb.checked?'#1a1a2e':'#ccc';this.querySelector('span').style.left=cb.checked?'22px':'2px';">
          <span style="position:absolute;top:2px;left:{if $cert_enabled}22px{else}2px{/if};width:20px;height:20px;background:#fff;border-radius:50%;transition:left .2s;"></span>
        </div>
        <span style="font-weight:600;font-size:14px;">{neria_admin key='cert.toggle_label'}</span>
      </label>
      <input type="checkbox" id="certEnabledCb" name="cert_enabled" value="1" {if $cert_enabled}checked{/if} style="display:none;">
      <p class="neria-field-hint">{neria_admin key='cert.toggle_hint'}</p>
    </div>

    <div class="neria-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
      {* Préfixe numéro de série *}
      <div>
        <label class="neria-label">{neria_admin key='cert.prefix_label'}</label>
        <input type="text" name="cert_prefix" value="{$cert_prefix|escape:'html':'UTF-8'}"
               class="neria-input" placeholder="CERT" maxlength="20"
               style="font-family:monospace;text-transform:uppercase;">
        <p class="neria-field-hint">{neria_admin key='cert.prefix_hint'}</p>
      </div>

      {* Titre du certificat *}
      <div>
        <label class="neria-label">{neria_admin key='cert.title_label'}</label>
        <input type="text" name="cert_title" value="{$cert_title|escape:'html':'UTF-8'}"
               class="neria-input" placeholder="{neria_admin key='cert.title_placeholder'}">
      </div>

      {* Sous-titre *}
      <div>
        <label class="neria-label">{neria_admin key='cert.subtitle_label'}</label>
        <input type="text" name="cert_subtitle" value="{$cert_subtitle|escape:'html':'UTF-8'}"
               class="neria-input" placeholder="{neria_admin key='cert.subtitle_placeholder'}">
      </div>
    </div>

    {* Corps du texte *}
    <div style="margin-bottom:16px;">
      <label class="neria-label">{neria_admin key='cert.body_label'}</label>
      <textarea name="cert_body" class="neria-input" rows="3"
                placeholder="{neria_admin key='cert.body_placeholder'}">{$cert_body|escape:'html':'UTF-8'}</textarea>
      <p class="neria-field-hint">{neria_admin key='cert.body_hint'}</p>
    </div>

    {* QR Code *}
    <div class="neria-card" style="background:#faf6f0;border:1px solid #e8d8c0;border-radius:8px;padding:16px;margin-bottom:16px;">
      <h4 style="margin:0 0 8px;font-size:13px;color:#1a1a2e;">🔲 {neria_admin key='cert.qr_title'}</h4>
      <p style="margin:0 0 12px;font-size:12px;color:#888;">{neria_admin key='cert.qr_desc'}</p>

      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:12px;">
        <input type="checkbox" name="cert_qr_enabled" value="1" {if $cert_qr_enabled}checked{/if}
               id="certQrCb" onchange="document.getElementById('certQrUrlRow').style.display=this.checked?'block':'none';">
        <span style="font-size:13px;font-weight:600;">{neria_admin key='cert.qr_toggle'}</span>
      </label>

      <div id="certQrUrlRow" style="display:{if $cert_qr_enabled}block{else}none{/if};">
        <label class="neria-label">{neria_admin key='cert.qr_url_label'}</label>
        <input type="text" name="cert_qr_url" value="{$cert_qr_url|escape:'html':'UTF-8'}"
               class="neria-input" placeholder="https://votreboutique.com/authentifier">
        <p class="neria-field-hint">{neria_admin key='cert.qr_url_hint'}</p>
      </div>
    </div>

    <button type="submit" class="neria-btn">
      {neria_admin key='cert.save_config'}
    </button>
  </form>
</div>

{* ── KPIs ────────────────────────────────────────────────────── *}
{if $cert_stats}
<div class="neria-section" style="margin-top:32px;">
  <h2 class="neria-section-title">📊 {neria_admin key='cert.stats_title'}</h2>

  <div class="neria-kpi-grid neria-kpi-grid--large" style="margin-bottom:8px;">
    <div class="neria-kpi neria-kpi--main">
      <div class="neria-kpi__value">{$cert_stats.total|number_format:0:',':' '}</div>
      <div class="neria-kpi__label">{neria_admin key='cert.kpi_total'}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$cert_stats.email_rate}%</div>
      <div class="neria-kpi__label">{neria_admin key='cert.kpi_email_rate'}</div>
      <div class="neria-kpi__rate" style="font-size:11px;color:#888;">{$cert_stats.emailed|number_format:0:',':' '} / {$cert_stats.total|number_format:0:',':' '}</div>
    </div>
    <div class="neria-kpi">
      <div class="neria-kpi__value">{$cert_stats.this_month|number_format:0:',':' '}</div>
      <div class="neria-kpi__label">{neria_admin key='cert.kpi_this_month'}</div>
      {if $cert_stats.last_month > 0 || $cert_stats.this_month > 0}
        <div class="neria-kpi__rate" style="font-size:11px;color:{if $cert_stats.trend_pct >= 0}#27ae60{else}#c0392b{/if};">
          {if $cert_stats.trend_pct >= 0}▲{else}▼{/if} {$cert_stats.trend_pct|abs}% {neria_admin key='cert.kpi_vs_last_month'}
        </div>
      {/if}
    </div>
  </div>

  {if $cert_stats.top_products}
    <div style="margin-top:12px;">
      <p style="font-size:12px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px;">
        {neria_admin key='cert.kpi_top_products'}
      </p>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        {foreach from=$cert_stats.top_products item=tp}
          <span style="background:#faf6f0;border:1px solid #e8d8c0;border-radius:20px;padding:4px 12px;font-size:12px;color:#1a1a2e;">
            {$tp.product_name|escape:'html':'UTF-8'|truncate:30:'…'}
            <strong style="color:#b38b59;">× {$tp.cnt|intval}</strong>
          </span>
        {/foreach}
      </div>
    </div>
  {/if}
</div>
{/if}

{* ── Liste des certificats émis ─────────────────────────────── *}
<div class="neria-section" style="margin-top:32px;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <h2 class="neria-section-title" style="margin:0;">
      {neria_admin key='cert.list_title'}
      {if $cert_count}
        <span style="font-size:13px;background:#1a1a2e;color:#fff;border-radius:12px;padding:2px 10px;margin-left:8px;">{$cert_count}</span>
      {/if}
    </h2>
  </div>

  {if $cert_list}
    <div style="overflow-x:auto;">
      <table class="neria-table" style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="background:#f0e8d8;">
            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;">{neria_admin key='cert.col_serial'}</th>
            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;">{neria_admin key='cert.col_product'}</th>
            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;">{neria_admin key='cert.col_customer'}</th>
            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;">{neria_admin key='cert.col_order'}</th>
            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;">{neria_admin key='cert.col_date'}</th>
            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#888;text-transform:uppercase;font-size:11px;">{neria_admin key='cert.col_emailed'}</th>
            <th style="padding:10px 12px;text-align:right;"></th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$cert_list item=c}
          <tr style="border-bottom:1px solid #f0e0cc;{if $c@iteration % 2 == 0}background:#fffdf9;{/if}">
            <td style="padding:10px 12px;">
              <code style="background:#1a1a2e;color:#b38b59;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;">
                {$c.serial_number|escape:'html':'UTF-8'}
              </code>
            </td>
            <td style="padding:10px 12px;">{$c.product_name|escape:'html':'UTF-8'|truncate:40:'…'}</td>
            <td style="padding:10px 12px;">{$c.customer_name|escape:'html':'UTF-8'}</td>
            <td style="padding:10px 12px;">
              <a href="{$link->getAdminLink('AdminOrders')|escape:'html':'UTF-8'}&id_order={$c.id_order|intval}&vieworder=1"
                 target="_blank" style="color:#1a1a2e;font-weight:600;">
                #{$c.id_order|intval}
                {if isset($c.order_ref) && $c.order_ref} · {$c.order_ref|escape:'html':'UTF-8'}{/if}
              </a>
            </td>
            <td style="padding:10px 12px;color:#888;">{$c.date_issued|date_format:'%d/%m/%Y %H:%M'}</td>
            <td style="padding:10px 12px;">
              {if $c.emailed}
                <span style="color:#27ae60;font-weight:600;">✅ {neria_admin key='cert.sent'}</span>
              {else}
                <span style="color:#e67e22;">⏳ {neria_admin key='cert.not_sent'}</span>
              {/if}
            </td>
            <td style="padding:10px 12px;text-align:right;">
              {* Télécharger *}
              <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html':'UTF-8'}" style="display:inline;">
                <input type="hidden" name="neria_action" value="cert_download">
                <input type="hidden" name="id_certificate" value="{$c.id_certificate|intval}">
                <button type="submit"
                        style="height:28px;padding:0 10px;background:#1a1a2e;color:#fff;border:none;border-radius:4px;font-size:11px;cursor:pointer;margin-right:4px;"
                        title="{neria_admin key='cert.download'}">
                  ⬇
                </button>
              </form>
              {* Supprimer *}
              <form method="post" action="{$smarty.server.REQUEST_URI|escape:'html':'UTF-8'}" style="display:inline;">
                <input type="hidden" name="neria_action" value="cert_delete">
                <input type="hidden" name="id_certificate" value="{$c.id_certificate|intval}">
                <button type="button"
                        data-confirm="{neria_admin key='cert.delete_confirm'|escape:'html'}"
                        onclick="neriaConfirmDelete(this);"
                        style="height:28px;padding:0 10px;background:#c0392b;color:#fff;border:none;border-radius:4px;font-size:11px;cursor:pointer;"
                        title="{neria_admin key='cert.delete'}">
                  ✕
                </button>
              </form>
            </td>
          </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <div style="text-align:center;padding:40px 20px;color:#aaa;background:#faf6f0;border-radius:8px;border:2px dashed #e8d8c0;">
      <div style="font-size:32px;margin-bottom:12px;">📜</div>
      <p style="margin:0;font-size:14px;">{neria_admin key='cert.no_certs'}</p>
      <p style="margin:8px 0 0;font-size:12px;">{neria_admin key='cert.no_certs_hint'}</p>
    </div>
  {/if}
</div>
