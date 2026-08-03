ALTER TABLE guia_de_acoes
    ADD COLUMN freq_div VARCHAR(255) NULL AFTER ativos_circ,
    ADD COLUMN datas_resultados TEXT NULL AFTER freq_div,
    ADD COLUMN meses_mdi VARCHAR(255) NULL AFTER datas_resultados,
    ADD COLUMN datas_assembleias TEXT NULL AFTER meses_mdi,
    ADD COLUMN pauta_assembleias TEXT NULL AFTER datas_assembleias,
    ADD COLUMN datas_conselhos TEXT NULL AFTER pauta_assembleias;
