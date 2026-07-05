<!DOCTYPE html>
<html lang="{$neria_prefs_lang|escape:'html'}" dir="{$neria_prefs_dir|escape:'html'}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$neria_shop_name|escape:'html'} — {neria_admin key='front.prefs_title_suffix'}</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Georgia', serif;
      background: #f9f6f1;
      color: #2c2c2c;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 20px;
    }
    .card {
      background: #fff;
      border: 1px solid #e8d5b0;
      border-radius: 12px;
      max-width: 560px;
      width: 100%;
      padding: 48px 44px;
      box-shadow: 0 4px 24px rgba(0,0,0,.06);
    }
    .logo {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 3px;
      text-transform: uppercase;
      color: #b38b59;
      margin-bottom: 28px;
    }
    h1 {
      font-size: 22px;
      font-weight: 400;
      color: #2c2c2c;
      margin-bottom: 8px;
      line-height: 1.3;
    }
    .subtitle {
      font-size: 13px;
      color: #888;
      margin-bottom: 32px;
      line-height: 1.6;
    }
    .email-badge {
      display: inline-block;
      background: #f9f6f1;
      border: 1px solid #e8d5b0;
      border-radius: 20px;
      padding: 4px 14px;
      font-size: 12px;
      color: #666;
      margin-bottom: 28px;
    }
    .pref-list { list-style: none; display: flex; flex-direction: column; gap: 10px; margin-bottom: 32px; }
    .pref-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 16px;
      border: 1px solid #e8d5b0;
      border-radius: 8px;
      background: #fdfaf6;
      transition: border-color .15s;
    }
    .pref-item:hover { border-color: #b38b59; }
    .pref-label { font-size: 14px; color: #2c2c2c; }
    /* Toggle switch */
    .toggle { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
    .toggle input { opacity: 0; width: 0; height: 0; }
    .slider {
      position: absolute; inset: 0;
      background: #ddd;
      border-radius: 24px;
      cursor: pointer;
      transition: background .2s;
    }
    .slider::before {
      content: '';
      position: absolute;
      width: 18px; height: 18px;
      left: 3px; bottom: 3px;
      background: #fff;
      border-radius: 50%;
      transition: transform .2s;
      box-shadow: 0 1px 3px rgba(0,0,0,.2);
    }
    input:checked + .slider { background: #b38b59; }
    input:checked + .slider::before { transform: translateX(20px); }
    .btn-save {
      width: 100%;
      padding: 14px;
      background: #2c2c2c;
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 14px;
      font-family: inherit;
      letter-spacing: 1px;
      cursor: pointer;
      transition: background .15s;
      text-transform: uppercase;
    }
    .btn-save:hover { background: #b38b59; }
    .success-banner {
      background: #f0faf0;
      border: 1px solid #a8d5a8;
      border-radius: 8px;
      padding: 14px 18px;
      font-size: 13px;
      color: #2d6a2d;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .error-banner {
      background: #fdf0f0;
      border: 1px solid #e8b0b0;
      border-radius: 8px;
      padding: 14px 18px;
      font-size: 13px;
      color: #8b2020;
      margin-bottom: 24px;
    }
    .unsub-link {
      display: block;
      text-align: center;
      margin-top: 24px;
      font-size: 12px;
      color: #aaa;
      text-decoration: none;
    }
    .unsub-link:hover { color: #888; text-decoration: underline; }
    .divider { border: none; border-top: 1px solid #e8d5b0; margin: 24px 0; }
  </style>
</head>
<body>
<div class="card">
  <div class="logo">{$neria_shop_name|escape:'html'}</div>

  {if isset($neria_prefs_error) && $neria_prefs_error}
    <h1>{neria_admin key='front.prefs_invalid_link_title'}</h1>
    <div class="error-banner">
      {neria_admin key='front.prefs_invalid_link_body'}
    </div>
    <a href="{$neria_shop_url|escape:'html'}" style="color:#b38b59;font-size:13px;">{neria_admin key='front.prefs_back_to_shop'}</a>

  {else}

    {if $neria_prefs_saved}
      <div class="success-banner">
        {neria_admin key='front.prefs_saved_success'}
      </div>
    {/if}

    <h1>{neria_admin key='front.prefs_heading'}</h1>
    <p class="subtitle">{neria_admin key='front.prefs_subtitle'}</p>

    {if $neria_prefs_email !== ''}
      <div class="email-badge">✉ {$neria_prefs_email|escape:'html'}</div>
    {/if}

    <form method="post" action="">
      <input type="hidden" name="email" value="{$neria_prefs_email|escape:'html'}">
      <input type="hidden" name="token" value="{$neria_prefs_token|escape:'html'}">
      <input type="hidden" name="lang"  value="{$neria_prefs_lang|escape:'html'}">
      <input type="hidden" name="cid"   value="{$neria_prefs_cid|intval}">
      <input type="hidden" name="neria_prefs_save" value="1">

      <ul class="pref-list">
        {foreach $neria_prefs_cats as $cat}
          <li class="pref-item">
            <span class="pref-label">{$neria_prefs_labels[$cat]|escape:'html'}</span>
            <label class="toggle">
              <input type="checkbox" name="pref_{$cat|escape:'html'}" value="1"
                {if isset($neria_prefs[$cat]) && $neria_prefs[$cat]}checked{/if}>
              <span class="slider"></span>
            </label>
          </li>
        {/foreach}
      </ul>

      <button type="submit" class="btn-save">{neria_admin key='front.prefs_save_btn'}</button>
    </form>

    <hr class="divider">

    {if $neria_unsub_url !== ''}
      <a href="{$neria_unsub_url|escape:'html'}" class="unsub-link">
        {neria_admin key='front.prefs_unsub_all'}
      </a>
    {/if}

  {/if}
</div>
</body>
</html>
