public_html/gluon/
│
├── index.php                 # Front Controller
├── .htaccess                 # Redirecionamentos Apache
│
├── config/
│   ├── database.php          # Conexão PDO e Criptografia AES
│   └── env.php
│
├── api/                      # Back-end API (JSON/POST)
│   ├── auth.php
│   ├── directories.php       # CRUD de Diretórios/Arquivos/Agendas
│   ├── user.php              # Preferências de usuário e resolução do diretório obrigatório de Anotações
│   ├── editor.php            # Gerencia leitura e gravação de códigos
│   └── schedule.php          # NOVO: Micro-API para arrastar/redimensionar eventos
│   └── cron_recurrence.php   # NOVO: Motor autônomo de repetição de tarefas (via CRON)
│   └── flashcards.php        # NOVO: Micro-API para CRUD de Flashcards
│   └── adjacency.php        # NOVO: Micro-API para Lista adjacente
│   └── pronuncias.php       # NOVO: CRUD administrativo para ajustes de pronúncia TTS
│   └── sistema_de_condicionais.php # NOVO: Micro-API para tarefas com dependência condicional
│   └── plano.php            # NOVO: Micro-API para diretórios do tipo Plano
│   └── trilha.php           # NOVO: Micro-API para trilhas dinâmicas de estudo (mapas/fases/GPT)
│   └── topicos.php          # NOVO: CRUD de matérias + sub-matérias com geração GPT e reordenação
│
├── views/                    # Front-end (Vanilla JS + Tailwind)
│   ├── login.html
│   ├── dashboard.html        # ATUALIZADO: Atalho no cabeçalho para o diretório obrigatório de Anotações
│   ├── adm.php               # NOVO: Painel administrativo (restrito ao usuário 1)
│   ├── configuracao-tts.php  # NOVO: CRUD mobile para tabela pronuncias
│   ├── topicos.php           # NOVO: Lista administrativa de matérias
│   ├── materia.php           # NOVO: Tela de sub-matérias com geração GPT e alteração de ordem
│   ├── settings.html
│   ├── editor.html           # Interface do editor de código
│   ├── schedule.html         # NOVO: Interface da Linha do Tempo / Agenda
│   ├── flashcards.html       # NOVO: Interface de estudo de Flashcards
│   ├── gerar_cards_batch.html # NOVO: Interface de geração assíncrona (Batch OpenAI)
│   ├── adjacency.html       # NOVO: Lista adjacente
│   ├── sistema_de_condicionais.html # NOVO: Fluxo de tarefas condicionais
│   ├── plano.html           # NOVO: Planejamento em 5 fases com acordeon
│   ├── map.html             # ATUALIZADO: Mapa jogável dinâmico (fases vindas do banco)
│   ├── phase.html           # NOVO: Tela da fase com slides (jogador + edição do criador)
│   └── errors/
│
└── assets/

======================================================================

TABELA: users
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(50) NOT NULL UNIQUE,
email VARCHAR(100) NOT NULL UNIQUE,
password_hash VARCHAR(255) NOT NULL,
remember_token VARCHAR(255) DEFAULT NULL,
root_view VARCHAR(10) DEFAULT 'grid',
root_new_item_position VARCHAR(10) DEFAULT 'end',
copied_directory_id INT UNSIGNED DEFAULT NULL, -- Guarda o ID do diretório copiado
home_directory_id INT UNSIGNED DEFAULT NULL, -- Guarda o ID da agenda definida como página inicial
source_directory_id INT UNSIGNED DEFAULT NULL, -- Guarda o ID do diretório obrigatório de Anotações do usuário
tts_provider VARCHAR(20) NOT NULL DEFAULT 'fishaudio', -- Provedor padrão de TTS por usuário (fishaudio | openai)
encrypted_data TEXT DEFAULT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

INDEX idx_username (username),
INDEX idx_email (email),
INDEX idx_remember_token (remember_token),
INDEX fk_users_copied_directory (copied_directory_id),
INDEX fk_users_home_directory (home_directory_id),
INDEX fk_users_source_directory (source_directory_id),
FOREIGN KEY (copied_directory_id) REFERENCES directories(id) ON DELETE SET NULL,
FOREIGN KEY (home_directory_id) REFERENCES directories(id) ON DELETE SET NULL,
FOREIGN KEY (source_directory_id) REFERENCES directories(id) ON DELETE SET NULL
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: directories
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
parent_id INT UNSIGNED DEFAULT NULL, -- NULL significa que está na Raiz
target_id INT UNSIGNED DEFAULT NULL, -- NOVO: ID do diretório alvo (Apenas para Portais)
type TINYINT DEFAULT 0,              -- 0 = Pasta, 1 = Arquivo de Código, 2 = Agenda, 3 = Portal, 4 = Deck de Flashcards, 5 = Controle, 6 = Sistema de Condicional, 7 = Plano, 8 = Trilha, 9 = Mapa, 10 = Fase
deck_mode VARCHAR(20) DEFAULT 'aleatorio';
deck_front_language VARCHAR(10) NOT NULL DEFAULT 'pt-BR', -- Idioma da frente do card (pt-BR | en-US | en-GB)
deck_back_language VARCHAR(10) NOT NULL DEFAULT 'en-GB', -- Idioma do verso do card (pt-BR | en-US | en-GB)
deck_structure VARCHAR(20) NOT NULL DEFAULT 'fatos', -- Estrutura da geração (fatos | perguntas | traducoes | parafrases)
name_encrypted TEXT NOT NULL,        -- Nome do diretório criptografado
default_view VARCHAR(10) DEFAULT 'grid',
open_mode VARCHAR(12) NOT NULL DEFAULT 'fullscreen', -- NOVO: Forma de abertura (fullscreen | preview)
new_item_position VARCHAR(10) DEFAULT 'end',
sort_order INT DEFAULT 0,
icon VARCHAR(50) DEFAULT 'fa-folder',      -- Ícone FontAwesome
icon_color_from VARCHAR(7) DEFAULT '#3b82f6', -- Cor inicial do Gradient (Hex)
icon_color_to VARCHAR(7) DEFAULT '#6366f1',   -- Cor final do Gradient (Hex)
cover_url_encrypted TEXT DEFAULT NULL,     -- URL da imagem de capa (Criptografado)
start_date DATETIME DEFAULT NULL,    -- NOVO: Início da tarefa/evento na agenda
end_date DATETIME DEFAULT NULL,      -- NOVO: Fim da tarefa/evento na agenda
is_recurring TINYINT(1) DEFAULT 0,   -- NOVO: Flag para saber se tem regra de recorrência
is_completed TINYINT(1) DEFAULT 0,   -- NOVO: 1 = tarefa concluída (oculta na agenda por padrão)
is_public TINYINT(1) DEFAULT 0,      -- NOVO: 1 = diretório visível em perfil público
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (parent_id) REFERENCES directories(id) ON DELETE CASCADE,
FOREIGN KEY (target_id) REFERENCES directories(id) ON DELETE CASCADE,
INDEX idx_user_parent (user_id, parent_id),
INDEX idx_user_parent_public_sort (user_id, parent_id, is_public, sort_order, id),
INDEX idx_user_type_parent_recurrence (user_id, type, parent_id, is_recurring),
INDEX idx_sort_order (sort_order),
INDEX idx_type (type),
INDEX idx_dates (start_date, end_date),
INDEX idx_is_recurring (is_recurring),
INDEX idx_is_completed (is_completed),
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: directory_recurrences
directory_id INT UNSIGNED PRIMARY KEY,
type VARCHAR(20) NOT NULL COMMENT 'daily, weekly, monthly, yearly, custom',
interval_value INT DEFAULT 1 COMMENT 'Ex: a cada 2 dias/semanas',
days_of_week VARCHAR(50) DEFAULT NULL COMMENT 'Dias específicos da semana (0-6)',
custom_dates JSON DEFAULT NULL COMMENT 'Lista de datas exatas em formato JSON',
exceptions TEXT NULL COMMENT 'Dias que foram excluídos / pulados pelo usuário',
time_start TIME NULL COMMENT 'Horário de início (limite) para a repetição por hora',
time_end TIME NULL COMMENT 'Horário de término (limite) para a repetição por hora';
end_date DATETIME DEFAULT NULL COMMENT 'Data limite para parar a repetição',
next_run_date DATETIME NOT NULL COMMENT 'A próxima vez que a rotina deve clonar a tarefa',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
INDEX idx_next_run (next_run_date)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: files_code
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
directory_id INT UNSIGNED NOT NULL, -- FK referenciando directories
language VARCHAR(20) DEFAULT 'javascript',
content_encrypted LONGTEXT,         -- Código fonte salvo com criptografia
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
INDEX idx_directory (directory_id),
INDEX idx_directory_id_id (directory_id, id)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: flashcards
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
directory_id INT UNSIGNED NOT NULL, -- FK referenciando directories (Deck)
front_encrypted TEXT DEFAULT NULL,   -- Frente do card criptografada (aceita NULL para cards apenas com verso)
back_encrypted TEXT DEFAULT NULL,       -- Verso do card criptografado
image_front_encrypted LONGTEXT DEFAULT NULL,
image_back_encrypted LONGTEXT DEFAULT NULL,
audio_front_encrypted LONGTEXT DEFAULT NULL, -- Áudio (MP3/Base64) da frente criptografado
audio_back_encrypted LONGTEXT DEFAULT NULL,  -- Áudio (MP3/Base64) do verso criptografado
has_audio_front TINYINT(1) DEFAULT 0,  -- NOVO: Flag para o áudio da frente
has_audio_back TINYINT(1) DEFAULT 0,   -- NOVO: Flag para o áudio do verso
sort_order INT DEFAULT 0,           -- Para ordenar os cards no futuro, se necessário
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
INDEX idx_directory (directory_id)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: flashcard_scores
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
flashcard_id INT UNSIGNED NOT NULL,
score TINYINT UNSIGNED DEFAULT 0 COMMENT 'Max 20',
next_review_at DATETIME DEFAULT NULL,
last_reviewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

UNIQUE KEY unique_user_card (user_id, flashcard_id),
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (flashcard_id) REFERENCES flashcards(id) ON DELETE CASCADE,
INDEX idx_user_card (user_id, flashcard_id),
INDEX idx_flashcard_user_next_review (flashcard_id, user_id, next_review_at, score)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

REGRA DE PROGRESSÃO DE FASE (TRILHA/MAPA/FASE)
- Tipo 8 (Trilha) aceita apenas filhos do tipo 9 (Mapa).
- Tipo 9 (Mapa) aceita apenas filhos do tipo 10 (Fase).
- Tipo 10 (Fase) funciona como deck de flashcards jogável no mapa.
- Desbloqueio: a fase N+1 só libera quando a fase N tiver pelo menos 1 flashcard e `deck_due_cards = 0` para o usuário.
- Navegação entre mapas é permitida, mas fases bloqueadas continuam inacessíveis até cumprir a revisão da fase anterior.


======================================================================

TABELA: schedule_tags
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
name VARCHAR(80) NOT NULL,
color VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

UNIQUE KEY uniq_user_tag_name (user_id, name),
INDEX idx_user_id (user_id),
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: directory_tag_links
directory_id INT UNSIGNED NOT NULL,
tag_id INT UNSIGNED NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

PRIMARY KEY (directory_id, tag_id),
INDEX idx_tag_id (tag_id),
FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
FOREIGN KEY (tag_id) REFERENCES schedule_tags(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: adjacency_items
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
directory_id INT UNSIGNED NOT NULL, -- Liga à tabela directories (O Guarda-chuva)
parent_id INT UNSIGNED DEFAULT NULL, -- A Mágica: Liga a outro item desta mesma tabela
label VARCHAR(255) NOT NULL, -- Ex: "Art 5º", "Banca Cebraspe"
division_type VARCHAR(50) DEFAULT NULL, -- Ex: "artigo", "banca"
is_completed TINYINT(1) DEFAULT 0,
sort_order INT DEFAULT 0,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
FOREIGN KEY (parent_id) REFERENCES adjacency_items(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: conditional_items
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
directory_id INT UNSIGNED NOT NULL, -- Liga à tabela directories (type = 6)
parent_id INT UNSIGNED DEFAULT NULL, -- Hierarquia de tarefas/subtarefas
label VARCHAR(255) NOT NULL,
conditional_item_id INT UNSIGNED DEFAULT NULL, -- A tarefa que precisa ser concluída antes desta
is_completed TINYINT(1) DEFAULT 0,
sort_order INT DEFAULT 0,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
FOREIGN KEY (parent_id) REFERENCES conditional_items(id) ON DELETE CASCADE,
FOREIGN KEY (conditional_item_id) REFERENCES conditional_items(id) ON DELETE SET NULL,
INDEX idx_conditional_directory_parent (directory_id, parent_id),
INDEX idx_conditional_dep (conditional_item_id)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


TABELA: user_follows
follower_id INT UNSIGNED NOT NULL,
followed_id INT UNSIGNED NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

PRIMARY KEY (follower_id, followed_id),
FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE,
INDEX idx_followed (followed_id)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: saved_directories
user_id INT UNSIGNED NOT NULL,
directory_id INT UNSIGNED NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

PRIMARY KEY (user_id, directory_id),
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
INDEX idx_saved_directory (directory_id)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: pronuncias
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
language VARCHAR(10) NOT NULL COMMENT 'pt-BR | en-US | en-GB',
source_text VARCHAR(255) NOT NULL COMMENT 'Texto original encontrado',
target_text VARCHAR(255) NOT NULL COMMENT 'Texto substituto para melhor pronúncia no TTS',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

UNIQUE KEY uniq_language_source (language, source_text),
INDEX idx_language (language)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


======================================================================

TABELA: flashcard_batch_jobs
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
directory_id INT UNSIGNED NOT NULL,
topic VARCHAR(200) DEFAULT NULL,
deck_structure VARCHAR(20) NOT NULL DEFAULT 'fatos',
openai_input_file_id VARCHAR(80) DEFAULT NULL,
openai_batch_id VARCHAR(80) DEFAULT NULL,
openai_output_file_id VARCHAR(80) DEFAULT NULL,
openai_error_file_id VARCHAR(80) DEFAULT NULL,
status VARCHAR(30) NOT NULL DEFAULT 'submitted',
error_message TEXT DEFAULT NULL,
result_cards_json LONGTEXT DEFAULT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
completed_at DATETIME DEFAULT NULL,

INDEX idx_user_deck (user_id, directory_id),
INDEX idx_openai_batch (openai_batch_id),
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA flashcard_book_progress
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
directory_id INT UNSIGNED NOT NULL,
current_index INT UNSIGNED DEFAULT 0,
completed_reads TINYINT UNSIGNED DEFAULT 0 COMMENT 'Leituras completas do deck livro (max 3)',
next_review_at DATETIME DEFAULT NULL,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
UNIQUE KEY unique_user_deck (user_id, directory_id),
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================


======================================================================

TABELA: plano_meta
directory_id INT UNSIGNED PRIMARY KEY, -- FK para directories (type = 7)
current_phase TINYINT UNSIGNED NOT NULL DEFAULT 1, -- Fase atual automática (1 a 5)
phases_data JSON DEFAULT NULL, -- Brainstorm/Conclusão/status por fase
recurrence_rules JSON DEFAULT NULL, -- Configuração de repetição da agenda por fase
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


======================================================================

TABELA: track_nodes
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
track_directory_id INT UNSIGNED NOT NULL, -- FK para directories (type = 8, a matéria/trilha)
position_index INT UNSIGNED NOT NULL, -- Ordem global da trilha (1..N)
map_number INT UNSIGNED NOT NULL, -- Mapa calculado automaticamente (10 fases por mapa)
phase_number TINYINT UNSIGNED NOT NULL, -- Fase dentro do mapa (1..10)
title VARCHAR(255) NOT NULL,
objective TEXT DEFAULT NULL,
questions_json LONGTEXT DEFAULT NULL, -- Perguntas diagnósticas por fase (JSON array)
                              -- OBS: geração de "Gerar +10 fases (GPT)" cria só índice; perguntas são opcionais e podem ser geradas/editadas depois na tela da fase
prerequisite_positions_json LONGTEXT DEFAULT NULL, -- NOVO: Dependências explícitas por posição (JSON array), ex: [3,4]
source VARCHAR(20) NOT NULL DEFAULT 'manual', -- manual | gpt | fallback
is_published TINYINT(1) NOT NULL DEFAULT 0, -- NOVO: só aparece no mapa após confirmação do criador
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
published_at DATETIME DEFAULT NULL, -- NOVO: data de publicação da fase

UNIQUE KEY uniq_track_position (track_directory_id, position_index),
INDEX idx_track_map (track_directory_id, map_number, phase_number),
INDEX idx_track_publish (track_directory_id, is_published, position_index),
FOREIGN KEY (track_directory_id) REFERENCES directories(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: track_user_progress
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
track_directory_id INT UNSIGNED NOT NULL,
current_position INT UNSIGNED NOT NULL DEFAULT 1, -- próxima fase desbloqueada
completed_positions_json LONGTEXT DEFAULT NULL, -- posições concluídas (JSON array)
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

UNIQUE KEY uniq_user_track (user_id, track_directory_id),
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (track_directory_id) REFERENCES directories(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: track_generation_jobs
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
track_directory_id INT UNSIGNED NOT NULL,
model VARCHAR(40) NOT NULL DEFAULT 'gpt-5.4',
prompt_payload LONGTEXT DEFAULT NULL, -- Prompt enviado para o GPT
response_payload LONGTEXT DEFAULT NULL, -- Resposta bruta para auditoria
status VARCHAR(20) NOT NULL DEFAULT 'completed',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

INDEX idx_track_jobs (track_directory_id, created_at),
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (track_directory_id) REFERENCES directories(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: track_node_slides
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
node_id BIGINT UNSIGNED NOT NULL, -- FK para track_nodes (uma fase específica)
content_json LONGTEXT DEFAULT NULL, -- Slides da fase (array JSON)
model VARCHAR(40) DEFAULT NULL, -- gpt-5.4 | fallback | manual
created_by INT UNSIGNED NOT NULL, -- usuário que gerou/editou por último
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

UNIQUE KEY uniq_node_slide (node_id),
FOREIGN KEY (node_id) REFERENCES track_nodes(id) ON DELETE CASCADE,
FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: materias
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
nome VARCHAR(255) NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

UNIQUE KEY uniq_materia_nome (nome)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: materia_subtopicos
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
materia_id INT UNSIGNED NOT NULL,
titulo VARCHAR(255) NOT NULL,
sort_order INT UNSIGNED NOT NULL DEFAULT 1,
parent_subtopico_id BIGINT UNSIGNED DEFAULT NULL, -- referência opcional ao item usado para expandir
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

INDEX idx_materia_sort (materia_id, sort_order),
INDEX idx_materia_parent (materia_id, parent_subtopico_id),
FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE,
FOREIGN KEY (parent_subtopico_id) REFERENCES materia_subtopicos(id) ON DELETE SET NULL
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
