-- v1.4.0 · Fase 2 del bot (PROPUESTA_BOT_TELEGRAM.md §5 y §6.1): vincular la cuenta de Telegram e «Ignorar» un release.
-- Aplicar con: php cli/migrate.php

-- Versión que el usuario decidió no instalar: no cuenta como pendiente hasta que salga otra (Repo::isOutdated)
ALTER TABLE repos
    ADD COLUMN IF NOT EXISTS ignored_release VARCHAR(255) NULL AFTER my_updated_at;

-- Una cuenta de Telegram (la persona, from.id) por usuario, y viceversa
CREATE TABLE IF NOT EXISTS telegram_links (
    user_id           CHAR(8)      NOT NULL,
    telegram_user_id  BIGINT       NOT NULL,
    telegram_name     VARCHAR(255) NOT NULL DEFAULT '',
    telegram_username VARCHAR(64)  NULL,
    linked_at         DATETIME     NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_tglinks_telegram (telegram_user_id),
    CONSTRAINT fk_tglinks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Códigos REL-XXXXXXXX de un solo uso (10 min), solo el sha256; uno por usuario
CREATE TABLE IF NOT EXISTS telegram_link_codes (
    code_hash  CHAR(64)     NOT NULL,
    user_id    CHAR(8)      NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (code_hash),
    UNIQUE KEY uq_tgcodes_user (user_id),
    CONSTRAINT fk_tgcodes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
