<?php
$travelToken = isset($travelToken) ? $travelToken : '';
$tourOptions = isset($travelData['options']['tour_type']) ? $travelData['options']['tour_type'] : [];
$travOpts    = isset($travelData['options']['transport_mean']) ? $travelData['options']['transport_mean'] : [];
$prefillTour = isset($travelData['tour_type']) ? $travelData['tour_type'] : null;
$prefillTrav = isset($travelData['transport_mean']) ? $travelData['transport_mean'] : null;
$firstName = isset($tokenData['first_name']) ? $tokenData['first_name'] : '';
// Trova l'indice del prefill (per mettere selected sull'opzione corretta)
$nTour = (int)count($tourOptions);
$nTrans = (int)count($travOpts);
$iTourSel = null;
for ($i = 0; $i < $nTour; $i++) {
    $cod = $tourOptions[$i] ?? ['codice' => ''];
    if ($cod['codice'] === $prefillTour) { $iTourSel = $i; break; }
}
$iTranSel = null;
for ($i = 0; $i < $nTrans; $i++) {
    $cod = $travOpts[$i] ?? ['codice' => ''];
    if ($cod['codice'] === $prefillTrav) { $iTranSel = $i; break; }
}
$selTourEmpty  = ($iTourSel === null);
$selTransEmpty = ($iTranSel === null);
$tassaShow   = isset($tassaShow) ? (bool)$tassaShow : false;
$tassaTotale = isset($tassaTotale) ? (float)$tassaTotale : 0.0;
$tassaAmount = '€ ' . number_format($tassaTotale, 2, ',', ' ');
?>
<!DOCTYPE html>
<html lang="it" data-title-i18n="step2_title">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completamento Registrazione — Dati di Viaggio</title>
    <script src="<?php echo asset_url('js/register_lang.js'); ?>?v=2"></script>
    <style>
        body { font-family: sans-serif; background-color: #f4f7f6; color: #333; display: flex; justify-content: center; padding: 20px; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 600px; width: 100%; }
        h1 { color: #2c3e50; text-align: center; }
        .step-banner { background: #eaf7ea; border: 1px solid #bfe6bf; border-radius: 6px; padding: 12px 16px; margin-bottom: 22px; color: #2c6a2c; font-size: 0.93em; line-height: 1.5; }
        .tax-banner { background: #fdf6e7; border: 1px solid #f0d9a0; border-radius: 6px; padding: 12px 16px; margin: 18px 0 22px; color: #7a5b1e; font-size: 0.93em; line-height: 1.5; }
        .field { margin-bottom: 14px; display: flex; flex-direction: column; }
        .field label { font-size: 0.9em; margin-bottom: 5px; color: #666; }
        .field select { padding: 9px; border: 1px solid #ccc; border-radius: 4px; }
        .submit-btn { display: block; width: 100%; padding: 13px; background-color: #27ae60; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1.1em; }
        .submit-btn:hover { background-color: #219a52; }
        .submit-btn:disabled { background-color: #bbb; cursor: not-allowed; }
        #message { margin-top: 18px; text-align: center; }
        .muted { color: #8a969b; font-size: 0.85em; text-align: center; margin: 14px 0 0; }
        .error { color: #c0392b; }
    </style>
</head>
<body>
    <div class="container">
        <h1 data-i18n="step2_title">Completamento Registrazione</h1>
        <?php if ($firstName !== ''): ?>
            <p style="text-align:center; color:#555;" data-i18n="done_thanks" data-i18n-vars='<?php echo htmlspecialchars(json_encode(['name' => $firstName]), ENT_QUOTES); ?>'>Grazie <?php echo htmlspecialchars($firstName); ?>, i tuoi dati di soggiorno sono stati ricevuti.</p>
        <?php endif; ?>
        <div class="step-banner">
            <strong data-i18n="step2_banner_h">Step 2 — Dati di viaggio.</strong><br>
            <span data-i18n="step2_banner_d">Per concludere la registrazione indica il tipo di viaggio e il mezzo di trasporto.</span>
        </div>

        <form id="travelForm">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($travelToken); ?>">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">

            <div class="field">
                <label for="tourType" data-i18n="tour_type">Tipo di viaggio</label>
                <select id="tourType" name="tour_type" required>
                    <option value="" data-i18n="select" <?php echo $selTourEmpty ? 'selected="selected"' : ''; ?>>Seleziona</option>
                    <?php foreach ($tourOptions as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt['codice'] ?? '?'); ?>" <?php echo ($opt['codice'] === $prefillTour) ? 'selected="selected"' : ''; ?>><?php echo htmlspecialchars($opt['descrizione'] ?? ($opt['codice'] ?? '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="transportMean" data-i18n="transport_mean">Mezzo di trasporto</label>
                <select id="transportMean" name="transport_mean" required>
                    <option value="" data-i18n="select" <?php echo $selTransEmpty ? 'selected="selected"' : ''; ?>>Seleziona</option>
                    <?php foreach ($travOpts as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt['codice'] ?? '?'); ?>" <?php echo ($opt['codice'] === $prefillTrav) ? 'selected="selected"' : ''; ?>><?php echo htmlspecialchars($opt['descrizione'] ?? ($opt['codice'] ?? '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($tassaShow): ?>
                <div class="tax-banner">
                    <strong data-i18n="tax_banner_h">Tassa di soggiorno</strong> — <?php
                        if ($tassaTotale > 0) {
                            echo '<span data-i18n="tax_banner_amount" data-i18n-vars=\'{"amount":"'. htmlspecialchars($tassaAmount, ENT_QUOTES) . '"}\'>Per il soggiorno l\'importo è di '.$tassaAmount.'. Si prega di averlo a disposizione in contanti e di lasciare il totale in casa al momento del check-in.</span>';
                        } else {
                            echo '<span data-i18n="tax_banner_zero">L\'eventuale importo della tassa di soggiorno va saldato in contanti: si prega di lasciarlo in casa al momento del check-in.</span>';
                        }
                    ?>
                </div>
            <?php endif; ?>

            <button type="submit" class="submit-btn" id="travelSubmit" data-i18n="submit_travel">Completa Registrazione</button>
            <div id="message"></div>
            <p class="muted" data-i18n="final_note">Con questo passo concludi la registrazione della tua prenotazione.</p>
        </form>
    </div>

    <script src="<?php echo asset_url('js/register_travel.js'); ?>?v=i18n-2"></script>
</body>
</html>
