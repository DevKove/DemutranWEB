-- Migration 001: schema inicial do DemutranWEB.
SET NAMES utf8mb4;
SET time_zone = '-03:00';
CREATE TABLE roles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE,
 description VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL,
 registration VARCHAR(40) NULL UNIQUE, email VARCHAR(190) NOT NULL UNIQUE,
 phone VARCHAR(30) NULL, photo_path VARCHAR(255) NULL, position VARCHAR(100) NULL,
 department VARCHAR(100) NULL, role_id BIGINT UNSIGNED NULL,
 status ENUM('Ativo','Afastado','Inativo') NOT NULL DEFAULT 'Ativo',
 password_hash VARCHAR(255) NOT NULL, last_login_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_users_role FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE SET NULL,
 CONSTRAINT fk_users_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_users_status(status), INDEX idx_users_name(name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE permissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, permission_key VARCHAR(100) NOT NULL UNIQUE,
 module VARCHAR(60) NOT NULL, label VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE role_permissions (
 role_id BIGINT UNSIGNED NOT NULL, permission_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(role_id,permission_id),
 FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,
 FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_permissions (
 user_id BIGINT UNSIGNED NOT NULL, permission_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id,permission_id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL, module VARCHAR(60) NOT NULL, record_id BIGINT NULL,
 ip_address VARCHAR(45) NULL, metadata JSON NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_audit_created(created_at), INDEX idx_audit_module(module,action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE accidents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, protocol VARCHAR(30) NOT NULL UNIQUE,
 occurred_at DATETIME NOT NULL, location VARCHAR(180) NOT NULL, address VARCHAR(255) NULL,
 neighborhood VARCHAR(120) NULL, reference_point VARCHAR(180) NULL, road_type VARCHAR(80) NULL,
 weather VARCHAR(80) NULL, accident_type VARCHAR(80) NOT NULL, description TEXT NOT NULL,
 has_victims TINYINT(1) NOT NULL DEFAULT 0, has_injuries TINYINT(1) NOT NULL DEFAULT 0,
 has_fatalities TINYINT(1) NOT NULL DEFAULT 0, public_property_damage TINYINT(1) NOT NULL DEFAULT 0,
 signage_damage TINYINT(1) NOT NULL DEFAULT 0, status VARCHAR(40) NOT NULL DEFAULT 'Registrado',
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 FOREIGN KEY(created_by) REFERENCES users(id), INDEX idx_accident_date(occurred_at),
 INDEX idx_accident_location(neighborhood), INDEX idx_accident_type(accident_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE accident_vehicles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, accident_id BIGINT UNSIGNED NOT NULL,
 plate VARCHAR(10) NULL, brand VARCHAR(80) NULL, model VARCHAR(100) NULL, color VARCHAR(50) NULL,
 category VARCHAR(60) NULL, driver_name VARCHAR(160) NULL, damage_notes TEXT NULL,
 FOREIGN KEY(accident_id) REFERENCES accidents(id) ON DELETE CASCADE, INDEX idx_vehicle_plate(plate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE accident_people (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, accident_id BIGINT UNSIGNED NOT NULL,
 full_name VARCHAR(160) NOT NULL, document_ref VARCHAR(80) NULL, involvement VARCHAR(50) NOT NULL,
 contact VARCHAR(50) NULL, notes TEXT NULL, FOREIGN KEY(accident_id) REFERENCES accidents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE accident_witnesses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, accident_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL, contact VARCHAR(50) NULL, notes TEXT NULL,
 FOREIGN KEY(accident_id) REFERENCES accidents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE shifts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(140) NOT NULL, service_type VARCHAR(100) NOT NULL,
 service_date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, location VARCHAR(180) NOT NULL,
 team_name VARCHAR(120) NULL, manager_id BIGINT UNSIGNED NOT NULL, status ENUM('Rascunho','Publicada','Cancelada') NOT NULL DEFAULT 'Rascunho',
 notes TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 FOREIGN KEY(manager_id) REFERENCES users(id), INDEX idx_shift_date(service_date,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE shift_members (
 shift_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(shift_id,user_id), FOREIGN KEY(shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE service_orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_number BIGINT UNSIGNED NOT NULL UNIQUE,
 opened_at DATETIME NOT NULL, requester VARCHAR(160) NOT NULL, requesting_department VARCHAR(120) NULL,
 service_type VARCHAR(100) NOT NULL, description TEXT NOT NULL, location VARCHAR(180) NOT NULL,
 priority ENUM('Baixa','Média','Alta','Urgente') NOT NULL DEFAULT 'Baixa',
 status ENUM('Solicitada','Em análise','Aprovada','Em andamento','Pendente','Concluída','Cancelada') NOT NULL DEFAULT 'Solicitada',
 due_at DATETIME NULL, responsible_id BIGINT UNSIGNED NULL, created_by BIGINT UNSIGNED NOT NULL,
 started_at DATETIME NULL, completed_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 FOREIGN KEY(responsible_id) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(created_by) REFERENCES users(id),
 INDEX idx_orders_status(status,priority), INDEX idx_orders_opened(opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE service_order_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 old_status VARCHAR(40) NULL, new_status VARCHAR(40) NOT NULL, note TEXT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(order_id) REFERENCES service_orders(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL, message VARCHAR(500) NOT NULL, target_url VARCHAR(255) NULL,
 read_at DATETIME NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_notifications_user(user_id,read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO roles(name,description) VALUES
('Administrador','Acesso administrativo completo'),('Agente de trânsito','Acesso operacional conforme permissões'),('Servidor','Acesso aos módulos liberados');
INSERT INTO permissions(permission_key,module,label) VALUES
('dashboard.view','inicio','Visualizar início'),
('sinistros.view','sinistro','Visualizar sinistros'),('sinistros.create','sinistro','Registrar sinistros'),('sinistros.edit','sinistro','Editar sinistros'),
('escalas.view','escala','Visualizar escalas'),('escalas.create','escala','Criar escalas'),('escalas.edit','escala','Editar escalas'),
('ordens.view','ordens','Visualizar ordens de serviço'),('ordens.create','ordens','Abrir ordens de serviço'),('ordens.edit','ordens','Gerenciar ordens de serviço'),
('users.view','usuarios','Visualizar usuários'),('users.manage','usuarios','Administrar usuários'),
('admin.view','adm','Acessar administração'),('admin.manage','adm','Administrar sistema'),
('reports.view','relatorios','Visualizar relatórios'),('reports.export','relatorios','Exportar relatórios');
