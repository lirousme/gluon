CREATE TABLE branch_chat_daily_reads (
    user_id INT UNSIGNED NOT NULL,
    local_date DATE NOT NULL,
    read_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, local_date),
    CONSTRAINT fk_branch_chat_daily_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
