{*
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Page de confirmation de désabonnement
 * i18n : libellés via {neria_admin key='...'} dans la langue du destinataire
 *}
<section id="neria-unsubscribe" dir="{$neria_unsub_dir|default:'ltr'}" style="max-width:560px; margin:60px auto; padding:0 20px; text-align:center; font-family:Georgia,'Times New Roman',serif; color:#2c2c2c;">

  {if $neria_unsub_done}
    <h1 style="font-size:26px; font-weight:400; letter-spacing:0.02em; margin-bottom:18px;">
      {neria_admin key='unsub.done_title'}
    </h1>
    <p style="font-size:15px; line-height:1.7; color:#5a5450;">
      {neria_admin key='unsub.done_text'}
    </p>
  {else}
    <h1 style="font-size:26px; font-weight:400; letter-spacing:0.02em; margin-bottom:18px;">
      {neria_admin key='unsub.invalid_title'}
    </h1>
    <p style="font-size:15px; line-height:1.7; color:#5a5450;">
      {neria_admin key='unsub.invalid_text'}
    </p>
  {/if}

  <p style="margin-top:32px;">
    <a href="{$neria_shop_url|escape:'html'}" style="display:inline-block; padding:12px 28px; border:1px solid #b38b59; color:#b38b59; text-decoration:none; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">
      {neria_admin key='unsub.back_to_shop'}
    </a>
  </p>

  <p style="margin-top:28px; font-size:12px; color:#a09990;">{$neria_shop_name|escape:'html'}</p>
</section>
