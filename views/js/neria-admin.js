/**
 * NERIA — Luxury Email Suite
 * JavaScript back-office PrestaShop
 *
 * Fonctionnalités :
 * - Synchronisation color picker ↔ champ hex
 * - Aperçu temps réel du design email (iframe)
 * - Chargement AJAX des traductions
 * - Génération aperçu signature
 */

/* global neriaConfig */

(function () {
    'use strict';

    // ── Initialisation ────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initColorPickers();
        initRangeSync();
        initPreviewFrame();
        initTranslationsLoader();
        initTranslationsReset();
        initSubjectAnalyzer();
        initSignaturePreview();
        initDesignReset();
        initFontCards();
        initFileInputs();
        initActionAnchor();
        initAlertAutoDismiss();
    });

    // ── Champ fichier personnalisé (affiche le nom choisi) ───────
    function initFileInputs() {
        var fields = document.querySelectorAll('.neria-file-field__input');

        fields.forEach(function (input) {
            var nameEl = input.parentNode
                ? input.parentNode.querySelector('.neria-file-field__name')
                : null;
            if (!nameEl) return;

            input.addEventListener('change', function () {
                if (input.files && input.files.length > 0) {
                    nameEl.textContent = input.files[0].name;
                } else {
                    nameEl.textContent =
                        input.getAttribute('data-default-text') || '';
                }
            });
        });
    }

    // ── Synchronisation color picker ↔ champ texte hex ───────────
    function initColorPickers() {
        var pickers = document.querySelectorAll('.neria-color-picker');

        pickers.forEach(function (picker) {
            var syncKey = picker.getAttribute('data-sync');
            if (!syncKey) return;

            var hexInputs = document.querySelectorAll(
                '[data-sync="' + syncKey + '"]'
            );

            // Color picker → champ texte + aperçu
            picker.addEventListener('input', function () {
                hexInputs.forEach(function (input) {
                    if (input !== picker) input.value = picker.value;
                });
                schedulePreviewUpdate();
            });

            // Champ texte → color picker
            hexInputs.forEach(function (hexInput) {
                if (hexInput === picker) return;

                hexInput.addEventListener('input', function () {
                    var val = hexInput.value.trim();
                    if (/^#[0-9a-fA-F]{6}$/.test(val)) {
                        picker.value = val;
                        schedulePreviewUpdate();
                    }
                });
            });
        });
    }

    // ── Synchronisation range ↔ champ number ─────────────────────
    function initRangeSync() {
        var ranges = document.querySelectorAll('.neria-range');

        ranges.forEach(function (range) {
            var targetId = range.getAttribute('data-sync-input');
            if (!targetId) return;

            var numberInput = document.getElementById(targetId);
            if (!numberInput) return;

            range.addEventListener('input', function () {
                numberInput.value = range.value;
                schedulePreviewUpdate();
            });

            numberInput.addEventListener('input', function () {
                range.value = numberInput.value;
                schedulePreviewUpdate();
            });
        });
    }

    // ── Aperçu temps réel ─────────────────────────────────────────
    var previewTimer = null;

    function schedulePreviewUpdate() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(updatePreview, 600);
    }

    function updatePreview() {
        var frame = document.getElementById('neria-preview-frame');
        var loading = document.getElementById('neria-preview-loading');
        if (!frame) return;

        var template = getSelectedTemplate();
        var lang     = getSelectedLang();

        if (!template || !lang) return;

        // Collecte les valeurs de design courantes
        var params = buildPreviewParams(template, lang);

        if (loading) loading.classList.add('is-loading');

        frame.onload = function () {
            if (loading) loading.classList.remove('is-loading');
        };

        // Construit l'URL de preview à partir de la page admin courante
        // (token + configure=neria déjà présents, comme le src serveur de
        // l'iframe), nettoyée des paramètres d'aperçu précédents. On ne dépend
        // pas de neriaConfig (qui peut ne pas être défini sur la page).
        var baseUrl = window.location.href.split('#')[0]
            .replace(/&neria_action=[^&]*/g, '')
            .replace(/&neria_template=[^&]*/g, '')
            .replace(/&neria_lang=[^&]*/g, '')
            .replace(/&preview_[^=&]*=[^&]*/g, '');

        var url = baseUrl
            + (baseUrl.indexOf('?') > -1 ? '&' : '?')
            + 'neria_action=preview'
            + '&neria_template=' + encodeURIComponent(template)
            + '&neria_lang=' + encodeURIComponent(lang)
            + '&' + params;

        frame.src = url;
    }

    function buildPreviewParams(template, lang) {
        var parts = [];

        var fields = [
            'color_background', 'color_container',
            'color_accent', 'color_text', 'container_width', 'logo_width',
            'font_size', 'line_height', 'heading_weight'
        ];

        fields.forEach(function (field) {
            var el = document.getElementById(field)
                  || document.querySelector('[name="' + field + '"]');
            if (el) {
                parts.push(
                    encodeURIComponent('preview_' + field)
                    + '=' + encodeURIComponent(el.value)
                );
            }
        });

        return parts.join('&');
    }

    function initPreviewFrame() {
        var frame   = document.getElementById('neria-preview-frame');
        var loading = document.getElementById('neria-preview-loading');

        // Cache l'overlay de chargement dès que l'iframe a fini de charger
        // (chargement initial via le src serveur, ou mises à jour JS).
        if (frame && loading) {
            frame.addEventListener('load', function () {
                loading.classList.remove('is-loading');
            });
        }

        var templateSel = document.getElementById('preview_template');
        var langSel     = document.getElementById('preview_lang');

        if (templateSel) {
            templateSel.addEventListener('change', schedulePreviewUpdate);
        }
        if (langSel) {
            langSel.addEventListener('change', schedulePreviewUpdate);
        }
    }

    function getSelectedTemplate() {
        var el = document.getElementById('preview_template');
        return el ? el.value : 'order_conf';
    }

    function getSelectedLang() {
        var el = document.getElementById('preview_lang');
        return el ? el.value : 'fr';
    }

    // ── Chargement AJAX des traductions ───────────────────────────
    function initTranslationsLoader() {
        var btn = document.getElementById('neria-trad-load');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var template = document.getElementById('neria-trad-template');
            var lang     = document.getElementById('neria-trad-lang');

            if (!template || !lang || !template.value) return;

            // Soumet le formulaire avec les paramètres de sélection
            var form = document.createElement('form');
            form.method = 'post';
            form.action = window.location.href;

            var fields = {
                neria_action: 'load_translations',
                neria_tab:    'translations',
                trad_template: template.value,
                trad_lang:    lang.value
            };

            Object.keys(fields).forEach(function (key) {
                var input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = key;
                input.value = fields[key];
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        });
    }

    // ── Réinitialisation traductions ─────────────────────────────
    function initTranslationsReset() {
        var btn = document.getElementById('neria-trad-reset');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var msg = btn.getAttribute('data-confirm') || 'Réinitialiser ?';
            if (!window.confirm(msg)) return;

            var form = document.createElement('form');
            form.method = 'post';
            form.action = window.location.href;

            var fields = {
                neria_action:  'reset_template',
                neria_tab:     'translations',
                trad_template: btn.getAttribute('data-template') || '',
                trad_lang:     btn.getAttribute('data-lang') || ''
            };

            Object.keys(fields).forEach(function (key) {
                var input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = key;
                input.value = fields[key];
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        });
    }

    // ── Aperçu signature ─────────────────────────────────────────
    function initSignaturePreview() {
        var btn = document.getElementById('neria-sig-preview-btn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var style     = document.getElementById('neria-sig-style');
            var color     = document.getElementById('sig_color');
            var name      = document.getElementById('founder_name');
            var title     = document.getElementById('founder_title');
            var container = document.getElementById('neria-sig-preview');

            if (!style || !container) return;

            var params = new URLSearchParams({
                neria_action: 'preview_signature',
                neria_tab:    'configure',
                sig_style:    style.value,
                sig_color:    color ? color.value : '#b38b59',
                sig_name:     name  ? name.value  : '',
                sig_title:    title ? title.value : ''
            });

            var baseUrl = window.location.href.split('?')[0];

            fetch(baseUrl + '?' + params.toString())
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.preview) {
                        container.innerHTML =
                            '<img src="' + data.preview
                            + '" class="neria-signature-preview__img" alt="Signature">';
                    }
                })
                .catch(function () {
                    container.innerHTML =
                        '<span class="neria-signature-preview__placeholder">'
                        + 'Erreur lors de la génération</span>';
                });
        });
    }

    // ── Reset design ──────────────────────────────────────────────
    function initDesignReset() {
        var btn = document.getElementById('neria-design-reset');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var msg = btn.getAttribute('data-confirm')
                   || 'Réinitialiser le design ?';

            if (!window.confirm(msg)) return;

            var form = document.createElement('form');
            form.method = 'post';
            form.action = window.location.href;

            var fields = {
                neria_action: 'reset_design',
                neria_tab:    'design'
            };

            Object.keys(fields).forEach(function (key) {
                var input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = key;
                input.value = fields[key];
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        });
    }

    // ── Assistant de rédaction de sujet — Variante B (onglet Traductions) ──
    function initSubjectAnalyzer() {
        var SPAM_WORDS = (window.NERIA_SPAM_TRIGGERS && window.NERIA_SPAM_TRIGGERS.length)
            ? window.NERIA_SPAM_TRIGGERS
            : ['gratuit', 'urgent', 'gagner', 'offre', 'promo', 'free', 'win', 'discount'];

        var allLabels = window.NERIA_LABELS || {};
        var lang      = window.NERIA_LANG   || 'en';
        var L = allLabels[lang] || allLabels['en'] || {
            t: 'Email subject', s: '⚠ Risk words:', u: 'chars',
            e: 'empty', c: 'too short', o: 'optimal', l1: 'a bit long', l2: 'too long'
        };

        function isCJK(str) {
            return /[　-鿿가-힯぀-ヿ＀-￯؀-ۿ]/.test(str);
        }

        var widgets = document.querySelectorAll('.neria-subject-analyzer[data-target]');
        widgets.forEach(function (widget) {
            var fieldId = widget.getAttribute('data-target');
            var field   = document.getElementById(fieldId);
            if (!field) return;

            var elTitle = widget.querySelector('.nsa-title');
            var elChars = widget.querySelector('.nsa-chars');
            var elBar   = widget.querySelector('.nsa-bar');
            var elScore = widget.querySelector('.nsa-score');
            var elSpam  = widget.querySelector('.nsa-spam');

            if (elTitle) { elTitle.textContent = '✦ ' + L.t; }

            function analyze() {
                var val = field.value, len = val.length, score = 100, charColor, charLabel;
                var cjk = isCJK(val);

                if (len === 0) {
                    score -= 20; charColor = '#e05c5c'; charLabel = '0 ' + L.u + ' — ' + L.e;
                } else if (cjk) {
                    if (len < 8)        { score -= 10; charColor = '#b8600a'; charLabel = len + ' ' + L.u + ' — ' + L.c; }
                    else if (len <= 20)  {              charColor = '#4a9e6b'; charLabel = len + ' ' + L.u + ' — ' + L.o; }
                    else if (len <= 35)  { score -= 5;  charColor = '#b8600a'; charLabel = len + ' ' + L.u + ' — ' + L.l1; }
                    else                 { score -= 15; charColor = '#e05c5c'; charLabel = len + ' ' + L.u + ' — ' + L.l2; }
                } else {
                    if (len < 20)        { score -= 10; charColor = '#b8600a'; charLabel = len + ' ' + L.u + ' — ' + L.c; }
                    else if (len <= 50)   {              charColor = '#4a9e6b'; charLabel = len + ' ' + L.u + ' — ' + L.o; }
                    else if (len <= 70)   { score -= 5;  charColor = '#b8600a'; charLabel = len + ' ' + L.u + ' — ' + L.l1; }
                    else                  { score -= 15; charColor = '#e05c5c'; charLabel = len + ' ' + L.u + ' — ' + L.l2; }
                }

                var lc = val.toLowerCase(), found = [];
                SPAM_WORDS.forEach(function (w) {
                    if (lc.indexOf(w.toLowerCase()) !== -1 && found.indexOf(w) === -1) { found.push(w); }
                });
                score -= Math.min(24, found.length * 8);
                var caps = 0, maxC = 0;
                for (var i = 0; i < val.length; i++) {
                    if (val[i] >= 'A' && val[i] <= 'Z') { maxC = Math.max(maxC, ++caps); } else { caps = 0; }
                }
                if (maxC >= 6) { score -= 10; }
                if (val.indexOf('!!!') !== -1) { score -= 5; }
                score = Math.max(0, Math.min(100, score));

                var barColor = score >= 80 ? '#4a9e6b' : score >= 60 ? '#b8600a' : '#e05c5c';
                elChars.textContent    = charLabel;
                elChars.style.color    = charColor;
                elScore.textContent    = score + '/100';
                elScore.style.color    = barColor;
                elBar.style.width      = score + '%';
                elBar.style.background = barColor;
                elSpam.style.display   = found.length ? '' : 'none';
                elSpam.textContent     = found.length ? L.s + ' ' + found.join(', ') : '';
            }

            field.addEventListener('input', analyze);
            analyze();
        });
    }

    // ── Sélection des polices (font cards) ───────────────────────
    function initFontCards() {
        var cards = document.querySelectorAll('.neria-font-card');

        cards.forEach(function (card) {
            card.addEventListener('click', function () {
                var radio = card.querySelector('.neria-font-card__radio');
                if (!radio) return;

                // Désélectionne toutes les cartes du même groupe
                var groupName = radio.name;
                document.querySelectorAll(
                    '[name="' + groupName + '"]'
                ).forEach(function (r) {
                    r.closest('.neria-font-card')
                        .classList.remove('neria-font-card--selected');
                });

                // Sélectionne la carte courante
                radio.checked = true;
                card.classList.add('neria-font-card--selected');
            });
        });
    }

    // ── Repositionnement contextuel des messages d'action ─────────
    // Problème : la bannière de message s'affiche en haut de page, au-dessus de
    // la navigation ; après un POST la page revient en haut et l'utilisateur perd
    // sa position, surtout sur les onglets longs (stats, configure, help, bounces).
    //
    // Mécanisme : le serveur écrit l'action traitée sur la bannière
    // (data-neria-action). On retrouve la section (.neria-section) qui contient le
    // formulaire de cette même action, on y déplace la bannière et on défile
    // jusqu'à elle. Lire l'action depuis le DOM (et non un sessionStorage écrit au
    // clic) rend le comportement fiable même au rechargement (Ctrl+F5).
    // Dégradation gracieuse : sans JS, la bannière reste lisible en haut.
    function initActionAnchor() {
        var alertEl = document.querySelector('[data-neria-alert]');
        if (!alertEl) { return; }

        var action = alertEl.getAttribute('data-neria-action');
        if (!action) { return; }

        // La valeur ne contient que [a-z_] : sélecteur d'attribut sûr.
        var marker = document.querySelector('input[name="neria_action"][value="' + action + '"]');
        var section = marker && marker.closest ? marker.closest('.neria-section') : null;
        if (!section) { return; } // section non identifiée : la bannière reste en haut.

        section.insertBefore(alertEl, section.firstChild);
        try {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (err) {
            section.scrollIntoView();
        }
    }

    // ── Durée de vie des messages d'action ────────────────────────
    // Le message n'a pas de durée côté serveur (il réapparaît tant que
    // neria_action est dans la requête, ex. au rafraîchissement qui renvoie le
    // formulaire). On lui donne ici une fin de vie côté client : disparition
    // automatique après un délai, plus un bouton de fermeture immédiate.
    var ALERT_TTL_MS = 10000;
    function initAlertAutoDismiss() {
        var alertEl = document.querySelector('[data-neria-alert]');
        if (!alertEl) { return; }

        var timer = null;
        function dismiss() {
            if (timer) { clearTimeout(timer); timer = null; }
            alertEl.style.transition = 'opacity .4s ease';
            alertEl.style.opacity = '0';
            setTimeout(function () {
                if (alertEl.parentNode) { alertEl.parentNode.removeChild(alertEl); }
            }, 400);
        }

        // Bouton de fermeture (✕) aligné à droite de la bannière.
        var close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', 'Fermer');
        close.innerHTML = '✕';
        close.style.cssText = 'float:right; margin-left:12px; background:none; border:none;'
            + ' cursor:pointer; font-size:13px; line-height:1; color:inherit; opacity:.55;';
        close.addEventListener('mouseenter', function () { close.style.opacity = '1'; });
        close.addEventListener('mouseleave', function () { close.style.opacity = '.55'; });
        close.addEventListener('click', dismiss);
        alertEl.insertBefore(close, alertEl.firstChild);

        // Disparition automatique ; suspendue tant que la souris survole le message.
        timer = setTimeout(dismiss, ALERT_TTL_MS);
        alertEl.addEventListener('mouseenter', function () {
            if (timer) { clearTimeout(timer); timer = null; }
        });
        alertEl.addEventListener('mouseleave', function () {
            if (!timer) { timer = setTimeout(dismiss, ALERT_TTL_MS); }
        });
    }


    // ── Boutons flottants haut / bas ─────────────────────────────
    (function () {
        var btnTop = document.getElementById('neria-scroll-top');
        var btnBot = document.getElementById('neria-scroll-bot');
        if (!btnTop || !btnBot) { return; }

        // Trouve le conteneur scrollable (PS BO utilise souvent un div interne)
        function getScroller() {
            var el = document.querySelector('.main-page-content, #main, .content-div, body');
            return el || document.documentElement;
        }

        btnTop.onclick = function () {
            var s = getScroller();
            s.scrollTo ? s.scrollTo(0, 0) : (s.scrollTop = 0);
            window.scrollTo(0, 0);
        };
        btnBot.onclick = function () {
            var s = getScroller();
            var bottom = s.scrollHeight || document.body.scrollHeight;
            s.scrollTo ? s.scrollTo(0, bottom) : (s.scrollTop = bottom);
            window.scrollTo(0, document.body.scrollHeight);
        };
    })();

})();
