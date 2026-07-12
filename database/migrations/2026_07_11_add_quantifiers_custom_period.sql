ALTER TABLE quantifiers
    MODIFY COLUMN period_type ENUM('years', 'months', 'days', 'custom') NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS custom_period_unit ENUM('minutes', 'hours', 'days', 'months', 'years') NULL DEFAULT NULL AFTER period_type,
    ADD COLUMN IF NOT EXISTS custom_period_amount INT UNSIGNED NULL DEFAULT NULL AFTER custom_period_unit;
