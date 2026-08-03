CREATE TABLE IF NOT EXISTS guia_de_acoes_colunas (
    nome VARCHAR(64) NOT NULL,
    rotulo VARCHAR(120) NOT NULL,
    tipo ENUM('texto', 'numero') NOT NULL DEFAULT 'texto',
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO guia_de_acoes_colunas (nome, rotulo, tipo) VALUES
    ('free_float', 'Free float', 'numero'),
    ('nome_da_empresa', 'Nome da empresa', 'texto'),
    ('ativos', 'Ativos', 'numero'),
    ('ativos_circ', 'Ativos circ.', 'numero'),
    ('freq_div', 'Freq. div.', 'texto'),
    ('datas_resultados', 'Datas resultados', 'texto'),
    ('meses_mdi', 'Meses MDI', 'texto'),
    ('datas_assembleias', 'Datas assembleias', 'texto'),
    ('pauta_assembleias', 'Pauta assembleias', 'texto'),
    ('datas_conselhos', 'Datas conselhos', 'texto');
