<!DOCTYPE html>
<html lang="it" data-title-i18n="success_title">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione Completata</title>
    <script src="<?php echo asset_url('js/register_lang.js'); ?>?v=2"></script>
    <style>
        body { font-family: sans-serif; background-color: #f4f7f6; color: #333; display: flex; justify-content: center; padding: 20px; }
        .container { background: white; padding: 40px 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 560px; width: 100%; text-align: center; }
        h1 { color: #27ae60; font-size: 1.5em; margin-top: 0; }
        p { color: #666; line-height: 1.7; }
        .check { font-size: 3em; margin-bottom: 10px; }
        .note { background: #eef7ee; border: 1px solid #c6e6c6; border-radius: 6px; padding: 14px 18px; margin-top: 24px; font-size: 0.9em; color: #2c6a2c; }
    </style>
</head>
<body>
    <div class="container">
        <div class="check">&#10004;</div>
        <h1 data-i18n="success_h1">Registrazione completata</h1>
        <?php if (!empty($tokenData['first_name'])): ?>
            <p data-i18n="thanks_named" data-i18n-vars='<?php echo htmlspecialchars(json_encode(['name' => $tokenData['first_name']]), ENT_QUOTES); ?>'>Grazie <strong><?php echo htmlspecialchars($tokenData['first_name']); ?></strong>, i tuoi dati sono stati presi in carico.</p>
        <?php else: ?>
            <p data-i18n="thanks_anon">Grazie, i tuoi dati sono stati presi in carico.</p>
        <?php endif; ?>
        <div class="note">
            <p data-i18n="success_note">Conserva eventuali codici o conferme che ti sono stati comunicati. All'arrivo verrai accolto dallo staff.</p>
        </div>
    </div>
</body>
</html>
