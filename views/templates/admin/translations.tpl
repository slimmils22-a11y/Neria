{**
 * NERIA — translations.tpl
 * Onglet Traductions — Édition des textes par template et langue
 * Fix 3 : IDs uniques sur les selects (neria-trad-* uniquement)
 *}
{literal}<style>
#neria-delete-modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.45);z-index:99999;align-items:center;justify-content:center;}
#neria-delete-modal-overlay.active{display:flex;}
#neria-delete-modal{background:#fff;border-radius:10px;padding:32px 28px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.18);text-align:center;}
#neria-delete-modal h4{margin:0 0 12px;font-size:16px;color:#1a1a1a;}
#neria-delete-modal p{margin:0 0 24px;font-size:13px;color:#666;line-height:1.6;}
#neria-delete-modal .neria-modal-key{display:inline-block;background:#f3ede4;color:#b38b59;border-radius:4px;padding:2px 8px;font-family:monospace;font-size:12px;margin-bottom:16px;}
#neria-delete-modal-actions{display:flex;gap:10px;justify-content:center;}
</style>{/literal}
<div id="neria-delete-modal-overlay">
  <div id="neria-delete-modal">
    <h4>⚠ {neria_admin key='translations.delete_modal_title'}</h4>
    <p id="neria-delete-modal-msg"></p>
    <span id="neria-delete-modal-key" class="neria-modal-key"></span>
    <div id="neria-delete-modal-actions">
      <button type="button" class="neria-btn neria-btn--secondary" onclick="neriaCloseDeleteModal();">
        {neria_admin key='common.cancel'}
      </button>
      <button type="button" class="neria-btn neria-btn--danger" id="neria-delete-modal-confirm">
        {neria_admin key='translations.delete_btn'}
      </button>
    </div>
  </div>
</div>
<script>
var _neriaDeleteForm = null;
function neriaConfirmDelete(btn) {
  _neriaDeleteForm = btn.closest('form');
  document.getElementById('neria-delete-modal-msg').textContent  = btn.getAttribute('data-confirm');
  document.getElementById('neria-delete-modal-key').textContent  = btn.getAttribute('data-key');
  document.getElementById('neria-delete-modal-overlay').classList.add('active');
}
function neriaCloseDeleteModal() {
  document.getElementById('neria-delete-modal-overlay').classList.remove('active');
  _neriaDeleteForm = null;
}
document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('neria-delete-modal-confirm').addEventListener('click', function() {
    if (_neriaDeleteForm) { _neriaDeleteForm.submit(); }
  });
  document.getElementById('neria-delete-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) { neriaCloseDeleteModal(); }
  });
});
</script>
<script>window.NERIA_SPAM_TRIGGERS = {$subject_spam_triggers_json|default:'[]'};
window.NERIA_LABELS = {$nsa_labels_json|default:'{}'};
window.NERIA_LANG   = '{$selected_lang|default:'en'}';</script>

<div class="neria-section">
  <h2 class="neria-section__title">{neria_admin key='translations.page_title'}</h2>
  <p class="neria-section__desc">{neria_admin key='translations.page_desc'}</p>

  <div class="neria-card" style="margin-top:20px;background:var(--neria-bg-subtle,#f9f7f4);border-left:3px solid var(--neria-accent,#b8976a);padding:18px 24px;">
    <h4 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:var(--neria-accent,#b8976a);">{neria_admin key='translations.howto_title'}</h4>
    <ol style="margin:0;padding-left:18px;line-height:1.9;font-size:13px;color:var(--neria-text-muted,#888);">
      <li>{neria_admin key='translations.howto_step1'}</li>
      <li>{neria_admin key='translations.howto_step2'}</li>
      <li>{neria_admin key='translations.howto_step3'}</li>
    </ol>
    <p style="margin:12px 0 0;font-size:12px;color:var(--neria-text-muted,#aaa);">
      📊 {neria_admin key='translations.subject_hint'}
    </p>
    <p style="margin:8px 0 0;font-size:12px;color:var(--neria-text-muted,#aaa);">
      🔒 {neria_admin key='translations.custom_preserved'}
    </p>
  </div>
</div>

<div class="neria-section">
  <div class="neria-trad-selectors">

    <div class="neria-form-group">
      <label class="neria-label" for="neria-trad-template">
        {neria_admin key='common.template'}
      </label>
      <select id="neria-trad-template" name="trad_template" class="neria-select">
        <option value="">{neria_admin key='translations.choose_template'}</option>
        {foreach $template_labels as $key => $label}
          <option value="{$key}"
            {if isset($selected_template) && $selected_template === $key}selected{/if}>
            {$label}
          </option>
        {/foreach}
      </select>
    </div>

    <div class="neria-form-group">
      <label class="neria-label" for="neria-trad-lang">
        {neria_admin key='common.language'}
      </label>
      <select id="neria-trad-lang" name="trad_lang" class="neria-select">
        {foreach $lang_labels as $code => $name}
          <option value="{$code}"
            {if isset($selected_lang) && $selected_lang === $code}selected{/if}>
            {$lang_flags[$code]|default:''} {$name}
          </option>
        {/foreach}
      </select>
    </div>

    <div class="neria-form-group">
      <label class="neria-label" style="visibility:hidden">.</label>
      <button type="button" class="neria-btn neria-btn--primary" id="neria-trad-load">
        {neria_admin key='translations.load'}
      </button>
    </div>

    <div class="neria-form-group" style="margin-left:auto;">
      <label class="neria-label" style="visibility:hidden">.</label>
      <form method="post" style="margin:0;">
        <input type="hidden" name="neria_action" value="reload_all_translations">
        <button type="submit" class="neria-btn neria-btn--primary"
                title="{neria_admin key='translations.reload_all_title'}">
          ↺ {neria_admin key='translations.reload_all'}
        </button>
      </form>
    </div>

  </div>
</div>

{if isset($translations) && $translations}

  <div class="neria-section" id="neria-trad-editor">
    <div class="neria-trad-header">
      <h2 class="neria-section__title">
        {$template_labels[$selected_template]|default:$selected_template}
        <span class="neria-lang-chip">
          {$lang_flags[$selected_lang]|default:''}
          {$lang_labels[$selected_lang]|default:$selected_lang}
        </span>
      </h2>

      <div class="neria-trad-header__actions">
        <button type="button"
                class="neria-btn neria-btn--primary neria-btn--sm"
                id="neria-trad-reset"
                data-template="{$selected_template}"
                data-lang="{$selected_lang}"
                data-confirm="Restaurer les textes Neria d'origine ? Vos modifications seront sauvegardees dans l'historique.">
          {neria_admin key='translations.reset_template'}
        </button>
      </div>
    </div>

    <form method="post" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="neria_action"   value="save_translations">
      <input type="hidden" name="neria_tab"       value="translations">
      <input type="hidden" name="trad_template"   value="{$selected_template}">
      <input type="hidden" name="trad_lang"       value="{$selected_lang}">

      <div class="neria-trad-fields">
        {foreach $translations as $key => $value}
          <div class="neria-form-group neria-trad-field">

            <label class="neria-label" for="trad_field_{$key}">
              {$key}
              {if $is_custom[$key]|default:false}
                <span class="neria-badge neria-badge--accent">
                  {neria_admin key='translations.custom_badge'}
                </span>
              {/if}
            </label>

            {assign var="is_subject_field" value=($key === 'greeting_main' || $key === 'fallback_subject' || $key === 'subject')}
            {if $value|strlen > 120}
              <textarea id="trad_field_{$key}"
                        name="fields[{$key}]"
                        class="neria-textarea neria-textarea--auto"
                        rows="3"{if $is_subject_field} data-neria-subject="1"{/if}>{$value|escape:'html'}</textarea>
            {else}
              <input type="text"
                     id="trad_field_{$key}"
                     name="fields[{$key}]"
                     class="neria-input"
                     value="{$value|escape:'html'}"{if $is_subject_field} data-neria-subject="1"{/if}>
            {/if}
            {if $is_subject_field}
            <div id="nsa_wrap_{$key}" style="margin-top:8px;">
              <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span id="nsa_title_{$key}" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-gold,#b38b59);">&#x2726;</span>
                <span id="nsa_chars_{$key}" style="font-size:12px;color:var(--neria-text-muted,#888);"></span>
                <div style="flex:1;min-width:80px;height:3px;border-radius:2px;background:var(--neria-border,#e8e0d5);overflow:hidden;">
                  <span id="nsa_bar_{$key}" style="display:block;height:100%;width:0;border-radius:2px;transition:width .25s,background .25s;background:#ccc;"></span>
                </div>
                <span id="nsa_score_{$key}" style="font-size:13px;font-weight:700;color:#ccc;min-width:52px;text-align:right;">—/100</span>
              </div>
              <div id="nsa_spam_{$key}" style="display:none;margin-top:5px;font-size:11px;color:#c0392b;line-height:1.4;"></div>
            </div>
            <script>
            (function() {
              var SPAM = {$subject_spam_triggers_json|default:'[]'};
              var lang  = '{$selected_lang|default:'en'}';
              var NSA_L = {$nsa_labels_json|default:'{}'};
              var L = NSA_L[lang] || NSA_L['en'];
              var field  = document.getElementById('trad_field_{$key}');
              var eTitle = document.getElementById('nsa_title_{$key}');
              var eChars = document.getElementById('nsa_chars_{$key}');
              var eBar   = document.getElementById('nsa_bar_{$key}');
              var eScore = document.getElementById('nsa_score_{$key}');
              var eSpam  = document.getElementById('nsa_spam_{$key}');
              if (!field || !eChars) return;
              if (eTitle) eTitle.textContent = '✦ ' + L.t;
              function isCJK(str) {
                return /[　-鿿가-힯぀-ヿ＀-￯؀-ۿ]/.test(str);
              }
              function run() {
                var v = field.value, n = v.length, s = 100, cc, cl;
                var cjk = isCJK(v);
                if (n === 0) {
                  s -= 20; cc = '#e05c5c'; cl = '0 ' + L.u + ' — ' + L.e;
                } else if (cjk) {
                  if (n < 8)       { s -= 10; cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.c; }
                  else if (n <= 20){           cc = '#4a9e6b'; cl = n + ' ' + L.u + ' — ' + L.o; }
                  else if (n <= 35){ s -= 5;  cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.l1; }
                  else             { s -= 15; cc = '#e05c5c'; cl = n + ' ' + L.u + ' — ' + L.l2; }
                } else {
                  if (n < 20)      { s -= 10; cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.c; }
                  else if (n <= 50){           cc = '#4a9e6b'; cl = n + ' ' + L.u + ' — ' + L.o; }
                  else if (n <= 70){ s -= 5;  cc = '#b8600a'; cl = n + ' ' + L.u + ' — ' + L.l1; }
                  else             { s -= 15; cc = '#e05c5c'; cl = n + ' ' + L.u + ' — ' + L.l2; }
                }
                var lc = v.toLowerCase(), found = [];
                SPAM.forEach(function(w){ if (lc.indexOf(w) !== -1 && found.indexOf(w) === -1) found.push(w); });
                s -= Math.min(24, found.length * 8);
                var caps = 0, maxC = 0;
                for (var i = 0; i < v.length; i++) {
                    if (v[i] >= 'A' && v[i] <= 'Z') { maxC = Math.max(maxC, ++caps); } else { caps = 0; }
                }
                if (maxC >= 6) s -= 10;
                if (v.indexOf('!!!') !== -1) s -= 5;
                s = Math.max(0, Math.min(100, s));
                var bc = s >= 80 ? '#4a9e6b' : s >= 60 ? '#b8600a' : '#e05c5c';
                eChars.textContent = cl; eChars.style.color = cc;
                eScore.textContent = s + '/100'; eScore.style.color = bc;
                eBar.style.width = s + '%'; eBar.style.background = bc;
                eSpam.style.display = found.length ? '' : 'none';
                eSpam.textContent = found.length ? L.s + ' ' + found.join(', ') : '';
              }
              field.addEventListener('input', run);
              run();
            })();
            </script>
            {/if}

          </div>
        {/foreach}
      </div>

      <div class="neria-form-actions neria-form-actions--sticky">
        <button type="submit" class="neria-btn neria-btn--primary">
          {neria_admin key='translations.save'}
        </button>
      </div>

    </form>

    {* Changelog des modifications *}
    {if isset($translations) && $translations}
    <div class="neria-section neria-changelog" id="neria-changelog">
      <div class="neria-trad-header">
        <button type="button" class="neria-btn neria-btn--primary neria-btn--sm"
                onclick="document.getElementById('neria-changelog-body').classList.toggle('neria-changelog--hidden');">
          {neria_admin key='translations.history_title'}
          <span class="neria-badge" style="margin-left:6px;font-size:11px;background:rgba(255,255,255,.2);color:#fff;border-radius:10px;padding:1px 7px;">{$translation_history|count}</span>
          <span style="margin-left:6px;font-size:10px;">&#9660;</span>
        </button>
      </div>

      <div id="neria-changelog-body">
        {if isset($translation_history) && $translation_history}
        <table class="neria-changelog-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Champ</th>
              <th>Ancienne valeur</th>
              <th>Nouvelle valeur</th>
              <th>Auteur</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {foreach $translation_history as $entry}
            <tr>
              <td class="neria-changelog__date">{$entry.date_formatted}</td>
              <td class="neria-changelog__key"><code>{$entry.translation_key|escape:'html'}</code></td>
              <td class="neria-changelog__val neria-changelog__val--old">{$entry.old_value|truncate:90|escape:'html'}</td>
              <td class="neria-changelog__val">{$entry.new_value|truncate:90|escape:'html'}</td>
              <td class="neria-changelog__author">{$entry.author|escape:'html'}</td>
              <td class="neria-changelog__action" style="white-space:nowrap;display:flex;gap:6px;">
                <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;">
                  <input type="hidden" name="neria_action"  value="restore_translation">
                  <input type="hidden" name="neria_tab"      value="translations">
                  <input type="hidden" name="trad_template"  value="{$selected_template|escape:'html'}">
                  <input type="hidden" name="trad_lang"      value="{$selected_lang|escape:'html'}">
                  <input type="hidden" name="id_history"     value="{$entry.id_history|intval}">
                  <button type="submit" class="neria-btn neria-btn--primary neria-btn--xs"
                          onclick="return confirm('{neria_admin key='translations.restore_confirm'|escape:'javascript'}');">
                    {neria_admin key='translations.restore_btn'}
                  </button>
                </form>
                <form method="post" action="{$smarty.server.REQUEST_URI}" style="margin:0;" class="neria-delete-history-form">
                  <input type="hidden" name="neria_action"  value="delete_history">
                  <input type="hidden" name="neria_tab"      value="translations">
                  <input type="hidden" name="trad_template"  value="{$selected_template|escape:'html'}">
                  <input type="hidden" name="trad_lang"      value="{$selected_lang|escape:'html'}">
                  <input type="hidden" name="id_history"     value="{$entry.id_history|intval}">
                  <button type="button" class="neria-btn neria-btn--danger neria-btn--xs"
                          data-confirm="{neria_admin key='translations.delete_history_confirm'}"
                          data-key="{$entry.translation_key|escape:'html'}"
                          onclick="neriaConfirmDelete(this);">
                    {neria_admin key='translations.delete_btn'}
                  </button>
                </form>
              </td>
            </tr>
            {/foreach}
          </tbody>
        </table>
        {else}
        <p class="neria-changelog__empty">Aucune modification enregistree pour ce template dans cette langue.</p>
        {/if}
      </div>
    </div>
    {/if}

  </div>

{else}

  <div class="neria-empty-state">
    <span class="neria-empty-state__icon">❡</span>
    <p>{neria_admin key='translations.empty'}</p>
  </div>

{/if}

{* Section Variante B — visible uniquement si un test A/B est actif pour ce template *}
{if isset($abtest_active) && $abtest_active && isset($translations_b) && $translations_b}

  <div class="neria-section" id="neria-trad-editor-b">
    <div class="neria-trad-header">
      <h2 class="neria-section__title">
        {$template_labels[$selected_template]|default:$selected_template}
        <span class="neria-lang-chip">
          {$lang_flags[$selected_lang]|default:''}
          {$lang_labels[$selected_lang]|default:$selected_lang}
        </span>
        <span class="neria-badge neria-badge--accent" style="margin-left:8px;">Variante B</span>
      </h2>
      <p class="neria-section__desc" style="margin-top:4px;">
        Textes alternatifs envoyés à la moitié de vos clients. Modifiez uniquement les champs que vous voulez tester — laissez les autres identiques à la variante A.
      </p>
    </div>

    <form method="post" action="{$smarty.server.REQUEST_URI}">
      <input type="hidden" name="neria_action"   value="save_variant_b">
      <input type="hidden" name="neria_tab"       value="translations">
      <input type="hidden" name="trad_template"   value="{$selected_template}">
      <input type="hidden" name="trad_lang"       value="{$selected_lang}">
      <input type="hidden" name="id_abtest_b"     value="{$id_abtest_b}">

      <div class="neria-trad-fields">
        {foreach $translations_b as $key => $value}
          <div class="neria-form-group neria-trad-field">
            <label class="neria-label" for="trad_b_{$key}">{$key}</label>
            {assign var="is_subject_field_b" value=($key === 'greeting_main' || $key === 'fallback_subject' || $key === 'subject')}
            {if $value|strlen > 120}
              <textarea id="trad_b_{$key}"
                        name="fields_b[{$key}]"
                        class="neria-textarea neria-textarea--auto"
                        rows="3"{if $is_subject_field_b} data-neria-subject="1"{/if}>{$value|escape:'html'}</textarea>
            {else}
              <input type="text"
                     id="trad_b_{$key}"
                     name="fields_b[{$key}]"
                     class="neria-input"
                     value="{$value|escape:'html'}"{if $is_subject_field_b} data-neria-subject="1"{/if}>
            {/if}
            {if $is_subject_field_b}
            <div class="neria-subject-analyzer" style="margin-top:8px;" data-target="trad_b_{$key}">
              <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span class="nsa-title" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--neria-gold,#b38b59);">&#x2726;</span>
                <span class="nsa-chars" style="font-size:12px;color:var(--neria-text-muted,#888);">0 car.</span>
                <div style="flex:1;min-width:80px;height:3px;border-radius:2px;background:var(--neria-border,#e8e0d5);overflow:hidden;">
                  <span class="nsa-bar" style="display:block;height:100%;width:0;border-radius:2px;transition:width .25s,background .25s;background:#ccc;"></span>
                </div>
                <span class="nsa-score" style="font-size:13px;font-weight:700;color:var(--neria-text-muted,#aaa);min-width:52px;text-align:right;">&#8212;/100</span>
              </div>
              <div class="nsa-spam" style="display:none;margin-top:5px;font-size:11px;color:#c0392b;line-height:1.4;"></div>
            </div>
            {/if}
          </div>
        {/foreach}
      </div>

      <div class="neria-form-actions neria-form-actions--sticky">
        <button type="submit" class="neria-btn neria-btn--primary">
          {neria_admin key='translations.save'} — Variante B
        </button>
      </div>
    </form>
  </div>

{/if}
