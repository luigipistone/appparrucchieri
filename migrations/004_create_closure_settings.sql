-- Migration: 004_create_closure_settings
-- Aggiunge chiusure settimanali e giorni speciali configurabili dall'admin.

USE portale_parrucchieri;

CREATE TABLE IF NOT EXISTS weekly_closures (
    weekday TINYINT UNSIGNED PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_weekly_closures_weekday CHECK (weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS special_closures (
    closure_date DATE PRIMARY KEY,
    label VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO weekly_closures (weekday) VALUES (7);

INSERT IGNORE INTO schema_migrations (version, description)
VALUES ('004', 'create admin configurable closure settings');
