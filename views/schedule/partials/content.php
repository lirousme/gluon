
    <main class="flex-1 w-full flex overflow-hidden">
        
        <aside id="backlogSidebar" class="w-0 border-r-0 opacity-0 bg-slate-900 border-slate-700/50 flex flex-col shrink-0 z-30 shadow-xl relative transition-all duration-300 overflow-hidden">
            <div class="p-4 border-b border-slate-800 bg-slate-800/30 flex justify-between items-start min-w-[250px]">
                <div class="overflow-hidden">
                    <h3 class="font-semibold text-slate-300 flex items-center gap-2 whitespace-nowrap"><i class="fa-solid fa-inbox text-slate-500"></i> Sem Data (Backlog)</h3>
                    <p class="text-xs text-slate-500 mt-1 whitespace-nowrap">Tarefas não agendadas.</p>
                </div>
                <button onclick="scheduleApp.toggleSidebar()" class="text-slate-400 hover:text-white p-1 rounded hover:bg-slate-700 transition-colors shrink-0" title="Recolher Backlog">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>
            </div>
            <div id="unscheduledList" class="flex-1 overflow-y-auto p-4 no-scrollbar min-w-[250px]"></div>
        </aside>

        <div class="flex-1 relative flex flex-col bg-gluon-dark overflow-hidden">
            <div class="p-3 sm:p-4 border-b border-slate-700 bg-slate-800/80 backdrop-blur shrink-0 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 z-20">
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <button onclick="scheduleApp.toggleSidebar()" id="btnToggleSidebarNav" class="px-3 py-1.5 rounded-md text-gluon-primary bg-slate-800 hover:text-white hover:bg-slate-700 transition-colors border border-slate-700 shadow-sm shrink-0" title="Alternar Backlog">
                        <i class="fa-solid fa-inbox"></i>
                    </button>
                    <div id="dateControlContainer" class="flex items-center gap-2 w-full sm:w-auto"></div>
                </div>
                
                <div class="flex items-center gap-2 w-full sm:w-auto justify-center shrink-0">
                    <div class="flex items-center gap-1 bg-slate-900 rounded-lg p-1 border border-slate-700 w-full sm:w-auto justify-center shrink-0">
                        <button onclick="scheduleApp.setViewMode('timeline')" id="btn-view-timeline" class="px-3 py-1.5 rounded-md text-slate-400 hover:text-white transition-colors" title="Linha do Tempo (1 Dia)"><i class="fa-solid fa-clock"></i></button>
                        <button onclick="scheduleApp.setViewMode('kanban')" id="btn-view-kanban" class="px-3 py-1.5 rounded-md text-slate-400 hover:text-white transition-colors" title="Kanban Semanal (7 Dias)"><i class="fa-solid fa-columns"></i></button>
                        <button onclick="scheduleApp.setViewMode('list')" id="btn-view-list" class="px-3 py-1.5 rounded-md text-slate-400 hover:text-white transition-colors" title="Lista por Dia (7 Dias)"><i class="fa-solid fa-list"></i></button>
                    </div>
                    <button onclick="scheduleApp.toggleFilterDrawer()" id="btn-filter-drawer" class="px-3 py-1.5 rounded-md text-slate-300 bg-slate-900 border border-slate-700 hover:text-white hover:bg-slate-800 transition-colors" title="Filtros da agenda">
                        <i class="fa-solid fa-sliders"></i>
                    </button>
                </div>
            </div>

            <div id="viewContainer" class="flex-1 overflow-hidden relative flex flex-col"></div>
        </div>
    </main>

    <div id="filterDrawerBackdrop" onclick="scheduleApp.closeFilterDrawer()" class="fixed inset-0 bg-black/50 z-40 hidden opacity-0 transition-opacity duration-300"></div>
    <aside id="filterDrawer" class="fixed top-0 right-0 h-full w-full max-w-sm bg-slate-900 border-l border-slate-700 shadow-2xl z-50 translate-x-full transition-transform duration-300">
        <div class="h-full flex flex-col">
            <div class="p-4 border-b border-slate-700 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-white"><i class="fa-solid fa-filter mr-2 text-gluon-primary"></i>Filtros</h3>
                <button type="button" onclick="scheduleApp.closeFilterDrawer()" class="text-slate-400 hover:text-white p-2 rounded-lg hover:bg-slate-800"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="p-4 space-y-4">
                <label class="flex items-center justify-between gap-4 rounded-lg border border-slate-700 bg-slate-800/60 p-3 cursor-pointer">
                    <div>
                        <p class="text-sm font-semibold text-slate-200">Diretórios com revisão de flashcards vencida</p>
                        <p class="text-xs text-slate-400 mt-1">Exibe decks no horário da revisão mais antiga pendente.</p>
                    </div>
                    <input id="filter-show-flashcard-due" type="checkbox" onchange="scheduleApp.handleFilterChange()" class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-gluon-primary focus:ring-gluon-primary" checked>
                </label>

                <label class="flex items-center justify-between gap-4 rounded-lg border border-slate-700 bg-slate-800/60 p-3 cursor-pointer">
                    <div>
                        <p class="text-sm font-semibold text-slate-200">Mostrar apenas tarefas vencidas</p>
                        <p class="text-xs text-slate-400 mt-1">Exibe todas as tarefas cuja data final já passou e oculta dias vazios.</p>
                    </div>
                    <input id="filter-show-only-overdue" type="checkbox" onchange="scheduleApp.handleFilterChange()" class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-gluon-primary focus:ring-gluon-primary">
                </label>
            </div>
        </div>
    </aside>

    <!-- BARRA DE ROLAGEM CUSTOMIZADA -->
    <div id="gluon-custom-scrollbar" class="hidden md:hidden fixed bottom-[env(safe-area-inset-bottom)] left-0 right-0 h-[46px] bg-slate-900/80 backdrop-blur-md border-t border-slate-800/80 z-[60] flex items-center px-2">
        <div id="gluon-scroll-thumb" class="h-5 bg-slate-600 hover:bg-slate-500 active:bg-gluon-primary rounded-full cursor-grab active:cursor-grabbing transition-colors will-change-transform shadow-md" style="touch-action: none;"></div>
    </div>

    <!-- Modal Completo de Configurações -->
    <div id="dirModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center z-50 opacity-0 transition-opacity duration-300 px-4">
        <div class="bg-gluon-secondary border border-slate-700 rounded-xl shadow-2xl w-full max-w-md p-0 transform scale-95 transition-transform duration-300 overflow-hidden flex flex-col max-h-[90vh]" id="dirModalContent">
            
            <div class="p-5 border-b border-slate-700 flex justify-between items-center bg-slate-800/50">
                <h3 class="text-xl font-semibold text-white flex items-center gap-2" id="modalTitle">
                    <i class="fa-solid fa-plus text-gluon-primary"></i> <span>Novo Item</span>
                </h3>
                <button type="button" onclick="scheduleApp.closeModal()" class="text-slate-400 hover:text-white text-xl"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="flex border-b border-slate-700 bg-slate-900/30">
                <button type="button" onclick="scheduleApp.switchModalTab('geral')" id="tab-btn-geral" class="flex-1 py-3 text-sm font-medium border-b-2 border-gluon-primary text-white transition-colors">Geral</button>
                <button type="button" onclick="scheduleApp.switchModalTab('apar')" id="tab-btn-apar" class="flex-1 py-3 text-sm font-medium border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition-colors">Aparência</button>
                <button type="button" onclick="scheduleApp.switchModalTab('recor')" id="tab-btn-recor" class="flex-1 py-3 text-sm font-medium border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition-colors">Repetição</button>
            </div>

            <form id="dirForm" onsubmit="scheduleApp.saveDirectory(event)" class="flex flex-col flex-1 overflow-hidden">
                <input type="hidden" id="dirId">
                <input type="hidden" id="dirStartDate">
                <input type="hidden" id="dirEndDate">
                <input type="hidden" id="dirContextDate"> 

                <div id="tab-geral" class="p-6 overflow-y-auto flex-1">
                    <div id="typeSelectorContainer" class="mb-5">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Tipo de Item</label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <label class="cursor-pointer" onclick="scheduleApp.handleTypeChange(0)">
                                <input type="radio" name="itemType" value="0" class="peer hidden type-radio" checked>
                                <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 text-center transition-all hover:bg-slate-700">
                                    <i class="fa-solid fa-check-circle block text-lg mb-1"></i><span class="text-[11px] font-medium leading-tight block">Tarefa / Pasta</span>
                                </div>
                            </label>
                            <label class="cursor-pointer" onclick="scheduleApp.handleTypeChange(1)">
                                <input type="radio" name="itemType" value="1" class="peer hidden type-radio">
                                <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 text-center transition-all hover:bg-slate-700">
                                    <i class="fa-solid fa-file-code block text-lg mb-1"></i><span class="text-[11px] font-medium leading-tight block">Cód. Fonte</span>
                                </div>
                            </label>
                            <label class="cursor-pointer" onclick="scheduleApp.handleTypeChange(2)">
                                <input type="radio" name="itemType" value="2" class="peer hidden type-radio">
                                <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 text-center transition-all hover:bg-slate-700">
                                    <i class="fa-solid fa-calendar-days block text-lg mb-1"></i><span class="text-[11px] font-medium leading-tight block">Agenda</span>
                                </div>
                            </label>

                            <label class="cursor-pointer" onclick="scheduleApp.handleTypeChange(7)">
                                <input type="radio" name="itemType" value="7" class="peer hidden type-radio">
                                <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 text-center transition-all hover:bg-slate-700">
                                    <i class="fa-solid fa-list-ol block text-lg mb-1"></i><span class="text-[11px] font-medium leading-tight block">Plano</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div id="dirNameContainer" class="mb-5">
                        <label class="block text-sm font-medium text-slate-300 mb-1" id="nameLabel">Nome do Item</label>
                        <div class="relative">
                            <i class="fa-solid fa-pen absolute left-3 top-3.5 text-slate-500 text-sm"></i>
                            <textarea id="dirName" rows="1" autocomplete="off" class="w-full bg-slate-800 border border-slate-600 rounded-lg py-2.5 pl-9 pr-3 text-white focus:outline-none focus:border-gluon-primary focus:ring-1 focus:ring-gluon-primary transition-all resize-none overflow-hidden min-h-[44px]"></textarea>
                        </div>
                    </div>

                    <div id="folderSettingsGroup">
                        <div class="mb-5">
                            <label class="block text-sm font-medium text-slate-300 mb-2">Padrão de Visualização Interna</label>
                            <div class="grid grid-cols-3 gap-2 sm:gap-3">
                                <label class="cursor-pointer">
                                    <input type="radio" name="dirViewMode" value="grid" class="peer hidden view-radio" checked>
                                    <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 sm:p-3 text-center transition-all hover:bg-slate-700"><i class="fa-solid fa-border-all block text-xl mb-1"></i><span class="text-xs font-medium">Grid</span></div>
                                </label>
                                <label class="cursor-pointer">
                                    <input type="radio" name="dirViewMode" value="list" class="peer hidden view-radio">
                                    <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 sm:p-3 text-center transition-all hover:bg-slate-700"><i class="fa-solid fa-list-ul block text-xl mb-1"></i><span class="text-xs font-medium">Lista</span></div>
                                </label>
                                <label class="cursor-pointer">
                                    <input type="radio" name="dirViewMode" value="kanban" class="peer hidden view-radio">
                                    <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 sm:p-3 text-center transition-all hover:bg-slate-700"><i class="fa-solid fa-columns block text-xl mb-1"></i><span class="text-xs font-medium">Kanban</span></div>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Adicionar novos itens no:</label>
                            <div class="grid grid-cols-2 gap-2 sm:gap-3">
                                <label class="cursor-pointer">
                                    <input type="radio" name="dirItemPosition" value="start" class="peer hidden view-radio">
                                    <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 sm:p-3 text-center transition-all hover:bg-slate-700">
                                        <i class="fa-solid fa-arrow-up block text-xl mb-1"></i><span class="text-xs font-medium">Início</span>
                                    </div>
                                </label>
                                <label class="cursor-pointer">
                                    <input type="radio" name="dirItemPosition" value="end" class="peer hidden view-radio" checked>
                                    <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 sm:p-3 text-center transition-all hover:bg-slate-700">
                                        <i class="fa-solid fa-arrow-down block text-xl mb-1"></i><span class="text-xs font-medium">Final</span>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-apar" class="hidden p-6 overflow-y-auto flex-1 space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1"><i class="fa-regular fa-image mr-1"></i> Imagem de Capa (Opcional)</label>
                        <input type="file" id="dirCoverFile" accept="image/*" class="w-full text-sm text-slate-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-slate-700 file:text-white hover:file:bg-slate-600 transition-all cursor-pointer bg-slate-800 border border-slate-600 rounded-lg">
                        <input type="hidden" id="dirCoverBase64">
                        <div id="coverPreview" class="mt-3 hidden w-full h-24 rounded-lg bg-cover bg-center border border-slate-600 shadow-inner"></div>
                        <button type="button" id="btnRemoveCover" class="hidden mt-2 text-xs text-red-400 hover:text-red-300 flex items-center gap-1"><i class="fa-solid fa-trash"></i> Remover capa</button>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2"><i class="fa-solid fa-palette mr-1"></i> Cores do Ícone (Gradient)</label>
                        <div class="flex gap-4 items-center">
                            <div class="flex-1">
                                <span class="text-xs text-slate-500 block mb-1">Início</span>
                                <input type="color" id="dirColorFrom" value="#3b82f6" class="bg-slate-800 border border-slate-600">
                            </div>
                            <i class="fa-solid fa-arrow-right text-slate-500 mt-4"></i>
                            <div class="flex-1">
                                <span class="text-xs text-slate-500 block mb-1">Fim</span>
                                <input type="color" id="dirColorTo" value="#6366f1" class="bg-slate-800 border border-slate-600">
                            </div>
                        </div>
                    </div>

                    <div id="iconPickerContainer">
                        <label class="block text-sm font-medium text-slate-300 mb-2"><i class="fa-solid fa-icons mr-1"></i> Selecionar Ícone</label>
                        <div class="grid grid-cols-5 gap-2 max-h-40 overflow-y-auto no-scrollbar p-1" id="icon-picker"></div>
                    </div>
                </div>

                <div id="tab-recor" class="hidden p-6 overflow-y-auto flex-1 space-y-5">
                    <label class="flex items-center gap-2 cursor-pointer mb-2">
                        <input type="checkbox" id="is_recurring" class="rounded border-slate-600 bg-slate-800 text-gluon-primary focus:ring-gluon-primary transition-colors" onchange="scheduleApp.toggleRecurrenceFields()">
                        <span class="text-sm font-medium text-slate-300">Ativar Repetição Automática</span>
                    </label>
                    
                    <div id="recurrence_fields" class="hidden space-y-4 border-t border-slate-700 pt-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Frequência</label>
                            <select id="rec_type" class="w-full bg-slate-800 border border-slate-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-gluon-primary focus:ring-1 focus:ring-gluon-primary transition-colors" onchange="scheduleApp.handleRecurrenceTypeChange()">
                                <option value="hourly">De hora em hora (Diário)</option>
                                <option value="daily">Diariamente</option>
                                <option value="weekly">Semanalmente</option>
                                <option value="monthly">Mensalmente</option>
                                <option value="yearly">Anualmente</option>
                                <option value="custom">Datas Específicas</option>
                            </select>
                        </div>
                        
                        <div id="rec_interval_container">
                            <label class="block text-sm font-medium text-slate-300 mb-1">A cada (intervalo)</label>
                            <input type="number" id="rec_interval" min="1" value="1" class="w-full bg-slate-800 border border-slate-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-gluon-primary focus:ring-1 focus:ring-gluon-primary transition-colors">
                        </div>

                        <div id="rec_hourly_container" class="hidden">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-slate-300 mb-1">Janela: Início</label>
                                    <input type="time" id="rec_time_start" class="w-full bg-slate-800 border border-slate-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-gluon-primary focus:ring-1 focus:ring-gluon-primary transition-colors">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-300 mb-1">Janela: Término</label>
                                    <input type="time" id="rec_time_end" class="w-full bg-slate-800 border border-slate-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-gluon-primary focus:ring-1 focus:ring-gluon-primary transition-colors">
                                </div>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-2"><i class="fa-solid fa-circle-info"></i> A tarefa repetirá todos os dias, saltando a cada X horas, limitada estritamente dentro deste horário.</p>
                        </div>
                        
                        <div id="rec_custom_container" class="hidden">
                            <label class="block text-sm font-medium text-slate-300 mb-1">Datas (formato JSON)</label>
                            <input type="text" id="rec_custom" placeholder='Ex: ["2026-03-01", "2026-04-05"]' class="w-full bg-slate-800 border border-slate-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-gluon-primary focus:ring-1 focus:ring-gluon-primary transition-colors font-mono text-sm">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Parar de repetir em (Opcional)</label>
                            <input type="date" id="rec_end" class="w-full bg-slate-800 border border-slate-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-gluon-primary focus:ring-1 focus:ring-gluon-primary transition-colors">
                        </div>
                    </div>
                </div>

                <div class="p-5 border-t border-slate-700 bg-slate-800/50 flex flex-col-reverse sm:flex-row justify-between items-center gap-3 shrink-0">
                    <div class="w-full sm:w-auto">
                        <button type="button" id="btnDeleteDir" onclick="scheduleApp.deleteFromModal()" class="hidden w-full text-red-400 hover:text-white hover:bg-red-500/20 px-3 py-2 rounded-lg transition-colors flex justify-center items-center gap-2 text-sm">
                            <i class="fa-solid fa-trash"></i> <span>Excluir</span>
                        </button>
                    </div>
                    <div class="flex gap-2 w-full sm:w-auto justify-end">
                        <button type="button" id="btnOpenItemFromModal" onclick="scheduleApp.openItemFromModal()" class="hidden px-4 py-2 rounded-lg text-slate-200 bg-slate-700 hover:bg-slate-600 transition-colors text-sm font-medium flex items-center gap-2">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir item
                        </button>
                        <button type="button" onclick="scheduleApp.closeModal()" class="px-4 py-2 rounded-lg text-slate-300 hover:bg-slate-700 transition-colors text-sm font-medium">Cancelar</button>
                        <button type="submit" id="btnSaveDir" class="px-4 py-2 bg-gluon-primary hover:bg-blue-600 text-white rounded-lg shadow-lg flex items-center gap-2 text-sm font-medium transition-all">
                            <i class="fa-solid fa-check"></i> Salvar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Confirmar Exclusão de Recorrência -->
    <div id="deleteRecurrenceModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden items-center justify-center z-[60] opacity-0 transition-opacity duration-300 px-4">
        <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-2xl w-full max-w-sm p-6 transform scale-95 transition-transform duration-300">
            <h3 class="text-lg font-bold text-white mb-2">Excluir Evento Recorrente</h3>
            <p class="text-sm text-slate-400 mb-5">Este é um evento que se repete. Você deseja excluir/pular apenas esta ocorrência ou apagar todas as futuras definitivamente?</p>
            
            <div class="flex flex-col gap-3">
                <button onclick="scheduleApp.confirmDelete('single')" class="w-full bg-slate-700 hover:bg-slate-600 text-white font-medium py-2.5 rounded-lg transition-colors flex items-center justify-center gap-2">
                    <i class="fa-regular fa-calendar-xmark"></i> Pular/Excluir só neste dia
                </button>
                <button onclick="scheduleApp.confirmDelete('all')" class="w-full bg-red-600 hover:bg-red-500 text-white font-medium py-2.5 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-lg">
                    <i class="fa-solid fa-trash-can"></i> Excluir TODAS as repetições
                </button>
                <button onclick="scheduleApp.closeDeleteRecurrenceModal()" class="w-full text-slate-400 hover:text-white py-2 mt-1 text-sm font-medium transition-colors">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Confirmar Exclusão -->
    <div id="deleteConfirmModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden items-center justify-center z-[60] opacity-0 transition-opacity duration-300 px-4">
        <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-2xl w-full max-w-sm p-6 transform scale-95 transition-transform duration-300">
            <h3 class="text-lg font-bold text-white mb-2">Confirmar Exclusão</h3>
            <p id="deleteConfirmMessage" class="text-sm text-slate-400 mb-5">Tem certeza que deseja excluir este item permanentemente?</p>

            <div class="flex flex-col gap-3">
                <button onclick="scheduleApp.confirmSimpleDelete()" class="w-full bg-red-600 hover:bg-red-500 text-white font-medium py-2.5 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-lg">
                    <i class="fa-solid fa-trash-can"></i> Excluir permanentemente
                </button>
                <button onclick="scheduleApp.closeDeleteConfirmModal()" class="w-full text-slate-400 hover:text-white py-2 text-sm font-medium transition-colors">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    <div id="timelineContextMenu" class="hidden absolute bg-slate-800 border border-slate-700 rounded-xl shadow-2xl py-1.5 z-50 min-w-[170px] transition-opacity">
        <button onclick="scheduleApp.triggerModalFromMenu()" class="w-full text-left px-4 py-2.5 text-sm text-slate-300 hover:bg-slate-700 hover:text-white flex items-center gap-3 transition-colors">
            <i class="fa-solid fa-plus text-gluon-primary text-center w-4"></i> <span class="font-medium">Criar Tarefa</span>
        </button>
        <button onclick="scheduleApp.triggerPortalFromMenu()" class="w-full text-left px-4 py-2.5 text-sm text-fuchsia-400 hover:bg-slate-700 hover:text-fuchsia-300 flex items-center gap-3 transition-colors border-t border-slate-700/50 mt-1">
            <i class="fa-solid fa-door-open text-center w-4"></i> <span class="font-medium">Criar Portal</span>
        </button>
    </div>
