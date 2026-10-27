<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OspitiWebServer — Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-card { background: #fff; padding: 2rem 2.5rem; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.1); width: 100%; max-width: 380px; }
        h1 { text-align: center; margin-bottom: .5rem; font-size: 1.5rem; color: #333; }
        .subtitle { text-align: center; margin-bottom: 1.5rem; color: #888; font-size: .9rem; }
        label { display: block; margin-bottom: .25rem; font-weight: 600; font-size: .9rem; color: #555; }
        input[type="text"], input[type="password"] { width: 100%; padding: .6rem .8rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; margin-bottom: 1rem; }
        input:focus { outline: none; border-color: #4a90d9; box-shadow: 0 0 0 2px rgba(74,144,217,.15); }
        button { width: 100%; padding: .7rem; background: #4a90d9; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; font-weight: 600; transition: background .2s; }
        button:hover { background: #357ab8; }
        button:disabled { background: #a0c4e8; cursor: not-allowed; }
        .error { color: #d9534f; font-size: .85rem; margin-bottom: 1rem; display: none; text-align: center; }
    </style>
</head>
<body>
<div class="login-card">
    <h1>OspitiWebServer</h1>
    <p class="subtitle">Accedi al pannello di gestione</p>

    <?php if (!empty($error ?? '')): ?>
        <div class="error" style="display:block;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form id="loginForm" onsubmit="return false;">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autocomplete="username" autofocus>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">

        <button type="submit" id="btnLogin">Accedi</button>
    </form>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', async function() {
    const btn = document.getElementById('btnLogin');
    btn.disabled = true;
    btn.textContent = 'Accesso in corso...';

    try {
        const res = await fetch('/web/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                username: document.getElementById('username').value,
                password: document.getElementById('password').value
            })
        });

        const data = await res.json();

        if (res.ok && data.token) {
            localStorage.setItem('auth_token', data.token);
            window.location.href = '/';
        } else {
            alert(data.error || 'Credenziali non valide');
        }
    } catch (err) {
        alert('Errore di connessione al server');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Accedi';
    }
});
</script>
</body>
</html>
