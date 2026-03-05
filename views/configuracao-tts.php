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
    <title>Configuração de TTS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
    <main class="max-w-2xl mx-auto px-3 py-6">
        <div class="flex items-center justify-between gap-2 mb-4">
            <a href="/adm" class="text-blue-400 text-sm">&larr; Voltar</a>
            <h1 class="text-lg font-bold">Configuração de TTS</h1>
            <span></span>
        </div>

        <form id="form" class="bg-slate-900 border border-slate-700 rounded-xl p-4 space-y-3">
            <input type="hidden" id="id">
            <select id="language" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2" required>
                <option value="pt-BR">Português Brasileiro</option>
                <option value="en-US">Inglês Americano</option>
                <option value="en-GB">Inglês Britânico</option>
            </select>
            <input id="source_text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2" placeholder="Texto original" required>
            <input id="target_text" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2" placeholder="Texto para pronúncia" required>
            <div class="grid grid-cols-2 gap-2">
                <button class="bg-blue-600 hover:bg-blue-500 rounded-lg py-2 font-semibold" type="submit">Salvar</button>
                <button class="bg-slate-700 hover:bg-slate-600 rounded-lg py-2" type="button" onclick="resetForm()">Limpar</button>
            </div>
        </form>

        <div id="list" class="mt-4 space-y-2"></div>
    </main>

<script>
const apiUrl = '/api/pronuncias';

async function request(action, payload = {}) {
    const response = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...payload })
    });
    return response.json();
}

function renderItem(item) {
    return `
    <article class="bg-slate-900 border border-slate-700 rounded-lg p-3">
        <div class="text-xs text-slate-400">${item.language}</div>
        <div class="text-sm mt-1"><span class="text-slate-400">Origem:</span> ${item.source_text}</div>
        <div class="text-sm"><span class="text-slate-400">Pronúncia:</span> ${item.target_text}</div>
        <div class="mt-2 flex gap-2">
            <button class="flex-1 bg-amber-600 hover:bg-amber-500 rounded py-1.5 text-sm" onclick='editItem(${JSON.stringify(item)})'>Editar</button>
            <button class="flex-1 bg-red-700 hover:bg-red-600 rounded py-1.5 text-sm" onclick="deleteItem(${item.id})">Excluir</button>
        </div>
    </article>`;
}

async function loadItems() {
    const data = await request('list');
    const list = document.getElementById('list');
    if (data.status !== 'success') {
        list.innerHTML = '<p class="text-red-400 text-sm">Erro ao carregar.</p>';
        return;
    }
    list.innerHTML = data.data.map(renderItem).join('') || '<p class="text-slate-400 text-sm">Nenhum registro.</p>';
}

function editItem(item) {
    document.getElementById('id').value = item.id;
    document.getElementById('language').value = item.language;
    document.getElementById('source_text').value = item.source_text;
    document.getElementById('target_text').value = item.target_text;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('id').value = '';
    document.getElementById('language').value = 'pt-BR';
    document.getElementById('source_text').value = '';
    document.getElementById('target_text').value = '';
}

async function deleteItem(id) {
    if (!confirm('Deseja excluir este registro?')) return;
    const data = await request('delete', { id });
    if (data.status === 'success') loadItems();
}

document.getElementById('form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = {
        id: Number(document.getElementById('id').value || 0),
        language: document.getElementById('language').value,
        source_text: document.getElementById('source_text').value,
        target_text: document.getElementById('target_text').value
    };

    const action = payload.id > 0 ? 'update' : 'create';
    const data = await request(action, payload);
    if (data.status === 'success') {
        resetForm();
        loadItems();
    } else {
        alert(data.message || 'Erro ao salvar.');
    }
});

loadItems();
</script>
</body>
</html>
