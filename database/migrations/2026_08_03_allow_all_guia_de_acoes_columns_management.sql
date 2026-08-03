CREATE TABLE IF NOT EXISTS guia_de_acoes_colunas_excluidas (
    nome VARCHAR(64) NOT NULL,
    excluido_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
