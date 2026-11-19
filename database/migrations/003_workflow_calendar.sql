SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- Migration 003: calendário, histórico de escalas e aprovação formal de O.S.
-- Executar uma única vez em instalações que já aplicaram 001 e 002.

ALTER TABLE shifts
 ADD COLUMN cancel_reason TEXT NULL,
 ADD COLUMN cancelled_by BIGINT UNSIGNED NULL,
 ADD COLUMN cancelled_at DATETIME NULL;

ALTER TABLE service_orders
 ADD COLUMN approved_by BIGINT UNSIGNED NULL,
 ADD COLUMN approved_at DATETIME NULL,
 ADD COLUMN cancelled_by BIGINT UNSIGNED NULL,
 ADD COLUMN cancelled_at DATETIME NULL,
 ADD COLUMN cancellation_reason TEXT NULL;

CREATE TABLE IF NOT EXISTS shift_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shift_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(40) NOT NULL,
 details TEXT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id),
 INDEX idx_shift_history(shift_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(permission_key,module,label)
VALUES('ordens.approve','ordens','Aprovar ordens de serviço');

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key='ordens.approve'
WHERE r.name IN ('Administrador','Agente de trânsito');
