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
│
├── views/                    # Front-end (Vanilla JS + Tailwind)
│   ├── login.html
│   ├── dashboard.html        # ATUALIZADO: Suporte a Agenda
│   ├── settings.html
│   ├── editor.html           # Interface do editor de código
│   ├── schedule.html         # NOVO: Interface da Linha do Tempo / Agenda
│   ├── flashcards.html       # NOVO: Interface de estudo de Flashcards
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
back_encrypted TEXT NOT NULL,       -- Verso do card criptografado
sort_order INT DEFAULT 0,           -- Para ordenar os cards no futuro, se necessário
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
INDEX idx_directory (directory_id)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
