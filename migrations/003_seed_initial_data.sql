-- Migration: 003_seed_initial_data
-- Inserisce admin iniziale e servizi di esempio.

USE portale_parrucchieri;

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

INSERT IGNORE INTO schema_migrations (version, description)
VALUES ('003', 'seed initial admin user and default services');
