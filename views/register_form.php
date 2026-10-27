<!DOCTYPE html>
<html lang="it" data-title-i18n="title">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione Ospiti</title>
    <script src="<?php echo asset_url('js/register_lang.js'); ?>?v=2"></script>
    <style>
        body { font-family: sans-serif; background-color: #f4f7f6; color: #333; display: flex; justify-content: center; padding: 20px; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 600px; width: 100%; }
        h1 { color: #2c3e50; text-align: center; }
        .guest-info { margin-bottom: 20px; padding: 10px; background: #eef2f3; border-radius: 4px; text-align: center; }
        .person-block { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 5px; position: relative; }
        .person-block h3 { margin-top: 0; font-size: 1.1em; color: #555; }
        .field { margin-bottom: 10px; display: flex; flex-direction: column; }
        .field label { font-size: 0.9em; margin-bottom: 5px; color: #666; }
        .field input, .field select { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .field input.invalid { border-color: red; }
        .field input.valid { border-color: #27ae60; }
        .field.lookup-missing input { border-color: #e53e3e !important; background: #fff5f5; }
        .field.lookup-synced input { border-color: #27ae60 !important; }
        .sync-indicator { position: absolute; right: 8px; top: 30px; font-size: 1.1em; display: none; }
        .field.lookup-synced .sync-indicator { display: inline; }
        #lookup-banner {
            display: none;
            background: #fff3f0;
            border: 1px solid #feb2b2;
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 20px;
            color: #9b2c2c;
            font-size: 0.93em;
            line-height: 1.5;
        }
        #lookup-banner ul { margin: 6px 0 0 18px; padding: 0; }
        #lookup-banner li { margin: 2px 0; cursor: pointer; text-decoration: underline; }
        #lookup-banner li:hover { color: #c53030; }
        .submit-btn { display: block; width: 100%; padding: 12px; background-color: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1.1em; }
        .submit-btn:hover { background-color: #2980b9; }
        .city-combobox { position: relative; }
        .city-combobox .city-combobox__input { font-size: 16px; width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .city-combobox .city-combobox__list {
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            z-index: 30;
            margin: 2px 0 0;
            padding: 4px 0;
            list-style: none;
            background: #ffffff;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 6px 14px rgba(0,0,0,0.12);
            max-height: 40vh;
            overflow-y: auto;
        }
        .city-combobox .city-combobox__option {
            padding: 10px 12px;
            min-height: 44px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 0.95em;
        }
        .city-combobox .city-combobox__option .city-combobox__badge {
            font-size: 0.8em;
            color: #777;
            background: #f0f0f0;
            border-radius: 3px;
            padding: 1px 6px;
        }
        .city-combobox .city-combobox__option:hover,
        .city-combobox .city-combobox__option.active { background: #eef5fb; }
        .city-combobox .city-combobox__placeholder { padding: 10px 12px; color: #888; font-style: italic; }
        .city-combobox .city-combobox__more { padding: 8px 12px; color: #888; font-size: 0.85em; border-top: 1px solid #eee; }
        .city-combobox .city-combobox__error { display: block; margin-top: 4px; color: #c53030; font-size: 0.85em; }
        .privacy-field { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 15px; font-size: 0.88em; color: #555; line-height: 1.4; }
        .privacy-field input[type="checkbox"] { margin-top: 3px; flex-shrink: 0; }
        .privacy-field a { color: #3498db; text-decoration: underline; }
        .privacy-field a:hover { color: #2980b9; }
        .error { color: red; text-align: center; margin-bottom: 20px; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1 data-i18n="title">Registrazione Ospiti</h1>
        <div class="guest-info">
			<span data-i18n="welcome" data-i18n-vars='<?php echo htmlspecialchars(json_encode(['name' => $tokenData['first_name']]), ENT_QUOTES); ?>'>Benvenuto, <?php echo htmlspecialchars($tokenData['first_name']); ?>!</span><br>
            <span data-i18n="fill_in" data-i18n-vars='<?php echo htmlspecialchars(json_encode(['n' => (int)$tokenData['num_persons']]), ENT_QUOTES); ?>'>Per favore, compila i dati per <?php echo (int)$tokenData['num_persons']; ?> persona(e).</span>
        </div>

        <form id="regForm">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
            <div id="lookup-banner"></div>

            <?php for ($i = 1; $i <= $tokenData['num_persons']; $i++): ?>
                <div class="person-block">
                    <h3 data-i18n="person_n" data-i18n-vars='{"n":"<?php echo $i; ?>"}'>Persona <?php echo $i; ?></h3>
                    <?php
                    $numPersonsReg = (int)$tokenData['num_persons'];
                    $firstTypeReg  = (string)($tokenData['member_type_id'] ?? '');
                    if ($i == 1) {
                        $allowedReg  = ($numPersonsReg == 1) ? '16' : '17,18';
                        $prefillReg  = $firstTypeReg;
                    } else {
                        $allowedReg  = '19,20';
                        $prefillReg  = '';
                    }
                    ?>
                    <div class="field">
                        <label data-i18n="guest_type">Tipo Ospite</label>
                        <select name="persons[<?php echo $i-1; ?>][member_type_id]" class="guest-type-select" data-allowed="<?php echo $allowedReg; ?>" data-prefill="<?php echo htmlspecialchars($prefillReg); ?>">
                            <option value="">Seleziona</option>
                        </select>
                    </div>
                    <div class="field">
                        <label data-i18n="name">Nome *</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][first_name]" value="<?php echo ($i == 1) ? htmlspecialchars($tokenData['first_name']) : ''; ?>" required>
                    </div>
                    <div class="field">
                        <label data-i18n="lastname">Cognome *</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][last_name]" value="<?php echo ($i == 1) ? htmlspecialchars($tokenData['last_name']) : ''; ?>" required>
                    </div>
                    <div class="field">
                        <label data-i18n="gender">Genere</label>
                        <select name="persons[<?php echo $i-1; ?>][gender]">
                            <option value="" data-i18n="select">Seleziona</option>
                            <option value="M" data-i18n="male">Maschio</option>
                            <option value="F" data-i18n="female">Femmina</option>
                        </select>
                    </div>
                    <div class="field">
                        <label data-i18n="birth_date">Data di Nascita</label>
                        <input type="date" name="persons[<?php echo $i-1; ?>][birth_date]">
                    </div>
					                    <div class="field city-combobox" data-source="stati">
                        <label data-i18n="birth_state">Stato di Nascita</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][birth_state_name]" class="autocomplete-state city-combobox__input">
                        <input type="hidden" name="persons[<?php echo $i-1; ?>][birth_state_code]" data-role="city_code">
                        <ul class="city-combobox__list" role="listbox" hidden></ul>
                        <small class="city-combobox__error" hidden></small>
                    </div>
                    <div class="field field-italy-only hidden city-combobox" data-source="provincie">
                        <label data-i18n="birth_prov">Provincia di Nascita</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][birth_prov_name]" class="autocomplete-prov city-combobox__input">
                        <input type="hidden" name="persons[<?php echo $i-1; ?>][birth_prov_code]" data-role="city_code">
                        <ul class="city-combobox__list" role="listbox" hidden></ul>
                        <small class="city-combobox__error" hidden></small>
                    </div>
                    <div class="field field-italy-only hidden city-combobox" data-source="comuni" data-province-field="persons[<?php echo $i-1; ?>][birth_prov_code]">
                        <label data-i18n="birth_city">Città di Nascita</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][birth_city_name]" class="autocomplete-city city-combobox__input">
                        <input type="hidden" name="persons[<?php echo $i-1; ?>][birth_city_code]" data-role="city_code">
                        <ul class="city-combobox__list" role="listbox" hidden></ul>
                        <small class="city-combobox__error" hidden></small>
                    </div>

                    <div class="field city-combobox" data-source="stati">
                        <label data-i18n="res_state">Stato di Residenza</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][res_state_name]" maxlength="80" class="autocomplete-state residence-state city-combobox__input">
                        <input type="hidden" name="persons[<?php echo $i-1; ?>][res_state_code]" data-role="city_code">
                        <ul class="city-combobox__list" role="listbox" hidden></ul>
                        <small class="city-combobox__error" hidden></small>
                    </div>
                    <div class="field field-res-italy-only hidden city-combobox" data-source="provincie" data-res-group="prov">
                        <label data-i18n="res_prov">Provincia di Residenza</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][res_prov_name]" maxlength="30" class="autocomplete-prov residence-prov city-combobox__input">
                        <input type="hidden" name="persons[<?php echo $i-1; ?>][res_prov_code]" data-role="city_code">
                        <ul class="city-combobox__list" role="listbox" hidden></ul>
                        <small class="city-combobox__error" hidden></small>
                    </div>
                    <div class="field field-res-italy-only hidden city-combobox" data-source="comuni" data-province-field="persons[<?php echo $i-1; ?>][res_prov_code]">
                        <label data-i18n="res_city">Città di Residenza</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][res_city_name]" maxlength="80" class="autocomplete-city residence-city city-combobox__input">
                        <input type="hidden" name="persons[<?php echo $i-1; ?>][res_city_code]" data-role="city_code">
                        <ul class="city-combobox__list" role="listbox" hidden></ul>
                        <small class="city-combobox__error" hidden></small>
                    </div>
                    <div class="field">
                        <label data-i18n="res_address">Indirizzo di Residenza (opzionale)</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][res_address]" maxlength="50">
                    </div>

                    <div class="field city-combobox" data-source="stati">
                        <label data-i18n="citizenship">Cittadinanza</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][citizenship_state_name]" class="autocomplete-citizenship city-combobox__input">
                        <input type="hidden" name="persons[<?php echo $i-1; ?>][citizenship_state_code]" data-role="city_code">
                        <ul class="city-combobox__list" role="listbox" hidden></ul>
                        <small class="city-combobox__error" hidden></small>
                    </div>
                    <?php if ($i == 1): ?>
                    <div class="field">
                    <label data-i18n="doc_type">Tipo Documento</label>
                    <select name="persons[<?php echo $i-1; ?>][doc_type]" class="doc-type-select">
                        <option value="">Seleziona</option>
                    </select>
                    </div>
                    <div class="field">
                        <label data-i18n="doc_number">Numero Documento</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][doc_number]" class="doc-number-input"
                           aria-describedby="doc-number-error-0">
                    <span class="field-error" id="doc-number-error-0"></span>
                    </div>
                    <div class="field field-citizenship-italy hidden city-combobox" data-source="comuni">
                        <label data-i18n="doc_release_city">Città Rilascio Documento</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][doc_issue_city]" class="autocomplete-doc-city city-combobox__input">
                        <input type="hidden" name="persons[<?php echo $i-1; ?>][doc_issue_city_code]" data-role="city_code">
                        <ul class="city-combobox__list" role="listbox" hidden></ul>
                        <small class="city-combobox__error" hidden></small>
                    </div>
                    <div class="field field-non-italy-only hidden city-combobox" data-source="stati">
                        <label data-i18n="doc_release_state">Stato Rilascio Documento</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][doc_release_state_name]" class="autocomplete-doc-release-state city-combobox__input">
                        <input type="hidden" name="persons[<?php echo $i-1; ?>][doc_release_state_code]" data-role="city_code">
                        <ul class="city-combobox__list" role="listbox" hidden></ul>
                        <small class="city-combobox__error" hidden></small>
                    </div>
                    <div class="field">
                        <label data-i18n="email">Email</label>
                        <input type="email" name="persons[<?php echo $i-1; ?>][email]">
                    </div>
                    <div class="field">
                        <label data-i18n="phone">Telefono</label>
                        <input type="text" name="persons[<?php echo $i-1; ?>][phone]">
                    </div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>

            <div class="privacy-field">
                <input type="checkbox" id="privacyCheck">
                <label for="privacyCheck">
                    <span data-i18n="privacy_text">Confermo di aver letto e accetto</span>
                    <a href="/web/privacy" target="_blank" rel="noopener noreferrer"><span data-i18n="privacy_link">Informativa sulla Privacy e Termini di Servizio</span></a>
                </label>
            </div>
            <button type="submit" class="submit-btn" data-i18n="submit">Invia Registrazione</button>
            <div id="message" style="margin-top: 20px; text-align: center;"></div>
        </form>
    </div>

    <script src="<?php echo asset_url('js/register_form.js'); ?>?v=guesttype-1"></script>
</body>
</html>
