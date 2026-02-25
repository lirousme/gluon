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
│   ├── user.php              # (Futuro) Atualização de perfil, etc.
│   └── ...                   
│
├── views/                    # Front-end: Onde ficam os layouts (HTML/Tailwind)
│   ├── login.html            # Interface de login e registro
│   ├── dashboard.html        # (Futuro) Área logada
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
    remember_token VARCHAR(255) DEFAULT NULL, -- Para o recurso "Manter logado"
    encrypted_data TEXT DEFAULT NULL, -- Exemplo para dados futuros ultra-secretos
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
  -- Índices para buscas ultra rápidas no login
  INDEX idx_username (username),
  INDEX idx_email (email),
  INDEX idx_remember_token (remember_token)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
