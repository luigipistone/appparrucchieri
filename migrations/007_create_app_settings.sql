-- Migration: 007_create_app_settings
-- Salva nome attività, logo e palette colori configurabili dall'admin.

USE portale_parrucchieri;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value) VALUES
('business_name', 'Barber'),
('business_subtitle', 'booking'),
('logo_path', ''),
('primary_color', '#335eac'),
('accent_color', '#f42539'),
('background_color', '#ffffff')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

INSERT IGNORE INTO schema_migrations (version, description)
VALUES ('007', 'create configurable app settings');
