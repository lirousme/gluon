    <nav class="bg-gluon-secondary border-b border-slate-700/50 shrink-0 z-40 h-16 flex items-center justify-between px-4 sm:px-6">
        <div class="flex items-center gap-4">
            <button onclick="scheduleApp.goBack()" class="text-slate-400 hover:text-white transition-colors bg-slate-800 p-2 rounded-lg border border-slate-700" title="Voltar">
                <i class="fa-solid fa-arrow-left"></i>
            </button>
            <div class="h-6 w-px bg-slate-700 hidden sm:block"></div>
            <div class="flex items-center gap-2">
                <i id="agendaIcon" class="fa-solid fa-calendar-days text-gluon-primary text-xl"></i>
                <span id="agendaName" class="font-bold text-lg text-white tracking-wide">Carregando...</span>
            </div>
        </div>
        
        <div class="flex items-center gap-3">
            
            <div class="relative">
                <button onclick="scheduleApp.toggleMobileViewMenu()" id="btn-mobile-menu-trigger" class="text-slate-400 hover:text-white p-2 rounded-lg hover:bg-slate-800 transition-colors outline-none focus:ring-2 focus:ring-slate-700">
                    <i class="fa-solid fa-ellipsis-vertical px-1"></i>
                </button>
                <div id="mobileViewMenu" class="hidden absolute right-0 top-12 w-56 bg-slate-800 border border-slate-700 rounded-xl shadow-2xl py-2 z-50 origin-top-right transition-all">
                    <div class="px-4 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Ações da Agenda</div>
                    
                    <button onclick="scheduleApp.openModal(scheduleApp.agendaId); scheduleApp.toggleMobileViewMenu()" class="w-full text-left px-4 py-2.5 text-sm text-slate-300 hover:bg-slate-700 hover:text-white flex items-center gap-3 transition-colors">
                        <i class="fa-solid fa-folder-gear w-5 text-center text-lg"></i> <span class="font-medium">Configurações</span>
                    </button>

                    <button onclick="scheduleApp.copyCurrentDirectory(); scheduleApp.toggleMobileViewMenu()" id="btn-copy-dir" class="w-full text-left px-4 py-2.5 text-sm text-slate-300 hover:bg-slate-700 hover:text-white flex items-center gap-3 transition-colors">
                        <i class="fa-regular fa-copy w-5 text-center text-lg"></i> <span class="font-medium">Copiar Agenda</span>
                    </button>
                    <button onclick="scheduleApp.pasteDirectory(); scheduleApp.toggleMobileViewMenu()" id="btn-paste-dir" class="w-full text-left px-4 py-2.5 text-sm text-slate-300 hover:bg-slate-700 hover:text-white flex items-center gap-3 transition-colors hidden">
                        <i class="fa-solid fa-paste w-5 text-center text-lg"></i> <span class="font-medium">Colar Arquivos</span>
                    </button>
                    <button onclick="scheduleApp.moveDirectory(); scheduleApp.toggleMobileViewMenu()" id="btn-move-dir" class="w-full text-left px-4 py-2.5 text-sm text-amber-400 hover:bg-slate-700 hover:text-amber-300 flex items-center gap-3 transition-colors hidden">
                        <i class="fa-solid fa-truck-fast w-5 text-center text-lg"></i> <span class="font-medium">Mover para cá</span>
                    </button>
                    <button onclick="scheduleApp.createPortal(); scheduleApp.toggleMobileViewMenu()" id="btn-create-portal" class="w-full text-left px-4 py-2.5 text-sm text-fuchsia-400 hover:bg-slate-700 hover:text-fuchsia-300 flex items-center gap-3 transition-colors hidden">
                        <i class="fa-solid fa-door-open w-5 text-center text-lg"></i> <span class="font-medium">Criar Portal</span>
                    </button>

                    <button onclick="scheduleApp.deleteOverdueTasksFromMenu()" class="w-full text-left px-4 py-2.5 text-sm text-red-400 hover:bg-slate-700 hover:text-red-300 flex items-center gap-3 transition-colors border-t border-slate-700/50 mt-1">
                        <i class="fa-solid fa-trash-can-clock w-5 text-center text-lg"></i> <span class="font-medium">Apagar tarefas vencidas</span>
                    </button>
                </div>
            </div>

            <button onclick="scheduleApp.openModal()" class="bg-gluon-primary hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg shadow-lg transition-all flex items-center gap-2 text-sm">
                <i class="fa-solid fa-plus"></i> <span class="hidden sm:inline">Novo Item</span>
            </button>
        </div>
    </nav>
