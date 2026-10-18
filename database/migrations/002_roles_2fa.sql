-- v1.2.0 · Roles (admin / editor / viewer), tema por usuario y verificación en dos pasos (TOTP).
-- Los usuarios que ya existen pasan a admin: hasta la 1.1.x todos tenían todos los permisos.
-- Aplicar con: php cli/migrate.php (además genera APP_KEY en el .env si falta).

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role            ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer' AFTER password_hash,
    ADD COLUMN IF NOT EXISTS theme           VARCHAR(5)      NULL AFTER role,
    ADD COLUMN IF NOT EXISTS totp_secret     VARCHAR(255)    NULL,
    ADD COLUMN IF NOT EXISTS totp_enabled_at DATETIME        NULL,
    ADD COLUMN IF NOT EXISTS totp_last_step  BIGINT UNSIGNED NULL;

UPDATE users SET role = 'admin';

-- Códigos de recuperación del 2FA: un solo uso, solo el sha256 (se muestran una vez al generarlos)
CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    CHAR(8)         NOT NULL,
    code_hash  CHAR(64)        NOT NULL,
    used_at    DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_recovery_user (user_id, code_hash),
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
