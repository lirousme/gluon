<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}
if ((int)$_SESSION['user_id'] !== 1) {
    http_response_code(403);
    echo '<h1 style="font-family:sans-serif">403 - Acesso restrito ao administrador.</h1>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Administração</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
    <main class="max-w-xl mx-auto px-4 py-10">
        <a href="/dashboard" class="text-blue-400 text-sm">&larr; Voltar ao dashboard</a>
        <h1 class="text-2xl font-bold mt-4">Painel ADM</h1>
        <p class="text-slate-400 mt-2">Área restrita para manutenção administrativa.</p>

        <div class="mt-8">
            <a href="/configuracao-tts" class="inline-flex items-center justify-center w-full rounded-lg bg-blue-600 hover:bg-blue-500 transition-colors px-4 py-3 font-semibold">
                Configuração de TTS
            </a>
        </div>
    </main>
</body>
</html>
