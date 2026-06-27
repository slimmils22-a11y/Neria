{if $waitlist_oos}
<div style="margin-top:14px;">
  {if $waitlist_registered}
    <p style="font-size:13px;color:#1a7a40;font-weight:600;margin:0;">
      ✓ Vous serez notifié(e) dès que ce produit est de nouveau disponible.
      <a href="{$waitlist_unsubscribe_url}" style="font-size:12px;color:#7a6a5a;margin-left:8px;text-decoration:underline;">Annuler</a>
    </p>
  {else}
    <a href="{$waitlist_subscribe_url}?action=subscribe&id_product={$waitlist_id_product}&back={$waitlist_back_url|escape:'url'}"
       style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;
              background:#1a1a1a;color:#fff;border-radius:4px;text-decoration:none;
              font-size:13px;font-weight:600;letter-spacing:.03em;">
      🔔 M'avertir quand disponible
    </a>
  {/if}
</div>
{/if}
