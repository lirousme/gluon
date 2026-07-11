ALTER TABLE quantifiers
    ADD COLUMN IF NOT EXISTS order_position INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_completed,
    ADD INDEX IF NOT EXISTS idx_quantifiers_order (id_user, id_quantifier_father, order_position);
