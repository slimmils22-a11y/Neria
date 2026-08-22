{*
 * © 2026 Neria.software - All rights reserved
 *
 * Bloc Certificat d'Authenticité — affiché sur la fiche commande PrestaShop
 * Hook : displayAdminOrderMainBottom
 *}
<div class="neria-cert-block card mt-3">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:#1a1a2e;color:#fff;border-radius:6px 6px 0 0;padding:12px 18px;">
    <span style="font-size:14px;font-weight:600;letter-spacing:.04em;">
      📜 {neria_admin key='cert.block_title'}
    </span>
    <a href="{$cert_bo_url|escape:'html':'UTF-8'}" target="_blank"
       style="font-size:11px;color:#b38b59;text-decoration:none;">
      {neria_admin key='cert.view_all'} →
    </a>
  </div>

  <div class="card-body" style="background:#faf6f0;padding:18px;">

    {* ── Certificats déjà émis ────────────────────────────────── *}
    {if $cert_existing}
      <table class="table table-sm" style="margin-bottom:16px;font-size:12px;">
        <thead>
          <tr style="background:#f0e8d8;">
            <th>{neria_admin key='cert.col_serial'}</th>
            <th>{neria_admin key='cert.col_product'}</th>
            <th>{neria_admin key='cert.col_date'}</th>
            <th>{neria_admin key='cert.col_emailed'}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$cert_existing item=c}
          <tr>
            <td><code style="color:#1a1a2e;font-weight:700;">{$c.serial_number|escape:'html':'UTF-8'}</code></td>
            <td>{$c.product_name|escape:'html':'UTF-8'}</td>
            <td>{$c.date_issued|date_format:'%d/%m/%Y'}</td>
            <td>
              {if $c.emailed}
                <span style="color:#27ae60;">✓ {neria_admin key='cert.sent'}</span>
              {else}
                <span style="color:#888;">—</span>
              {/if}
            </td>
            <td>
              <form method="post" action="{$cert_action_url|escape:'html':'UTF-8'}" style="display:inline;">
                <input type="hidden" name="neria_action" value="cert_download">
                <input type="hidden" name="id_certificate" value="{$c.id_certificate|intval}">
                <button type="submit" class="btn btn-xs btn-default" title="{neria_admin key='cert.download'}">
                  ⬇ PDF
                </button>
              </form>
            </td>
          </tr>
          {/foreach}
        </tbody>
      </table>
    {/if}

    {* ── Formulaire d'émission ────────────────────────────────── *}
    <form method="post" action="{$cert_action_url|escape:'html':'UTF-8'}">
      <input type="hidden" name="neria_action" value="cert_issue">
      <input type="hidden" name="cert_id_order" value="{$cert_order_id|intval}">

      <div class="row">
        {* Produit *}
        <div class="col-md-5">
          <label style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;display:block;margin-bottom:4px;">
            {neria_admin key='cert.label_product'}
          </label>
          <select name="cert_id_product" class="form-control form-control-sm" required
                  onchange="neriaCertUpdateDetail(this)">
            <option value="">— {neria_admin key='cert.choose_product'} —</option>
            {foreach from=$cert_order_products item=p}
              <option value="{$p.product_id|intval}"
                      data-detail="{$p.id_order_detail|intval}">
                {$p.product_name|escape:'html':'UTF-8'}
                {if $p.product_reference} [{$p.product_reference|escape:'html':'UTF-8'}]{/if}
              </option>
            {/foreach}
          </select>
          <input type="hidden" name="cert_id_order_detail" id="neriaCertOrderDetail" value="0">
        </div>

        {* Numéro de série (modifiable) *}
        <div class="col-md-3">
          <label style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;display:block;margin-bottom:4px;">
            {neria_admin key='cert.label_serial'}
          </label>
          <input type="text" name="cert_serial" class="form-control form-control-sm"
                 placeholder="{neria_admin key='cert.serial_auto'}"
                 style="font-family:monospace;">
        </div>

        {* Envoyer par email *}
        <div class="col-md-2 d-flex align-items-end">
          <label style="font-size:12px;margin-bottom:6px;cursor:pointer;">
            <input type="checkbox" name="cert_send_email" value="1" checked style="margin-right:4px;">
            {neria_admin key='cert.send_email'}
          </label>
        </div>

        {* Bouton émettre *}
        <div class="col-md-2 d-flex align-items-end">
          <button type="submit"
                  style="height:34px;padding:0 16px;background:#1a1a2e;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;width:100%;transition:background .15s;"
                  onmouseover="this.style.background='#b38b59'"
                  onmouseout="this.style.background='#1a1a2e'">
            📜 {neria_admin key='cert.issue_btn'}
          </button>
        </div>
      </div>

      {* Traçabilité : artisane / région / temps de tissage (page publique du QR) *}
      <div class="row mt-2">
        <div class="col-md-4">
          <label style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;display:block;margin-bottom:4px;">
            {neria_admin key='cert.label_artisan'}
          </label>
          <input type="text" name="cert_artisan_name" class="form-control form-control-sm"
                 placeholder="{neria_admin key='cert.artisan_placeholder'}" maxlength="255">
        </div>
        <div class="col-md-3">
          <label style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;display:block;margin-bottom:4px;">
            {neria_admin key='cert.label_region'}
          </label>
          <input type="text" name="cert_region" class="form-control form-control-sm"
                 placeholder="{neria_admin key='cert.region_placeholder'}" maxlength="255">
        </div>
        <div class="col-md-3">
          <label style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;display:block;margin-bottom:4px;">
            {neria_admin key='cert.label_duration'}
          </label>
          <input type="text" name="cert_weaving_duration" class="form-control form-control-sm"
                 placeholder="{neria_admin key='cert.duration_placeholder'}" maxlength="255">
        </div>
      </div>

      {* Note artisan *}
      <div class="row mt-2">
        <div class="col-md-10">
          <label style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;display:block;margin-bottom:4px;">
            {neria_admin key='cert.label_note'}
          </label>
          <input type="text" name="cert_note" class="form-control form-control-sm"
                 placeholder="{neria_admin key='cert.note_placeholder'}" maxlength="255">
        </div>
      </div>

      {* Info signature / QR *}
      <div class="mt-2" style="font-size:11px;color:#aaa;display:flex;align-items:center;justify-content:space-between;">
        <span>
          {if $cert_has_signature}
            ✅ {neria_admin key='cert.sig_ok'}
          {else}
            ⚠️ {neria_admin key='cert.sig_missing'}
          {/if}
          &nbsp;·&nbsp;
          {if $cert_qr_enabled}
            🔲 {neria_admin key='cert.qr_on'}
          {else}
            {neria_admin key='cert.qr_off'}
          {/if}
        </span>
        <span style="font-size:10px;color:#b38b59;font-weight:600;letter-spacing:.05em;">✦ Powered by Neria</span>
      </div>
    </form>

  </div>{* /card-body *}
</div>

{literal}
<script>
function neriaCertUpdateDetail(sel) {
  var opt = sel.options[sel.selectedIndex];
  document.getElementById('neriaCertOrderDetail').value = opt.getAttribute('data-detail') || 0;
}
</script>
{/literal}
