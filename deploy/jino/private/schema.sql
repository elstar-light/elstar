CREATE TABLE IF NOT EXISTS elstar_requests (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  reference VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  kind VARCHAR(16) NOT NULL,
  fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
  payload LONGTEXT NOT NULL,
  photo_keys TEXT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'new',
  payment_status VARCHAR(24) NOT NULL DEFAULT 'unpaid',
  manager_note TEXT NOT NULL,
  notification_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  source_hash CHAR(64) CHARACTER SET ascii NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX elstar_requests_source_created (source_hash, created_at),
  INDEX elstar_requests_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS elstar_rate_limits (
  source_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY,
  window_start BIGINT NOT NULL,
  attempts INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
