CREATE TABLE telemetry (
    id            SERIAL PRIMARY KEY,
    keycloak_id   VARCHAR(36),
    serial_number VARCHAR(50),
    signal_type   VARCHAR(50),    -- тип движения: grip, pinch, extend
    signal_value  FLOAT,          -- сила сигнала 0.0 - 1.0
    recorded_at   TIMESTAMP DEFAULT NOW()
);

INSERT INTO telemetry (keycloak_id, serial_number, signal_type, signal_value, recorded_at) VALUES
    ('uuid-prothetic1', 'BP-X1-00001', 'grip',   0.85, '2024-09-01 10:00:00'),
    ('uuid-prothetic1', 'BP-X1-00001', 'pinch',  0.60, '2024-09-01 10:05:00'),
    ('uuid-prothetic1', 'BP-X1-00001', 'extend', 0.90, '2024-09-01 10:10:00'),
    ('uuid-prothetic2', 'BP-X2-00002', 'grip',   0.75, '2024-09-01 11:00:00'),
    ('uuid-prothetic2', 'BP-X2-00002', 'extend', 0.55, '2024-09-01 11:05:00'),
    ('uuid-prothetic3', 'BP-X1-00003', 'grip',   0.40, '2024-09-01 12:00:00'),
    ('uuid-prothetic3', 'BP-X1-00003', 'pinch',  0.35, '2024-09-01 12:05:00');
