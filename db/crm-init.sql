CREATE TABLE clients (
    id            SERIAL PRIMARY KEY,
    keycloak_id   VARCHAR(36) UNIQUE,
    first_name    VARCHAR(100),
    last_name     VARCHAR(100),
    email         VARCHAR(255) UNIQUE NOT NULL,
    serial_number VARCHAR(50),          -- номер протеза
    issued_at     DATE,
    created_at    TIMESTAMP DEFAULT NOW()
);

INSERT INTO clients (keycloak_id, first_name, last_name, email, serial_number, issued_at) VALUES
    ('uuid-prothetic1', 'Иван',    'Петров',   'prothetic1@example.com', 'BP-X1-00001', '2024-01-15'),
    ('uuid-prothetic2', 'Мария',   'Сидорова', 'prothetic2@example.com', 'BP-X2-00002', '2024-03-10'),
    ('uuid-prothetic3', 'Алексей', 'Козлов',   'prothetic3@example.com', 'BP-X1-00003', '2024-06-01');
