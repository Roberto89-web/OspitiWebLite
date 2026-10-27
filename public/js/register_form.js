// ─────────────────────────────────────────────
// VALIDAZIONE DOCUMENTI ITALIANI
// ─────────────────────────────────────────────

// Helper i18n: se register_lang.js non è caricato, torna la chiave (graceful degrade).
const T = (key, params) => (window.RegLang ? window.RegLang.t(key, params) : key);

const DOC_PATTERNS = {
    'carta cartacea': {
        regex: /^[A-Z]{2}\s?[0-9]{7}$/,
        label: 'Carta d\'Identità Cartacea',
        error: 'doc_carta_err'
    },
    'cie': {
        regex: /^[A-Z]{2}[0-9]{5}[A-Z]{2}$/,
        label: 'CIE (Carta d\'Identità Elettronica)',
        error: 'doc_cie_err'
    },
    'patente': {
        regex: /^[A-Z]{2}[0-9]{3}[A-Z]{2}[0-9]{5}[A-Z]{1}$/,
        label: 'Patente di Guida',
        error: 'doc_pat_err'
    }
};

/**
 * Valida un numero documento italiano.
 * @param {string} value  - il numero grezzo inserito dall'utente
 * @param {string} docType - 'carta identità' | 'patente' (dal selettore)
 * @returns {{ valid: boolean, error: string|null, detectedType: string|null }}
 *   `error` è una chiave i18n (da risolvere con T()) o null.
 */
function validateDocNumber(value, docType) {
    if (!value) return { valid: true, error: null, detectedType: null }; // campo vuoto: non obbligatorio qui

    const normalized = value.trim().toUpperCase();

    if (/[^A-Z0-9 ]/.test(normalized)) {
        return { valid: false, error: 'doc_only_letters', detectedType: null };
    }

    if (docType === 'PATEN') {
        const ok = DOC_PATTERNS['patente'].regex.test(normalized);
        return {
            valid: ok,
            error: ok ? null : DOC_PATTERNS['patente'].error,
            detectedType: ok ? 'patente' : null
        };
    }

    if (docType === 'IDENT') {
        if (DOC_PATTERNS['carta cartacea'].regex.test(normalized)) {
            return { valid: true, error: null, detectedType: 'carta cartacea' };
        }
        if (DOC_PATTERNS['cie'].regex.test(normalized)) {
            return { valid: true, error: null, detectedType: 'cie' };
        }
        return { valid: false, error: 'doc_ident_err', detectedType: null };
    }

    if (docType !== 'IDENT' && docType !== 'PATEN') {
        return { valid: true, error: null, detectedType: null };
    }

    if (DOC_PATTERNS['carta cartacea'].regex.test(normalized)) {
        return { valid: true, error: null, detectedType: 'carta cartacea' };
    }
    if (DOC_PATTERNS['cie'].regex.test(normalized)) {
        return { valid: true, error: null, detectedType: 'cie' };
    }
    if (DOC_PATTERNS['patente'].regex.test(normalized)) {
        return { valid: true, error: null, detectedType: 'patente' };
    }

    return { valid: false, error: 'doc_unknown', detectedType: null };
}

/**
 * Applica la validazione a un input doc_number e mostra/nasconde l'errore.
 * @returns {boolean} true se valido
 */
function applyDocValidation(docNumberInput) {
    const personBlock = docNumberInput.closest('.person-block');
    const index = docNumberInput.name.match(/persons\[(\d+)\]/)[1];
    const docTypeSelect = personBlock.querySelector(`select[name="persons[${index}][doc_type]"]`);
    const errorSpan = document.getElementById(`doc-number-error-${index}`);

    const docType = docTypeSelect ? docTypeSelect.value : '';
    const result = validateDocNumber(docNumberInput.value, docType);

    if (!result.valid && docNumberInput.value.trim() !== '') {
        docNumberInput.classList.add('invalid');
        docNumberInput.classList.remove('valid');
        if (errorSpan) {
            errorSpan.textContent = result.error ? T(result.error) : '';
            errorSpan.classList.add('visible');
        }
    } else {
        docNumberInput.classList.remove('invalid');
        if (docNumberInput.value.trim() !== '') docNumberInput.classList.add('valid');
        if (errorSpan) {
            errorSpan.textContent = '';
            errorSpan.classList.remove('visible');
        }
    }

    return result.valid || docNumberInput.value.trim() === '';
}

// Mostra l'errore solo su blur (non durante la digitazione)
document.addEventListener('blur', (e) => {
    if (e.target.classList.contains('doc-number-input')) {
        applyDocValidation(e.target);
    }
}, true);

// Ri-valida se cambia il tipo documento mentre il campo numero è già compilato
document.addEventListener('change', (e) => {
    if (e.target.classList.contains('doc-type-select')) {
        const personBlock = e.target.closest('.person-block');
        const docNumberInput = personBlock.querySelector('.doc-number-input');
        if (docNumberInput && docNumberInput.value.trim() !== '') {
            applyDocValidation(docNumberInput);
        }
    }
});

// ─────────────────────────────────────────────
// AUTOCOMPLETE COMUNI
// ─────────────────────────────────────────────

const API_BASE = '/web/api/lookups';

async function populateSelect(endpoint, selectElement) {
    try {
        const response = await fetch(`${API_BASE}/${endpoint}`, { credentials: 'same-origin' });
        if (!response.ok) return;
        const data = await response.json();
        const current = selectElement.value;
        selectElement.innerHTML = `<option value="">${T('select')}</option>`;

        const allowed = selectElement.dataset.allowed;
        data.forEach(item => {
            if (allowed && !allowed.split(',').includes(String(item.codice))) {
                return;
            }
            const option = document.createElement('option');
            option.value = item.codice;
            option.textContent = item.descrizione;
            selectElement.appendChild(option);
        });
        // Applica data-prefill solo se il campo era vuoto e il valore è permesso;
        // al resto si rimanda al flusso "current" originale.
        const prefill = selectElement.dataset.prefill;
        if (selectElement.value === '' && prefill && allowed && allowed.split(',').includes(prefill)) {
            selectElement.value = prefill;
        } else {
            selectElement.value = current;
        }
    } catch (e) {
        console.error(`Error populating select ${endpoint}:`, e);
    }
}

// ─────────────────────────────────────────────
// TIPO OSPITE — DEDUZIONE DAL PRIMO OSPITE
// Regole (client-side only):
//  • 1° = 17 (CAPO FAMIGLIA) → i successivi VUOTI → 19 (FAMILIARE)
//  • 1° = 18 (CAPO GRUPPO)   → i successivi VUOTI → 20 (MEMBRO GRUPPO)
//  • i successivi con un valore già scelto dall'utente non vengono toccati
//  • se il 1° cambia tipo, i successivi ancora vuoti vengono ricollocati
// ─────────────────────────────────────────────
function applyGuestTypeCascade() {
    const first = document.querySelector('.guest-type-select');
    if (!first || !first.value) return;
    const target = first.value === '17' ? '19' : first.value === '18' ? '20' : null;
    if (!target) return;
    document.querySelectorAll('.person-block').forEach((block, idx) => {
        if (idx === 0) return;
        const sel = block.querySelector('.guest-type-select');
        if (!sel) return;
        if (sel.value !== '') return; // già scelto dall'utente
        const allowed = sel.dataset.allowed ? sel.dataset.allowed.split(',') : [];
        if (allowed.includes(target)) sel.value = target;
    });
}

// ─────────────────────────────────────────────
// COMPONENTE COMBOBOX LOOKUP
// ─────────────────────────────────────────────

let _cbxUid = 0;
const _lookupCache = {};

const LOOKUP_SOURCES = {
    comuni: {
        url: `${API_BASE}/comuni/all`,
        map: (list) => (list || []).map(it => ({
            name: it.descrizione || '',
            code: it.codice || '',
            province: it.provincia || ''
        }))
    },
    stati: {
        url: `${API_BASE}/stati`,
        map: (list) => (list || []).map(it => ({
            name: it.descrizione || '',
            code: it.codice || ''
        }))
    },
    provincie: {
        url: `${API_BASE}/provincie`,
        map: (list) => (list || []).map(p => (typeof p === 'string')
            ? { name: p, code: p }
            : { name: p.descrizione || p.codice, code: p.codice || p.descrizione })
    }
};

function loadLookup(source) {
    const def = LOOKUP_SOURCES[source] || LOOKUP_SOURCES.comuni;
    if (_lookupCache[source]) return _lookupCache[source].promise;
    const entry = { promise: null, list: null };
    entry.promise = fetch(def.url, { credentials: 'same-origin' })
        .then(r => { if (!r.ok) throw new Error('lookup ' + source + ': ' + r.status); return r.json(); })
        .then(data => {
            entry.list = def.map(data);
            return entry.list;
        });
    _lookupCache[source] = entry;
    return entry.promise;
}

function normalizeLookup(s) {
    return (s || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/['’`]/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function filterLookup(pool, query, limit) {
    const q = normalizeLookup(query);
    if (!q) return { matches: [], more: false };
    const prefix = [], contains = [];
    for (const it of pool) {
        const n = normalizeLookup(it.name);
        if (n.indexOf(q) === 0) prefix.push(it);
        else if (n.includes(q)) contains.push(it);
    }
    const combined = prefix.concat(contains);
    return { matches: combined.slice(0, limit), more: combined.length > limit };
}

class CityCombobox {
    constructor(container, opts) {
        this.container = container;
        this.input = opts.input;
        this.codeInput = container.querySelector('[data-role="city_code"]') || container.querySelector('input[type="hidden"]');
        this.list = container.querySelector('.city-combobox__list');
        this.errorEl = container.querySelector('.city-combobox__error');
        this.provider = (typeof opts.provider === 'function') ? opts.provider : (() => []);
        this.source = opts.source || 'comuni';
        this.minChars = opts.minChars != null ? opts.minChars : 2;
        this.limit = opts.limit != null ? opts.limit : 50;
        // `emptyText` e `requireSelectionMsg` sono chiavi i18n (risolte al punto d'uso con T()).
        this.emptyText = opts.emptyText || 'no_results';
        this.requireSelectionMsg = opts.requireSelectionMsg || 'select_from_list';
        this.onSelect = opts.onSelect || null;
        this.uid = 'cbx' + (++_cbxUid);

        this.pool = undefined;
        this.matches = [];
        this.activeIndex = -1;
        this.isOpen = false;
        this.selectedFlag = false;
        this._timer = null;
        this._bind();
    }

    async _ensurePool() {
        if (this.pool !== undefined) return;
        const all = await loadLookup(this.source);
        this.pool = this.provider(all) || [];
    }

    _open() {
        if (this.isOpen) return;
        this.isOpen = true;
        this.list.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
    }

    _close() {
        if (!this.isOpen) return;
        this.isOpen = false;
        this.list.hidden = true;
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
        this.list.querySelectorAll('.city-combobox__option.active')
            .forEach(el => el.classList.remove('active'));
    }

    _placeholder(text) {
        const li = document.createElement('li');
        li.className = 'city-combobox__placeholder';
        li.setAttribute('role', 'option');
        li.setAttribute('aria-disabled', 'true');
        li.textContent = text;
        return li;
    }

    setError(msg) {
        if (this.errorEl) { this.errorEl.textContent = msg; this.errorEl.hidden = false; }
        this.input.classList.add('invalid');
        this.input.classList.remove('valid');
    }

    clearError() {
        if (this.errorEl) { this.errorEl.textContent = ''; this.errorEl.hidden = true; }
        this.input.classList.remove('invalid');
    }

    markValid() {
        this.clearError();
        this.input.classList.add('valid');
    }

    hasSelection() {
        return !!(this.selectedFlag && this.codeInput && this.codeInput.value && this.input.value.trim());
    }

    resetOnSourceChange() {
        this.input.value = '';
        if (this.codeInput) this.codeInput.value = '';
        this.selectedFlag = false;
        this.matches = [];
        this.activeIndex = -1;
        this.pool = undefined;
        this.clearError();
        this._close();
    }

    select(item) {
        this.input.value = item.name;
        if (this.codeInput) this.codeInput.value = item.code;
        this.selectedFlag = true;
        this.matches = [];
        this.activeIndex = -1;
        this.clearError();
        this.input.classList.add('valid');
        this._close();
        if (this.onSelect) this.onSelect(item);
    }

    _renderActive() {
        const opts = this.list.querySelectorAll('.city-combobox__option');
        opts.forEach(el => el.classList.remove('active'));
        if (this.activeIndex < 0 || !opts[this.activeIndex]) {
            this.input.removeAttribute('aria-activedescendant');
            return;
        }
        const active = opts[this.activeIndex];
        active.classList.add('active');
        this.input.setAttribute('aria-activedescendant', active.id);
        active.scrollIntoView({ block: 'nearest' });
    }

    async refresh() {
        try { await this._ensurePool(); } catch (e) { return; }
        const q = (this.input.value || '');
        let matches = [], more = false;
        if (normalizeLookup(q).length >= this.minChars) {
            const r = filterLookup(this.pool, q, this.limit);
            matches = r.matches; more = r.more;
        }
        this.matches = matches;
        this.activeIndex = -1;
        this.list.innerHTML = '';
        if (!matches.length) {
            if (normalizeLookup(q).length >= this.minChars) {
                this.list.appendChild(this._placeholder(T(this.emptyText)));
            }
            this._close();
            return;
        }
        matches.forEach((it, idx) => {
            const li = document.createElement('li');
            li.className = 'city-combobox__option';
            li.setAttribute('role', 'option');
            li.id = this.uid + '-opt-' + idx;
            const span = document.createElement('span');
            span.textContent = it.name;
            li.appendChild(span);
            if (it.province) {
                const badge = document.createElement('span');
                badge.className = 'city-combobox__badge';
                badge.textContent = it.province;
                li.appendChild(badge);
            }
            li.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.select(it);
            });
            this.list.appendChild(li);
        });
        if (more) {
            const m = document.createElement('li');
            m.className = 'city-combobox__more';
            m.textContent = T('type_more');
            this.list.appendChild(m);
        }
        this._open();
    }

    _bind() {
        this.input.setAttribute('role', 'combobox');
        this.input.setAttribute('aria-autocomplete', 'list');
        this.input.setAttribute('aria-expanded', 'false');
        this.input.setAttribute('aria-controls', this.uid + '-list');
        this.input.setAttribute('autocomplete', 'off');
        this.input.setAttribute('spellcheck', 'false');
        this.list.setAttribute('id', this.uid + '-list');

        this.input.addEventListener('input', () => {
            const had = this.selectedFlag;
            this.selectedFlag = false;
            if (had && this.codeInput) this.codeInput.value = '';
            this.activeIndex = -1;
            this.clearError();
            clearTimeout(this._timer);
            this._timer = setTimeout(() => this.refresh(), 150);
        });

        this.input.addEventListener('focus', () => this.refresh());

        this.input.addEventListener('keydown', (e) => {
            const opts = this.list.querySelectorAll('.city-combobox__option');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!this.isOpen) { this.refresh(); return; }
                if (opts.length) {
                    this.activeIndex = (this.activeIndex + 1) % opts.length;
                    this._renderActive();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (opts.length && this.activeIndex >= 0) {
                    this.activeIndex = (this.activeIndex - 1 + opts.length) % opts.length;
                    this._renderActive();
                }
            } else if (e.key === 'Enter') {
                if (this.isOpen && this.activeIndex >= 0 && opts[this.activeIndex]) {
                    e.preventDefault();
                    this.select(this.matches[this.activeIndex]);
                } else if (this.isOpen && opts.length === 1) {
                    // se c'è un unico risultato, Enter lo seleziona anche senza highlight
                }
            } else if (e.key === 'Escape') {
                this._close();
            } else if (e.key === 'Tab') {
                this._close();
            }
        });

        document.addEventListener('pointerdown', (e) => {
            if (!this.container.contains(e.target)) this._close();
        });
    }
}

function initCityComboboxes() {
    document.querySelectorAll('.city-combobox').forEach(container => {
        const input = container.querySelector('.city-combobox__input');
        if (!input || input.dataset.cbxInitialized) return;
        input.dataset.cbxInitialized = '1';

        const source = container.getAttribute('data-source') || 'comuni';
        const provSel = container.getAttribute('data-province-field');
        let provider = (all) => all;
        let onSelect = null;

        if (provSel) {
            const provName = provSel.replace(/_code\]$/, '_name]');
            const provText = document.querySelector(`input[name="${provName}"]`);
            provider = (all) => {
                const codeEl = document.querySelector(`input[name="${provSel}"]`);
                const code = codeEl ? codeEl.value.trim() : '';
                if (!code) return [];
                return all.filter(x => x.province === code);
            };
            if (provText) {
                provText.addEventListener('input', () => {
                    if (container._cbx && container._cbx.hasSelection()) container._cbx.resetOnSourceChange();
                });
            }
        }

        if (input.classList.contains('autocomplete-state')) {
            onSelect = () => toggleItalyFields(input);
        } else if (input.classList.contains('autocomplete-citizenship')) {
            onSelect = () => toggleCitizenshipItaly(input);
        }

        const opts = { input, source, provider, onSelect };
        if (source === 'comuni') {
            opts.emptyText = 'city_no_results';
            opts.requireSelectionMsg = 'city_select';
        } else if (source === 'stati') {
            opts.emptyText = 'state_no_results';
            opts.requireSelectionMsg = 'state_select';
        } else if (source === 'provincie') {
            opts.emptyText = 'prov_no_results';
            opts.requireSelectionMsg = 'prov_select';
            opts.minChars = 1;
        }
        const cbx = new CityCombobox(container, opts);
        container._cbx = cbx;
    });
}

function getCityCombobox(input) {
    const container = input.closest('.city-combobox');
    return container ? container._cbx : null;
}

function toggleItalyFields(stateInput) {
    const personBlock = stateInput.closest('.person-block');
    if (!personBlock) return;
    const isItaly = stateInput.value.trim().toUpperCase() === 'ITALIA';
    const isResidence = (stateInput.name || '').includes('res_state_name');
    const selector = isResidence ? '.field-res-italy-only' : '.field-italy-only';
    personBlock.querySelectorAll(selector).forEach(field => {
        field.classList.toggle('hidden', !isItaly);
    });
}

function toggleCitizenshipItaly(citizenshipInput) {
    const match = citizenshipInput.name.match(/persons\[(\d+)\]/);
    const index = match ? parseInt(match[1], 10) : -1;
    if (index !== 0) return;

    const personBlock = citizenshipInput.closest('.person-block');
    const rawValue = citizenshipInput.value.trim();
    const isItaly = rawValue.toUpperCase() === 'ITALIA';

    if (rawValue === '') {
        personBlock.querySelectorAll('.field-citizenship-italy').forEach(f => f.classList.add('hidden'));
        personBlock.querySelectorAll('.field-non-italy-only').forEach(f => f.classList.add('hidden'));
        return;
    }

    const docCityFields = personBlock.querySelectorAll('.field-citizenship-italy');
    docCityFields.forEach(field => {
        if (isItaly) field.classList.remove('hidden');
        else field.classList.add('hidden');
    });

    const nonItalyFields = personBlock.querySelectorAll('.field-non-italy-only');
    nonItalyFields.forEach(field => {
        if (!isItaly) field.classList.remove('hidden');
        else field.classList.add('hidden');
    });
}

function retranslateSelects() {
    // Riscrive il placeholder "Seleziona" dei select popolati dalla DB (descrizioni in italiano resta invariato).
    document.querySelectorAll('.doc-type-select, .guest-type-select').forEach(sel => {
        const current = sel.value;
        const firstOption = sel.querySelector('option[value=""]');
        if (firstOption) firstOption.textContent = T('select');
        sel.value = current;
    });
}

window.addEventListener('DOMContentLoaded', async () => {
    document.querySelectorAll('.doc-type-select').forEach(select => populateSelect('documenti', select));
    // populateSelect è async: attendi che i select ospiti siano popolati (e il
    // prefill del primo ospite applicato) prima di lanciare la cascata.
    const guestSelects = Array.from(document.querySelectorAll('.guest-type-select'));
    await Promise.all(guestSelects.map(select => populateSelect('tipo_alloggiato', select)));
    applyGuestTypeCascade();
    const firstGuest = document.querySelector('.guest-type-select');
    if (firstGuest) firstGuest.addEventListener('change', applyGuestTypeCascade);
    document.querySelectorAll('.autocomplete-state').forEach(input => toggleItalyFields(input));
    document.querySelectorAll('.autocomplete-citizenship').forEach(input => toggleCitizenshipItaly(input));
    initCityComboboxes();
    if (window.RegLang) window.__i18nChanged = retranslateSelects;
});

document.addEventListener('input', (e) => {
    const target = e.target;
    if (target.classList.contains('city-combobox__input')) {
        if (target.classList.contains('autocomplete-state')) toggleItalyFields(target);
        if (target.classList.contains('autocomplete-citizenship')) toggleCitizenshipItaly(target);
    }
});

document.addEventListener('blur', (e) => {
    const target = e.target;
    if (!target.classList?.contains('city-combobox__input')) return;
    const cbx = getCityCombobox(target);
    if (!cbx) return;
    if (target.closest('.field')?.classList.contains('hidden')) { cbx.clearError(); return; }
    if (target.value.trim() && !cbx.hasSelection()) {
        cbx.setError(T(cbx.requireSelectionMsg));
    } else {
        if (target.value.trim()) cbx.markValid();
        else cbx.clearError();
    }
}, true);

// ─────────────────────────────────────────────
// BANNER ERRORI LOOKUP
// ─────────────────────────────────────────────

function getFieldName(input) {
    const fieldEl = input.closest('.field');
    return fieldEl?.querySelector('label')?.textContent?.trim() || T('field_unknown');
}

function showLookupBanner(errors) {
    const banner = document.getElementById('lookup-banner');
    if (!banner) return;
    let html = `<strong>${T('lookup_banner_title')}</strong><ul>`;
    errors.forEach(err => {
        const spanId = `lookup-link-${errors.indexOf(err)}`;
        const label = err.label || T('field_unknown');
        html += `<li><span id="${spanId}" style="cursor:pointer;text-decoration:underline;" data-target-id="${err.input.id || err.input.name}">${label}</span></li>`;
    });
    html += '</ul>';
    banner.innerHTML = html;
    banner.style.display = 'block';

    banner.querySelectorAll('span[data-target-id]').forEach(span => {
        span.addEventListener('click', () => {
            const target = document.getElementById(span.dataset.targetId)
                || document.querySelector(`input[name="${span.dataset.targetId}"]`);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.focus();
            }
        });
    });
}

function hideLookupBanner() {
    const banner = document.getElementById('lookup-banner');
    if (banner) banner.style.display = 'none';
}

// ─────────────────────────────────────────────
// SUBMIT
// ─────────────────────────────────────────────

document.getElementById('regForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const msgDiv = document.getElementById('message');

    const submitBtn = e.target.querySelector('.submit-btn');
    const SUBMIT_LABEL = 'submit';
    const resetBtn = () => { submitBtn.disabled = false; submitBtn.textContent = T(SUBMIT_LABEL); };

    submitBtn.disabled = true;
    submitBtn.textContent = T('submitting');

    const privacyCheck = document.getElementById('privacyCheck');
    if (!privacyCheck?.checked) {
        msgDiv.innerHTML = `<b style="color: red;">${T('privacy_required')}</b>`;
        resetBtn();
        return;
    }

    // 1. VALIDAZIONE CAMPI AUTOCOMPLETE
    let autocompleteErrors = [];

    document.querySelectorAll('.autocomplete-prov, .autocomplete-city, .autocomplete-state, .autocomplete-citizenship, .autocomplete-doc-city, .autocomplete-doc-release-state').forEach(visibleInput => {
        if (visibleInput.closest('.field')?.classList.contains('hidden')) {
            visibleInput.classList.remove('invalid');
            return;
        }
        const cbx = getCityCombobox(visibleInput);
        if (!cbx) return;
        const val = visibleInput.value.trim();
        if (!val) { cbx.clearError(); return; }
        if (!cbx.hasSelection()) {
            cbx.setError(T(cbx.requireSelectionMsg));
            autocompleteErrors.push({ label: getFieldName(visibleInput), input: visibleInput });
        } else {
            cbx.markValid();
        }
    });

    if (autocompleteErrors.length > 0) {
        showLookupBanner(autocompleteErrors);
        msgDiv.innerHTML = `<b style="color: red;">${T('lookup_banner_msg')}</b>`;
        autocompleteErrors[0].input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        resetBtn();
        return;
    }
    hideLookupBanner();

    // 2. Valida tutti i campi doc_number prima di inviare
    let hasDocError = false;
    document.querySelectorAll('.doc-number-input').forEach(input => {
        const valid = applyDocValidation(input);
        if (!valid) hasDocError = true;
    });

    if (hasDocError) {
        hideLookupBanner();
        resetBtn();
        msgDiv.innerHTML = `<b style="color: red;">${T('fix_doc_numbers')}</b>`;
        return;
    }

    // 2b. VALIDAZIONE TIPO OSPITE
    let memberTypeError = null;
    document.querySelectorAll('.person-block').forEach((block, blockIndex) => {
        const select = block.querySelector('select[name*="member_type_id"]');
        if (select && !select.value) {
            memberTypeError = { index: blockIndex + 1, input: select };
        }
    });

    if (memberTypeError !== null) {
        hideLookupBanner();
        resetBtn();
        msgDiv.innerHTML = `<b style="color: red;">${T('member_type_required', { n: memberTypeError.index })}</b>`;
        memberTypeError.input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        memberTypeError.input.focus();
        return;
    }

    // 2b2. VALIDAZIONE STATO DI RESIDENZA (sempre obbligatorio)
    let resStateError = null;
    document.querySelectorAll('.person-block').forEach((block, blockIndex) => {
        const codeInput = block.querySelector('input[name*="res_state_code"]');
        if (codeInput && !codeInput.value.trim()) {
            resStateError = { index: blockIndex + 1, input: codeInput };
        }
    });

    if (resStateError !== null) {
        hideLookupBanner();
        resetBtn();
        msgDiv.innerHTML = `<b style="color: red;">${T('res_state_required', { n: resStateError.index })}</b>`;
        resStateError.input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const joinFields = (fields) => fields.map(f => T(f)).join(' ' + T('join_and') + ' ');
    const buildMissing = (err) => `${err.label}: ${joinFields(err.missingFields)} ${T('missing_suffix')}`;

    // 2c. VALIDAZIONE CAMPI OBBLIGATORI PER ITALIA (nascita)
    let italyBirthErrors = [];
    document.querySelectorAll('.person-block').forEach((block, blockIndex) => {
        const stateInput = block.querySelector('input[name*="birth_state_name"]');
        const stateCodeInput = block.querySelector('input[name*="birth_state_code"]');
        const provInput = block.querySelector('input[name*="birth_prov_name"]');
        const provCodeInput = block.querySelector('input[name*="birth_prov_code"]');
        const cityInput = block.querySelector('input[name*="birth_city_name"]');
        const cityCodeInput = block.querySelector('input[name*="birth_city_code"]');

        if (stateInput && provInput && cityInput) {
            const stateVal = stateInput.value.trim().toUpperCase();
            const isItalyName = stateVal === 'ITALIA';
            const isItalyCode = stateCodeInput && stateCodeInput.value.trim().toUpperCase() === '100000100';
            const isItaly = isItalyName || isItalyCode;
            const provCode = provCodeInput ? provCodeInput.value.trim() : '';
            const cityCode = cityCodeInput ? cityCodeInput.value.trim() : '';

            if (isItaly && (!provCode || !cityCode)) {
                const label = T('person_n', { n: blockIndex + 1 });
                italyBirthErrors.push({ label, input: stateInput, missingFields: [] });
                if (!provCode) italyBirthErrors[italyBirthErrors.length - 1].missingFields.push('field_province');
                if (!cityCode) italyBirthErrors[italyBirthErrors.length - 1].missingFields.push('field_city');
            }

            if (isItalyName && !isItalyCode) {
                const label = T('person_n', { n: blockIndex + 1 });
                const existingErr = italyBirthErrors.find(x => x.input === stateInput);
                if (existingErr) {
                    if (!existingErr.missingFields.includes('field_state')) existingErr.missingFields.push('field_state');
                } else {
                    italyBirthErrors.push({ label, input: stateInput, missingFields: ['field_state'] });
                }
            }
        }
    });

    if (italyBirthErrors.length > 0) {
        showLookupBanner(italyBirthErrors);
        const missingText = italyBirthErrors.map(buildMissing).join('; ');
        msgDiv.innerHTML = `<b style="color: red;">${missingText}</b>`;
        italyBirthErrors[0].input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        resetBtn();
        return;
    }

    // 2c-res. RESIDENZA
    let italyResidenceErrors = [];
    document.querySelectorAll('.person-block').forEach((block, blockIndex) => {
        const stateInput = block.querySelector('input[name*="res_state_name"]');
        const stateCodeInput = block.querySelector('input[name*="res_state_code"]');
        const provCodeInput = block.querySelector('input[name*="res_prov_code"]');
        const cityCodeInput = block.querySelector('input[name*="res_city_code"]');

        if (!stateInput) return;
        const stateVal = stateInput.value.trim().toUpperCase();
        const isItalyCode = stateCodeInput && stateCodeInput.value.trim().toUpperCase() === '100000100';
        const isItaly = stateVal === 'ITALIA' || isItalyCode;
        const provCode = provCodeInput ? provCodeInput.value.trim() : '';
        const cityCode = cityCodeInput ? cityCodeInput.value.trim() : '';

        if (isItaly && (!provCode || !cityCode)) {
            const err = { label: T('person_n', { n: blockIndex + 1 }), input: stateInput, missingFields: [] };
            if (!provCode) err.missingFields.push('field_res_province');
            if (!cityCode) err.missingFields.push('field_res_city');
            italyResidenceErrors.push(err);
        }
    });

    if (italyResidenceErrors.length > 0) {
        showLookupBanner(italyResidenceErrors);
        const missingText = italyResidenceErrors.map(buildMissing).join('; ');
        msgDiv.innerHTML = `<b style="color: red;">${missingText}</b>`;
        italyResidenceErrors[0].input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        resetBtn();
        return;
    }

    // 2d. VALIDAZIONE INCROCIATA
    let crossFieldErrors = [];
    document.querySelectorAll('.person-block').forEach(block => {
        const stateInput = block.querySelector('input[name*="birth_state_name"]');
        const provInput = block.querySelector('input[name*="birth_prov_name"]');
        const cityInput = block.querySelector('input[name*="birth_city_name"]');

        if (stateInput && provInput) {
            const stateVal = stateInput.value.trim();
            const provVal = provInput.value.trim();
            if (stateVal && provVal && stateVal.toUpperCase() !== 'ITALIA') {
                crossFieldErrors.push({ label: T('birth_state'), input: stateInput });
            }
        }

        if (stateInput && cityInput) {
            const stateVal = stateInput.value.trim();
            const cityVal = cityInput.value.trim();
            if (stateVal && cityVal && stateVal.toUpperCase() !== 'ITALIA') {
                if (!crossFieldErrors.some(x => x.input === stateInput)) {
                    crossFieldErrors.push({ label: T('birth_state'), input: stateInput });
                }
            }
        }
    });

    if (crossFieldErrors.length > 0) {
        showLookupBanner(crossFieldErrors);
        msgDiv.innerHTML = `<b style="color: red;">${T('coherence')}</b>`;
        crossFieldErrors[0].input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    // 3. Build FormData
    msgDiv.textContent = T('submitting');

    const formData = new FormData(e.target);
    const data = { token: formData.get('token'), csrf: formData.get('csrf') || '', persons: [] };

    const personsMap = {};
    formData.forEach((value, key) => {
        if (key.startsWith('persons')) {
            const match = key.match(/persons\[(\d+)\]\[(.+)\]/);
            if (match) {
                const index = match[1];
                const field = match[2];
                if (!personsMap[index]) personsMap[index] = {};
                personsMap[index][field] = value;
            }
        }
    });

    data.persons = Object.values(personsMap);

    try {
        const response = await fetch('/web/api/register/submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(data)
        });

        if (response.ok) {
            const next = await response.json();
            e.target.querySelector('.submit-btn').disabled = true;

            if (next.next_url && next.next_step === 2) {
                msgDiv.innerHTML = `<b style="color: green;">${T('step2_redirect')}</b>`;
                setTimeout(() => { location.href = next.next_url; }, 1200);
                return;
            }

            if (next.redirect_to) {
                msgDiv.innerHTML = `<b style="color: green;">${T('redirect_to')} ${next.redirect_to}</b>`;
                setTimeout(() => { location.href = next.redirect_to; }, 1500);
                return;
            }

            msgDiv.innerHTML = `<b style="color: green;">${T('sent_success')}</b>`;
        } else {
            const errorText = await response.text();
            let errorMsg = T('server_error_default');
            try {
                const errorJson = JSON.parse(errorText);
                errorMsg = errorJson.error || errorMsg;
            } catch (err) {
                errorMsg = errorText || errorMsg;
            }
            msgDiv.innerHTML = `<b style="color: red;">${T('error_prefix', { msg: errorMsg })}</b>`;
            resetBtn();
        }
    } catch (err) {
        msgDiv.innerHTML = `<b style="color: red;">${T('net_error', { msg: err.message })}</b>`;
        resetBtn();
    }
});
