ALTER TABLE quantifiers
    ADD COLUMN IF NOT EXISTS period_type ENUM('years', 'months', 'days') NULL DEFAULT NULL AFTER derivative_quantities,
    ADD COLUMN IF NOT EXISTS start_datetime DATETIME NULL DEFAULT NULL AFTER period_type,
    ADD COLUMN IF NOT EXISTS end_datetime DATETIME NULL DEFAULT NULL AFTER start_datetime,
    ADD COLUMN IF NOT EXISTS repeat_until DATETIME NULL DEFAULT NULL AFTER end_datetime;
