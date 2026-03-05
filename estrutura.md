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
│   ├── user.php
│   ├── editor.php            # Gerencia leitura e gravação de códigos
│   └── schedule.php          # NOVO: Micro-API para arrastar/redimensionar eventos
│   └── cron_recurrence.php   # NOVO: Motor autônomo de repetição de tarefas (via CRON)
│   └── flashcards.php        # NOVO: Micro-API para CRUD de Flashcards
│   └── adjacency.php        # NOVO: Micro-API para Lista adjacente
│   └── pronuncias.php       # NOVO: CRUD administrativo para ajustes de pronúncia TTS
│
├── views/                    # Front-end (Vanilla JS + Tailwind)
│   ├── login.html
│   ├── dashboard.html        # ATUALIZADO: Botão ADM no cabeçalho (apenas usuário 1)
│   ├── adm.php               # NOVO: Painel administrativo (restrito ao usuário 1)
│   ├── configuracao-tts.php  # NOVO: CRUD mobile para tabela pronuncias
│   ├── settings.html
│   ├── editor.html           # Interface do editor de código
│   ├── schedule.html         # NOVO: Interface da Linha do Tempo / Agenda
│   ├── flashcards.html       # NOVO: Interface de estudo de Flashcards
│   ├── adjacency.html       # NOVO: Lista adjacente
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
encrypted_data TEXT DEFAULT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

INDEX idx_username (username),
INDEX idx_email (email),
INDEX idx_remember_token (remember_token),
INDEX fk_users_copied_directory (copied_directory_id),
FOREIGN KEY (copied_directory_id) REFERENCES directories(id) ON DELETE SET NULL
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: directories
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
parent_id INT UNSIGNED DEFAULT NULL, -- NULL significa que está na Raiz
target_id INT UNSIGNED DEFAULT NULL, -- NOVO: ID do diretório alvo (Apenas para Portais)
type TINYINT DEFAULT 0,              -- 0 = Pasta, 1 = Arquivo de Código, 2 = Agenda, 3 = Portal, 4 = Deck de Flashcards
deck_mode VARCHAR(20) DEFAULT 'aleatorio';
deck_front_language VARCHAR(10) NOT NULL DEFAULT 'pt-BR', -- Idioma da frente do card (pt-BR | en-US | en-GB)
deck_back_language VARCHAR(10) NOT NULL DEFAULT 'en-GB', -- Idioma do verso do card (pt-BR | en-US | en-GB)
name_encrypted TEXT NOT NULL,        -- Nome do diretório criptografado
default_view VARCHAR(10) DEFAULT 'grid',
new_item_position VARCHAR(10) DEFAULT 'end',
sort_order INT DEFAULT 0,
icon VARCHAR(50) DEFAULT 'fa-folder',      -- Ícone FontAwesome
icon_color_from VARCHAR(7) DEFAULT '#3b82f6', -- Cor inicial do Gradient (Hex)
icon_color_to VARCHAR(7) DEFAULT '#6366f1',   -- Cor final do Gradient (Hex)
cover_url_encrypted TEXT DEFAULT NULL,     -- URL da imagem de capa (Criptografado)
start_date DATETIME DEFAULT NULL,    -- NOVO: Início da tarefa/evento na agenda
end_date DATETIME DEFAULT NULL,      -- NOVO: Fim da tarefa/evento na agenda
is_recurring TINYINT(1) DEFAULT 0,   -- NOVO: Flag para saber se tem regra de recorrência
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (parent_id) REFERENCES directories(id) ON DELETE CASCADE,
FOREIGN KEY (target_id) REFERENCES directories(id) ON DELETE CASCADE,
INDEX idx_user_parent (user_id, parent_id),
INDEX idx_sort_order (sort_order),
INDEX idx_type (type),
INDEX idx_dates (start_date, end_date),
INDEX idx_is_recurring (is_recurring),
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
INDEX idx_directory (directory_id)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

TABELA: flashcards
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
directory_id INT UNSIGNED NOT NULL, -- FK referenciando directories (Deck)
front_encrypted TEXT NOT NULL,      -- Frente do card criptografada
back_encrypted TEXT DEFAULT NULL,       -- Verso do card criptografado
image_front_encrypted LONGTEXT DEFAULT NULL,
image_back_encrypted LONGTEXT DEFAULT NULL,
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
INDEX idx_user_card (user_id, flashcard_id)
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

TABELA flashcard_book_progress
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
directory_id INT UNSIGNED NOT NULL,
current_index INT UNSIGNED DEFAULT 0,
completed_reads TINYINT UNSIGNED DEFAULT 0 COMMENT 'Leituras completas do deck livro (max 3)',
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
UNIQUE KEY unique_user_deck (user_id, directory_id),
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

======================================================================

======================================================================

NOVAS VARIÁVEIS DE AMBIENTE (.env) PARA VOZES TTS POR IDIOMA
FISH_REFERENCE_ID_PT_BR=...
FISH_REFERENCE_ID_EN_US=...
FISH_REFERENCE_ID_EN_GB=...

(Compatibilidade: se não definidas, o sistema usa os IDs antigos FRONT/BACK.)

