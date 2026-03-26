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
    <title>Sub-matérias</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
    <main class="max-w-4xl mx-auto px-4 py-8 space-y-6">
        <div class="flex flex-wrap items-center gap-3 justify-between">
            <div>
                <a href="/topicos" class="text-blue-400 text-sm">&larr; Voltar para matérias</a>
                <h1 id="tituloMateria" class="text-3xl font-bold mt-2">Matéria</h1>
            </div>
            <button id="btnReorder" class="items-center gap-2 bg-slate-800 hover:bg-slate-700 border border-slate-600 text-slate-200 text-sm py-2 px-3 rounded transition-all inline-flex">
                Alterar ordem
            </button>
        </div>

        <section class="bg-slate-900 border border-slate-700 rounded-xl p-4 flex gap-2">
            <input id="subInput" class="flex-1 bg-slate-800 border border-slate-600 rounded-lg px-3 py-2" placeholder="Nome da sub-matéria (manual)">
            <button id="btnCriarSub" class="bg-blue-600 hover:bg-blue-500 px-4 py-2 rounded-lg font-semibold">Criar sub-matérias</button>
        </section>

        <section class="bg-slate-900 border border-slate-700 rounded-xl p-4">
            <ul id="listaSub" class="space-y-2"></ul>
        </section>
    </main>

<script>
const materiaId = Number(new URLSearchParams(window.location.search).get('materia_id') || 0);
if (!materiaId) window.location.href = '/topicos';

const state = { materia: null, subtopicos: [], reorderMode: false, sortable: null };

const api = async (action, data = {}) => {
    const res = await fetch('/api/topicos', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...data })
    });
    return res.json();
};

function escapeHtml(str) {
    return String(str).replace(/[&<>'"]/g, s => ({ '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;' }[s]));
}

function render() {
    const h = document.getElementById('tituloMateria');
    h.textContent = state.materia ? state.materia.nome : 'Matéria';

    const ul = document.getElementById('listaSub');
    if (!state.subtopicos.length) {
        ul.innerHTML = '<li class="text-slate-400">Ainda não há sub-matérias.</li>';
        return;
    }

    ul.innerHTML = state.subtopicos.map((s, i) => `
        <li data-id="${s.id}" class="bg-slate-800 border ${state.reorderMode ? 'border-green-500 cursor-move' : 'border-slate-700'} rounded-lg p-3 flex items-center gap-2">
            <div class="w-8 text-slate-400 text-sm">${i + 1}</div>
            <button onclick="editarSub(${s.id}, '${String(s.titulo).replace(/'/g, "\\'")}')" class="flex-1 text-left">${escapeHtml(s.titulo)}</button>
            <button onclick="gerarFilhos(${s.id})" class="text-xs bg-indigo-600 hover:bg-indigo-500 px-2 py-1 rounded">Gerar sub-matérias</button>
            <button onclick="excluirSub(${s.id})" class="text-xs bg-rose-600 hover:bg-rose-500 px-2 py-1 rounded">Excluir</button>
        </li>
    `).join('');

    if (state.sortable) state.sortable.destroy();
    if (state.reorderMode) {
        state.sortable = new Sortable(ul, {
            animation: 150,
            onEnd: salvarOrdem
        });
    }
}

async function loadData() {
    const r = await api('list_subtopicos', { materia_id: materiaId });
    if (r.status !== 'success') return alert(r.message || 'Erro');
    state.materia = r.materia;
    state.subtopicos = r.subtopicos || [];
    render();
}

async function salvarOrdem() {
    const ids = [...document.querySelectorAll('#listaSub li[data-id]')].map(li => Number(li.dataset.id));
    const r = await api('reorder_subtopicos', { materia_id: materiaId, order: ids });
    if (r.status !== 'success') return alert(r.message || 'Erro ao salvar ordem');
    await loadData();
}

async function gerarRaiz() {
    const manual = document.getElementById('subInput').value.trim();
    if (manual) {
        const r1 = await api('generate_subtopicos', { materia_id: materiaId, manual_seed: manual });
        if (r1.status !== 'success') return alert(r1.message || 'Erro');
        document.getElementById('subInput').value = '';
        return loadData();
    }
    const r = await api('generate_subtopicos', { materia_id: materiaId });
    if (r.status !== 'success') return alert(r.message || 'Erro');
    await loadData();
}

async function gerarFilhos(id) {
    const r = await api('generate_subtopicos', { materia_id: materiaId, parent_subtopico_id: id });
    if (r.status !== 'success') return alert(r.message || 'Erro');
    await loadData();
}

async function editarSub(id, tituloAtual) {
    const novo = prompt('Editar sub-matéria:', tituloAtual);
    if (!novo || !novo.trim()) return;
    const r = await api('update_subtopico', { id, titulo: novo.trim() });
    if (r.status !== 'success') return alert(r.message || 'Erro');
    await loadData();
}

async function excluirSub(id) {
    if (!confirm('Excluir esta sub-matéria?')) return;
    const r = await api('delete_subtopico', { id, materia_id: materiaId });
    if (r.status !== 'success') return alert(r.message || 'Erro');
    await loadData();
}

function toggleReorder() {
    state.reorderMode = !state.reorderMode;
    const btn = document.getElementById('btnReorder');
    btn.classList.toggle('bg-green-700', state.reorderMode);
    btn.textContent = state.reorderMode ? 'Reordenando...' : 'Alterar ordem';
    render();
}

document.getElementById('btnCriarSub').addEventListener('click', gerarRaiz);
document.getElementById('btnReorder').addEventListener('click', toggleReorder);
loadData();
</script>
</body>
</html>
