{**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — webhooks.tpl
 * Configuration des notifications webhook sortantes.
 * Neria notifie des applications externes (CRM, Zapier, Make…)
 * à chaque événement important via HTTP POST + HMAC-SHA256.
 *}

<div class="neria-section">
  <h2 class="neria-section__title">{neria_admin key='webhook.title'}</h2>
  <p class="neria-section__desc">{neria_admin key='webhook.desc'}</p>

  {* ── Mode d'emploi ──────────────────────────────────────────────── *}
  <div class="neria-card" style="margin-top:20px;background:var(--neria-bg-subtle,#f9f7f4);border-left:3px solid var(--neria-accent,#b8976a);padding:18px 24px;">
    <h4 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:var(--neria-accent,#b8976a);">{neria_admin key='webhook.howto_title'}</h4>
    <ol style="margin:0;padding-left:18px;line-height:1.9;font-size:13px;color:var(--neria-text-muted,#888);">
      <li>{neria_admin key='webhook.howto_step1'}</li>
      <li>{neria_admin key='webhook.howto_step2'}</li>
      <li>{neria_admin key='webhook.howto_step3'}</li>
    </ol>
    <p style="margin:12px 0 0;font-size:12px;color:var(--neria-text-muted,#aaa);">
      ⚡ {neria_admin key='webhook.zapier_hint'}
    </p>
  </div>

  {* ── Formulaire de configuration ────────────────────────────────── *}
  <div class="neria-card" style="margin-top:28px;">
    <h3 class="neria-card__title">{neria_admin key='webhook.config_title'}</h3>

    <form method="post" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="neria_action" value="save_webhooks">
      <input type="hidden" name="neria_tab"    value="webhooks">

      <div class="neria-form-grid">

        {* URL ──────────────────────────────────────────────────────── *}
        <div class="neria-form-group" style="grid-column:1/-1;">
          <label class="neria-label" for="webhook-url-input">{neria_admin key='webhook.url_label'}</label>
          <input type="url" id="webhook-url-input" name="webhook_url" class="neria-input"
                 value="{$webhook_url|escape:'html'}"
                 placeholder="https://hooks.zapier.com/hooks/catch/xxxxx/yyyyy/">
          <span class="neria-hint">{neria_admin key='webhook.url_hint'}</span>
        </div>

        {* Secret HMAC ──────────────────────────────────────────────── *}
        <div class="neria-form-group" style="grid-column:1/-1;">
          <label class="neria-label" for="webhook-secret-input">{neria_admin key='webhook.secret_label'}</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="text" id="webhook-secret-input" name="webhook_secret" class="neria-input"
                   value="{$webhook_secret|escape:'html'}"
                   placeholder="{neria_admin key='webhook.secret_placeholder'}"
                   style="font-family:monospace;flex:1;">
            <button type="button" class="neria-btn neria-btn--sm" onclick="neriaGenerateSecret()">
              ⟳ {neria_admin key='webhook.generate_secret'}
            </button>
          </div>
          <span class="neria-hint">{neria_admin key='webhook.secret_hint'}</span>
        </div>

        {* Événements actifs ─────────────────────────────────────────── *}
        <div class="neria-form-group" style="grid-column:1/-1;">
          <label class="neria-label">{neria_admin key='webhook.events_label'}</label>
          <div style="display:flex;flex-wrap:wrap;gap:10px 20px;margin-top:6px;">
            {foreach $webhook_all_events as $evt}
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
              <input type="checkbox" name="webhook_events[]" value="{$evt|escape:'html'}"
                     {if empty($webhook_events) || in_array($evt, $webhook_events)}checked{/if}>
              <span class="neria-badge neria-badge--on" style="padding:2px 8px;font-size:11px;">{$evt|escape:'html'}</span>
              {neria_admin key="webhook.event_{$evt}"}
            </label>
            {/foreach}
          </div>
          <span class="neria-hint">{neria_admin key='webhook.events_hint'}</span>
        </div>

      </div>

      <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" class="neria-btn neria-btn--primary">
          ✓ {neria_admin key='common.save'}
        </button>
      </div>
    </form>

    {* Bouton test (formulaire séparé pour éviter la double soumission) *}
    {if $webhook_url}
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-top:12px;display:inline-block;">
      <input type="hidden" name="neria_action" value="test_webhook">
      <input type="hidden" name="neria_tab"    value="webhooks">
      <button type="submit" class="neria-btn neria-btn--sm">
        ⚡ {neria_admin key='webhook.test_btn'}
      </button>
    </form>
    <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin-top:12px;display:inline-block;margin-left:8px;">
      <input type="hidden" name="neria_action" value="process_webhook_queue_now">
      <input type="hidden" name="neria_tab"    value="webhooks">
      <button type="submit" class="neria-btn neria-btn--primary neria-btn--sm">
        ▶ {neria_admin key='webhook.process_now_btn'}
      </button>
    </form>
    <span class="neria-hint" style="margin-left:8px;">{neria_admin key='webhook.test_hint'}</span>
    {/if}
  </div>

  {* ── Exemple de payload ──────────────────────────────────────────── *}
  <div class="neria-card" style="margin-top:24px;">
    <h3 class="neria-card__title">{neria_admin key='webhook.payload_title'}</h3>
    <pre style="background:var(--neria-bg-subtle,#f9f7f4);border-radius:6px;padding:16px;font-size:12px;line-height:1.6;overflow-x:auto;margin:0;">{literal}{
  "event":    "email_opened",
  "shop_id":  1,
  "template": "abandoned_cart_1",
  "lang":     "fr",
  "customer_id": 1247,
  "tracking_token": "a3f...9e",
  "timestamp": "2026-06-18T14:32:00+02:00"
}{/literal}</pre>
    <p class="neria-hint" style="margin-top:8px;">{neria_admin key='webhook.signature_hint'}</p>
  </div>

  {* ── Journal des dernières livraisons ────────────────────────────── *}
  <div class="neria-card" style="margin-top:24px;">
    <h3 class="neria-card__title">{neria_admin key='webhook.recent_deliveries'}</h3>

    {if $webhook_deliveries|@count > 0}
    <div style="display:flex;gap:10px;margin-top:12px;">
      <select id="neria-webhook-filter-event" class="neria-select" style="max-width:220px;">
        <option value="">{neria_admin key='webhook.filter_all_events'}</option>
        {foreach $webhook_all_events as $evt}
          <option value="{$evt|escape:'html'}">{$evt|escape:'html'}</option>
        {/foreach}
      </select>
      <select id="neria-webhook-filter-status" class="neria-select" style="max-width:180px;">
        <option value="">{neria_admin key='webhook.filter_all_status'}</option>
        <option value="done">{neria_admin key='webhook.status_done'}</option>
        <option value="pending">{neria_admin key='webhook.status_pending'}</option>
        <option value="failed">{neria_admin key='webhook.status_failed'}</option>
      </select>
    </div>
    <table class="neria-table" id="neria-webhook-table" style="margin-top:12px;">
      <thead>
        <tr>
          <th>{neria_admin key='webhook.col_event'}</th>
          <th style="text-align:center;">{neria_admin key='webhook.col_status'}</th>
          <th style="text-align:center;">{neria_admin key='webhook.col_attempts'}</th>
          <th>{neria_admin key='webhook.col_date'}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach $webhook_deliveries as $d}
        <tr data-event="{$d.event|escape:'html'}" data-status="{$d.status|escape:'html'}">
          <td><code style="font-size:12px;">{$d.event|escape:'html'}</code></td>
          <td style="text-align:center;">
            <span class="neria-badge {if $d.status === 'done'}neria-badge--on{elseif $d.status === 'failed'}neria-badge--off{else}neria-badge--neutral{/if}">
              {neria_admin key="webhook.status_{$d.status}"}
            </span>
          </td>
          <td style="text-align:center;color:var(--neria-text-muted,#888);">{$d.attempts|intval}</td>
          <td style="font-size:12px;color:var(--neria-text-muted,#888);">{$d.date_add|escape:'html'}</td>
          <td>
            {if $d.status === 'failed'}
            <form method="post" action="{$smarty.server.REQUEST_URI}" style="display:inline;">
              <input type="hidden" name="neria_action" value="retry_webhook">
              <input type="hidden" name="neria_tab"    value="webhooks">
              <input type="hidden" name="id_webhook"   value="{$d.id_webhook|intval}">
              <button type="submit" class="neria-link-btn">↺ {neria_admin key='webhook.retry_btn'}</button>
            </form>
            {/if}
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
    {else}
    <p class="neria-hint" style="margin-top:12px;">{neria_admin key='webhook.no_deliveries'}</p>
    {/if}
  </div>

</div>

<script>
(function () {
  var filterEvent  = document.getElementById('neria-webhook-filter-event');
  var filterStatus = document.getElementById('neria-webhook-filter-status');
  var table        = document.getElementById('neria-webhook-table');
  function applyFilters() {
    if (!table) { return; }
    var evVal = filterEvent  ? filterEvent.value  : '';
    var stVal = filterStatus ? filterStatus.value : '';
    table.querySelectorAll('tbody tr').forEach(function (row) {
      var matchEv = !evVal || row.dataset.event  === evVal;
      var matchSt = !stVal || row.dataset.status === stVal;
      row.style.display = (matchEv && matchSt) ? '' : 'none';
    });
  }
  if (filterEvent)  { filterEvent.addEventListener('change', applyFilters); }
  if (filterStatus) { filterStatus.addEventListener('change', applyFilters); }
})();

function neriaGenerateSecret() {
  var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  var result = '';
  var buf = new Uint8Array(40);
  window.crypto.getRandomValues(buf);
  for (var i = 0; i < 40; i++) {
    result += chars[buf[i] % chars.length];
  }
  document.getElementById('webhook-secret-input').value = result;
}
</script>
