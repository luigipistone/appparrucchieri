CREATE DATABASE IF NOT EXISTS portale_parrucchieri CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE portale_parrucchieri;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(32) PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role ENUM('admin','cliente') NOT NULL DEFAULT 'cliente',
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(40) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    price DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 30,
    image_path VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('confermato','annullato') NOT NULL DEFAULT 'confermato',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_appointments_range (starts_at, ends_at, status),
    INDEX idx_appointments_user (user_id),
    CONSTRAINT fk_appointments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_appointments_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    channel ENUM('email','telefono') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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

INSERT INTO users (role, first_name, last_name, email, phone, password_hash)
VALUES ('admin', 'Admin', 'Salone', 'admin@salone.local', '+390000000000', '$2y$12$xCP8gSZz6cQv82s0MWHoc.WgwJKIe9w.dFNQ060b2Qs6bCLWVYnC2')
ON DUPLICATE KEY UPDATE role = VALUES(role);

INSERT INTO services (name, description, price, duration_minutes, active)
SELECT 'Taglio uomo', 'Taglio classico o moderno con rifinitura finale.', 18.00, 30, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Taglio uomo');

INSERT INTO services (name, description, price, duration_minutes, active)
SELECT 'Taglio + barba', 'Servizio completo capelli e barba con panni caldi.', 28.00, 60, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Taglio + barba');

INSERT INTO services (name, description, price, duration_minutes, active)
SELECT 'Barba', 'Regolazione, rasatura e definizione barba.', 12.00, 30, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Barba');

INSERT IGNORE INTO schema_migrations (version, description) VALUES
('001', 'create database and schema_migrations table'),
('002', 'create core application tables'),
('003', 'seed initial admin user and default services'),
('004', 'create admin configurable closure settings');
