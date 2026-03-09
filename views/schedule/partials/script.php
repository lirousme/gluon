    <script>
        const scheduleApp = {
            agendaId: null,
            items: [],
            currentDateObj: null,
            
            isDragging: false,
            isResizing: false,
            wasDragged: false,
            dragElement: null,
            startY: 0,
            startTop: 0,
            startHeight: 0,

            sortableInstances: [],

            state: {
                view: 'timeline',
                sidebarOpen: false,
                directoryCache: new Map(),
                copied_directory_id: null,
                pendingStartDate: null,
                pendingEndDate: null,
                availableIcons: [
                    'fa-folder', 'fa-folder-open', 'fa-check-circle', 'fa-file-code', 'fa-calendar-days', 'fa-door-open', 'fa-clock', 'fa-file-lines', 'fa-file-image', 'fa-file-pdf', 'fa-star', 'fa-heart', 'fa-image', 'fa-music', 'fa-video', 
                    'fa-code', 'fa-terminal', 'fa-database', 'fa-server', 'fa-lock', 'fa-vault', 'fa-book', 'fa-briefcase', 'fa-globe', 'fa-list-check'
                ]
            },

            customScroll: {
                active: false,
                isDragging: false,
                dragRAF: null,
                syncRAF: null,
                
                init() {
                    if(window.innerWidth < 768) return; 
                    
                    this.track = document.getElementById('gluon-custom-scrollbar');
                    this.thumb = document.getElementById('gluon-scroll-thumb');
                    this.wrapper = document.getElementById('kanban-wrapper');
                    
                    if(!this.track || !this.thumb || !this.wrapper) return;

                    this.active = true;
                    this.track.classList.remove('hidden', 'md:hidden');
                    this.track.classList.add('md:flex');
                    this.wrapper.classList.add('no-scrollbar');

                    this.update = this.update.bind(this);
                    this.sync = this.sync.bind(this);
                    this.startDrag = this.startDrag.bind(this);
                    this.onDrag = this.onDrag.bind(this);
                    this.stopDrag = this.stopDrag.bind(this);
                    this.trackClick = this.trackClick.bind(this);

                    this.wrapper.addEventListener('scroll', this.sync, { passive: true });
                    window.addEventListener('resize', this.update, { passive: true });
                    
                    // Adicionado suporte a toque e mouse para fluidez em qualquer dispositivo
                    this.thumb.addEventListener('mousedown', this.startDrag);
                    this.thumb.addEventListener('touchstart', this.startDrag, { passive: false });
                    
                    this.track.addEventListener('mousedown', this.trackClick);
                    
                    if(window.ResizeObserver) {
                        this.resizeObserver = new ResizeObserver(this.update);
                        this.resizeObserver.observe(this.wrapper);
                    }

                    setTimeout(this.update, 100);
                },

                destroy() {
                    this.active = false;
                    if(this.dragRAF) cancelAnimationFrame(this.dragRAF);
                    if(this.syncRAF) cancelAnimationFrame(this.syncRAF);
                    
                    if(this.track) {
                        this.track.classList.add('hidden', 'md:hidden');
                        this.track.classList.remove('md:flex');
                    }
                    if(this.wrapper) {
                        this.wrapper.removeEventListener('scroll', this.sync);
                        this.wrapper.classList.remove('no-scrollbar');
                    }
                    if(this.resizeObserver && this.wrapper) this.resizeObserver.unobserve(this.wrapper);
                    window.removeEventListener('resize', this.update);
                    
                    if(this.thumb) {
                        this.thumb.removeEventListener('mousedown', this.startDrag);
                        this.thumb.removeEventListener('touchstart', this.startDrag);
                    }
                    if(this.track) this.track.removeEventListener('mousedown', this.trackClick);
                },

                update() {
                    if(!this.active || !this.wrapper) return;
                    const { scrollWidth, clientWidth } = this.wrapper;
                    
                    if (scrollWidth <= clientWidth + 2) {
                        this.track.style.display = 'none';
                        return;
                    }
                    this.track.style.display = '';
                    
                    const trackW = this.track.clientWidth;
                    const ratio = clientWidth / scrollWidth;
                    
                    // A largura real proporcional calculada corretamente em cima do trackW
                    const thumbW = Math.max(60, trackW * ratio); 
                    this.thumb.style.width = `${thumbW}px`;
                    
                    this.sync();
                },

                sync() {
                    // Removido o bloqueio isDragging daqui para sincronia em tempo real
                    if(!this.active || !this.wrapper) return;
                    if(this.syncRAF) cancelAnimationFrame(this.syncRAF);
                    
                    this.syncRAF = requestAnimationFrame(() => {
                        const { scrollWidth, clientWidth, scrollLeft } = this.wrapper;
                        const trackW = this.track.clientWidth;
                        const thumbW = parseFloat(this.thumb.style.width);
                        
                        const maxScroll = scrollWidth - clientWidth;
                        if(maxScroll <= 0) return;

                        const maxThumbLeft = trackW - thumbW;
                        const scrollRatio = scrollLeft / maxScroll;
                        
                        this.thumb.style.transform = `translate3d(${scrollRatio * maxThumbLeft}px, 0, 0)`;
                    });
                },

                startDrag(e) {
                    if (e.type === 'mousedown' && e.button !== 0) return;
                    e.preventDefault(); e.stopPropagation();
                    this.isDragging = true;
                    
                    this.startX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX;
                    this.startScroll = this.wrapper.scrollLeft;
                    document.body.style.userSelect = 'none';
                    this.wrapper.style.scrollBehavior = 'auto'; // Impede o "smooth" nativo lutar contra o dedo
                    
                    document.addEventListener('mousemove', this.onDrag, { passive: false });
                    document.addEventListener('mouseup', this.stopDrag);
                    document.addEventListener('touchmove', this.onDrag, { passive: false });
                    document.addEventListener('touchend', this.stopDrag);
                },

                onDrag(e) {
                    if(!this.isDragging) return;
                    e.preventDefault();
                    this.currentX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX; 
                    
                    if(this.dragRAF) return; 
                    this.dragRAF = requestAnimationFrame(() => {
                        const dx = this.currentX - this.startX;
                        const trackW = this.track.clientWidth;
                        const thumbW = parseFloat(this.thumb.style.width);
                        const maxThumbLeft = trackW - thumbW;
                        const maxScroll = this.wrapper.scrollWidth - this.wrapper.clientWidth;
                        
                        if (maxThumbLeft > 0) {
                            const scrollDx = (dx / maxThumbLeft) * maxScroll;
                            // Modificando SOMENTE o scroll da view, o navegador dispara `scroll`
                            // chamando nosso `sync()` imediatamente, removendo o engasgo (jitter).
                            this.wrapper.scrollLeft = this.startScroll + scrollDx;
                        }
                        
                        this.dragRAF = null; 
                    });
                },

                stopDrag() {
                    this.isDragging = false;
                    document.body.style.userSelect = '';
                    this.wrapper.style.scrollBehavior = '';
                    
                    document.removeEventListener('mousemove', this.onDrag);
                    document.removeEventListener('mouseup', this.stopDrag);
                    document.removeEventListener('touchmove', this.onDrag);
                    document.removeEventListener('touchend', this.stopDrag);
                },

                trackClick(e) {
                    if(e.target === this.thumb || this.isDragging) return;
                    const trackRect = this.track.getBoundingClientRect();
                    const clickX = e.clientX - trackRect.left;
                    const thumbW = parseFloat(this.thumb.style.width);
                    const trackW = this.track.clientWidth;
                    const maxThumbLeft = trackW - thumbW;
                    const maxScroll = this.wrapper.scrollWidth - this.wrapper.clientWidth;
                    
                    let newThumbLeft = clickX - (thumbW / 2);
                    newThumbLeft = Math.max(0, Math.min(maxThumbLeft, newThumbLeft));
                    
                    const newScroll = (newThumbLeft / maxThumbLeft) * maxScroll;
                    this.wrapper.scrollTo({ left: newScroll, behavior: 'smooth' });
                }
            },

            getMarkedBackRoute() {
                const urlParams = new URLSearchParams(window.location.search);
                const from = urlParams.get('from');
                return from ? decodeURIComponent(from) : '/dashboard';
            },

            buildViewUrl(view, id) {
                const from = encodeURIComponent(`${window.location.pathname}${window.location.search}`);
                return `/${view}?id=${id}&from=${from}`;
            },

            navigateToItemView(type, id) {
                const viewByType = { 1: 'editor', 2: 'schedule', 4: 'flashcards', 5: 'adjacency' };
                const targetView = viewByType[type];
                if (!targetView) return false;
                window.location.href = this.buildViewUrl(targetView, id);
                return true;
            },

            goBack() {
                if (window.history.length > 1) {
                    window.history.back();
                    return;
                }
                window.location.href = this.getMarkedBackRoute();
            },

            async init() {
                this.currentDateObj = new Date(); 
                const urlParams = new URLSearchParams(window.location.search);
                this.agendaId = urlParams.get('id');

                if (!this.agendaId) {
                    window.location.href = this.getMarkedBackRoute();
                    return;
                }
                
                this.renderIconPicker();
                this.setupFormListeners();
                
                const savedSidebar = localStorage.getItem('gluon_agenda_sidebar');
                if (savedSidebar === 'false') {
                    this.state.sidebarOpen = false;
                    const sidebar = document.getElementById('backlogSidebar');
                    if (sidebar) {
                        sidebar.classList.add('w-0', 'border-r-0', 'opacity-0');
                        sidebar.classList.remove('w-64', 'sm:w-80', 'border-r');
                    }
                    const btnNav = document.getElementById('btnToggleSidebarNav');
                    if(btnNav) {
                        btnNav.classList.remove('text-slate-400');
                        btnNav.classList.add('text-gluon-primary', 'bg-slate-800');
                    }
                }

                await this.fetchUserPrefs();
                await this.fetchAgendaInfo();
                await this.loadData();
                
                document.addEventListener('click', (event) => {
                    const menu = document.getElementById('mobileViewMenu');
                    const trigger = document.getElementById('btn-mobile-menu-trigger');
                    if (menu && !menu.classList.contains('hidden') && !menu.contains(event.target) && !trigger.contains(event.target)) {
                        menu.classList.add('hidden');
                    }
                });
            },

            toggleSidebar() {
                this.state.sidebarOpen = !this.state.sidebarOpen;
                const sidebar = document.getElementById('backlogSidebar');
                const btnNav = document.getElementById('btnToggleSidebarNav');
                
                if (this.state.sidebarOpen) {
                    sidebar.classList.remove('w-0', 'border-r-0', 'opacity-0');
                    sidebar.classList.add('w-64', 'sm:w-80', 'border-r');
                    if(btnNav) {
                        btnNav.classList.add('text-slate-400');
                        btnNav.classList.remove('text-gluon-primary', 'bg-slate-800');
                    }
                } else {
                    sidebar.classList.add('w-0', 'border-r-0', 'opacity-0');
                    sidebar.classList.remove('w-64', 'sm:w-80', 'border-r');
                    if(btnNav) {
                        btnNav.classList.remove('text-slate-400');
                        btnNav.classList.add('text-gluon-primary', 'bg-slate-800');
                    }
                }
                localStorage.setItem('gluon_agenda_sidebar', this.state.sidebarOpen);
            },

            toggleMobileViewMenu() { 
                document.getElementById('mobileViewMenu').classList.toggle('hidden'); 
            },

            updateTopButtons() {
                const btnCopy = document.getElementById('btn-copy-dir');
                const btnPaste = document.getElementById('btn-paste-dir');
                const btnMove = document.getElementById('btn-move-dir');
                const btnPortal = document.getElementById('btn-create-portal');
                
                if (btnPaste && btnMove && btnPortal) {
                    if (this.state.copied_directory_id !== null && this.state.copied_directory_id !== undefined) {
                        btnPaste.classList.remove('hidden'); btnPaste.classList.add('flex');
                        btnMove.classList.remove('hidden'); btnMove.classList.add('flex');
                        btnPortal.classList.remove('hidden'); btnPortal.classList.add('flex');
                    } else {
                        btnPaste.classList.add('hidden'); btnPaste.classList.remove('flex');
                        btnMove.classList.add('hidden'); btnMove.classList.remove('flex');
                        btnPortal.classList.add('hidden'); btnPortal.classList.remove('flex');
                    }
                }
            },

            async copyCurrentDirectory() {
                const response = await this.api('user', 'copy_directory', { dir_id: this.agendaId });
                if (response && response.status === 'success') {
                    this.state.copied_directory_id = this.agendaId; 
                    this.updateTopButtons(); 
                    this.showToast(response.message, 'success');
                } else {
                    this.showToast(response ? response.message : 'Erro ao copiar', 'error');
                }
            },

            async pasteDirectory() {
                const response = await this.api('directories', 'paste', { target_parent_id: this.agendaId });
                if (response && response.status === 'success') {
                    this.showToast(response.message, 'success');
                    await this.loadData(); 
                } else {
                    this.showToast(response ? response.message : 'Erro ao colar', 'error');
                }
            },

            async moveDirectory() {
                const response = await this.api('directories', 'move', { target_parent_id: this.agendaId });
                if (response && response.status === 'success') {
                    this.state.copied_directory_id = null; 
                    this.updateTopButtons(); 
                    this.showToast(response.message, 'success');
                    await this.loadData(); 
                } else {
                    this.showToast(response ? response.message : 'Erro ao mover', 'error');
                }
            },

            async createPortal() {
                const response = await this.api('directories', 'create_portal', { target_parent_id: this.agendaId });
                if (response && response.status === 'success') {
                    this.state.copied_directory_id = null;
                    this.updateTopButtons();
                    this.showToast(response.message, 'success');
                    await this.loadData();
                } else {
                    this.showToast(response ? response.message : 'Erro ao criar portal', 'error');
                }
            },

            getLocalYYYYMMDD(dateObj) {
                const y = dateObj.getFullYear();
                const m = String(dateObj.getMonth() + 1).padStart(2, '0');
                const d = String(dateObj.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            },

            escapeHTML(str) {
                if (!str) return '';
                return str.replace(/[&<>'"]/g, tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag]));
            },

            getTextGradientStyle(from, to) { 
                return `background: -webkit-linear-gradient(135deg, ${from || '#3b82f6'}, ${to || '#6366f1'}); -webkit-background-clip: text; -webkit-text-fill-color: transparent;`; 
            },

            showToast(message, type = 'success') {
                let toast = document.getElementById('gluon-toast');
                if (!toast) { toast = document.createElement('div'); toast.id = 'gluon-toast'; document.body.appendChild(toast); }
                const icon = type === 'success' ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-circle-exclamation"></i>';
                const bgClass = type === 'success' ? 'bg-green-600' : 'bg-red-600';
                toast.className = `fixed bottom-6 right-6 px-5 py-3 rounded-lg shadow-xl text-white text-sm font-medium flex items-center gap-3 z-50 transition-all duration-300 transform translate-y-10 opacity-0 ${bgClass}`;
                toast.innerHTML = `${icon} <span>${message}</span>`;
                setTimeout(() => { toast.classList.remove('translate-y-10', 'opacity-0'); toast.classList.add('translate-y-0', 'opacity-100'); }, 10);
                setTimeout(() => { toast.classList.remove('translate-y-0', 'opacity-100'); toast.classList.add('translate-y-10', 'opacity-0'); }, 3000);
            },

            async api(endpoint, action, payload = {}) {
                payload.action = action;
                try {
                    const res = await fetch(`/api/${endpoint}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
                    if (res.status === 401) window.location.href = '/'; 
                    const data = await res.json();
                    if (data.status !== 'success') throw new Error(data.message);
                    return data;
                } catch (err) { console.error(err); this.showToast(err.message || 'Erro de conexão.', 'error'); return null; }
            },

            async fetchUserPrefs() {
                const response = await this.api('user', 'get_prefs');
                if (response && response.data) {
                    this.state.copied_directory_id = response.data.copied_directory_id;
                    this.updateTopButtons();
                }
            },

            async fetchAgendaInfo() {
                const res = await this.api('schedule', 'get_agenda_info', { id: this.agendaId });
                if (res && res.data) {
                    const iconEl = document.getElementById('agendaIcon');
                    iconEl.className = `fa-solid ${res.data.icon} text-xl`;
                    iconEl.style = this.getTextGradientStyle(res.data.color_from, res.data.color_to);
                    document.getElementById('agendaName').innerText = res.data.name;
                    
                    let savedView = res.data.view;
                    if (!['timeline', 'kanban', 'list'].includes(savedView)) savedView = 'timeline';
                    this.state.view = savedView;
                    this.updateViewButtons();

                    // Salva a agenda no cache para poder editá-la
                    this.state.directoryCache.set(Number(this.agendaId), res.data);
                }
            },

            async setViewMode(view) {
                this.state.view = view;
                this.updateViewButtons();
                this.render();
                this.api('schedule', 'update_view', { id: this.agendaId, view: view });
            },

            updateViewButtons() {
                ['timeline', 'kanban', 'list'].forEach(v => {
                    const btn = document.getElementById(`btn-view-${v}`);
                    if(btn) {
                        btn.classList.remove('bg-slate-700', 'text-white');
                        btn.classList.add('text-slate-400');
                        if(v === this.state.view) {
                            btn.classList.remove('text-slate-400');
                            btn.classList.add('bg-slate-700', 'text-white');
                        }
                    }
                });
            },

            async loadData() {
                const res = await this.api('directories', 'fetch', { parent_id: this.agendaId });
                if (res) {
                    this.items = res.data;
                    
                    // Salva a agenda temporariamente
                    const agendaData = this.state.directoryCache.get(Number(this.agendaId));
                    this.state.directoryCache.clear();
                    if (agendaData) this.state.directoryCache.set(Number(this.agendaId), agendaData);
                    
                    res.data.forEach(d => this.state.directoryCache.set(Number(d.id), d));
                    this.render();
                }
            },

            isTaskOnDate(item, targetDateStr) {
                const baseDateStr = item.start_date ? item.start_date.split(' ')[0] : null;
                if (!baseDateStr) return false;

                // Verifica se foi excepcionalmente pulado (usando a data Y-m-d)
                if (item.rec_exceptions && item.rec_exceptions !== '[]') {
                    try {
                        const exceptions = typeof item.rec_exceptions === 'string' ? JSON.parse(item.rec_exceptions) : item.rec_exceptions;
                        if (exceptions && exceptions.includes(targetDateStr)) return false;
                    } catch(e) {}
                }

                // Tarefa crua / Data base exata
                if (baseDateStr === targetDateStr) return true;
                
                if (item.is_recurring !== 1) return false;
                if (targetDateStr < baseDateStr) return false; 
                if (item.rec_end && targetDateStr > item.rec_end.split(' ')[0]) return false;

                const baseD = new Date(baseDateStr + 'T00:00:00');
                const targetD = new Date(targetDateStr + 'T00:00:00');
                const diffTime = targetD.getTime() - baseD.getTime();
                const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));
                const interval = parseInt(item.rec_interval) || 1;

                if (item.rec_type === 'hourly') {
                    return true; // Se é de hora em hora, ele se repete TODOS os dias a partir da data base.
                } else if (item.rec_type === 'daily') {
                    return diffDays % interval === 0;
                } else if (item.rec_type === 'weekly') {
                    return diffDays % (interval * 7) === 0;
                } else if (item.rec_type === 'monthly') {
                    const mDiff = (targetD.getFullYear() - baseD.getFullYear()) * 12 + (targetD.getMonth() - baseD.getMonth());
                    return mDiff % interval === 0 && targetD.getDate() === baseD.getDate();
                } else if (item.rec_type === 'yearly') {
                    const yDiff = targetD.getFullYear() - baseD.getFullYear();
                    return yDiff % interval === 0 && targetD.getMonth() === baseD.getMonth() && targetD.getDate() === baseD.getDate();
                } else if (item.rec_type === 'custom') {
                    try {
                        const dates = JSON.parse(item.rec_custom || '[]');
                        return dates.includes(targetDateStr);
                    } catch(e) { return false; }
                }
                return false;
            },

            // Retorna ARRAY com todas as projeções de horários de uma tarefa num dia
            getTaskInstancesOnDate(item, targetDateStr) {
                if (!this.isTaskOnDate(item, targetDateStr)) return [];

                const instances = [];
                const baseDateStr = item.start_date.split(' ')[0];
                const isBaseDate = baseDateStr === targetDateStr;
                
                const origStart = new Date(item.start_date.replace(' ', 'T'));
                const origEnd = new Date(item.end_date.replace(' ', 'T'));
                const durationMs = origEnd.getTime() - origStart.getTime();

                // Extrair exceções para filtrar blocos de horários específicos (em Hourly)
                let exceptions = [];
                if (item.rec_exceptions && item.rec_exceptions !== '[]') {
                    try { exceptions = typeof item.rec_exceptions === 'string' ? JSON.parse(item.rec_exceptions) : item.rec_exceptions; } catch(e) {}
                }

                // Tratar a repetição "De hora em hora" (Hourly) para gerar os múltiplos blocos no dia
                if (item.is_recurring === 1 && item.rec_type === 'hourly') {
                    
                    let tStart = item.rec_time_start ? item.rec_time_start.substring(0, 5) : origStart.toTimeString().substring(0, 5);
                    let tEnd = item.rec_time_end ? item.rec_time_end.substring(0, 5) : '23:59';
                    const interval = parseInt(item.rec_interval) || 1;
                    
                    const [sHour, sMin] = tStart.split(':').map(Number);
                    const [eHour, eMin] = tEnd.split(':').map(Number);
                    
                    let currentMins = sHour * 60 + sMin;
                    const endMinsLimit = eHour * 60 + eMin;

                    while (currentMins <= endMinsLimit) {
                        const curH = Math.floor(currentMins / 60);
                        const curM = currentMins % 60;
                        
                        const instDateTimeStr = `${targetDateStr} ${curH.toString().padStart(2,'0')}:${curM.toString().padStart(2,'0')}:00`;
                        
                        // Ignora se este bloco específico de data+hora estiver nas exceções
                        if (!exceptions.includes(targetDateStr) && !exceptions.includes(instDateTimeStr)) {
                            const instStart = new Date(`${targetDateStr}T${curH.toString().padStart(2,'0')}:${curM.toString().padStart(2,'0')}:00`);
                            const instEnd = new Date(instStart.getTime() + durationMs);

                            // Descobre se esta hora/minuto em específico é o bloco PAI verdadeiro ou uma projeção virtual
                            const isProj = !(isBaseDate && curH === origStart.getHours() && curM === origStart.getMinutes());
                            
                            instances.push({ start: instStart, end: instEnd, isProjection: isProj, uid: `${item.id}-${curH}-${curM}` });
                        }
                        
                        currentMins += interval * 60; // Avança o relógio
                    }
                } else {
                    // Tarefas normais (apenas UMA instância por dia, na mesma hora)
                    const instTimeStr = origStart.toTimeString().substring(0,8);
                    const instDateTimeStr = `${targetDateStr} ${instTimeStr}`;
                    
                    if (!exceptions.includes(targetDateStr) && !exceptions.includes(instDateTimeStr)) {
                        const instStart = new Date(`${targetDateStr}T${instTimeStr}`);
                        const instEnd = new Date(instStart.getTime() + durationMs);
                        instances.push({ start: instStart, end: instEnd, isProjection: !isBaseDate, uid: item.id });
                    }
                }
                
                return instances;
            },

            handleTimelineClick(e) {
                if (e.target.closest('.event-card') || this.wasDragged || this.isDragging || this.isResizing) return;

                const containerRect = document.getElementById('timelineScroll').getBoundingClientRect();
                const clickY = e.clientY - containerRect.top + document.getElementById('timelineScroll').scrollTop;
                
                const snappedY = Math.floor(clickY / 30) * 30; 
                const hours = Math.floor(snappedY / 60);
                const minutes = snappedY % 60;

                const y = this.currentDateObj.getFullYear();
                const m = String(this.currentDateObj.getMonth() + 1).padStart(2, '0');
                const d = String(this.currentDateObj.getDate()).padStart(2, '0');
                const baseDate = `${y}-${m}-${d}`;

                const startObj = new Date(`${baseDate}T${hours.toString().padStart(2,'0')}:${minutes.toString().padStart(2,'0')}:00`);
                const endObj = new Date(startObj.getTime() + 60*60000); 

                this.state.pendingStartDate = this.toMySQLFormat(startObj);
                this.state.pendingEndDate = this.toMySQLFormat(endObj);

                if (this.state.copied_directory_id) {
                    const menu = document.getElementById('timelineContextMenu');
                    
                    let posX = e.pageX;
                    let posY = e.pageY;
                    menu.classList.remove('hidden');
                    if (posX + menu.offsetWidth > window.innerWidth) posX = window.innerWidth - menu.offsetWidth - 10;
                    if (posY + menu.offsetHeight > window.innerHeight) posY = window.innerHeight - menu.offsetHeight - 10;
                    
                    menu.style.left = `${posX}px`;
                    menu.style.top = `${posY}px`;

                    const closeMenu = (evt) => {
                        if (!menu.contains(evt.target)) {
                            menu.classList.add('hidden');
                            document.removeEventListener('click', closeMenu);
                        }
                    };
                    setTimeout(() => document.addEventListener('click', closeMenu), 10);
                } else {
                    this.openModal(null, '', this.state.pendingStartDate, this.state.pendingEndDate);
                }
            },

            triggerModalFromMenu() {
                document.getElementById('timelineContextMenu').classList.add('hidden');
                this.openModal(null, '', this.state.pendingStartDate, this.state.pendingEndDate);
            },

            async triggerPortalFromMenu() {
                document.getElementById('timelineContextMenu').classList.add('hidden');
                
                const response = await this.api('directories', 'create_portal', {
                    target_parent_id: this.agendaId,
                    start_date: this.state.pendingStartDate,
                    end_date: this.state.pendingEndDate
                });
                
                if (response && response.status === 'success') {
                    this.state.copied_directory_id = null;
                    this.updateTopButtons();
                    this.showToast('Portal criado e agendado com sucesso!', 'success');
                    await this.loadData();
                } else {
                    this.showToast(response ? response.message : 'Erro ao criar portal agendado', 'error');
                }
            },

            renderDateControls() {
                const container = document.getElementById('dateControlContainer');
                const dateObj = this.currentDateObj;
                
                const months = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
                
                const monthOptions = months.map((m, i) => 
                    `<option class="bg-slate-800" value="${i}" ${dateObj.getMonth() === i ? 'selected' : ''}>${m}</option>`
                ).join('');
                
                const currentYear = new Date().getFullYear();
                let yearOptions = '';
                for(let i = currentYear - 5; i <= currentYear + 5; i++) {
                    yearOptions += `<option class="bg-slate-800" value="${i}" ${dateObj.getFullYear() === i ? 'selected' : ''}>${i}</option>`;
                }

                container.innerHTML = `
                    <div class="flex items-center bg-slate-900 border border-slate-600 rounded p-1 shadow-inner h-[34px]">
                        <button onclick="scheduleApp.navigateDays(-1)" class="px-2 py-1 text-slate-400 hover:text-white hover:bg-slate-700 rounded transition-colors" title="Anterior"><i class="fa-solid fa-chevron-left"></i></button>
                        
                        <div class="flex items-center px-3 border-x border-slate-700 mx-1">
                            <span class="text-sm font-bold text-gluon-primary mr-2 min-w-[20px] text-center">${String(dateObj.getDate()).padStart(2, '0')}</span>
                            
                            <div class="relative flex items-center">
                                <select onchange="scheduleApp.handleMonthChange(event)" class="bg-transparent text-sm text-white font-medium focus:outline-none cursor-pointer appearance-none outline-none pr-4">
                                    ${monthOptions}
                                </select>
                                <i class="fa-solid fa-angle-down text-[10px] text-slate-500 absolute right-0 pointer-events-none"></i>
                            </div>
                            
                            <div class="relative flex items-center ml-2">
                                <select onchange="scheduleApp.handleYearChange(event)" class="bg-transparent text-sm text-slate-400 font-medium focus:outline-none cursor-pointer appearance-none outline-none pr-4">
                                    ${yearOptions}
                                </select>
                                <i class="fa-solid fa-angle-down text-[10px] text-slate-600 absolute right-0 pointer-events-none"></i>
                            </div>
                        </div>

                        <button onclick="scheduleApp.navigateDays(1)" class="px-2 py-1 text-slate-400 hover:text-white hover:bg-slate-700 rounded transition-colors" title="Próximo"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    <span id="dateRangeLabel" class="text-xs text-slate-400 font-medium ml-2 hidden sm:block"></span>
                `;
            },

            navigateDays(direction) {
                const delta = this.state.view === 'timeline' ? direction : direction * 7;
                this.currentDateObj.setDate(this.currentDateObj.getDate() + delta);
                this.render();
            },

            handleMonthChange(e) {
                this.currentDateObj.setMonth(parseInt(e.target.value));
                this.render();
            },

            handleYearChange(e) {
                this.currentDateObj.setFullYear(parseInt(e.target.value));
                this.render();
            },

            getDatesArray(startDate, days) {
                let dates = [];
                let curr = new Date(startDate);
                for(let i=0; i<days; i++) {
                    dates.push(new Date(curr));
                    curr.setDate(curr.getDate() + 1);
                }
                return dates;
            },

            getTimelineHTML() {
                let labelsHTML = '';
                for(let i=0; i<24; i++) {
                    labelsHTML += `<div class="absolute w-full text-right pr-2 text-xs text-slate-500 font-medium" style="top: ${i*60 - 8}px">${i.toString().padStart(2, '0')}:00</div>`;
                }

                return `
                <div id="timelineScroll" class="flex-1 overflow-y-auto relative no-scrollbar">
                    <div id="timelineContainer" class="relative w-full h-[1440px] timeline-grid" ondragover="scheduleApp.allowDrop(event)" ondrop="scheduleApp.dropOnTimeline(event)" onclick="scheduleApp.handleTimelineClick(event)">
                        <div class="absolute left-0 top-0 bottom-0 w-12 border-r border-slate-700/50 bg-slate-900/50 z-0">${labelsHTML}</div>
                        <div id="eventsLayer" class="absolute inset-0 z-10"></div>
                    </div>
                </div>`;
            },

            getKanbanHTML(startDate) {
                const dates = this.getDatesArray(startDate, 7);
                const dayNames = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                
                // Removido snap-x e alterado o padding-bottom para criar espaço na barra inferior
                let html = `<div id="kanban-wrapper" class="flex-1 flex gap-4 sm:gap-5 overflow-x-auto p-4 relative h-full items-start pb-20">`;
                dates.forEach(d => {
                    const dStr = this.getLocalYYYYMMDD(d); 
                    const displayDate = `${dayNames[d.getDay()]}, ${('0'+d.getDate()).slice(-2)}/${('0'+(d.getMonth()+1)).slice(-2)}`;
                    const isToday = dStr === this.getLocalYYYYMMDD(new Date());
                    const headerClass = isToday ? 'text-gluon-primary font-bold' : 'text-slate-300 font-semibold';
                    
                    // Removido snap-start para liberar as paradas e melhorar a fluidez visual
                    html += `
                    <div class="bg-slate-800/80 border border-slate-700 rounded-xl w-[280px] shrink-0 flex flex-col max-h-full shadow-lg relative overflow-hidden transition-transform">
                        <div class="p-3 border-b border-slate-700 text-center ${headerClass} bg-slate-900/50 flex items-center justify-between gap-2">
                            <span class="truncate">${displayDate}</span>
                            <button type="button" onclick="event.stopPropagation(); scheduleApp.openKanbanQuickAdd('${dStr}')" class="shrink-0 w-7 h-7 rounded-lg border border-slate-600 bg-slate-800/80 text-slate-300 hover:text-white hover:border-gluon-primary hover:bg-slate-700 transition-colors" title="Adicionar tarefa/diretório neste dia">
                                <i class="fa-solid fa-plus text-xs"></i>
                            </button>
                        </div>
                        <div class="p-2 flex-1 overflow-y-auto sortable-day-col no-scrollbar min-h-[100px]" data-date="${dStr}">
                            <!-- items -->
                        </div>
                    </div>`;
                });
                html += `</div>`;
                return html;
            },

            openKanbanQuickAdd(dateStr) {
                let hour = 9;
                let minute = 0;
                const todayStr = this.getLocalYYYYMMDD(new Date());

                if (dateStr === todayStr) {
                    const now = new Date();
                    const minutesRounded = Math.ceil(now.getMinutes() / 30) * 30;
                    hour = now.getHours();
                    minute = minutesRounded;

                    if (minute >= 60) {
                        hour += 1;
                        minute = 0;
                    }
                }

                const startObj = new Date(`${dateStr}T${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}:00`);
                const endObj = new Date(startObj.getTime() + 60 * 60000);

                this.state.pendingStartDate = this.toMySQLFormat(startObj);
                this.state.pendingEndDate = this.toMySQLFormat(endObj);
                this.openModal(null, '', this.state.pendingStartDate, this.state.pendingEndDate, dateStr);
            },

            getListHTML(startDate) {
                const dates = this.getDatesArray(startDate, 7);
                const dayNames = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
                
                let html = `<div class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-6 relative h-full no-scrollbar pb-20">`;
                dates.forEach(d => {
                    const dStr = this.getLocalYYYYMMDD(d); 
                    const displayDate = `${dayNames[d.getDay()]}, ${('0'+d.getDate()).slice(-2)} de ${d.toLocaleString('pt-BR', {month:'long'})}`;
                    const isToday = dStr === this.getLocalYYYYMMDD(new Date());
                    const headerClass = isToday ? 'text-gluon-primary' : 'text-slate-300';
                    
                    html += `
                    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl overflow-hidden shadow-sm">
                        <h3 class="font-bold text-lg border-b border-slate-700/50 px-4 py-3 sticky top-0 bg-slate-800 z-10 ${headerClass}">${displayDate}</h3>
                        <div class="sortable-day-col p-3 min-h-[60px] flex flex-col gap-2" data-date="${dStr}">
                            <!-- items -->
                        </div>
                    </div>`;
                });
                html += `</div>`;
                return html;
            },

            render() {
                if (this.state.view !== 'kanban') this.customScroll.destroy();

                this.renderDateControls();
                
                const viewContainer = document.getElementById('viewContainer');
                const selectedDate = this.currentDateObj;
                const dStr = this.getLocalYYYYMMDD(selectedDate); 
                const y = selectedDate.getFullYear();
                const m = String(selectedDate.getMonth() + 1).padStart(2, '0');
                const d = String(selectedDate.getDate()).padStart(2, '0');
                const label = document.getElementById('dateRangeLabel');

                if (this.state.view === 'timeline') {
                    if (label) label.innerText = 'Exibição: 1 Dia';
                } else {
                    const endDate = new Date(selectedDate);
                    endDate.setDate(endDate.getDate() + 6);
                    const endY = endDate.getFullYear();
                    const endM = String(endDate.getMonth() + 1).padStart(2, '0');
                    const endD = String(endDate.getDate()).padStart(2, '0');
                    if (label) label.innerText = `Exibição: ${d}/${m}/${y} até ${endD}/${endM}/${endY}`;
                }

                const unscheduledContainer = document.getElementById('unscheduledList');
                unscheduledContainer.innerHTML = '';
                
                let backlogItems = this.items.filter(item => !item.start_date || !item.end_date);
                let scheduledItems = this.items.filter(item => item.start_date && item.end_date);

                scheduledItems.sort((a, b) => {
                    const timeA = a.start_date ? a.start_date.split(' ')[1] : '00:00:00';
                    const timeB = b.start_date ? b.start_date.split(' ')[1] : '00:00:00';
                    if (timeA === timeB) {
                        return (a.name || '').localeCompare(b.name || '');
                    }
                    return timeA.localeCompare(timeB);
                });

                backlogItems.forEach(item => {
                    unscheduledContainer.innerHTML += this.generateBacklogCard(item);
                });

                this.sortableInstances.forEach(s => s.destroy());
                this.sortableInstances = [];

                if (this.state.view === 'timeline') {
                    viewContainer.innerHTML = this.getTimelineHTML();
                    this.renderTimelineItems(scheduledItems, dStr);
                    this.setupTimelineMouseEvents();
                    
                    setTimeout(() => {
                        const scrollEl = document.getElementById('timelineScroll');
                        if(scrollEl && scrollEl.scrollTop === 0) scrollEl.scrollTop = 8 * 60 - 20;
                    }, 50);

                } else if (this.state.view === 'kanban') {
                    viewContainer.innerHTML = this.getKanbanHTML(selectedDate);
                    this.renderColumnsItems(scheduledItems, selectedDate, 'kanban');
                    setTimeout(() => this.customScroll.init(), 0);
                } else if (this.state.view === 'list') {
                    viewContainer.innerHTML = this.getListHTML(selectedDate);
                    this.renderColumnsItems(scheduledItems, selectedDate, 'list');
                }

                if (this.state.view === 'kanban' || this.state.view === 'list') {
                    this.initSortable();
                } else {
                    document.querySelectorAll('#unscheduledList .backlog-item').forEach(el => {
                        el.setAttribute('draggable', 'true');
                        el.ondragstart = (e) => this.dragStart(e, el.getAttribute('data-id'));
                    });
                }
            },

            generateBacklogCard(item) {
                const repeatIcon = item.is_recurring === 1 ? `<i class="fa-solid fa-repeat text-[10px] ml-1 text-gluon-primary" title="Tarefa Recorrente"></i>` : '';
                return `
                <div data-id="${item.id}" class="backlog-item bg-slate-800 border border-slate-700 p-3 rounded-lg shadow-sm cursor-grab hover:bg-slate-700 transition-colors flex justify-between items-center group relative overflow-hidden mb-3" onclick="scheduleApp.handleEventClick(event, ${item.id})">
                    ${item.cover_url ? `<div class="absolute inset-0 bg-cover bg-center opacity-10" style="background-image: url('${item.cover_url}')"></div>` : ''}
                    <div class="flex items-center gap-2 truncate pointer-events-none z-10 flex-1 pr-2">
                        <i class="fa-solid fa-grip-vertical text-slate-500 handle hidden sortable-handle"></i>
                        <i class="fa-solid ${item.icon} text-sm" style="${this.getTextGradientStyle(item.color_from, item.color_to)}"></i>
                        <span class="font-medium text-sm text-slate-200 truncate w-full">${this.escapeHTML(item.name)} ${repeatIcon}</span>
                    </div>
                    <button onclick="event.stopPropagation(); scheduleApp.openModal(${item.id})" class="text-slate-400 hover:text-white sm:opacity-0 group-hover:opacity-100 transition-opacity p-1 z-20"><i class="fa-solid fa-cog"></i></button>
                </div>`;
            },

            renderTimelineItems(scheduledItems, selectedDateStr) {
                const eventsLayer = document.getElementById('eventsLayer');
                eventsLayer.innerHTML = '';
                
                let allInstances = [];
                scheduledItems.forEach(item => {
                    const instances = this.getTaskInstancesOnDate(item, selectedDateStr);
                    instances.forEach(inst => allInstances.push({ item, inst }));
                });

                // Ordenar globalmente pelo tempo na Linha do Tempo 
                allInstances.sort((a, b) => a.inst.start.getTime() - b.inst.start.getTime());

                allInstances.forEach(entry => {
                    const item = entry.item;
                    const inst = entry.inst;
                    
                    const startMins = inst.start.getHours() * 60 + inst.start.getMinutes();
                    const endMins = inst.end.getHours() * 60 + inst.end.getMinutes();
                    const duration = endMins - startMins;
                    
                    const fromColor = item.color_from || '#3b82f6';
                    const toColor = item.color_to || '#6366f1';
                    
                    const borderStyle = inst.isProjection ? 'border-style: dashed; border-width: 2px;' : `border-left: 4px solid ${fromColor};`;
                    const bgStyle = `background: linear-gradient(135deg, ${fromColor}33, ${toColor}33); border-color: ${fromColor}80; color: #fff; ${borderStyle}`;
                    
                    const repeatIcon = item.is_recurring === 1 ? `<i class="fa-solid fa-repeat text-[10px] ml-1 opacity-80" title="Tarefa Recorrente"></i>` : '';
                    const projClass = inst.isProjection ? 'virtual-task opacity-80' : '';
                    const resizeHTML = inst.isProjection ? '' : `<div class="resize-handle" onmousedown="scheduleApp.startResize(event, ${item.id})"></div>`;

                    const instTimeStr = inst.start.getHours().toString().padStart(2,'0') + ':' + inst.start.getMinutes().toString().padStart(2,'0') + ':00';
                    const contextDateStr = `${selectedDateStr} ${instTimeStr}`;

                    eventsLayer.innerHTML += `
                        <div id="evt-${inst.uid}" class="event-card group ${projClass}" data-id="${item.id}" style="top: ${startMins}px; height: ${Math.max(duration, 15)}px; ${bgStyle}" onclick="scheduleApp.handleEventClick(event, ${item.id})">
                            ${item.cover_url ? `<div class="absolute inset-0 bg-cover bg-center opacity-20 pointer-events-none" style="background-image: url('${item.cover_url}')"></div>` : ''}
                            <div class="flex justify-between items-start pointer-events-none z-10 relative">
                                <div class="font-bold truncate text-sm flex items-center gap-1.5 w-full pr-6">
                                    <i class="fa-solid ${item.icon} text-xs"></i> <span class="truncate">${this.escapeHTML(item.name)} ${repeatIcon}</span>
                                </div>
                            </div>
                            <div class="text-[10px] opacity-70 pointer-events-none z-10 relative font-medium">${inst.start.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})} - ${inst.end.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                            
                            <button onclick="event.stopPropagation(); scheduleApp.openModal(${item.id}, '', null, null, '${contextDateStr}')" class="absolute top-1 right-1 text-white/70 hover:text-white sm:opacity-0 group-hover:opacity-100 transition-opacity p-1.5 z-20 bg-black/40 rounded shadow-md"><i class="fa-solid fa-cog text-xs"></i></button>

                            ${resizeHTML}
                        </div>
                    `;
                });

                document.querySelectorAll('.event-card').forEach(el => {
                    el.addEventListener('mousedown', (e) => {
                        if(el.classList.contains('virtual-task')) return; 
                        if(e.target.classList.contains('resize-handle') || e.target.closest('button')) return;
                        this.startDragTimeline(e, el);
                    });
                });
            },

            renderColumnsItems(scheduledItems, startDate, viewType) {
                const dates = this.getDatesArray(startDate, 7).map(d => this.getLocalYYYYMMDD(d));
                
                dates.forEach(dateStr => {
                    const col = document.querySelector(`.sortable-day-col[data-date="${dateStr}"]`);
                    if (col) {
                        let dailyInstances = [];
                        
                        // Captura todas as ocorrências de todas as tarefas para aquele dia em específico
                        scheduledItems.forEach(item => {
                            const instances = this.getTaskInstancesOnDate(item, dateStr);
                            instances.forEach(inst => dailyInstances.push({ item, inst }));
                        });

                        // Ordenar globalmente pelo horário de início de cada instância no dia
                        dailyInstances.sort((a, b) => {
                            const timeA = a.inst.start.getTime();
                            const timeB = b.inst.start.getTime();
                            if (timeA === timeB) {
                                return (a.item.name || '').localeCompare(b.item.name || '');
                            }
                            return timeA - timeB;
                        });

                        let html = '';
                        dailyInstances.forEach(entry => {
                            html += this.generateItemCard(entry.item, viewType, entry.inst, dateStr);
                        });
                        
                        col.innerHTML += html;
                    }
                });
            },

            generateItemCard(item, viewType, inst, dateStr = '') {
                const fromColor = item.color_from || '#3b82f6';
                const timeStr = `${inst.start.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})} - ${inst.end.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}`;
                const repeatIcon = item.is_recurring === 1 ? `<i class="fa-solid fa-repeat text-[10px] ml-1 text-gluon-primary" title="Tarefa Recorrente"></i>` : '';

                const projectionClass = `${inst.isProjection ? 'virtual-task' : ''} cursor-grab`;
                const borderStyle = `border-left: 4px solid ${fromColor};`;
                const handleHTML = `<i class="fa-solid fa-grip-vertical text-slate-400 handle hidden sortable-handle text-xs"></i>`;

                const instTimeStr = inst.start.getHours().toString().padStart(2,'0') + ':' + inst.start.getMinutes().toString().padStart(2,'0') + ':00';
                const contextDateStr = `${dateStr} ${instTimeStr}`;

                if (viewType === 'kanban') {
                    return `
                    <div data-id="${item.id}" data-context-start="${this.toMySQLFormat(inst.start)}" data-context-end="${this.toMySQLFormat(inst.end)}" class="kanban-item bg-slate-700/80 border border-slate-600 p-2.5 rounded-lg shadow-sm hover:bg-slate-600 transition-colors flex flex-col group relative overflow-hidden mb-2 ${projectionClass}" style="${borderStyle}" onclick="scheduleApp.handleEventClick(event, ${item.id})">
                        ${item.cover_url ? `<div class="absolute inset-0 bg-cover bg-center opacity-20 pointer-events-none" style="background-image: url('${item.cover_url}')"></div>` : ''}
                        <div class="flex justify-between items-start z-10 relative">
                            <div class="flex items-center gap-1.5 truncate w-full pr-5">
                                ${handleHTML}
                                <i class="fa-solid ${item.icon} text-xs" style="${this.getTextGradientStyle(item.color_from, item.color_to)}"></i>
                                <span class="font-bold text-sm text-slate-100 truncate">${this.escapeHTML(item.name)} ${repeatIcon}</span>
                            </div>
                        </div>
                        <div class="text-[10px] text-slate-400 mt-1 z-10 relative font-medium"><i class="fa-regular fa-clock"></i> ${timeStr}</div>
                        
                        <button onclick="event.stopPropagation(); scheduleApp.openModal(${item.id}, '', null, null, '${contextDateStr}')" class="absolute top-1.5 right-1.5 text-slate-400 hover:text-white sm:opacity-0 group-hover:opacity-100 transition-opacity p-1 z-20 bg-slate-800/80 rounded border border-slate-600"><i class="fa-solid fa-cog text-xs"></i></button>
                    </div>`;
                } else {
                    return `
                    <div data-id="${item.id}" data-context-start="${this.toMySQLFormat(inst.start)}" data-context-end="${this.toMySQLFormat(inst.end)}" class="list-item bg-slate-700/50 hover:bg-slate-700 border border-slate-600/50 p-3 rounded-lg shadow-sm transition-colors flex items-center justify-between group relative overflow-hidden ${projectionClass}" style="${borderStyle}" onclick="scheduleApp.handleEventClick(event, ${item.id})">
                        ${item.cover_url ? `<div class="absolute inset-0 bg-cover bg-center opacity-10 pointer-events-none" style="background-image: url('${item.cover_url}')"></div>` : ''}
                        <div class="flex items-center gap-3 z-10 relative overflow-hidden flex-1 pr-2">
                            ${handleHTML}
                            <div class="flex flex-col min-w-[80px] text-center border-r border-slate-600 pr-3 mr-1">
                                <span class="text-xs text-slate-300 font-semibold tracking-wide">${timeStr.split(' - ')[0]}</span>
                                <span class="text-[10px] text-slate-500">${timeStr.split(' - ')[1]}</span>
                            </div>
                            <i class="fa-solid ${item.icon} text-lg shrink-0" style="${this.getTextGradientStyle(item.color_from, item.color_to)}"></i>
                            <span class="font-semibold text-sm text-slate-200 truncate flex-1">${this.escapeHTML(item.name)} ${repeatIcon}</span>
                        </div>
                        <button onclick="event.stopPropagation(); scheduleApp.openModal(${item.id}, '', null, null, '${contextDateStr}')" class="text-slate-400 hover:text-white sm:opacity-0 group-hover:opacity-100 transition-opacity p-2 z-20 shrink-0 bg-slate-800 rounded shadow"><i class="fa-solid fa-cog"></i></button>
                    </div>`;
                }
            },

            handleEventClick(e, id) {
                if (this.wasDragged) {
                    this.wasDragged = false;
                    return;
                }
                this.enterDirectoryOrFile(id);
            },

            async enterDirectoryOrFile(id) {
                const item = this.state.directoryCache.get(Number(id));
                if(!item) return;

                if (this.navigateToItemView(item.type, id)) {
                    return;
                } else if (item.type === 3) {
                    if (!item.target_id) return this.showToast('Portal corrompido: Destino não encontrado.', 'error');
                    
                    const pathRes = await this.api('directories', 'get_path', { id: item.target_id });
                    
                    if(pathRes && pathRes.status === 'success' && pathRes.data.length > 0) {
                        const targetDir = pathRes.data[pathRes.data.length - 1];
                        
                        if (this.navigateToItemView(targetDir.type, targetDir.id)) {
                            return;
                        }

                        window.location.href = `/dashboard?id=${id}&target_id=${item.target_id}&portal=1`;
                    } else {
                        this.showToast('Erro ao abrir portal. O destino pode não existir mais.', 'error');
                    }
                } else {
                    window.location.href = `/dashboard?id=${id}`;
                }
            },

            initSortable() {
                const commonOptions = {
                    group: 'schedule',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    dragClass: 'sortable-drag',
                    delay: 100, delayOnTouchOnly: true,
                    onEnd: (evt) => this.handleSortableEnd(evt)
                };

                const unscheduled = document.getElementById('unscheduledList');
                if (unscheduled) {
                    this.sortableInstances.push(new Sortable(unscheduled, { ...commonOptions, sort: false }));
                }

                document.querySelectorAll('.sortable-day-col').forEach(col => {
                    this.sortableInstances.push(new Sortable(col, { ...commonOptions }));
                });
            },

            async handleSortableEnd(evt) {
                const itemEl = evt.item;
                const itemId = itemEl.getAttribute('data-id');
                const toEl = evt.to;
                const fromEl = evt.from;

                const oldPosition = Number.isInteger(evt.oldDraggableIndex) ? evt.oldDraggableIndex : evt.oldIndex;
                const newPosition = Number.isInteger(evt.newDraggableIndex) ? evt.newDraggableIndex : evt.newIndex;
                const sameColumnWithoutPositionChange = toEl === fromEl && oldPosition === newPosition;
                if (sameColumnWithoutPositionChange) {
                    return;
                }

                const isDayColumn = (el) => el && el.classList && el.classList.contains('sortable-day-col');
                const affectedColumns = [];
                if (isDayColumn(toEl)) affectedColumns.push(toEl);
                if (isDayColumn(fromEl) && fromEl !== toEl) affectedColumns.push(fromEl);

                if (toEl.id === 'unscheduledList') {
                    await this.updateItemDates(itemId, null, null);
                    if (isDayColumn(fromEl)) {
                        await this.recalculateColumnTimes(fromEl, fromEl.getAttribute('data-date'), false);
                        await this.loadData();
                    }
                } else {
                    for (let idx = 0; idx < affectedColumns.length; idx++) {
                        const col = affectedColumns[idx];
                        const dateStr = col.getAttribute('data-date');
                        await this.recalculateColumnTimes(col, dateStr, false);
                    }
                    await this.loadData();
                }
            },

            async recalculateColumnTimes(columnEl, targetDateStr, shouldReload = true) {
                const orderedItems = Array.from(columnEl.querySelectorAll('[data-id]'));

                if (orderedItems.length === 0) return;

                const updates = [];

                for (let idx = 0; idx < orderedItems.length; idx++) {
                    const el = orderedItems[idx];
                    const id = Number(el.getAttribute('data-id'));
                    const item = this.state.directoryCache.get(id);
                    if (!item) continue;

                    const durationMinutes = this.getItemDurationMinutes(item, targetDateStr, el);
                    let startDate;
                    const previousUpdate = updates.length > 0 ? updates[updates.length - 1] : null;

                    if (previousUpdate) {
                        startDate = new Date(previousUpdate.endDate.getTime());
                    } else if (orderedItems.length > 1) {
                        const nextId = Number(orderedItems[1].getAttribute('data-id'));
                        const nextItem = this.state.directoryCache.get(nextId);
                        const nextRange = this.getItemRangeOnDate(nextItem, targetDateStr, orderedItems[1]);
                        if (nextRange?.start) {
                            startDate = new Date(nextRange.start.getTime() - (durationMinutes * 60000));
                        }
                    }

                    if (!startDate) {
                        const currentRange = this.getItemRangeOnDate(item, targetDateStr, el);
                        startDate = currentRange?.start ? new Date(currentRange.start.getTime()) : new Date(`${targetDateStr}T08:00:00`);
                    }

                    const endDate = new Date(startDate.getTime() + (durationMinutes * 60000));
                    updates.push({
                        id,
                        startDate,
                        endDate,
                        contextStart: el.getAttribute('data-context-start') || null,
                        contextEnd: el.getAttribute('data-context-end') || null
                    });
                }

                for (const upd of updates) {
                    await this.updateItemDates(upd.id, upd.startDate, upd.endDate, false, {
                        contextStart: upd.contextStart,
                        contextEnd: upd.contextEnd
                    });
                }

                if (shouldReload) await this.loadData();
            },

            getItemRangeOnDate(item, dateStr, itemEl = null) {
                if (!item) return null;
                if (itemEl) {
                    const contextStart = itemEl.getAttribute('data-context-start');
                    const contextEnd = itemEl.getAttribute('data-context-end');
                    if (contextStart && contextEnd) {
                        const contextStartDate = new Date(contextStart.replace(' ', 'T'));
                        const contextEndDate = new Date(contextEnd.replace(' ', 'T'));
                        if (!Number.isNaN(contextStartDate.getTime()) && !Number.isNaN(contextEndDate.getTime())) {
                            return { start: contextStartDate, end: contextEndDate };
                        }
                    }
                }
                const instances = this.getTaskInstancesOnDate(item, dateStr);
                const sameDayInstance = instances.find(inst => this.getLocalYYYYMMDD(inst.start) === dateStr);
                const baseInstance = instances.find(inst => !inst.isProjection);
                const pickedInstance = sameDayInstance || baseInstance || instances[0];
                if (!pickedInstance?.start || !pickedInstance?.end) return null;
                return {
                    start: new Date(pickedInstance.start),
                    end: new Date(pickedInstance.end)
                };
            },

            getItemDurationMinutes(item, dateStr, itemEl = null) {
                const itemRange = this.getItemRangeOnDate(item, dateStr, itemEl);
                if (itemRange?.start && itemRange?.end) {
                    return Math.max(1, Math.round((itemRange.end.getTime() - itemRange.start.getTime()) / 60000));
                }

                if (item?.start_date && item?.end_date) {
                    const rawStart = new Date(item.start_date.replace(' ', 'T'));
                    const rawEnd = new Date(item.end_date.replace(' ', 'T'));
                    if (!Number.isNaN(rawStart.getTime()) && !Number.isNaN(rawEnd.getTime())) {
                        return Math.max(1, Math.round((rawEnd.getTime() - rawStart.getTime()) / 60000));
                    }
                }

                return 60;
            },

            startDragTimeline(e, el) {
                if (e.button !== 0) return;
                this.isDragging = true;
                this.wasDragged = false;
                this.dragElement = el;
                this.startY = e.clientY;
                this.startTop = parseInt(el.style.top || 0);
                el.classList.add('dragging');
                e.preventDefault();
            },

            startResize(e, id) {
                e.preventDefault(); e.stopPropagation();
                this.isResizing = true;
                this.wasDragged = false;
                
                // Usar a seleção genérica que pega o PARENTE real
                this.dragElement = e.target.closest('.event-card');
                
                this.startY = e.clientY;
                this.startHeight = parseInt(this.dragElement.style.height || 60);
                this.dragElement.classList.add('resizing');
            },

            setupTimelineMouseEvents() {
                if (this._onMouseMove) document.removeEventListener('mousemove', this._onMouseMove);
                if (this._onMouseUp) document.removeEventListener('mouseup', this._onMouseUp);

                this._onMouseMove = (e) => {
                    if (this.isDragging && this.dragElement) {
                        this.wasDragged = true;
                        const deltaY = e.clientY - this.startY;
                        let newTop = this.startTop + deltaY;
                        newTop = Math.floor(newTop / 15) * 15;
                        if (newTop < 0) newTop = 0;
                        this.dragElement.style.top = newTop + 'px';
                        this.updateLabelRealtime(this.dragElement, newTop, parseInt(this.dragElement.style.height));
                    }
                    if (this.isResizing && this.dragElement) {
                        this.wasDragged = true;
                        const deltaY = e.clientY - this.startY;
                        let newHeight = this.startHeight + deltaY;
                        newHeight = Math.max(15, Math.floor(newHeight / 15) * 15);
                        this.dragElement.style.height = newHeight + 'px';
                        this.updateLabelRealtime(this.dragElement, parseInt(this.dragElement.style.top), newHeight);
                    }
                };

                this._onMouseUp = async () => {
                    if (this.isDragging && this.dragElement) {
                        this.dragElement.classList.remove('dragging');
                        this.isDragging = false;
                        if(this.wasDragged) await this.commitTimelineChanges(this.dragElement);
                        this.dragElement = null;
                    }
                    if (this.isResizing && this.dragElement) {
                        this.dragElement.classList.remove('resizing');
                        this.isResizing = false;
                        if(this.wasDragged) await this.commitTimelineChanges(this.dragElement);
                        this.dragElement = null;
                    }
                };

                document.addEventListener('mousemove', this._onMouseMove);
                document.addEventListener('mouseup', this._onMouseUp);
            },

            updateLabelRealtime(el, top, height) {
                const sh = Math.floor(top/60); const sm = top%60;
                const end = top + height;
                const eh = Math.floor(end/60); const em = end%60;
                const labelEl = el.querySelector('.text-\\[10px\\]');
                if (labelEl) labelEl.innerText = `${sh.toString().padStart(2,'0')}:${sm.toString().padStart(2,'0')} - ${eh.toString().padStart(2,'0')}:${em.toString().padStart(2,'0')}`;
            },

            async commitTimelineChanges(el) {
                const id = el.getAttribute('data-id');
                const top = parseInt(el.style.top);
                const height = parseInt(el.style.height);

                const y = this.currentDateObj.getFullYear();
                const m = String(this.currentDateObj.getMonth() + 1).padStart(2, '0');
                const d = String(this.currentDateObj.getDate()).padStart(2, '0');
                const baseDate = `${y}-${m}-${d}`;
                
                const sh = Math.floor(top/60); const sm = top%60;
                const startObj = new Date(`${baseDate}T${sh.toString().padStart(2,'0')}:${sm.toString().padStart(2,'0')}:00`);
                
                const end = top + height;
                const eh = Math.floor(end/60); const em = end%60;
                const endObj = new Date(`${baseDate}T${eh.toString().padStart(2,'0')}:${em.toString().padStart(2,'0')}:00`);

                await this.updateItemDates(id, startObj, endObj);
            },

            dragStart(ev, id) { ev.dataTransfer.setData("text/plain", id); },
            allowDrop(ev) { ev.preventDefault(); },
            
            async dropOnTimeline(ev) {
                ev.preventDefault();
                const id = ev.dataTransfer.getData("text/plain");
                if (!id) return;
                const containerRect = document.getElementById('timelineScroll').getBoundingClientRect();
                const dropY = ev.clientY - containerRect.top + document.getElementById('timelineScroll').scrollTop;
                const snappedY = Math.floor(dropY / 30) * 30; 
                const hours = Math.floor(snappedY / 60);
                const minutes = snappedY % 60;

                const y = this.currentDateObj.getFullYear();
                const m = String(this.currentDateObj.getMonth() + 1).padStart(2, '0');
                const d = String(this.currentDateObj.getDate()).padStart(2, '0');
                const baseDate = `${y}-${m}-${d}`;

                const startObj = new Date(`${baseDate}T${hours.toString().padStart(2,'0')}:${minutes.toString().padStart(2,'0')}:00`);
                const endObj = new Date(startObj.getTime() + 60*60000); 

                await this.updateItemDates(id, startObj, endObj);
            },

            toMySQLFormat(dateObj) {
                if (!dateObj) return null;
                if (typeof dateObj === 'string') return dateObj; 
                return dateObj.getFullYear() + '-' +
                    ('0' + (dateObj.getMonth()+1)).slice(-2) + '-' +
                    ('0' + dateObj.getDate()).slice(-2) + ' ' +
                    ('0' + dateObj.getHours()).slice(-2) + ':' +
                    ('0' + dateObj.getMinutes()).slice(-2) + ':00';
            },

            applyTimeToExistingDate(baseDateTime, updatedDateTime) {
                if (!baseDateTime || !updatedDateTime) return null;

                const baseDate = typeof baseDateTime === 'string' ? new Date(baseDateTime.replace(' ', 'T')) : baseDateTime;
                const updated = typeof updatedDateTime === 'string' ? new Date(updatedDateTime.replace(' ', 'T')) : updatedDateTime;

                if (!baseDate || !updated || Number.isNaN(baseDate.getTime()) || Number.isNaN(updated.getTime())) {
                    return null;
                }

                const merged = new Date(baseDate.getTime());
                merged.setHours(updated.getHours(), updated.getMinutes(), 0, 0);
                return this.toMySQLFormat(merged);
            },

            async updateItemDates(id, startVal, endVal, shouldReload = true, options = {}) {
                const item = this.state.directoryCache.get(Number(id));
                let formattedStart = this.toMySQLFormat(startVal);
                let formattedEnd = this.toMySQLFormat(endVal);

                // Em tarefas recorrentes, preservar a data-base e alterar apenas o horário
                // evita quebrar a régua de repetição ao reorganizar em dias projetados.
                if (item?.is_recurring === 1 && formattedStart && formattedEnd && item.start_date && item.end_date) {
                    const mergedStart = this.applyTimeToExistingDate(item.start_date, formattedStart);
                    const mergedEnd = this.applyTimeToExistingDate(item.end_date, formattedEnd);
                    if (mergedStart && mergedEnd) {
                        formattedStart = mergedStart;
                        formattedEnd = mergedEnd;
                    }
                }

                const payload = { 
                    id: id, 
                    start_date: formattedStart,
                    end_date: formattedEnd,
                    context_start: options.contextStart || null,
                    context_end: options.contextEnd || null
                };
                await this.api('schedule', 'update_times', payload);
                if (shouldReload) await this.loadData();
            },

            setupFormListeners() {
                document.getElementById('dirCoverFile').addEventListener('change', (e) => this.handleCoverUpload(e));
                
                const dirNameInput = document.getElementById('dirName');
                if (dirNameInput) {
                    dirNameInput.addEventListener('input', function() {
                        this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'; 
                    });
                    dirNameInput.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.saveDirectory(e); }
                    });
                }
            },

            renderIconPicker() {
                const container = document.getElementById('icon-picker');
                container.innerHTML = this.state.availableIcons.map(icon => `
                    <label class="cursor-pointer">
                        <input type="radio" name="dirIcon" value="${icon}" class="peer hidden icon-radio" ${icon === 'fa-check-circle' ? 'checked' : ''}>
                        <div class="border border-slate-600 bg-slate-800 text-slate-400 rounded-lg p-2 flex items-center justify-center transition-all hover:bg-slate-700 hover:text-white h-10">
                            <i class="fa-solid ${icon} text-lg"></i>
                        </div>
                    </label>
                `).join('');
            },

            autoChangeIcon(iconClass) {
                const radios = document.getElementsByName('dirIcon');
                for(let i=0; i<radios.length; i++) { if(radios[i].value === iconClass) radios[i].checked = true; }
            },

            switchModalTab(tabName) {
                const tabs = ['geral', 'apar', 'recor'];
                tabs.forEach(t => {
                    const tEl = document.getElementById(`tab-${t}`);
                    const bEl = document.getElementById(`tab-btn-${t}`);
                    if (tEl && bEl) {
                        if (t === tabName) {
                            tEl.classList.remove('hidden');
                            bEl.classList.add('border-gluon-primary', 'text-white'); 
                            bEl.classList.remove('border-transparent', 'text-slate-400');
                        } else {
                            tEl.classList.add('hidden');
                            bEl.classList.add('border-transparent', 'text-slate-400'); 
                            bEl.classList.remove('border-gluon-primary', 'text-white');
                        }
                    }
                });
            },

            toggleRecurrenceFields() {
                const isChecked = document.getElementById('is_recurring').checked;
                const fields = document.getElementById('recurrence_fields');
                if (isChecked) fields.classList.remove('hidden');
                else fields.classList.add('hidden');
            },

            handleRecurrenceTypeChange() {
                const type = document.getElementById('rec_type').value;
                const intervalCont = document.getElementById('rec_interval_container');
                const customCont = document.getElementById('rec_custom_container');
                const hourlyCont = document.getElementById('rec_hourly_container');

                if (type === 'custom') {
                    intervalCont.classList.add('hidden');
                    customCont.classList.remove('hidden');
                    if (hourlyCont) hourlyCont.classList.add('hidden');
                } else if (type === 'hourly') {
                    intervalCont.classList.remove('hidden');
                    customCont.classList.add('hidden');
                    if (hourlyCont) hourlyCont.classList.remove('hidden');
                } else {
                    intervalCont.classList.remove('hidden');
                    customCont.classList.add('hidden');
                    if (hourlyCont) hourlyCont.classList.add('hidden');
                }
            },

            handleTypeChange(typeValue) {
                const folderSettings = document.getElementById('folderSettingsGroup');
                const nameLabel = document.getElementById('nameLabel');
                
                if (typeValue === 0) {
                    folderSettings.style.display = 'block';
                    nameLabel.innerText = 'Nome da Tarefa / Pasta';
                    this.autoChangeIcon('fa-check-circle');
                } else if (typeValue === 1) {
                    folderSettings.style.display = 'none';
                    nameLabel.innerText = 'Nome do Arquivo (Ex: script.js)';
                    this.autoChangeIcon('fa-file-code');
                } else if (typeValue === 2) {
                    folderSettings.style.display = 'none';
                    nameLabel.innerText = 'Nome da Agenda / Cronograma';
                    this.autoChangeIcon('fa-calendar-days');
                }
            },

            handleCoverUpload(e) {
                const file = e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = (event) => {
                    const img = new Image();
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        const MAX_WIDTH = 600; const MAX_HEIGHT = 400;
                        let width = img.width; let height = img.height;
                        if (width > height) { if (width > MAX_WIDTH) { height *= MAX_WIDTH / width; width = MAX_WIDTH; } } 
                        else { if (height > MAX_HEIGHT) { width *= MAX_HEIGHT / height; height = MAX_HEIGHT; } }
                        canvas.width = width; canvas.height = height;
                        const ctx = canvas.getContext('2d'); ctx.drawImage(img, 0, 0, width, height);
                        const base64 = canvas.toDataURL('image/jpeg', 0.6);
                        document.getElementById('dirCoverBase64').value = base64;
                        const preview = document.getElementById('coverPreview');
                        preview.style.backgroundImage = `url('${base64}')`;
                        preview.classList.remove('hidden');
                        document.getElementById('btnRemoveCover').classList.remove('hidden');
                    }
                    img.src = event.target.result;
                }
                reader.readAsDataURL(file);
            },

            removeCover() {
                document.getElementById('dirCoverFile').value = ''; document.getElementById('dirCoverBase64').value = '';
                const preview = document.getElementById('coverPreview');
                if (preview) { preview.classList.add('hidden'); preview.style.backgroundImage = 'none'; }
                const btn = document.getElementById('btnRemoveCover'); if (btn) btn.classList.add('hidden');
            },

            openModal(id = '', name = '', startDate = null, endDate = null, contextDate = null) {
                this.switchModalTab('geral');
                document.getElementById('dirId').value = id;
                
                document.getElementById('dirStartDate').value = startDate || '';
                document.getElementById('dirEndDate').value = endDate || '';
                document.getElementById('dirContextDate').value = contextDate || '';
                
                let dirObj = null;
                const dirNameInput = document.getElementById('dirName');
                const typeSelector = document.getElementById('typeSelectorContainer');
                const folderSettings = document.getElementById('folderSettingsGroup');
                const nameLabel = document.getElementById('nameLabel');
                const iconPickerContainer = document.getElementById('iconPickerContainer');
                const btnRecor = document.getElementById('tab-btn-recor');

                if (id) {
                    dirObj = this.state.directoryCache.get(Number(id));
                    
                    if (dirObj) {
                        if (dirObj.start_date) document.getElementById('dirStartDate').value = dirObj.start_date;
                        if (dirObj.end_date) document.getElementById('dirEndDate').value = dirObj.end_date;
                    }
                    
                    let titleText = 'Item';
                    if(dirObj.type === 0) titleText = 'Tarefa / Pasta';
                    if(dirObj.type === 1) titleText = 'Arquivo';
                    if(dirObj.type === 2) titleText = 'Agenda';
                    if(dirObj.type === 3) titleText = 'Portal';

                    document.getElementById('modalTitle').innerHTML = `<i class="fa-solid fa-gear text-gluon-primary"></i> <span>Configurar ${titleText}</span>`;
                    typeSelector.classList.add('hidden');
                    btnRecor.classList.remove('hidden');
                    
                    if(dirObj.type === 0) { folderSettings.style.display = 'block'; nameLabel.innerText = 'Nome da Tarefa/Pasta'; iconPickerContainer.style.display = 'block'; } 
                    else if(dirObj.type === 1) { folderSettings.style.display = 'none'; nameLabel.innerText = 'Nome do Arquivo'; iconPickerContainer.style.display = 'block'; } 
                    else if(dirObj.type === 2) { folderSettings.style.display = 'none'; nameLabel.innerText = 'Nome da Agenda'; iconPickerContainer.style.display = 'block'; }
                    else if(dirObj.type === 3) { folderSettings.style.display = 'none'; nameLabel.innerText = 'Nome do Portal'; iconPickerContainer.style.display = 'none'; btnRecor.classList.add('hidden');}
                } else {
                    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-plus text-gluon-primary"></i> <span>Novo Item</span>';
                    typeSelector.classList.remove('hidden');
                    iconPickerContainer.style.display = 'block';
                    btnRecor.classList.remove('hidden');
                    
                    const typeRadios = document.getElementsByName('itemType');
                    typeRadios[0].checked = true;
                    this.handleTypeChange(0);
                }

                dirNameInput.value = dirObj ? dirObj.name : name;

                this.removeCover();
                if (dirObj && dirObj.cover_url) {
                    document.getElementById('dirCoverBase64').value = dirObj.cover_url;
                    document.getElementById('coverPreview').style.backgroundImage = `url('${dirObj.cover_url}')`;
                    document.getElementById('coverPreview').classList.remove('hidden');
                    document.getElementById('btnRemoveCover').classList.remove('hidden');
                }

                document.getElementById('dirColorFrom').value = (dirObj && dirObj.color_from) ? dirObj.color_from : '#3b82f6';
                document.getElementById('dirColorTo').value = (dirObj && dirObj.color_to) ? dirObj.color_to : '#6366f1';
                
                const iconToSelect = (dirObj && dirObj.icon) ? dirObj.icon : (dirObj?.type === 1 ? 'fa-file-code' : (dirObj?.type === 2 ? 'fa-calendar-days' : (dirObj?.type === 3 ? 'fa-door-open' : 'fa-check-circle'))); 
                this.autoChangeIcon(iconToSelect);

                const posToSelect = (dirObj && dirObj.new_item_position) ? dirObj.new_item_position : 'end';
                const posRadios = document.getElementsByName('dirItemPosition');
                for(let i=0; i<posRadios.length; i++) { posRadios[i].checked = (posRadios[i].value === posToSelect); }

                document.getElementById('is_recurring').checked = (dirObj && dirObj.is_recurring === 1);
                document.getElementById('rec_type').value = (dirObj && dirObj.rec_type) ? dirObj.rec_type : 'daily';
                document.getElementById('rec_interval').value = (dirObj && dirObj.rec_interval) ? dirObj.rec_interval : 1;
                document.getElementById('rec_custom').value = (dirObj && dirObj.rec_custom) ? dirObj.rec_custom : '';
                document.getElementById('rec_end').value = (dirObj && dirObj.rec_end) ? dirObj.rec_end.split(' ')[0] : '';
                
                document.getElementById('rec_time_start').value = (dirObj && dirObj.rec_time_start) ? dirObj.rec_time_start.substring(0, 5) : '08:00';
                document.getElementById('rec_time_end').value = (dirObj && dirObj.rec_time_end) ? dirObj.rec_time_end.substring(0, 5) : '18:00';

                this.toggleRecurrenceFields();
                this.handleRecurrenceTypeChange();

                const btnDelete = document.getElementById('btnDeleteDir');
                if(id) btnDelete.classList.remove('hidden'); else btnDelete.classList.add('hidden');

                const viewToSelect = (dirObj && dirObj.view) ? dirObj.view : 'grid';
                const viewRadios = document.getElementsByName('dirViewMode');
                for(let i=0; i<viewRadios.length; i++) { viewRadios[i].checked = (viewRadios[i].value === viewToSelect); }
                
                const modal = document.getElementById('dirModal');
                const content = document.getElementById('dirModalContent');
                modal.classList.remove('hidden'); modal.classList.add('flex');
                
                setTimeout(() => { 
                    modal.classList.remove('opacity-0'); 
                    content.classList.remove('scale-95'); 
                    
                    dirNameInput.style.height = 'auto';
                    if (dirNameInput.scrollHeight > 0) {
                        dirNameInput.style.height = dirNameInput.scrollHeight + 'px';
                    }

                    if(!id) dirNameInput.focus(); 
                }, 20);
            },

            closeModal() {
                const modal = document.getElementById('dirModal');
                const content = document.getElementById('dirModalContent');
                
                modal.classList.add('opacity-0'); 
                content.classList.add('scale-95');
                
                setTimeout(() => { 
                    modal.classList.add('hidden'); 
                    modal.classList.remove('flex'); 
                    document.getElementById('dirForm').reset(); 
                    document.getElementById('dirStartDate').value = '';
                    document.getElementById('dirEndDate').value = '';
                    document.getElementById('dirContextDate').value = '';
                    
                    const btnSave = document.getElementById('btnSaveDir');
                    if (btnSave) {
                        btnSave.innerHTML = '<i class="fa-solid fa-check"></i> Salvar';
                        btnSave.disabled = false;
                    }
                    
                    const btnDelete = document.getElementById('btnDeleteDir');
                    if (btnDelete) {
                        btnDelete.innerHTML = '<i class="fa-solid fa-trash"></i> <span>Excluir</span>';
                        btnDelete.disabled = false;
                    }

                }, 300);
            },

            async saveDirectory(e) {
                if (e && e.preventDefault) e.preventDefault();
                
                const id = document.getElementById('dirId').value;
                
                if (!document.getElementById('dirName').value.trim()) {
                    return this.showToast("O nome do item é obrigatório.", "error");
                }

                let selectedView = 'grid';
                const viewRadios = document.getElementsByName('dirViewMode');
                for(let i=0; i<viewRadios.length; i++) { if(viewRadios[i].checked) selectedView = viewRadios[i].value; }

                let selectedPos = 'end';
                const posRadios = document.getElementsByName('dirItemPosition');
                for(let i=0; i<posRadios.length; i++) { if(posRadios[i].checked) selectedPos = posRadios[i].value; }

                let selectedType = 0;
                const typeRadios = document.getElementsByName('itemType');
                for(let i=0; i<typeRadios.length; i++) { if(typeRadios[i].checked) selectedType = parseInt(typeRadios[i].value); }

                let selectedIcon = 'fa-check-circle';
                const iconRadios = document.getElementsByName('dirIcon');
                for(let i=0; i<iconRadios.length; i++) { if(iconRadios[i].checked) selectedIcon = iconRadios[i].value; }

                const is_recurring = document.getElementById('is_recurring').checked ? 1 : 0;
                const rec_type = document.getElementById('rec_type').value;
                const rec_interval = parseInt(document.getElementById('rec_interval').value) || 1;
                const rec_custom = document.getElementById('rec_custom').value;
                const rec_end = document.getElementById('rec_end').value || null;
                const rec_time_start = document.getElementById('rec_time_start').value || null;
                const rec_time_end = document.getElementById('rec_time_end').value || null;

                const btn = document.getElementById('btnSaveDir');
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Salvando...';
                btn.disabled = true;

                const payload = {
                    id: id !== '' ? id : null,
                    parent_id: this.agendaId, 
                    type: selectedType,
                    name: document.getElementById('dirName').value.trim(),
                    view: selectedView,
                    new_item_position: selectedPos,
                    cover_url: document.getElementById('dirCoverBase64').value,
                    color_from: document.getElementById('dirColorFrom').value || '#3b82f6',
                    color_to: document.getElementById('dirColorTo').value || '#6366f1',
                    icon: selectedIcon,
                    start_date: document.getElementById('dirStartDate').value,
                    end_date: document.getElementById('dirEndDate').value,
                    is_recurring: is_recurring,
                    rec_type: rec_type,
                    rec_interval: rec_interval,
                    rec_custom: rec_custom,
                    rec_end: rec_end,
                    rec_time_start: rec_time_start,
                    rec_time_end: rec_time_end
                };

                const response = await this.api('directories', id !== '' ? 'update' : 'create', payload);

                if (response) { 
                    this.closeModal(); 
                    
                    if (String(id) === String(this.agendaId)) {
                        await this.fetchAgendaInfo();
                    }
                    
                    await this.loadData(); 
                } else {
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> Salvar'; 
                    btn.disabled = false;
                }
            },

            deleteFromModal() {
                const id = document.getElementById('dirId').value;
                const isRecurring = document.getElementById('is_recurring').checked;
                
                if(!id) return;

                if (isRecurring) {
                    const modal = document.getElementById('deleteRecurrenceModal');
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    setTimeout(() => {
                        modal.classList.remove('opacity-0');
                        modal.children[0].classList.remove('scale-95');
                    }, 10);
                } else {
                    const name = document.getElementById('dirName').value;
                    this.openDeleteConfirmModal(name);
                }
            },

            openDeleteConfirmModal(name) {
                const message = document.getElementById('deleteConfirmMessage');
                if (message) {
                    message.textContent = `Atenção: "${name}" será apagado permanentemente. Deseja continuar?`;
                }

                const modal = document.getElementById('deleteConfirmModal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                setTimeout(() => {
                    modal.classList.remove('opacity-0');
                    modal.children[0].classList.remove('scale-95');
                }, 10);
            },

            closeDeleteConfirmModal() {
                const modal = document.getElementById('deleteConfirmModal');
                modal.classList.add('opacity-0');
                modal.children[0].classList.add('scale-95');
                setTimeout(() => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }, 300);
            },

            confirmSimpleDelete() {
                const id = document.getElementById('dirId').value;
                this.closeDeleteConfirmModal();
                this.executeDelete(id, 'all');
            },

            closeDeleteRecurrenceModal() {
                const modal = document.getElementById('deleteRecurrenceModal');
                modal.classList.add('opacity-0');
                modal.children[0].classList.add('scale-95');
                setTimeout(() => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }, 300);
            },

            confirmDelete(scope) {
                const id = document.getElementById('dirId').value;
                this.closeDeleteRecurrenceModal();
                this.executeDelete(id, scope);
            },

            async executeDelete(id, scope) {
                const btn = document.getElementById('btnDeleteDir'); 
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Excluindo...';
                btn.disabled = true; 
                
                const targetDate = document.getElementById('dirContextDate').value;

                const response = await this.api('directories', 'delete', { 
                    id: id, 
                    scope: scope,
                    target_date: targetDate
                });
                
                if(response) { 
                    this.showToast(response.message, 'success');
                    this.closeModal(); 
                    
                    if (String(id) === String(this.agendaId)) {
                        window.location.href = this.getMarkedBackRoute();
                        return;
                    }

                    await this.loadData();
                } else {
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                }
            }
        };

        document.addEventListener('DOMContentLoaded', () => scheduleApp.init());
        
////////////////////////////////////////
//FORÇA A PROIBIÇÃO DE ZOOM PARA IPHONE
// Impede o zoom de pinça (multi-touch)
document.addEventListener('touchstart', function (event) {
  if (event.touches.length > 1) {
    event.preventDefault();
  }
}, { passive: false });

// Impede o zoom de clique duplo (apenas no root do document, não nos botões)
let lastTouchEndGlobal = 0;
document.addEventListener('touchend', function (event) {
  const now = (new Date()).getTime();
  if (now - lastTouchEndGlobal <= 300 && !event.target.closest('.message-wrapper')) {
    event.preventDefault();
  }
  lastTouchEndGlobal = now;
}, false);
////////////////////////////////////////
    </script>
