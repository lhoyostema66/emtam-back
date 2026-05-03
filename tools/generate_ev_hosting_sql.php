<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * Escape SQL literal value (or NULL).
 */
function sqlValue(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    $text = (string) $value;
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace("'", "\\'", $text);

    return "'" . $text . "'";
}

function quoteId(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @param array<int, string> $columns
 */
function buildInsert(string $table, array $columns, array $rows): string
{
    if (empty($rows)) {
        return "-- Sin datos para {$table}" . PHP_EOL;
    }

    $cols = implode(', ', array_map(static fn(string $c): string => quoteId($c), $columns));
    $sql = "INSERT INTO " . quoteId($table) . " ({$cols}) VALUES" . PHP_EOL;

    $values = [];
    foreach ($rows as $row) {
        $items = [];
        foreach ($columns as $column) {
            $items[] = sqlValue($row[$column] ?? null);
        }
        $values[] = '  (' . implode(', ', $items) . ')';
    }

    $sql .= implode(',' . PHP_EOL, $values) . ';' . PHP_EOL;

    return $sql;
}

$sub1 = array_map(
    static fn(object $r): array => [
        'ri_su_1-id' => $r->{'ri_su_1-id'} ?? null,
        'ri_su_1-nomrisn1' => $r->{'ri_su_1-nomrisn1'} ?? null,
        'ri_su_1-rie_id-fk' => $r->{'ri_su_1-rie_id-fk'} ?? null,
    ],
    DB::table('riesgo_sub1_cat')
        ->orderBy('ri_su_1-id')
        ->get()
        ->all()
);

$sub2 = array_map(
    static fn(object $r): array => [
        'ri_su_2-id' => $r->{'ri_su_2-id'} ?? null,
        'ri_su_2-ri_su_1_id-fk' => $r->{'ri_su_2-ri_su_1_id-fk'} ?? null,
        'ri_su_2-nomrisn2' => $r->{'ri_su_2-nomrisn2'} ?? null,
        'ri_su_2-nomrisn3' => $r->{'ri_su_2-nomrisn3'} ?? null,
    ],
    DB::table('riesgo_sub2_cat')
        ->orderBy('ri_su_2-id')
        ->get()
        ->all()
);

$ev = array_map(
    static fn(object $r): array => [
        'ev_ri-id' => $r->{'ev_ri-id'} ?? null,
        'ev_ri-el_vu_id-fk' => $r->{'ev_ri-el_vu_id-fk'} ?? null,
        'ev_ri-el_vu_cod-fk' => $r->{'ev_ri-el_vu_cod-fk'} ?? null,
        'ev_ri-rie_id-fk' => $r->{'ev_ri-rie_id-fk'} ?? null,
        'ev_ri-ri_su_1_id-fk' => $r->{'ev_ri-ri_su_1_id-fk'} ?? null,
        'ev_ri-ri_su_2_id-fk' => $r->{'ev_ri-ri_su_2_id-fk'} ?? null,
        'ev_ri-ri_su_1_nomrisn1-fk' => $r->{'ev_ri-ri_su_1_nomrisn1-fk'} ?? null,
        'ev_ri-ri_su_2_nomrisn2-fk' => $r->{'ev_ri-ri_su_2_nomrisn2-fk'} ?? null,
    ],
    DB::table('ev_riesgo_cfg')
        ->orderBy('ev_ri-id')
        ->get()
        ->all()
);

$out = [];
$out[] = '-- =============================================================';
$out[] = '-- Script SQL único para hosting: filtros EV por riesgo/subniveles';
$out[] = '-- Generado automáticamente desde la BD local';
$out[] = '-- =============================================================';
$out[] = '';
$out[] = 'START TRANSACTION;';
$out[] = 'SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;';
$out[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$out[] = '';
$out[] = '-- 1) Crear tablas nuevas si no existen';
$out[] = 'CREATE TABLE IF NOT EXISTS `riesgo_sub1_cat` (';
$out[] = '  `ri_su_1-id` varchar(191) NOT NULL,';
$out[] = '  `ri_su_1-nomrisn1` text NULL,';
$out[] = '  `ri_su_1-rie_id-fk` text NULL,';
$out[] = '  PRIMARY KEY (`ri_su_1-id`),';
$out[] = '  KEY `riesgo_sub1_cat_ri_su_1_rie_id_fk_index` (`ri_su_1-rie_id-fk`(191))';
$out[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
$out[] = '';
$out[] = 'CREATE TABLE IF NOT EXISTS `riesgo_sub2_cat` (';
$out[] = '  `ri_su_2-id` varchar(191) NOT NULL,';
$out[] = '  `ri_su_2-ri_su_1_id-fk` text NULL,';
$out[] = '  `ri_su_2-nomrisn2` text NULL,';
$out[] = '  `ri_su_2-nomrisn3` text NULL,';
$out[] = '  PRIMARY KEY (`ri_su_2-id`),';
$out[] = '  KEY `riesgo_sub2_cat_ri_su_2_ri_su_1_id_fk_index` (`ri_su_2-ri_su_1_id-fk`(191))';
$out[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
$out[] = '';
$out[] = 'CREATE TABLE IF NOT EXISTS `ev_riesgo_cfg` (';
$out[] = '  `ev_ri-id` varchar(191) NOT NULL,';
$out[] = '  `ev_ri-el_vu_id-fk` text NULL,';
$out[] = '  `ev_ri-el_vu_cod-fk` text NULL,';
$out[] = '  `ev_ri-ev_lu_id-fk` text NULL,';
$out[] = '  `ev_ri-rie_id-fk` text NULL,';
$out[] = '  `ev_ri-ri_su_1_id-fk` text NULL,';
$out[] = '  `ev_ri-ri_su_2_id-fk` text NULL,';
$out[] = '  `ev_ri-ri_su_1_nomrisn1-fk` text NULL,';
$out[] = '  `ev_ri-ri_su_2_nomrisn2-fk` text NULL,';
$out[] = '  PRIMARY KEY (`ev_ri-id`),';
$out[] = '  KEY `ev_riesgo_cfg_ev_ri_rie_id_fk_index` (`ev_ri-rie_id-fk`(191)),';
$out[] = '  KEY `ev_riesgo_cfg_ev_ri_ri_su_1_id_fk_index` (`ev_ri-ri_su_1_id-fk`(191)),';
$out[] = '  KEY `ev_riesgo_cfg_ev_ri_ri_su_2_id_fk_index` (`ev_ri-ri_su_2_id-fk`(191)),';
$out[] = '  KEY `ev_riesgo_cfg_ev_ri_ev_lu_id_fk_index` (`ev_ri-ev_lu_id-fk`(191)),';
$out[] = '  KEY `ev_riesgo_cfg_ev_ri_el_vu_id_fk_index` (`ev_ri-el_vu_id-fk`(191)),';
$out[] = '  KEY `ev_riesgo_cfg_ev_ri_el_vu_cod_fk_index` (`ev_ri-el_vu_cod-fk`(191))';
$out[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
$out[] = '';
$out[] = '-- 2) Ajustes de esquema para instalaciones donde la tabla ya existía incompleta';
$out[] = "SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ev_riesgo_cfg' AND COLUMN_NAME = 'ev_ri-ev_lu_id-fk') = 0, 'ALTER TABLE `ev_riesgo_cfg` ADD COLUMN `ev_ri-ev_lu_id-fk` TEXT NULL', 'SELECT 1');";
$out[] = 'PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;';
$out[] = '';
$out[] = '-- 3) Reemplazar datos';
$out[] = 'DELETE FROM `ev_riesgo_cfg`;';
$out[] = 'DELETE FROM `riesgo_sub2_cat`;';
$out[] = 'DELETE FROM `riesgo_sub1_cat`;';
$out[] = '';
$out[] = trim(buildInsert('riesgo_sub1_cat', ['ri_su_1-id', 'ri_su_1-nomrisn1', 'ri_su_1-rie_id-fk'], $sub1));
$out[] = '';
$out[] = trim(buildInsert('riesgo_sub2_cat', ['ri_su_2-id', 'ri_su_2-ri_su_1_id-fk', 'ri_su_2-nomrisn2', 'ri_su_2-nomrisn3'], $sub2));
$out[] = '';
$out[] = trim(buildInsert(
    'ev_riesgo_cfg',
    [
        'ev_ri-id',
        'ev_ri-el_vu_id-fk',
        'ev_ri-el_vu_cod-fk',
        'ev_ri-rie_id-fk',
        'ev_ri-ri_su_1_id-fk',
        'ev_ri-ri_su_2_id-fk',
        'ev_ri-ri_su_1_nomrisn1-fk',
        'ev_ri-ri_su_2_nomrisn2-fk',
    ],
    $ev
));
$out[] = '';
$out[] = '-- 4) Completar mapeo a ev_lugar_mst (columna ev_ri-ev_lu_id-fk)';
$out[] = 'UPDATE `ev_riesgo_cfg` e';
$out[] = 'LEFT JOIN `ev_lugar_mst` l_by_id ON l_by_id.`ev_lu-id` = e.`ev_ri-el_vu_id-fk`';
$out[] = 'LEFT JOIN `ev_lugar_mst` l_by_cod ON UPPER(TRIM(l_by_cod.`ev_lu-cod`)) = UPPER(TRIM(e.`ev_ri-el_vu_cod-fk`))';
$out[] = 'SET e.`ev_ri-ev_lu_id-fk` = COALESCE(l_by_id.`ev_lu-id`, l_by_cod.`ev_lu-id`);';
$out[] = '';
$out[] = 'SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;';
$out[] = 'COMMIT;';
$out[] = '';
$out[] = '-- Verificación rápida';
$out[] = "SELECT 'riesgo_sub1_cat' AS tabla, COUNT(*) AS total FROM `riesgo_sub1_cat`";
$out[] = "UNION ALL SELECT 'riesgo_sub2_cat', COUNT(*) FROM `riesgo_sub2_cat`";
$out[] = "UNION ALL SELECT 'ev_riesgo_cfg', COUNT(*) FROM `ev_riesgo_cfg`;";

$sql = implode(PHP_EOL, $out) . PHP_EOL;

$targetPath = __DIR__ . '/../SQL/2026-04-28_ev_risk_filters_hosting.sql';
file_put_contents($targetPath, $sql);

echo "SQL generado: {$targetPath}" . PHP_EOL;
echo "Filas exportadas => sub1: " . count($sub1) . ", sub2: " . count($sub2) . ", ev: " . count($ev) . PHP_EOL;
