// FASE 1.5 — STEP 2: Dati di Viaggio (ROSS 19/20) — invio + redirect finale
(function () {
    'use strict';
    const T = (key, params) => (window.RegLang ? window.RegLang.t(key, params) : key);
    const form = document.getElementById('travelForm');
    if (!form) return;
    const btn = document.getElementById('travelSubmit');
    const message = document.getElementById('message');
    const SUBMIT_LABEL = 'submit_travel';

    const resetBtn = () => { btn.disabled = false; btn.textContent = T(SUBMIT_LABEL); };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        btn.disabled = true;
        btn.textContent = T('submitting');
        message.innerHTML = '';

        const data = {
            token: form.token ? form.token.value : '',
            csrf: form.csrf ? form.csrf.value : '',
            tour_type: form.tour_type ? form.tour_type.value : '',
            transport_mean: form.transport_mean ? form.transport_mean.value : '',
        };

        try {
            const response = await fetch('/web/api/register/travel', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });

            if (response.ok) {
                const result = await response.json();
                btn.textContent = T('confirmed');
                const saved = result.already_complete ? T('already_complete') : T('travel_saved');
                message.innerHTML = `<b style="color: green;">${saved}.</b>`;

                if (result.redirect_to) {
                    setTimeout(() => { location.href = result.redirect_to; }, 1200);
                } else {
                    setTimeout(() => { location.href = '/register/travel/' + encodeURIComponent(data.token); }, 1200);
                }
                return;
            }

            let errMsg = T('server_error_default');
            try {
                const json = JSON.parse(await response.text());
                errMsg = json.error || json.message || errMsg;
            } catch (parseErr) { errMsg = parseErr.message || errMsg; }
            resetBtn();
            message.innerHTML = `<b style="color: red;">${errMsg}</b>`;
        } catch (netErr) {
            resetBtn();
            message.innerHTML = `<b style="color: red;">${T('net_error', { msg: netErr.message })}</b>`;
        }
    });
})();
