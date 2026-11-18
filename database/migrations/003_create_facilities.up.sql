-- =====================================================================
-- SyncClass — 003_create_facilities (UP)
-- Buildings and rooms (the schedulable physical resources).
-- Requirements: MD-02, CNF-05, BR-05, BR-06.
-- =====================================================================

CREATE TABLE IF NOT EXISTS buildings (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    campus     VARCHAR(120) NULL,
    address    VARCHAR(255) NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_buildings_code (code),
    KEY idx_buildings_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    building_id INT UNSIGNED     NOT NULL,
    code       VARCHAR(20)       NOT NULL,
    name       VARCHAR(120)      NULL,
    floor      SMALLINT          NULL,
    room_type  ENUM('lecture','laboratory','seminar') NOT NULL DEFAULT 'lecture',
    capacity   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    facilities VARCHAR(500)      NULL,
    is_active  TINYINT(1)        NOT NULL DEFAULT 1,
    created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rooms_building_code (building_id, code),
    KEY idx_rooms_type_capacity (room_type, capacity),
    KEY idx_rooms_active (is_active),
    CONSTRAINT fk_rooms_building
        FOREIGN KEY (building_id) REFERENCES buildings (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_rooms_capacity CHECK (capacity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
