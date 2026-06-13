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
        initSignaturePreview();
        initDesignReset();
        initFontCards();
    });

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
            'color_accent', 'color_text', 'container_width', 'logo_width'
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

})();
