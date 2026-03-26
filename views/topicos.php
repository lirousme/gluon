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
    <title>Matérias (Trilhas)</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
    <main class="max-w-3xl mx-auto px-4 py-8 space-y-6">
        <a href="/adm" class="text-blue-400 text-sm">&larr; Voltar ao ADM</a>
        <h1 class="text-3xl font-bold">Matérias (Trilhas)</h1>

        <section class="bg-slate-900 border border-slate-700 rounded-xl p-4 flex gap-2">
            <input id="materiaInput" class="flex-1 bg-slate-800 border border-slate-600 rounded-lg px-3 py-2" placeholder="Nome da matéria">
            <button id="btnInserir" class="bg-blue-600 hover:bg-blue-500 px-4 py-2 rounded-lg font-semibold">Inserir matéria</button>
        </section>

        <section class="bg-slate-900 border border-slate-700 rounded-xl p-4">
            <ul id="listaMaterias" class="space-y-2"></ul>
        </section>
    </main>

<script>
const api = async (action, data = {}) => {
    const res = await fetch('/api/topicos', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...data })
    });
    return res.json();
};

const state = { materias: [] };

function renderMaterias() {
    const ul = document.getElementById('listaMaterias');
    if (!state.materias.length) {
        ul.innerHTML = '<li class="text-slate-400">Nenhuma matéria/trilha cadastrada.</li>';
        return;
    }

    ul.innerHTML = state.materias.map(m => `
        <li class="bg-slate-800 border border-slate-700 rounded-lg p-3 flex items-center gap-2">
            <button onclick="openMateria(${m.id})" class="flex-1 text-left hover:text-blue-300 transition-colors font-medium">${escapeHtml(m.nome)}</button>
            <button onclick="editarMateria(${m.id}, '${escapeJs(m.nome)}')" class="text-xs bg-amber-600 hover:bg-amber-500 px-2 py-1 rounded">Editar</button>
            <button onclick="excluirMateria(${m.id})" class="text-xs bg-rose-600 hover:bg-rose-500 px-2 py-1 rounded">Excluir</button>
        </li>
    `).join('');
}

function escapeHtml(str) {
    return String(str).replace(/[&<>'"]/g, s => ({ '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;' }[s]));
}
function escapeJs(str) { return String(str).replace(/'/g, "\\'"); }

async function loadMaterias() {
    const r = await api('list_materias');
    if (r.status !== 'success') return alert(r.message || 'Erro');
    state.materias = r.materias || [];
    renderMaterias();
}

async function criarMateria() {
    const input = document.getElementById('materiaInput');
    const nome = input.value.trim();
    if (!nome) return;
    const r = await api('create_materia', { nome });
    if (r.status !== 'success') return alert(r.message || 'Erro');
    input.value = '';
    await loadMaterias();
}

async function editarMateria(id, nomeAtual) {
    const novo = prompt('Novo nome da matéria:', nomeAtual);
    if (!novo || !novo.trim()) return;
    const r = await api('update_materia', { id, nome: novo.trim() });
    if (r.status !== 'success') return alert(r.message || 'Erro');
    await loadMaterias();
}

async function excluirMateria(id) {
    if (!confirm('Excluir matéria e sub-matérias?')) return;
    const r = await api('delete_materia', { id });
    if (r.status !== 'success') return alert(r.message || 'Erro');
    await loadMaterias();
}

function openMateria(id) {
    window.location.href = '/materia?materia_id=' + id;
}

document.getElementById('btnInserir').addEventListener('click', criarMateria);
loadMaterias();
</script>
</body>
</html>
