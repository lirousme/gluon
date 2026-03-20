<!-- 
  Arquivo: head.php
  Diretório: public_html/gluon/views/schedule/partials/head.php
  
  Pilar: Fácil Manutenção, Bonito, Seguro e Rápido.
  Suporta Múltiplas Visualizações: Linha do Tempo, Kanban Semanal, Lista e Recorrências.
  *Atualizado: Otimização Ultra-Fluida (Smooth) da Barra de Rolagem Customizada do Kanban.
-->
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
        <script src="/assets/zoom-lock.js" defer></script>
    <title>Gluon - Agenda</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    <script>
        tailwind.config = {
            theme: { extend: { colors: { gluon: { dark: '#0f172a', primary: '#3b82f6', secondary: '#1e293b' } } } }
        }
    </script>
    <style>
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        .timeline-grid {
            background-image: linear-gradient(to bottom, #334155 1px, transparent 1px), linear-gradient(to bottom, #1e293b 1px, transparent 1px);
            background-size: 100% 60px, 100% 30px;
        }
        .event-card {
            position: absolute;
            left: 50px;
            right: 10px;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 0.75rem;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255,255,255,0.1);
            user-select: none;
            transition: box-shadow 0.2s;
            cursor: pointer;
        }
        .event-card:hover { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5); z-index: 30 !important; }
        .resize-handle {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 8px;
            cursor: ns-resize;
            background: rgba(255,255,255,0.2);
            opacity: 0;
            transition: opacity 0.2s;
        }
        .event-card:hover .resize-handle { opacity: 1; }
        
        .dragging { opacity: 0.7; cursor: grabbing !important; z-index: 50 !important; }
        .resizing { cursor: ns-resize !important; z-index: 50 !important; }
        
        .sortable-ghost { opacity: 0.4; background-color: #1e293b !important; border: 2px dashed #3b82f6 !important; }
        .sortable-drag { opacity: 1 !important; cursor: grabbing !important; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5) !important; transform: scale(1.02); }

        .view-radio:checked + div, .type-radio:checked + div { background-color: #3b82f6; border-color: #3b82f6; color: white; }
        .icon-radio:checked + div { background-color: #1e293b; border-color: #3b82f6; color: #3b82f6; }
        
        input[type="color"] {
            -webkit-appearance: none;
            border: none;
            width: 100%;
            height: 40px;
            border-radius: 0.5rem;
            cursor: pointer;
        }
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: none; border-radius: 0.5rem; }

        .virtual-task {
            border-style: dashed !important;
            border-width: 2px !important;
            opacity: 0.7;
        }
        .virtual-task:hover { opacity: 0.9; }

        .current-time-item {
            position: relative;
            isolation: isolate;
            border-color: transparent !important;
            border-left-color: transparent !important;
        }
        .current-time-item::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 2px;
            background: linear-gradient(120deg, #22d3ee, #8b5cf6, #f43f5e, #22d3ee);
            background-size: 240% 240%;
            animation: current-item-border-flow 3s linear infinite;
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
            z-index: 1;
        }
        @supports not ((-webkit-mask-composite: xor) or (mask-composite: exclude)) {
            .current-time-item::before {
                inset: 0;
                padding: 0;
                border: 2px solid transparent;
                border-radius: inherit;
                background:
                    linear-gradient(120deg, #22d3ee, #8b5cf6, #f43f5e, #22d3ee) border-box;
                background-size: 240% 240%;
                animation: current-item-border-flow 3s linear infinite;
            }
        }
        .current-time-item > * {
            position: relative;
            z-index: 2;
        }
        @keyframes current-item-border-flow {
            0% { background-position: 0% 50%; }
            100% { background-position: 200% 50%; }
        }
        body.embedded-preview {
            padding-bottom: 0 !important;
        }
    </style>
</head>
<body class="bg-gluon-dark text-slate-200 h-[100dvh] flex flex-col font-sans overflow-hidden selection:bg-gluon-primary selection:text-white relative pb-[env(safe-area-inset-bottom)]">
