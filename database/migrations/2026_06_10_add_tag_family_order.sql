ALTER TABLE tag_family
  ADD COLUMN ordem INT NOT NULL DEFAULT 0 AFTER tipo_de_relacao;
