public_html/gluon/
│
├── index.php                 # Front Controller: Recebe e direciona TODAS as requisições.
├── .htaccess                 # (Para Apache) Redireciona tudo para o index.php
│
├── config/                   # Configurações globais e de segurança
│   ├── database.php          # Conexão PDO e chaves de criptografia AES
│   └── env.php               # Variáveis de ambiente (senhas do BD, etc.)
│
├── api/                      # Back-end: Endpoints que processam dados (Retornam JSON)
│   ├── auth.php              # Login, Registro, Logout, Validação de Sessão
│   ├── directories.php       # CRUD de Diretórios (Nomes criptografados + Estética)
│   └── user.php              # Preferências do Usuário (Root View, Perfil, etc)
│
├── views/                    # Front-end: Onde ficam os layouts (HTML/Tailwind)
│   ├── login.html            # Interface de login e registro
│   ├── dashboard.html        # Área logada com Abas de Estética, Grid/List/Kanban
│   └── errors/               # Páginas de erro (404, 500)
│
└── assets/                   # Arquivos estáticos públicos
├── js/                   # Scripts globais
├── css/                  # CSS customizado (se necessário além do Tailwind)
└── img/                  # Imagens e ícones

BANCO DE DADOS:

TABELA: users
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(50) NOT NULL UNIQUE,
email VARCHAR(100) NOT NULL UNIQUE,
password_hash VARCHAR(255) NOT NULL,
remember_token VARCHAR(255) DEFAULT NULL,
root_view VARCHAR(10) DEFAULT 'grid',
encrypted_data TEXT DEFAULT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

INDEX idx_username (username),
INDEX idx_email (email),
INDEX idx_remember_token (remember_token)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

TABELA: directories
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id INT UNSIGNED NOT NULL,
parent_id INT UNSIGNED DEFAULT NULL, -- NULL significa que está na Raiz
name_encrypted TEXT NOT NULL,        -- Nome do diretório criptografado
default_view VARCHAR(10) DEFAULT 'grid',
sort_order INT DEFAULT 0,
icon VARCHAR(50) DEFAULT 'fa-folder',      -- Ícone FontAwesome
icon_color_from VARCHAR(7) DEFAULT '#3b82f6', -- Cor inicial do Gradient (Hex)
icon_color_to VARCHAR(7) DEFAULT '#6366f1',   -- Cor final do Gradient (Hex)
cover_url_encrypted TEXT DEFAULT NULL,     -- URL da imagem de capa (Criptografado)
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
FOREIGN KEY (parent_id) REFERENCES directories(id) ON DELETE CASCADE,
INDEX idx_user_parent (user_id, parent_id),
INDEX idx_sort_order (sort_order)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
