<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class TenantBulkImportController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function mappings(): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        if (! Schema::hasTable('bulk_import_file_map_cfg')) {
            return response()->json(['message' => 'Missing bulk_import_file_map_cfg table.'], 422);
        }

        $rows = DB::table('bulk_import_file_map_cfg')
            ->orderBy('upload_order')
            ->orderBy('file_name')
            ->get();

        $csvFolder = $this->resolveCsvFolder($tenantId);
        $csvFiles = $this->csvFolderFiles($tenantId);
        $filesByLower = [];
        foreach ($csvFiles as $f) {
            $filesByLower[strtolower($f)] = true;
        }

        $mapped = $rows->map(static function ($row) use ($filesByLower) {
            $fileName = trim((string) ($row->file_name ?? ''));

            return [
                'id' => (int) ($row->id ?? 0),
                'file_name' => $fileName,
                'destination_table' => trim((string) ($row->destination_table ?? '')),
                'upload_order' => (int) ($row->upload_order ?? 0),
                'is_active' => (bool) ($row->is_active ?? false),
                'notes' => $row->notes !== null ? (string) $row->notes : null,
                'exists_in_csv_folder' => isset($filesByLower[strtolower($fileName)]),
            ];
        })->values();

        return response()->json([
            'data' => $mapped,
            'csv_folder_files' => $csvFiles,
            'csv_folder' => [
                'tenant_id' => $tenantId,
                'folder_name' => $csvFolder['folder_name'],
                'relative_path' => $csvFolder['relative_path'],
                'uses_root' => $csvFolder['uses_root'],
            ],
        ]);
    }

    public function syncDefaultMappings(): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }
        if (! Schema::hasTable('bulk_import_file_map_cfg')) {
            return response()->json(['message' => 'Missing bulk_import_file_map_cfg table.'], 422);
        }

        $known = $this->defaultMappings();
        $knownByLower = [];
        foreach ($known as $row) {
            $knownByLower[strtolower($row['file_name'])] = $row;
        }

        $maxOrder = (int) DB::table('bulk_import_file_map_cfg')->max('upload_order');
        $added = 0;
        $updated = 0;
        foreach ($this->csvFolderFiles($tenantId) as $fileName) {
            $default = $knownByLower[strtolower($fileName)] ?? null;
            $destination = $default['destination_table'] ?? $this->inferDestinationTable($fileName);
            if (! is_string($destination) || trim($destination) === '') {
                continue;
            }
            $destination = trim($destination);
            $order = (int) ($default['upload_order'] ?? ($maxOrder + 10));
            if ($order > $maxOrder) {
                $maxOrder = $order;
            }

            $exists = DB::table('bulk_import_file_map_cfg')->whereRaw('LOWER(file_name)=?', [strtolower($fileName)])->first();
            if ($exists === null) {
                DB::table('bulk_import_file_map_cfg')->insert([
                    'file_name' => $fileName,
                    'destination_table' => $destination,
                    'upload_order' => $order,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $added++;
                continue;
            }

            DB::table('bulk_import_file_map_cfg')
                ->where('id', $exists->id)
                ->update([
                    'destination_table' => trim((string) ($exists->destination_table ?? '')) !== '' ? $exists->destination_table : $destination,
                    'upload_order' => (int) ($exists->upload_order ?? 0) > 0 ? (int) $exists->upload_order : $order,
                    'updated_at' => now(),
                ]);
            $updated++;
        }

        return response()->json([
            'message' => 'Mapping sincronizado.',
            'added' => $added,
            'updated' => $updated,
        ]);
    }

    public function upsertMapping(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }
        if (! Schema::hasTable('bulk_import_file_map_cfg')) {
            return response()->json(['message' => 'Missing bulk_import_file_map_cfg table.'], 422);
        }

        $data = $request->validate([
            'file_name' => ['required', 'string', 'max:180'],
            'destination_table' => ['required', 'string', 'max:120'],
            'upload_order' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $fileName = trim((string) $data['file_name']);
        $destination = trim((string) $data['destination_table']);
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $destination)) {
            return response()->json(['message' => 'Nombre de tabla destino inválido.'], 422);
        }

        $row = DB::table('bulk_import_file_map_cfg')->whereRaw('LOWER(file_name)=?', [strtolower($fileName)])->first();
        if ($row === null) {
            DB::table('bulk_import_file_map_cfg')->insert([
                'file_name' => $fileName,
                'destination_table' => $destination,
                'upload_order' => (int) ($data['upload_order'] ?? 100),
                'is_active' => array_key_exists('is_active', $data) ? (int) ((bool) $data['is_active']) : 1,
                'notes' => array_key_exists('notes', $data) ? (string) ($data['notes'] ?? '') : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('bulk_import_file_map_cfg')
                ->where('id', $row->id)
                ->update([
                    'file_name' => $fileName,
                    'destination_table' => $destination,
                    'upload_order' => (int) ($data['upload_order'] ?? ($row->upload_order ?? 100)),
                    'is_active' => array_key_exists('is_active', $data) ? (int) ((bool) $data['is_active']) : (int) ($row->is_active ?? 1),
                    'notes' => array_key_exists('notes', $data) ? (string) ($data['notes'] ?? '') : $row->notes,
                    'updated_at' => now(),
                ]);
        }

        return response()->json(['message' => 'Mapping guardado.']);
    }

    public function import(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }
        if (! Schema::hasTable('bulk_import_file_map_cfg')) {
            return response()->json(['message' => 'Missing bulk_import_file_map_cfg table.'], 422);
        }

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'max:51200'],
            'clear_before_import' => ['nullable', 'boolean'],
        ]);

        $files = collect($request->file('files', []))
            ->filter(static fn ($f) => $f instanceof UploadedFile)
            ->values();
        if ($files->isEmpty()) {
            return response()->json(['message' => 'No se recibieron archivos válidos.'], 422);
        }

        $clearBeforeImport = (bool) ($data['clear_before_import'] ?? false);
        $mappings = DB::table('bulk_import_file_map_cfg')
            ->where('is_active', 1)
            ->orderBy('upload_order')
            ->orderBy('file_name')
            ->get();

        $filesByLowerName = [];
        foreach ($files as $file) {
            $name = basename((string) $file->getClientOriginalName());
            $filesByLowerName[strtolower($name)] = $file;
        }

        $orderedUploads = [];
        foreach ($mappings as $m) {
            $name = strtolower((string) $m->file_name);
            if (! isset($filesByLowerName[$name])) {
                continue;
            }
            $orderedUploads[] = [$m, $filesByLowerName[$name]];
        }

        if ($orderedUploads === []) {
            return response()->json([
                'message' => 'Ningún archivo coincide con el catálogo de mapeo activo.',
            ], 422);
        }

        $clearedTables = [];
        $results = [];
        $errors = [];
        foreach ($orderedUploads as [$map, $file]) {
            $fileName = (string) $map->file_name;
            $table = trim((string) ($map->destination_table ?? ''));
            if ($table === '' || ! preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $errors[] = ['file' => $fileName, 'error' => 'Tabla destino inválida en el catálogo.'];
                continue;
            }
            if (! Schema::hasTable($table)) {
                $errors[] = ['file' => $fileName, 'error' => "La tabla destino [{$table}] no existe."];
                continue;
            }

            try {
                $kind = $this->detectFileKind($file);
                if ($kind === 'xls') {
                    throw new \RuntimeException('Archivo Excel antiguo (.xls) no soportado. Usa CSV o XLSX.');
                }

                $parsed = $kind === 'xlsx'
                    ? $this->parseXlsx($file->getRealPath() ?: $file->getPathname())
                    : $this->parseCsv($file->getRealPath() ?: $file->getPathname());

                $header = $parsed['header'];
                $rows = $parsed['rows'];
                if ($header === [] || $rows === []) {
                    $results[] = [
                        'file' => $fileName,
                        'table' => $table,
                        'file_type' => strtoupper($kind),
                        'inserted' => 0,
                        'skipped' => 0,
                    ];
                    continue;
                }

                $columns = Schema::getColumnListing($table);
                $tenantColumn = $this->tenantColumn($columns);
                $columnByLower = [];
                foreach ($columns as $col) {
                    $columnByLower[strtolower($col)] = $col;
                }

                $columnMap = [];
                foreach ($header as $sourceCol) {
                    $key = strtolower(trim($sourceCol));
                    if ($key === '') {
                        continue;
                    }
                    $dest = $columnByLower[$key] ?? null;
                    if (is_string($dest)) {
                        $columnMap[$sourceCol] = $dest;
                    }
                }
                if ($columnMap === []) {
                    throw new \RuntimeException('No hay columnas compatibles entre archivo y tabla destino.');
                }

                if ($clearBeforeImport && ! isset($clearedTables[$table])) {
                    $this->clearTable($table);
                    $clearedTables[$table] = true;
                }

                $inserted = 0;
                $skipped = 0;
                $batch = [];
                foreach ($rows as $index => $r) {
                    $rowNumber = $index + 2;
                    $payload = [];
                    foreach ($columnMap as $sourceCol => $destCol) {
                        $raw = $r[$sourceCol] ?? null;
                        if (is_string($raw)) {
                            $raw = trim($raw);
                        }
                        $payload[$destCol] = $raw === '' ? null : $raw;
                    }
                    if ($payload === []) {
                        $skipped++;
                        continue;
                    }
                    if ($tenantColumn !== null) {
                        $incomingTenant = trim((string) ($payload[$tenantColumn] ?? ''));
                        if ($incomingTenant !== '' && ! $this->sameTenantValue($incomingTenant, $tenantId)) {
                            throw new \RuntimeException("Tenant incompatible en fila {$rowNumber}. Se esperaba [{$tenantId}] y llegó [{$incomingTenant}].");
                        }
                        $payload[$tenantColumn] = $tenantId;
                    }
                    $batch[] = $payload;
                    if (count($batch) >= 500) {
                        DB::table($table)->insert($batch);
                        $inserted += count($batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    DB::table($table)->insert($batch);
                    $inserted += count($batch);
                }

                $results[] = [
                    'file' => $fileName,
                    'table' => $table,
                    'file_type' => strtoupper($kind),
                    'inserted' => $inserted,
                    'skipped' => $skipped,
                ];
            } catch (Throwable $e) {
                $errors[] = ['file' => $fileName, 'table' => $table, 'error' => $e->getMessage()];
            }
        }

        $summary = [
            'files_processed' => count($results),
            'files_with_errors' => count($errors),
            'rows_inserted' => array_sum(array_map(static fn ($r) => (int) ($r['inserted'] ?? 0), $results)),
        ];

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'bulk_import_executed',
            'module' => 'admin.import',
            'entity_id' => $tenantId,
            'entity_type' => 'bulk_import_file_map_cfg',
            'new_value' => [
                'clear_before_import' => $clearBeforeImport,
                'summary' => $summary,
                'results' => $results,
                'errors' => $errors,
            ],
        ]);

        return response()->json([
            'message' => count($errors) > 0 ? 'Importación finalizada con observaciones.' : 'Importación completada.',
            'summary' => $summary,
            'results' => $results,
            'errors' => $errors,
        ]);
    }

    private function csvFolderFiles(?string $tenantId = null): array
    {
        $context = $this->resolveCsvFolder($tenantId);
        $path = $context['path'];
        if (! is_dir($path)) {
            return [];
        }
        $items = scandir($path);
        if (! is_array($items)) {
            return [];
        }
        $files = [];
        foreach ($items as $item) {
            if (! is_string($item) || $item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            if (! is_file($full)) {
                continue;
            }
            if (! preg_match('/\.(csv|xlsx|xls)$/i', $item)) {
                continue;
            }
            $files[] = $item;
        }
        natcasesort($files);

        return array_values($files);
    }

    /** @return array{path:string,folder_name:?string,relative_path:string,uses_root:bool} */
    private function resolveCsvFolder(?string $tenantId = null): array
    {
        $basePath = base_path('..'.DIRECTORY_SEPARATOR.'CSV');
        $tenantId = trim((string) ($tenantId ?? $this->tenantContext->tenantId() ?? ''));

        if ($tenantId !== '') {
            foreach ($this->tenantFolderCandidates($tenantId) as $candidate) {
                $candidatePath = $basePath.DIRECTORY_SEPARATOR.$candidate;
                if (is_dir($candidatePath)) {
                    return [
                        'path' => $candidatePath,
                        'folder_name' => $candidate,
                        'relative_path' => 'CSV/'.$candidate,
                        'uses_root' => false,
                    ];
                }
            }
        }

        return [
            'path' => $basePath,
            'folder_name' => null,
            'relative_path' => 'CSV',
            'uses_root' => true,
        ];
    }

    /** @return array<int, string> */
    private function tenantFolderCandidates(string $tenantId): array
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            return [];
        }

        $slug = $this->normalizeTenantFolderSegment($tenantId);
        $compact = str_replace('-', '', $slug);
        $candidates = [
            $tenantId,
            str_replace('-', ' ', $slug),
            $slug,
            str_replace('-', '_', $slug),
        ];

        $aliases = [
            'morell' => ['El-Morell', 'El Morell', 'Morell'],
            'elmorell' => ['El-Morell', 'El Morell', 'Morell'],
            'lapoblademafumet' => ['La-Pobla-de-Mafumet', 'La Pobla de Mafumet', 'Pobla-de-Mafumet'],
            'constanti' => ['Constanti', 'Constanti', 'Constanti'],
        ];

        foreach ($aliases[$compact] ?? [] as $alias) {
            $candidates[] = $alias;
        }

        $unique = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            $key = strtolower($candidate);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    private function normalizeTenantFolderSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && trim($converted) !== '') {
                $value = $converted;
            }
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;

        return trim($value, '-');
    }

    private function tenantColumn(array $columns): ?string
    {
        if (in_array('id_tenant', $columns, true)) {
            return 'id_tenant';
        }
        if (in_array('tenant_id', $columns, true)) {
            return 'tenant_id';
        }
        foreach ($columns as $column) {
            if (str_ends_with((string) $column, 'tenant_id')) {
                return (string) $column;
            }
        }

        return null;
    }

    private function sameTenantValue(string $left, string $right): bool
    {
        return $this->normalizeTenantFolderSegment($left) === $this->normalizeTenantFolderSegment($right);
    }

    private function detectFileKind(UploadedFile $file): string
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        $header = '';
        $fh = @fopen($path, 'rb');
        if ($fh !== false) {
            $header = (string) fread($fh, 8);
            fclose($fh);
        }
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        if (str_starts_with($header, "PK\x03\x04") || str_contains($mime, 'spreadsheetml') || $ext === 'xlsx') {
            return 'xlsx';
        }
        if (str_starts_with($header, "\xD0\xCF\x11\xE0") || str_contains($mime, 'ms-excel') || $ext === 'xls') {
            return 'xls';
        }

        return 'csv';
    }

    /**
     * @return array{header: array<int, string>, rows: array<int, array<string, string|null>>}
     */
    private function parseCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('No se pudo leer el archivo CSV.');
        }
        $sample = fgets($fh);
        rewind($fh);
        $delimiter = $this->guessDelimiter((string) ($sample ?: ''));

        $headerRaw = fgetcsv($fh, 0, $delimiter);
        if (! is_array($headerRaw)) {
            fclose($fh);
            throw new \RuntimeException('CSV sin cabecera.');
        }
        $header = [];
        foreach ($headerRaw as $h) {
            $header[] = $this->normalizeHeaderCell((string) $h);
        }

        $rows = [];
        while (($line = fgetcsv($fh, 0, $delimiter)) !== false) {
            if (! is_array($line)) {
                continue;
            }
            $assoc = [];
            $allEmpty = true;
            foreach ($header as $i => $col) {
                if ($col === '') {
                    continue;
                }
                $value = $line[$i] ?? null;
                if (is_string($value)) {
                    $value = $this->normalizeTextCell($value);
                }
                if ($value !== null && (string) $value !== '') {
                    $allEmpty = false;
                }
                $assoc[$col] = $value;
            }
            if ($allEmpty) {
                continue;
            }
            $rows[] = $assoc;
        }
        fclose($fh);

        return ['header' => array_values(array_filter($header, static fn ($h) => $h !== '')), 'rows' => $rows];
    }

    /**
     * @return array{header: array<int, string>, rows: array<int, array<string, string|null>>}
     */
    private function parseXlsx(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $matrix = $sheet->toArray(null, true, true, true);
        if ($matrix === []) {
            return ['header' => [], 'rows' => []];
        }
        $first = reset($matrix);
        if (! is_array($first)) {
            return ['header' => [], 'rows' => []];
        }

        $header = [];
        foreach ($first as $col) {
            $header[] = $this->normalizeHeaderCell((string) $col);
        }

        $rows = [];
        $i = 0;
        foreach ($matrix as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($i === 0) {
                $i++;
                continue;
            }
            $assoc = [];
            $allEmpty = true;
            $j = 0;
            foreach ($header as $colName) {
                $value = array_values($row)[$j] ?? null;
                if ($colName === '') {
                    $j++;
                    continue;
                }
                if (is_string($value)) {
                    $value = $this->normalizeTextCell($value);
                } elseif ($value !== null) {
                    $value = trim((string) $value);
                }
                if ($value !== null && (string) $value !== '') {
                    $allEmpty = false;
                }
                $assoc[$colName] = $value;
                $j++;
            }
            if ($allEmpty) {
                continue;
            }
            $rows[] = $assoc;
        }

        return ['header' => array_values(array_filter($header, static fn ($h) => $h !== '')), 'rows' => $rows];
    }

    private function guessDelimiter(string $line): string
    {
        $candidates = [';', ',', "\t", '|'];
        $best = ';';
        $bestCount = -1;
        foreach ($candidates as $c) {
            $count = substr_count($line, $c);
            if ($count > $bestCount) {
                $best = $c;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function normalizeHeaderCell(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = $this->normalizeTextCell($value);

        return trim($value);
    }

    private function normalizeTextCell(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding') && ! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return trim($value);
    }

    private function clearTable(string $table): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                DB::table($table)->truncate();
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            return;
        }
        DB::table($table)->delete();
    }

    /** @return array<int, array{file_name:string,destination_table:string,upload_order:int}> */
    private function defaultMappings(): array
    {
        return [
            ['file_name' => 'ACCION_OPERATIVA.csv', 'destination_table' => 'accion_operativa_cfg', 'upload_order' => 10],
            ['file_name' => 'ACCION_SET.csv', 'destination_table' => 'accion_set_cfg', 'upload_order' => 20],
            ['file_name' => 'ACCION_SET_DETALLE.csv', 'destination_table' => 'accion_set_detalle_cfg', 'upload_order' => 30],
            ['file_name' => 'ACCION_SET_DETALLE_CANAL.csv', 'destination_table' => 'accion_set_detalle_canal_cfg', 'upload_order' => 40],
            ['file_name' => 'CANAL_COMUNICACION.csv', 'destination_table' => 'canal_comunicacion_cat', 'upload_order' => 50],
            ['file_name' => 'FASE_ACTIVACION.csv', 'destination_table' => 'fase_activacion_cat', 'upload_order' => 60],
            ['file_name' => 'GRUPO_OPERATIVO.csv', 'destination_table' => 'grupo_operativo_cfg', 'upload_order' => 70],
            ['file_name' => 'LUGAR_TIPO.csv', 'destination_table' => 'lugar_tipo_cat', 'upload_order' => 80],
            ['file_name' => 'NIVEL_ALERTA.csv', 'destination_table' => 'nivel_alerta_cat', 'upload_order' => 90],
            ['file_name' => 'NIVEL_EMERGENCIA.csv', 'destination_table' => 'nivel_emergencia_cat', 'upload_order' => 100],
            ['file_name' => 'ROL.csv', 'destination_table' => 'rol_cat', 'upload_order' => 110],
            ['file_name' => 'TIPO_EMERGENCIA.csv', 'destination_table' => 'tipo_emergencia_cat', 'upload_order' => 120],
            ['file_name' => 'TIPO_RIESGO.csv', 'destination_table' => 'tipo_riesgo_cat', 'upload_order' => 130],
            ['file_name' => 'RIESGO.csv', 'destination_table' => 'riesgo_cat', 'upload_order' => 140],
            ['file_name' => 'RIESGO_SUB1.csv', 'destination_table' => 'riesgo_sub1_cat', 'upload_order' => 150],
            ['file_name' => 'RIESGO_SUB2.csv', 'destination_table' => 'riesgo_sub2_cat', 'upload_order' => 160],
            ['file_name' => 'TIPO_RIESGO_NIVEL_ACCION_SET.csv', 'destination_table' => 'tipo_riesgo_nivel_accion_set_cfg', 'upload_order' => 170],
            ['file_name' => 'RIESGO_NIVEL_ACCION_SET.csv', 'destination_table' => 'riesgo_nivel_accion_set_cfg', 'upload_order' => 180],
            ['file_name' => 'CRITERIOS_RIESGO_NI_AL.csv', 'destination_table' => 'criterios_nivel_alerta_cfg', 'upload_order' => 190],
            ['file_name' => 'PERSONA.csv', 'destination_table' => 'persona_mst', 'upload_order' => 200],
            ['file_name' => 'users.csv', 'destination_table' => 'users', 'upload_order' => 205],
            ['file_name' => 'PERSONA_ROL.csv', 'destination_table' => 'persona_rol_cfg', 'upload_order' => 210],
            ['file_name' => 'PERSONA_ROL_GRUPO.csv', 'destination_table' => 'persona_rol_grupo_cfg', 'upload_order' => 220],
            ['file_name' => 'ELEMENTO_VULN.csv', 'destination_table' => 'elemento_vuln_mst', 'upload_order' => 230],
            ['file_name' => 'EV_LUGAR.csv', 'destination_table' => 'ev_lugar_mst', 'upload_order' => 240],
            ['file_name' => 'EV_LUGAR_CONTACTO.csv', 'destination_table' => 'ev_lugar_contacto_mst', 'upload_order' => 250],
            ['file_name' => 'EV_LUGAR_COORDENADA.csv', 'destination_table' => 'ev_lugar_coordenada_mst', 'upload_order' => 260],
            ['file_name' => 'EV_RIESGO.csv', 'destination_table' => 'ev_riesgo_trs', 'upload_order' => 270],
            ['file_name' => 'ACTIVACION_DEL_PLAN.csv', 'destination_table' => 'activacion_del_plan_trs', 'upload_order' => 280],
            ['file_name' => 'ACTIVACION_NIVEL_HIST.csv', 'destination_table' => 'activacion_nivel_hist_trs', 'upload_order' => 290],
            ['file_name' => 'ASIGNACION_EN_FUNCIONES.csv', 'destination_table' => 'asignacion_en_funciones_trs', 'upload_order' => 300],
            ['file_name' => 'CRONOLOGIA_EMERGENCIA.csv', 'destination_table' => 'cronologia_emergencia_trs', 'upload_order' => 310],
            ['file_name' => 'EJECUCION_ACCION.csv', 'destination_table' => 'ejecucion_accion_trs', 'upload_order' => 320],
            ['file_name' => 'NOTAS_OPERATIVAS.csv', 'destination_table' => 'notas_operativas_trs', 'upload_order' => 330],
            ['file_name' => 'NOTIFICACION_CONFIRMACION.csv', 'destination_table' => 'notificacion_confirmacion_trs', 'upload_order' => 340],
            ['file_name' => 'NOTIFICACION_ENVIO.csv', 'destination_table' => 'notificacion_envio_trs', 'upload_order' => 350],
            ['file_name' => 'DICCIONARIO_DATOS.csv', 'destination_table' => 'diccionario_datos_cfg', 'upload_order' => 360],
            ['file_name' => 'INDICE.csv', 'destination_table' => 'informacion_tablas', 'upload_order' => 370],
            ['file_name' => 'Reglas_Por_Tabla.csv', 'destination_table' => 'reglas_por_tabla_cfg', 'upload_order' => 380],
        ];
    }

    private function inferDestinationTable(string $fileName): ?string
    {
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $base), '_'));
        if ($slug === '') {
            return null;
        }

        $candidates = [
            $slug,
            $slug.'_cfg',
            $slug.'_cat',
            $slug.'_mst',
            $slug.'_trs',
        ];

        foreach ($candidates as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }
}
