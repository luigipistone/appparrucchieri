-- Migration: 001_create_database
-- Crea il database applicativo se non esiste.

CREATE DATABASE IF NOT EXISTS portale_parrucchieri
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE portale_parrucchieri;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(32) PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version, description)
VALUES ('001', 'create database and schema_migrations table');
