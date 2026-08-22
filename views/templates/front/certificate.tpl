{*
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Page de traçabilité publique d'une pièce certifiée
 * i18n : libellés via {neria_admin key='...'} dans la langue du visiteur
 *}
<section id="neria-trace" dir="{$neria_trace_dir|default:'ltr'}" style="max-width:640px; margin:60px auto; padding:0 20px; font-family:Georgia,'Times New Roman',serif; color:#2c2c2c;">

  {if $neria_trace_found}
    <p style="text-align:center; font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:#b38b59; margin:0 0 8px;">
      {neria_admin key='trace.eyebrow'}
    </p>
    <h1 style="text-align:center; font-size:28px; font-weight:400; letter-spacing:.02em; margin:0 0 30px;">
      {neria_admin key='trace.title'}
    </h1>

    <div style="background:#faf6f0; border:1px solid #e8d5b0; padding:28px 32px; text-align:center;">
      <p style="font-size:19px; margin:0 0 6px;">{$neria_trace_product|escape:'html':'UTF-8'}</p>
      <p style="font-size:11px; letter-spacing:.08em; color:#8a8378; margin:0 0 22px; font-family:monospace;">
        {neria_admin key='trace.label_serial'} {$neria_trace_serial|escape:'html':'UTF-8'}
      </p>

      <table style="width:100%; border-collapse:collapse; text-align:left; font-size:14px;">
        {if $neria_trace_artisan}
        <tr>
          <td style="padding:10px 0; border-top:1px solid #e8d5b0; color:#8a8378; font-size:11px; letter-spacing:.06em; text-transform:uppercase; width:40%;">{neria_admin key='trace.label_artisan'}</td>
          <td style="padding:10px 0; border-top:1px solid #e8d5b0;">{$neria_trace_artisan|escape:'html':'UTF-8'}</td>
        </tr>
        {/if}
        {if $neria_trace_region}
        <tr>
          <td style="padding:10px 0; border-top:1px solid #e8d5b0; color:#8a8378; font-size:11px; letter-spacing:.06em; text-transform:uppercase;">{neria_admin key='trace.label_region'}</td>
          <td style="padding:10px 0; border-top:1px solid #e8d5b0;">{$neria_trace_region|escape:'html':'UTF-8'}</td>
        </tr>
        {/if}
        {if $neria_trace_duration}
        <tr>
          <td style="padding:10px 0; border-top:1px solid #e8d5b0; color:#8a8378; font-size:11px; letter-spacing:.06em; text-transform:uppercase;">{neria_admin key='trace.label_duration'}</td>
          <td style="padding:10px 0; border-top:1px solid #e8d5b0;">{$neria_trace_duration|escape:'html':'UTF-8'}</td>
        </tr>
        {/if}
        {if $neria_trace_date}
        <tr>
          <td style="padding:10px 0; border-top:1px solid #e8d5b0; color:#8a8378; font-size:11px; letter-spacing:.06em; text-transform:uppercase;">{neria_admin key='trace.label_certified'}</td>
          <td style="padding:10px 0; border-top:1px solid #e8d5b0;">{$neria_trace_date|escape:'html':'UTF-8'}</td>
        </tr>
        {/if}
      </table>

      {if $neria_trace_note}
        <p style="margin:24px 0 0; padding-top:20px; border-top:1px solid #e8d5b0; font-style:italic; font-size:15px; line-height:1.7; color:#5a5450;">
          {neria_admin key='trace.note_heading'}<br>
          "{$neria_trace_note|escape:'html':'UTF-8'}"
        </p>
      {/if}
    </div>

    <p style="text-align:center; margin-top:22px; font-size:12px; letter-spacing:.04em; color:#8a8378;">
      {neria_admin key='trace.unique_piece'}
    </p>
  {else}
    <h1 style="text-align:center; font-size:24px; font-weight:400; letter-spacing:.02em; margin-bottom:16px;">
      {neria_admin key='trace.not_found_title'}
    </h1>
    <p style="text-align:center; font-size:14px; line-height:1.7; color:#5a5450;">
      {neria_admin key='trace.not_found_text'}
    </p>
  {/if}

  <p style="text-align:center; margin-top:36px;">
    <a href="{$neria_shop_url|escape:'html':'UTF-8'}" style="display:inline-block; padding:12px 28px; border:1px solid #b38b59; color:#b38b59; text-decoration:none; font-size:13px; letter-spacing:.08em; text-transform:uppercase;">
      {neria_admin key='trace.back_to_shop'}
    </a>
  </p>

  <p style="text-align:center; margin-top:24px; font-size:12px; color:#6b6459;">{$neria_shop_name|escape:'html':'UTF-8'}</p>
</section>
