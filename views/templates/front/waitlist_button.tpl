{* © 2026 Neria.software - All rights reserved *}
{if $waitlist_oos}
<div style="margin-top:14px;">
  {if $waitlist_registered}
    <p style="font-size:13px;color:#1a7a40;font-weight:600;margin:0;">
      ✓ {neria_admin key='front.waitlist_notified'}
      <a href="{$waitlist_unsubscribe_url|escape:'html'}" style="font-size:12px;color:#7a6a5a;margin-left:8px;text-decoration:underline;">{neria_admin key='front.waitlist_cancel'}</a>
    </p>
  {else}
    <a href="{$waitlist_subscribe_url|escape:'html'}?action=subscribe&id_product={$waitlist_id_product|intval}&back={$waitlist_back_url|escape:'url'}"
       style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;
              background:#1a1a1a;color:#fff;border-radius:4px;text-decoration:none;
              font-size:13px;font-weight:600;letter-spacing:.03em;">
      🔔 {neria_admin key='front.waitlist_notify_me'}
    </a>
  {/if}
</div>
{/if}
