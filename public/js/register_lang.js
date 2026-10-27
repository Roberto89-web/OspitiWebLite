// ─────────────────────────────────────────────
// register_lang.js — IT/EN switching for the
// registration flow (register_form + travel + success).
//
// - No reload: re-applying i18n keeps the in-flight form data
//   (unlike the selfcheckin page, which only has a button).
// - Toggle is auto-injected at the top of `.container`
//   (first child) if not already present.
// - Persistence: sessionStorage (`register_lang`) + `?lang=it|en`
//   in the URL (history.replaceState, so no history noise).
//
// API:
//   RegLang.lang             'it' | 'en'
//   RegLang.t(key, vars?)    translated string (vars substitutes {name} etc.)
//   RegLang.setLang('it'|'en')
//   RegLang.apply(rootEl?)   re-apply [data-i18n] on the tree
// ─────────────────────────────────────────────
(function (root) {
    'use strict';

    var KEY = 'register_lang';

    var STRINGS = {
        it: {
            // ── common ──
            'lang_label': 'Lingua',
            'select':       'Seleziona',
            'submitting':   'Invio in corso...',
            'server_error_default': 'Errore del server',
            'error_prefix': 'Errore: {msg}',
            'net_error':    'Errore di connessione. Riprova più tardi. Errore: {msg}',
            'person_n':     'Persona {n}',
            'join_and':     'e',
            'missing_suffix': 'mancante/i',

            // ── register_form (step 1) ──
            'title':            'Registrazione Ospiti',
            'welcome':          'Benvenuto, {name}!',
            'fill_in':          'Per favore, compila i dati per {n} persona(e).',
            'person':           'Persona',
            'scan_doc':         'Scansiona documento per questa persona',
            'guest_type':       'Tipo Ospite',
            'name':             'Nome *',
            'lastname':         'Cognome *',
            'gender':           'Genere',
            'male':             'Maschio',
            'female':           'Femmina',
            'birth_date':       'Data di Nascita',
            'birth_state':      'Stato di Nascita',
            'birth_prov':       'Provincia di Nascita',
            'birth_city':       'Città di Nascita',
            'res_state':        'Stato di Residenza',
            'res_prov':         'Provincia di Residenza',
            'res_city':         'Città di Residenza',
            'res_address':      'Indirizzo di Residenza (opzionale)',
            'citizenship':      'Cittadinanza',
            'doc_type':         'Tipo Documento',
            'doc_number':       'Numero Documento',
            'doc_release_city': 'Città Rilascio Documento',
            'doc_release_state':'Stato Rilascio Documento',
            'email':            'Email',
            'phone':            'Telefono',
            'privacy_text':     'Confermo di aver letto e accetto',
            'privacy_link':     'Informativa sulla Privacy e Termini di Servizio',
            'submit':           'Invia Registrazione',
            'privacy_required': 'Devi accettare l\'Informativa sulla Privacy e i Termini di Servizio per continuare.',
            'lookup_banner_title': 'Completa i campi seguenti selezionando un valore dai suggerimenti:',
            'lookup_banner_msg':   'Compila i campi evidenziati selezionando un valore dai suggerimenti.',
            'fix_doc_numbers':  'Correggi gli errori nei numeri di documento prima di inviare.',
            'member_type_required': 'Persona {n}: devi selezionare il "Tipo Ospite" prima di inviare.',
            'res_state_required': 'Persona {n}: devi selezionare lo "Stato di Residenza" prima di inviare.',
            'field_province':   'Provincia',
            'field_city':       'Città',
            'field_state':      'Stato',
            'field_res_province': 'Provincia di residenza',
            'field_res_city':     'Città di residenza',
            'coherence':        'Coerenza campi: se lo stato di nascita non è ITALIA, i campi provincia e città di nascita devono restare vuoti. Compilali solo per nascite in Italia.',
            'sent_success':     'Registrazione completata con successo! Grazie.',
            'step2_redirect':   'Dati ricevuti! Passiamo allo Step 2 (viaggio)...',
            'redirect_to':      'Invio completato. Continua su:',

            // ── combobox ──
            'city_no_results':  'Nessun comune trovato',
            'state_no_results': 'Nessuno stato trovato',
            'prov_no_results':  'Nessuna provincia trovata',
            'city_select':      'Seleziona una città dalla lista',
            'state_select':     'Seleziona uno stato dalla lista',
            'prov_select':      'Seleziona una provincia dalla lista',
            'select_from_list': 'Seleziona un valore dalla lista',
            'no_results':       'Nessun risultato trovato',
            'type_more':        'Continua a digitare per restringere…',
            'field_unknown':    'Campo sconosciuto',

            // ── doc validation ──
            'doc_only_letters': 'Sono ammessi solo lettere e numeri, senza caratteri speciali.',
            'doc_carta_err':    'Il numero di carta d\'identità non è valido. Formato atteso: 2 lettere seguite da 7 cifre (es. AR1234567).',
            'doc_cie_err':      'Il numero della CIE non è valido. Formato atteso: 2 lettere, 5 cifre, 2 lettere (es. CA00000AA).',
            'doc_pat_err':      'Il numero di patente non è valido. Formato atteso: 2 lettere, 3 cifre, 2 lettere, 5 cifre, 1 lettera (es. MI000AA00000B).',
            'doc_ident_err':    'Il numero di carta d\'identità non è valido. Formati accettati: cartacea (es. AR1234567) o elettronica CIE (es. CA00000AA).',
            'doc_unknown':      'Numero documento non riconosciuto. Seleziona il tipo di documento e verifica il formato.',
            'doc_carta_label':  'Carta d\'Identità Cartacea',
            'doc_cie_label':    'CIE (Carta d\'Identità Elettronica)',
            'doc_pat_label':    'Patente di Guida',

            // ── register_travel (step 2) ──
            'step2_title':      'Completamento Registrazione',
            'done_thanks':      'Grazie {name}, i tuoi dati di soggiorno sono stati ricevuti.',
            'step2_banner_h':   'Step 2 — Dati di viaggio.',
            'step2_banner_d':   'Per concludere la registrazione indica il tipo di viaggio e il mezzo di trasporto.',
            'tour_type':        'Tipo di viaggio',
            'transport_mean':   'Mezzo di trasporto',
            'submit_travel':    'Completa Registrazione',
            'confirmed':        'Confermato',
            'already_complete': 'Registrazione già completata',
            'travel_saved':     'Dati di viaggio salvati',
            'final_note':       'Con questo passo concludi la registrazione della tua prenotazione.',

            // ── register_travel (step 2) — tassa di soggiorno ──
            'tax_banner_h':       'Tassa di soggiorno',
            'tax_banner_amount':  'Per il soggiorno l\'importo è di {amount}. Si prega di averlo a disposizione in contanti e di lasciare il totale in casa al momento del check-in.',
            'tax_banner_zero':    'L\'eventuale importo della tassa di soggiorno va saldato in contanti: si prega di lasciarlo in casa al momento del check-in.',

            // ── register_success ──
            'success_title':    'Registrazione Completata',
            'success_h1':       'Registrazione completata',
            'thanks_named':     'Grazie {name}, i tuoi dati sono stati presi in carico.',
            'thanks_anon':      'Grazie, i tuoi dati sono stati presi in carico.',
            'success_note':     'Conserva eventuali codici o conferme che ti sono stati comunicati. All\'arrivo verrai accolto dallo staff.'
        },
        en: {
            // ── common ──
            'lang_label': 'Language',
            'select':       'Select',
            'submitting':   'Sending...',
            'server_error_default': 'Server error',
            'error_prefix': 'Error: {msg}',
            'net_error':    'Connection error. Please try again later. Error: {msg}',
            'person_n':     'Person {n}',
            'join_and':     'and',
            'missing_suffix': 'missing',

            // ── register_form (step 1) ──
            'title':            'Guest Registration',
            'welcome':          'Welcome, {name}!',
            'fill_in':          'Please fill in the details for {n} person(s).',
            'person':           'Person',
            'scan_doc':         'Scan the document for this person',
            'guest_type':       'Guest Type',
            'name':             'First name *',
            'lastname':         'Last name *',
            'gender':           'Gender',
            'male':             'Male',
            'female':           'Female',
            'birth_date':       'Date of birth',
            'birth_state':      'State of birth',
            'birth_prov':       'Province of birth',
            'birth_city':       'City of birth',
            'res_state':        'State of residence',
            'res_prov':         'Province of residence',
            'res_city':         'City of residence',
            'res_address':      'Residence address (optional)',
            'citizenship':      'Citizenship',
            'doc_type':         'Document type',
            'doc_number':       'Document number',
            'doc_release_city': 'Document issue city',
            'doc_release_state':'Document issue state',
            'email':            'Email',
            'phone':            'Phone',
            'privacy_text':     'I confirm that I have read and accept the ',
            'privacy_link':     'Privacy notice and Terms of Service',
            'submit':           'Submit registration',
            'privacy_required': 'You must accept the Privacy notice and Terms of Service to continue.',
            'lookup_banner_title': 'Complete the fields below by picking a value from the suggestions:',
            'lookup_banner_msg':   'Fill in the highlighted fields by picking a value from the suggestions.',
            'fix_doc_numbers':  'Fix the errors in the document numbers before submitting.',
            'member_type_required': 'Person {n}: you must select a "Guest type" before submitting.',
            'res_state_required': 'Person {n}: you must select a "Residence state" before submitting.',
            'field_province':   'Province',
            'field_city':       'City',
            'field_state':      'State',
            'field_res_province': 'Residence province',
            'field_res_city':     'Residence city',
            'coherence':        'Field consistency: if the state of birth is not ITALY, the birth-province and birth-city fields must be left blank. Fill them in only for Italian births.',
            'sent_success':     'Registration completed successfully. Thank you.',
            'step2_redirect':   'Data received! Moving to step 2 (travel)...',
            'redirect_to':      'Submission complete. Continue to:',

            // ── combobox ──
            'city_no_results':  'No city found',
            'state_no_results': 'No state found',
            'prov_no_results':  'No province found',
            'city_select':      'Pick a city from the list',
            'state_select':     'Pick a state from the list',
            'prov_select':      'Pick a province from the list',
            'select_from_list': 'Pick a value from the list',
            'no_results':       'No results found',
            'type_more':        'Keep typing to narrow down…',
            'field_unknown':    'Unknown field',

            // ── doc validation ──
            'doc_only_letters': 'Only letters and digits are allowed (no special characters).',
            'doc_carta_err':    'The ID-card number is not valid. Expected format: 2 letters followed by 7 digits (e.g. AR1234567).',
            'doc_cie_err':      'The CIE number is not valid. Expected format: 2 letters, 5 digits, 2 letters (e.g. CA00000AA).',
            'doc_pat_err':      'The driving licence number is not valid. Expected format: 2 letters, 3 digits, 2 letters, 5 digits, 1 letter (e.g. MI000AA00000B).',
            'doc_ident_err':    'The ID-card number is not valid. Accepted formats: paper (e.g. AR1234567) or e-ID CIE (e.g. CA00000AA).',
            'doc_unknown':      'Document number not recognised. Select the document type and make sure the format is correct.',
            'doc_carta_label':  'Paper ID Card',
            'doc_cie_label':    'CIE (Electronic ID)',
            'doc_pat_label':    'Driving Licence',

            // ── register_travel (step 2) ──
            'step2_title':      'Complete Registration',
            'done_thanks':      'Thank you {name}, your stay details have been received.',
            'step2_banner_h':   'Step 2 — Travel details.',
            'step2_banner_d':   'To finish the registration, indicate the travel type and the means of transport.',
            'tour_type':        'Travel type',
            'transport_mean':   'Means of transport',
            'submit_travel':    'Complete registration',
            'confirmed':        'Confirmed',
            'already_complete': 'Registration already completed',
            'travel_saved':     'Travel details saved',
            'final_note':       'This step finalises the registration of your booking.',

            // ── register_travel (step 2) — tourist tax ──
            'tax_banner_h':       'Tourist tax',
            'tax_banner_amount':  'The amount for your stay is {amount}. Please keep it ready in cash and leave the total in the apartment upon check-in.',
            'tax_banner_zero':    'Any tourist tax due must be paid in cash: please leave it in the apartment upon check-in.',

            // ── register_success ──
            'success_title':    'Registration Complete',
            'success_h1':       'Registration completed',
            'thanks_named':     'Thank you {name}, your details have been accepted.',
            'thanks_anon':      'Thank you, your details have been accepted.',
            'success_note':     'Keep any codes or confirmations that were provided to you. On arrival, the staff will welcome you.'
        }
    };

    function detectLang() {
        var qs = null;
        try { qs = new URLSearchParams(location.search).get('lang'); } catch (e) {}
        if (qs === 'it' || qs === 'en') {
            try { sessionStorage.setItem(KEY, qs); } catch (e) {}
            return qs;
        }
        try {
            var stored = sessionStorage.getItem(KEY);
            if (stored === 'it' || stored === 'en') return stored;
        } catch (e) {}
        var nav = (navigator.language || 'it').toLowerCase();
        return nav.indexOf('en') === 0 ? 'en' : 'it';
    }

    var lang = detectLang();

    function t(key, vars) {
        var dict = STRINGS[lang];
        var out = dict[key];
        if (out == null) return key;
        if (vars) {
            for (var k in vars) {
                if (Object.prototype.hasOwnProperty.call(vars, k)) {
                    out = String(out).split('{' + k + '}').join(vars[k]);
                }
            }
        }
        return out;
    }

    function _varsOf(el) {
        var a = el.getAttribute('data-i18n-vars');
        if (!a) return null;
        try { return JSON.parse(a); } catch (e) { return null; }
    }
    function applyI18n(rootEl) {
        rootEl = rootEl || document;
        if (typeof document !== 'undefined') document.documentElement.setAttribute('lang', lang);
        if (typeof rootEl.querySelectorAll === 'function') {
            rootEl.querySelectorAll('[data-i18n]').forEach(function (el) {
                el.textContent = t(el.getAttribute('data-i18n'), _varsOf(el));
            });
        }
        if (typeof document !== 'undefined' && document.title) {
            var titleKey = document.documentElement.getAttribute('data-title-i18n') || 'title';
            document.title = t(titleKey);
        }
        if (typeof window.__i18nChanged === 'function') window.__i18nChanged();
    }

    function setLang(next) {
        if (next !== 'it' && next !== 'en') return;
        if (next === lang) return;
        lang = next;
        try { sessionStorage.setItem(KEY, next); } catch (e) {}
        try {
            var u = new URL(location.href);
            u.searchParams.set('lang', next);
            history.replaceState({}, '', u.toString());
        } catch (e) {}
        applyI18n();
        if (document.body && document.body.__regLangToggle) document.body.__regLangToggle.mark();
    }

    function markActive(wrap) {
        wrap.querySelectorAll('a[data-rl-lang]').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-rl-lang') === lang);
        });
    }

    function injectToggle(container) {
        if (document.body && document.body.__regLangToggle) return markActive(document.body.__regLangToggle.wrap);
        var c = container
            || (typeof document !== 'undefined' ? (document.querySelector('.container') || document.body.firstElementChild || document.body) : null);
        if (!c) return null;
        if (typeof document.head !== 'undefined' && !document.head.querySelector('style[data-rl]')) {
            var style = document.createElement('style');
            style.setAttribute('data-rl', '1');
            style.textContent = [
                '.lang-toggle{text-align:right;font-size:0.85em;margin:-10px 0 12px;user-select:none;}',
                '.lang-toggle a{display:inline-block;padding:2px 8px;color:#8a969b;text-decoration:none;border-radius:3px;}',
                '.lang-toggle a:hover{color:#2c3e50;background:#f4f7f6;}',
                '.lang-toggle a.active{color:#2c3e50;font-weight:700;}'
            ].join('');
            document.head.appendChild(style);
        }
        var wrap = document.createElement('div');
        wrap.className = 'lang-toggle';
        wrap.setAttribute('role', 'group');
        wrap.setAttribute('aria-label', 'Lingua / Language');
        wrap.innerHTML =
            '<a href="#" data-rl-lang="it" id="rl-it">IT</a>' +
            '<span aria-hidden="true">/</span>' +
            '<a href="#" data-rl-lang="en" id="rl-en">EN</a>';
        c.insertBefore(wrap, c.firstChild);
        wrap.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                setLang(a.getAttribute('data-rl-lang'));
            });
        });
        var mark = function () { markActive(wrap); };
        mark();
        if (document.body) document.body.__regLangToggle = { mark: mark, wrap: wrap };
        return mark;
    }

    var api = {
        lang: lang,
        t: t,
        strings: STRINGS,
        setLang: setLang,
        apply: applyI18n,
        injectToggle: injectToggle,
        ready: false
    };

    if (typeof document !== 'undefined') {
        var boot = function () {
            injectToggle();
            applyI18n();
            api.ready = true;
            api.lang = lang;
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }

    root.RegLang = api;
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
