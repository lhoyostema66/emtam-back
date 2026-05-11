-- =============================================================
-- Script SQL para hosting
-- Equivalente a la migración: 2026_05_04_120000_create_bulk_import_file_map_cfg_table.php
-- Crea tabla de mapeo de importación masiva + carga inicial (upsert)
-- =============================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `bulk_import_file_map_cfg` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_name` VARCHAR(180) NOT NULL,
  `destination_table` VARCHAR(120) NOT NULL,
  `upload_order` INT UNSIGNED NOT NULL DEFAULT 100,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bulk_import_file_map_cfg_file_name_unique` (`file_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `bulk_import_file_map_cfg` (`file_name`, `destination_table`, `upload_order`, `is_active`, `created_at`, `updated_at`) VALUES
('ACCION_OPERATIVA.csv', 'accion_operativa_cfg', 10, 1, NOW(), NOW()),
('ACCION_SET.csv', 'accion_set_cfg', 20, 1, NOW(), NOW()),
('ACCION_SET_DETALLE.csv', 'accion_set_detalle_cfg', 30, 1, NOW(), NOW()),
('ACCION_SET_DETALLE_CANAL.csv', 'accion_set_detalle_canal_cfg', 40, 1, NOW(), NOW()),
('CANAL_COMUNICACION.csv', 'canal_comunicacion_cat', 50, 1, NOW(), NOW()),
('FASE_ACTIVACION.csv', 'fase_activacion_cat', 60, 1, NOW(), NOW()),
('GRUPO_OPERATIVO.csv', 'grupo_operativo_cfg', 70, 1, NOW(), NOW()),
('LUGAR_TIPO.csv', 'lugar_tipo_cat', 80, 1, NOW(), NOW()),
('NIVEL_ALERTA.csv', 'nivel_alerta_cat', 90, 1, NOW(), NOW()),
('NIVEL_EMERGENCIA.csv', 'nivel_emergencia_cat', 100, 1, NOW(), NOW()),
('ROL.csv', 'rol_cat', 110, 1, NOW(), NOW()),
('TIPO_EMERGENCIA.csv', 'tipo_emergencia_cat', 120, 1, NOW(), NOW()),
('TIPO_RIESGO.csv', 'tipo_riesgo_cat', 130, 1, NOW(), NOW()),
('RIESGO.csv', 'riesgo_cat', 140, 1, NOW(), NOW()),
('RIESGO_SUB1.csv', 'riesgo_sub1_cat', 150, 1, NOW(), NOW()),
('RIESGO_SUB2.csv', 'riesgo_sub2_cat', 160, 1, NOW(), NOW()),
('TIPO_RIESGO_NIVEL_ACCION_SET.csv', 'tipo_riesgo_nivel_accion_set_cfg', 170, 1, NOW(), NOW()),
('RIESGO_NIVEL_ACCION_SET.csv', 'riesgo_nivel_accion_set_cfg', 180, 1, NOW(), NOW()),
('CRITERIOS_RIESGO_NI_AL.csv', 'criterios_nivel_alerta_cfg', 190, 1, NOW(), NOW()),
('PERSONA.csv', 'persona_mst', 200, 1, NOW(), NOW()),
('PERSONA_ROL.csv', 'persona_rol_cfg', 210, 1, NOW(), NOW()),
('PERSONA_ROL_GRUPO.csv', 'persona_rol_grupo_cfg', 220, 1, NOW(), NOW()),
('ELEMENTO_VULN.csv', 'elemento_vuln_mst', 230, 1, NOW(), NOW()),
('EV_LUGAR.csv', 'ev_lugar_mst', 240, 1, NOW(), NOW()),
('EV_LUGAR_CONTACTO.csv', 'ev_lugar_contacto_mst', 250, 1, NOW(), NOW()),
('EV_LUGAR_COORDENADA.csv', 'ev_lugar_coordenada_mst', 260, 1, NOW(), NOW()),
('EV_RIESGO.csv', 'ev_riesgo_trs', 270, 1, NOW(), NOW()),
('ACTIVACION_DEL_PLAN.csv', 'activacion_del_plan_trs', 280, 1, NOW(), NOW()),
('ACTIVACION_NIVEL_HIST.csv', 'activacion_nivel_hist_trs', 290, 1, NOW(), NOW()),
('ASIGNACION_EN_FUNCIONES.csv', 'asignacion_en_funciones_trs', 300, 1, NOW(), NOW()),
('CRONOLOGIA_EMERGENCIA.csv', 'cronologia_emergencia_trs', 310, 1, NOW(), NOW()),
('EJECUCION_ACCION.csv', 'ejecucion_accion_trs', 320, 1, NOW(), NOW()),
('NOTAS_OPERATIVAS.csv', 'notas_operativas_trs', 330, 1, NOW(), NOW()),
('NOTIFICACION_CONFIRMACION.csv', 'notificacion_confirmacion_trs', 340, 1, NOW(), NOW()),
('NOTIFICACION_ENVIO.csv', 'notificacion_envio_trs', 350, 1, NOW(), NOW()),
('DICCIONARIO_DATOS.csv', 'diccionario_datos_cfg', 360, 1, NOW(), NOW()),
('INDICE.csv', 'informacion_tablas', 370, 1, NOW(), NOW()),
('Reglas_Por_Tabla.csv', 'reglas_por_tabla_cfg', 380, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `destination_table` = VALUES(`destination_table`),
  `upload_order` = VALUES(`upload_order`),
  `is_active` = VALUES(`is_active`),
  `updated_at` = NOW();

COMMIT;

-- Verificación rápida
SELECT COUNT(*) AS total_mapeos FROM `bulk_import_file_map_cfg`;

