CREATE TABLE IF NOT EXISTS quantifiers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL,
    id_quantifier_father INT UNSIGNED NULL DEFAULT NULL,
    title VARCHAR(255) NOT NULL DEFAULT 'Novo quantificador',
    maximum_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    current_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    derivative_quantities TINYINT(1) NOT NULL DEFAULT 0,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quantifiers_user (id_user),
    INDEX idx_quantifiers_father (id_quantifier_father),
    CONSTRAINT fk_quantifiers_father FOREIGN KEY (id_quantifier_father) REFERENCES quantifiers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
