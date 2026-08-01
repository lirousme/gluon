ALTER TABLE guia_de_acoes
    ADD COLUMN free_float DOUBLE NULL AFTER setor,
    ADD COLUMN nome_da_empresa VARCHAR(255) NULL AFTER free_float,
    ADD COLUMN ativos DOUBLE NULL AFTER nome_da_empresa,
    ADD COLUMN ativos_circ DOUBLE NULL AFTER ativos;
