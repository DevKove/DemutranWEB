SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- Executar uma única vez após a migration 001.
ALTER TABLE shifts
 ADD COLUMN starts_at DATETIME NULL,
 ADD COLUMN ends_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS sequences (
 sequence_key VARCHAR(60) PRIMARY KEY,
 next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
 setting_key VARCHAR(100) PRIMARY KEY,
 setting_value TEXT NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL,
 CONSTRAINT fk_settings_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(80) NOT NULL UNIQUE,
 description VARCHAR(255) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accident_signatures (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 accident_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 signature_data MEDIUMTEXT NOT NULL,
 signed_at DATETIME NOT NULL,
 CONSTRAINT fk_signature_accident FOREIGN KEY(accident_id) REFERENCES accidents(id) ON DELETE CASCADE,
 CONSTRAINT fk_signature_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 module VARCHAR(40) NOT NULL,
 record_id BIGINT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 stored_name VARCHAR(100) NOT NULL UNIQUE,
 mime_type VARCHAR(120) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 uploaded_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 CONSTRAINT fk_attachment_user FOREIGN KEY(uploaded_by) REFERENCES users(id),
 INDEX idx_attachment_record(module,record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_order_executors (
 order_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(order_id,user_id),
 FOREIGN KEY(order_id) REFERENCES service_orders(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_order_updates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 update_type VARCHAR(40) NOT NULL,
 description TEXT NOT NULL,
 materials TEXT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(order_id) REFERENCES service_orders(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id),
 INDEX idx_order_updates(order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL,
 message VARCHAR(500) NOT NULL,
 target_url VARCHAR(255) NULL,
 read_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_notifications_user(user_id,read_at,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles(name,description) VALUES
 ('Administrador','Acesso administrativo completo'),
 ('Agente de trânsito','Acesso operacional conforme permissões'),
 ('Servidor','Acesso aos módulos liberados');

INSERT IGNORE INTO permissions(permission_key,module,label) VALUES
 ('dashboard.view','inicio','Visualizar início'),
 ('sinistros.view','sinistro','Visualizar sinistros'),
 ('sinistros.create','sinistro','Registrar sinistros'),
 ('sinistros.edit','sinistro','Editar sinistros'),
 ('sinistros.delete','sinistro','Inativar sinistros'),
 ('escalas.view','escala','Visualizar escalas'),
 ('escalas.create','escala','Criar escalas'),
 ('escalas.edit','escala','Editar escalas'),
 ('escalas.publish','escala','Publicar escalas'),
 ('ordens.view','ordens','Visualizar ordens de serviço'),
 ('ordens.create','ordens','Abrir ordens de serviço'),
 ('ordens.edit','ordens','Gerenciar ordens de serviço'),
 ('ordens.transition','ordens','Alterar status e executar O.S.'),
 ('users.view','usuarios','Visualizar usuários'),
 ('users.manage','usuarios','Administrar usuários'),
 ('users.permissions','usuarios','Gerenciar permissões individuais'),
 ('admin.view','adm','Acessar administração'),
 ('admin.manage','adm','Administrar sistema'),
 ('reports.view','relatorios','Visualizar relatórios'),
 ('reports.export','relatorios','Exportar relatórios');

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.name='Administrador';

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN
 ('dashboard.view','sinistros.view','sinistros.create','sinistros.edit','escalas.view','escalas.create','escalas.edit','escalas.publish','ordens.view','ordens.create','ordens.edit','ordens.transition','reports.view')
WHERE r.name='Agente de trânsito';

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN
 ('dashboard.view','escalas.view','ordens.view')
WHERE r.name='Servidor';
