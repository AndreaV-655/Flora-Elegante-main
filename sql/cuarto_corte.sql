-- Ejecutar DESPUÉS del esquema original y de tercer_corte.sql.
-- Conserva datos; las sentencias son idempotentes.
INSERT IGNORE INTO rol (nombre) VALUES ('empleado');

CREATE TABLE IF NOT EXISTS fe_documento (
 id CHAR(32) PRIMARY KEY,
 empleado_id BIGINT NOT NULL,
 creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 archivo VARCHAR(120) NOT NULL,
 tipo VARCHAR(20) NOT NULL DEFAULT 'imagen',
 resultado LONGTEXT NULL,
 confianza DECIMAL(4,3) NULL,
 estado VARCHAR(20) NOT NULL DEFAULT 'procesando',
 datos_confirmados LONGTEXT NULL,
 confirmado_en DATETIME NULL,
 FOREIGN KEY (empleado_id) REFERENCES usuario(id),
 INDEX idx_fe_documento_empleado (empleado_id, creado),
 INDEX idx_fe_documento_estado (estado, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS logs_automatizacion (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 nombre_flujo VARCHAR(80) NOT NULL,
 fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 fecha_fin DATETIME NULL,
 datos_entrada TEXT NULL,
 estado VARCHAR(10) NOT NULL,
 resultado_o_error VARCHAR(300) NULL,
 INDEX idx_logs_automatizacion (nombre_flujo, fecha_inicio),
 INDEX idx_logs_estado (estado, fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
