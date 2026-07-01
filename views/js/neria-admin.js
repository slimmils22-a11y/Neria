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
        initSectionReset();
        initFontCards();
        initFileInputs();
        initActionAnchor();
        initAlertAutoDismiss();
        initWatchdogAnalyze();
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

    function fitPreviewFrame(f) {
        try {
            var doc = f.contentWindow.document;
            var h = Math.max(
                doc.body.scrollHeight, doc.body.offsetHeight,
                doc.documentElement.scrollHeight, doc.documentElement.offsetHeight
            );
            if (h > 100) { f.style.height = (h + 80) + 'px'; }
        } catch(e) {}
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
            var f = frame;
            setTimeout(function() { fitPreviewFrame(f); }, 100);
            setTimeout(function() { fitPreviewFrame(f); }, 800);
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
                var f = frame;
                setTimeout(function() { fitPreviewFrame(f); }, 100);
                setTimeout(function() { fitPreviewFrame(f); }, 800);
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

    // ── Reset par section (non-destructif : sans sauvegarde) ─────
    function initSectionReset() {
        document.querySelectorAll('.neria-section-reset').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                // Format : "field1:value1,field2:value2"
                var raw = btn.getAttribute('data-reset') || '';
                var defaults = {};
                raw.split(',').forEach(function (pair) {
                    var colon = pair.indexOf(':');
                    if (colon > 0) {
                        defaults[pair.slice(0, colon).trim()] = pair.slice(colon + 1).trim();
                    }
                });
                applySectionDefaults(defaults);
            });
        });
    }

    function applySectionDefaults(defaults) {
        Object.keys(defaults).forEach(function (field) {
            var val = String(defaults[field]);

            // Couleur
            var picker = document.querySelector(
                'input[name="' + field + '"][type="color"]'
            );
            if (picker) {
                picker.value = val;
                document.querySelectorAll('[data-sync="' + field + '"]')
                    .forEach(function (el) { el.value = val; });
                schedulePreviewUpdate();
                return;
            }

            // Range / Number pair
            var range = document.getElementById(field + '_range');
            if (range) {
                range.value = val;
                var num = document.getElementById(field)
                       || document.getElementById(field + '_number');
                if (num) num.value = val;
                schedulePreviewUpdate();
                return;
            }

            // Select
            var sel = document.querySelector('select[name="' + field + '"]');
            if (sel) {
                sel.value = val;
                schedulePreviewUpdate();
                return;
            }

            // Radio (radius / separator / shadow / heading_weight)
            var target = document.querySelector(
                'input[name="' + field + '"][value="' + val + '"]'
            );
            if (target) {
                document.querySelectorAll('input[name="' + field + '"]')
                    .forEach(function (r) {
                        r.checked = false;
                        // Réinitialise les previews inline (radius, sep, shadow)
                        var prev = r.parentElement &&
                            r.parentElement.querySelector(
                                '[data-radius],[data-sep],[data-shadow]'
                            );
                        if (prev) {
                            prev.style.borderColor = '#e8d5b0';
                            prev.style.background  = '#fff';
                        }
                        // Réinitialise les neria-radio-card
                        var card = r.closest('.neria-radio-card');
                        if (card) card.classList.remove('neria-radio-card--selected');
                    });

                target.checked = true;
                var tPrev = target.parentElement &&
                    target.parentElement.querySelector(
                        '[data-radius],[data-sep],[data-shadow]'
                    );
                if (tPrev) {
                    tPrev.style.borderColor = '#b38b59';
                    tPrev.style.background  = '#f9f4ef';
                }
                var tCard = target.closest('.neria-radio-card');
                if (tCard) tCard.classList.add('neria-radio-card--selected');

                schedulePreviewUpdate();
            }
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


    // ── Bouton Analyser Watchdog ──────────────────────────────────
    function initWatchdogAnalyze() {
        var btn   = document.getElementById('neria-watchdog-analyze-btn');
        if (!btn) return;

        var icon  = document.getElementById('neria-watchdog-analyze-icon');
        var label = document.getElementById('neria-watchdog-analyze-label');

        btn.addEventListener('click', function () {
            btn.disabled = true;
            if (icon)  icon.style.animation  = 'neria-spin 1s linear infinite';
            if (label) label.textContent      = 'Analyse en cours…';

            // URL AJAX : page courante + action (sans hash — le fragment n'est pas envoyé au serveur)
            var base = window.location.href.split('#')[0].replace(/&neria_action=[^&]*/g, '');
            var url  = base + '&neria_action=watchdog_refresh';

            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { applyWatchdogData(d); })
                .catch(function () { /* silencieux : rechargement au prochain accès */ })
                .finally(function () {
                    btn.disabled = false;
                    if (icon)  { icon.style.animation = ''; icon.textContent = '🔄'; }
                    if (label) label.textContent = 'Analyser maintenant';
                });
        });
    }

    function applyWatchdogData(d) {
        var CIRC = 251.2;
        var score = d.score || 0;
        var color = d.color || '#16a34a';

        // Cercle SVG
        var circle = document.getElementById('neria-wd-circle-bar');
        if (circle) {
            circle.setAttribute('stroke', color);
            circle.setAttribute('stroke-dashoffset', (CIRC - CIRC * score / 100).toFixed(1));
        }
        var scoreNum = document.getElementById('neria-wd-score-num');
        if (scoreNum) {
            scoreNum.textContent = score;
            scoreNum.style.fill  = color;
        }
        var scoreLbl = document.getElementById('neria-wd-score-label');
        if (scoreLbl) {
            scoreLbl.textContent = d.label || '—';
            scoreLbl.style.color = color;
        }

        // Problèmes détectés
        var issuesWrap = document.getElementById('neria-wd-issues-wrap');
        if (issuesWrap) {
            if (!d.issues || d.issues.length === 0) {
                issuesWrap.innerHTML =
                    '<div style="color:#16a34a;font-size:13px;font-weight:600;">✓ Aucun problème détecté</div>' +
                    '<div style="color:#888;font-size:12px;margin-top:4px;">Tous les systèmes fonctionnent normalement.</div>';
            } else {
                var items = d.issues.map(function (i) {
                    return '<li>' + escHtml(i) + '</li>';
                }).join('');
                issuesWrap.innerHTML =
                    '<div style="font-size:12px;font-weight:700;color:#7a5800;margin-bottom:8px;">Problèmes détectés :</div>' +
                    '<ul style="margin:0;padding-left:16px;font-size:12px;color:#5c3d1e;line-height:1.8;">' + items + '</ul>';
            }
        }

        // Grille crons + queue + erreurs 24h
        var cronsGrid = document.getElementById('neria-wd-crons-grid');
        if (cronsGrid && d.crons) {
            var html = '';
            Object.keys(d.crons).forEach(function (k) {
                var c = d.crons[k];
                var st = c.status || 'late';
                var bdr  = st === 'ok' ? '#bbf7d0' : (st === 'error' ? '#fecaca' : '#fed7aa');
                var bg   = st === 'ok' ? '#f0fdf4' : (st === 'error' ? '#fff5f5' : '#fffbf0');
                var fc   = st === 'ok' ? '#16a34a' : (st === 'error' ? '#dc2626' : '#d97706');
                var icon = st === 'ok' ? '✓' : (st === 'error' ? '✕' : '⚠');
                var sub  = c.last_run
                    ? ('Il y a ' + (c.age_minutes < 60 ? c.age_minutes + ' min' : Math.floor(c.age_minutes / 60) + 'h') +
                       ' (' + c.last_count + ' traité' + (c.last_count > 1 ? 's' : '') + ')')
                    : 'Jamais exécuté';
                var subColor = c.last_run ? '#888' : '#d97706';
                html += '<div style="padding:12px 14px;border-radius:6px;border:1px solid ' + bdr + ';background:' + bg + ';">' +
                    '<div style="font-size:11px;font-weight:700;color:' + fc + ';margin-bottom:4px;">' + icon + ' ' + escHtml(c.label || k) + '</div>' +
                    '<div style="font-size:11px;color:' + subColor + ';">' + sub + '</div></div>';
            });

            // Queue
            if (d.queue) {
                var q = d.queue;
                var qst  = q.status || 'ok';
                var qbdr = qst === 'ok' ? '#bbf7d0' : '#fed7aa';
                var qbg  = qst === 'ok' ? '#f0fdf4' : '#fffbf0';
                var qfc  = qst === 'ok' ? '#16a34a' : '#d97706';
                var qSub = '';
                if (q.exists) {
                    if (q.stuck > 0)  qSub += '<div style="font-size:11px;color:#d97706;">' + q.stuck + ' bloqué' + (q.stuck > 1 ? 's' : '') + ' (&gt;2h)</div>';
                    if (q.failed > 0) qSub += '<div style="font-size:11px;color:#dc2626;">' + q.failed + ' en échec</div>';
                    if (!q.stuck && !q.failed) qSub = '<div style="font-size:11px;color:#888;">' + q.total_pending + ' en attente — OK</div>';
                } else {
                    qSub = '<div style="font-size:11px;color:#888;">Queue non activée</div>';
                }
                html += '<div style="padding:12px 14px;border-radius:6px;border:1px solid ' + qbdr + ';background:' + qbg + ';">' +
                    '<div style="font-size:11px;font-weight:700;color:' + qfc + ';margin-bottom:4px;">' + (qst === 'ok' ? '✓' : '⚠') + ' File d\'attente</div>' + qSub + '</div>';
            }

            // Erreurs 24h
            if (d.rc_24h) {
                var err   = d.rc_24h.error    || 0;
                var crit  = d.rc_24h.critical  || 0;
                var warn  = d.rc_24h.warning   || 0;
                var hasErr = err > 0 || crit > 0;
                var eBdr  = hasErr ? '#fecaca' : '#bbf7d0';
                var eBg   = hasErr ? '#fff5f5' : '#f0fdf4';
                var eFc   = hasErr ? '#dc2626' : '#16a34a';
                var eSub  = '';
                if (!err && !crit && !warn) {
                    eSub = '<div style="font-size:11px;color:#888;">Aucune anomalie</div>';
                } else {
                    if (crit) eSub += '<div style="font-size:11px;color:#dc2626;">' + crit + ' critique' + (crit > 1 ? 's' : '') + '</div>';
                    if (err)  eSub += '<div style="font-size:11px;color:#a32d2d;">' + err  + ' erreur'   + (err  > 1 ? 's' : '') + '</div>';
                    if (warn) eSub += '<div style="font-size:11px;color:#d97706;">' + warn + ' warning'  + (warn > 1 ? 's' : '') + '</div>';
                }
                html += '<div style="padding:12px 14px;border-radius:6px;border:1px solid ' + eBdr + ';background:' + eBg + ';">' +
                    '<div style="font-size:11px;font-weight:700;color:' + eFc + ';margin-bottom:4px;">' + (hasErr ? '✕' : '✓') + ' Erreurs (24h)</div>' + eSub + '</div>';
            }
            cronsGrid.innerHTML = html;
        }

        // Anomalies métriques
        var anom = document.getElementById('neria-wd-anomalies');
        if (anom) {
            if (!d.anomalies || d.anomalies.length === 0) {
                anom.innerHTML = '';
            } else {
                var rows = d.anomalies.map(function (a) {
                    var parts = [];
                    if (a.open_drop  >= 20) parts.push('Ouv. : -' + a.open_drop  + '%');
                    if (a.click_drop >= 20) parts.push('Clics : -' + a.click_drop + '%');
                    return '<div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #fde68a;font-size:12px;">' +
                        '<strong>' + escHtml(a.template) + '</strong> — ' + parts.join(' · ') + '</div>';
                }).join('');
                anom.innerHTML =
                    '<div style="background:#fffbf0;border:1px solid #fcd34d;border-radius:6px;padding:14px 18px;margin-top:4px;">' +
                    '<div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:10px;">⚠ Anomalies détectées sur ' + d.anomalies.length + ' template' + (d.anomalies.length > 1 ? 's' : '') + '</div>' +
                    rows + '</div>';
            }
        }
    }

    function escHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
