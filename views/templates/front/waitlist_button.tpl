{* © 2026 Neria.software - All rights reserved *}
{* Round 339 : <a href> remplacé par un vrai <form method="post"> — le
   contrôleur controllers/front/waitlist.php exige un POST (garde-fou
   anti-CSRF déjà en place dans son code, voir son commentaire), mais un
   <a href> ne produit qu'une requête GET : le clic ne déclenchait donc
   JAMAIS l'inscription/désinscription réelle (redirection immédiate sans
   traitement), quel que soit le client. Le bouton semblait fonctionner
   (page produit rechargée, aucune erreur visible) mais neria_waitlist
   n'était jamais mis à jour. *}
{if $waitlist_oos}
<div style="margin-top:14px;">
  {if $waitlist_registered}
    <div style="font-size:13px;color:#1a7a40;font-weight:600;margin:0;display:flex;align-items:center;gap:8px;">
      <span>✓ {neria_admin key='front.waitlist_notified'}</span>
      <form action="{$waitlist_unsubscribe_url|escape:'html'}" method="post" style="display:inline;margin:0;">
        <button type="submit" style="font-size:12px;color:#7a6a5a;background:none;border:none;padding:0;text-decoration:underline;cursor:pointer;font-family:inherit;">{neria_admin key='front.waitlist_cancel'}</button>
      </form>
    </div>
  {else}
    <form action="{$waitlist_subscribe_url|escape:'html'}" method="post" style="margin:0;">
      <button type="submit"
         style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;
                background:#1a1a1a;color:#fff;border-radius:4px;border:none;
                font-size:13px;font-weight:600;letter-spacing:.03em;cursor:pointer;font-family:inherit;">
        🔔 {neria_admin key='front.waitlist_notify_me'}
      </button>
    </form>
  {/if}
</div>
{/if}
