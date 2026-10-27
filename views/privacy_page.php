<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informativa sulla Privacy e Termini di Servizio</title>
    <style>
        body { font-family: sans-serif; background-color: #f4f7f6; color: #333; display: flex; justify-content: center; padding: 20px; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 800px; width: 100%; }
        h1 { color: #2c3e50; text-align: center; margin-bottom: 30px; font-size: 1.6em; }
        h2 { color: #34495e; border-bottom: 2px solid #3498db; padding-bottom: 8px; margin-top: 30px; }
        h3 { color: #555; margin-top: 20px; }
        hr { border: none; border-top: 1px solid #ddd; margin: 20px 0; }
        ul { padding-left: 24px; }
        li { margin-bottom: 6px; }
        blockquote { background: #f9f9f9; border-left: 4px solid #3498db; margin: 15px 0; padding: 12px 16px; color: #555; font-style: italic; }
        p { line-height: 1.7; }
        a.close-btn { display: inline-block; margin-top: 30px; padding: 10px 20px; color: #3498db; text-decoration: none; border: 1px solid #3498db; border-radius: 4px; font-size: 0.95em; }
        a.close-btn:hover { background: #3498db; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <?php echo $html; ?>
        <a href="javascript:window.close()" class="close-btn">Chiudi finestra</a>
    </div>
</body>
</html>
