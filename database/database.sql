-- Esquema COMPLETO de referencia (estado actual). No se ejecuta: la base se crea y actualiza con
-- php cli/migrate.php, que aplica database/migrations/*.sql en orden. Mantén este fichero al día al añadir migraciones.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(100) NOT NULL,
    applied_at DATETIME     NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS repos (
    id                CHAR(6)       NOT NULL,
    position          INT UNSIGNED  NOT NULL DEFAULT 0,
    name              VARCHAR(255)  NOT NULL,
    type              VARCHAR(10)   NOT NULL,
    tags              JSON          NOT NULL DEFAULT '[]',
    tag_pattern       VARCHAR(255)  NULL,
    last_seen_release VARCHAR(255)  NULL,
    last_seen_digest  VARCHAR(100)  NULL,
    last_release_date VARCHAR(40)   NULL,
    last_checked      DATETIME      NULL,
    check_status      VARCHAR(16)   NULL,
    my_version        VARCHAR(255)  NULL,
    my_updated_at     DATE          NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_repos_type_name (type, name),
    KEY idx_repos_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    repo_name   VARCHAR(255)    NOT NULL,
    type        VARCHAR(10)     NOT NULL,
    version     VARCHAR(255)    NOT NULL,
    url         VARCHAR(1000)   NULL,
    detected_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_history_detected (detected_at),
    KEY idx_history_repo (repo_name, detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level      VARCHAR(10)     NOT NULL,
    message    TEXT            NOT NULL,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id            CHAR(8)      NOT NULL,
    username      VARCHAR(32)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL,
    last_login_at DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens «Recordarme»: solo el sha256 del validator; la cookie es selector:validator
CREATE TABLE IF NOT EXISTS user_tokens (
    selector       CHAR(18)     NOT NULL,
    user_id        CHAR(8)      NOT NULL,
    validator_hash CHAR(64)     NOT NULL,
    expires_at     INT UNSIGNED NOT NULL,
    PRIMARY KEY (selector),
    KEY idx_tokens_user (user_id),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos fallidos de login. key_name = 'net:<IPv4 o prefijo IPv6 /64>' o 'user:<usuario>'. Hora en epoch (sin zona).
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_name     VARCHAR(100)    NOT NULL,
    attempted_at INT UNSIGNED    NOT NULL,
    PRIMARY KEY (id),
    KEY idx_attempts_key (key_name, attempted_at),
    KEY idx_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS check_jobs (
    id          VARCHAR(24)   NOT NULL,
    status      VARCHAR(10)   NOT NULL,
    source      VARCHAR(10)   NOT NULL,
    repo_ids    JSON          NULL,
    total       INT UNSIGNED  NOT NULL DEFAULT 0,
    done        INT UNSIGNED  NOT NULL DEFAULT 0,
    new_count   INT UNSIGNED  NOT NULL DEFAULT 0,
    errors      INT UNSIGNED  NOT NULL DEFAULT 0,
    current     VARCHAR(255)  NULL,
    message     VARCHAR(500)  NULL,
    created_at  DATETIME      NOT NULL,
    started_at  DATETIME      NULL,
    updated_at  DATETIME      NOT NULL,
    finished_at DATETIME      NULL,
    PRIMARY KEY (id),
    KEY idx_jobs_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado interno (p. ej. last_run: la última verificación completa, para la «salud del cron»)
CREATE TABLE IF NOT EXISTS app_state (
    name       VARCHAR(64) NOT NULL,
    value      JSON        NOT NULL,
    updated_at DATETIME    NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes editables desde Configuración. Los secretos NO van aquí: están en el .env.
CREATE TABLE IF NOT EXISTS settings (
    name       VARCHAR(64) NOT NULL,
    value      JSON        NOT NULL,
    updated_at DATETIME    NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
