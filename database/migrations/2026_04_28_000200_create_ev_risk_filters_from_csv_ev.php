<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTables();
        $this->importData();
    }

    public function down(): void
    {
        Schema::dropIfExists('ev_riesgo_cfg');
        Schema::dropIfExists('riesgo_sub2_cat');
        Schema::dropIfExists('riesgo_sub1_cat');
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('riesgo_sub1_cat')) {
            Schema::create('riesgo_sub1_cat', function (Blueprint $table) {
                $table->string('ri_su_1-id', 191)->primary();
                $table->text('ri_su_1-nomrisn1')->nullable();
                $table->text('ri_su_1-rie_id-fk')->nullable();
                $table->index(['ri_su_1-rie_id-fk']);
            });
        }

        if (! Schema::hasTable('riesgo_sub2_cat')) {
            Schema::create('riesgo_sub2_cat', function (Blueprint $table) {
                $table->string('ri_su_2-id', 191)->primary();
                $table->text('ri_su_2-ri_su_1_id-fk')->nullable();
                $table->text('ri_su_2-nomrisn2')->nullable();
                $table->text('ri_su_2-nomrisn3')->nullable();
                $table->index(['ri_su_2-ri_su_1_id-fk']);
            });
        }

        if (! Schema::hasTable('ev_riesgo_cfg')) {
            Schema::create('ev_riesgo_cfg', function (Blueprint $table) {
                $table->string('ev_ri-id', 191)->primary();
                $table->text('ev_ri-el_vu_id-fk')->nullable();
                $table->text('ev_ri-el_vu_cod-fk')->nullable();
                $table->text('ev_ri-ev_lu_id-fk')->nullable();
                $table->text('ev_ri-rie_id-fk')->nullable();
                $table->text('ev_ri-ri_su_1_id-fk')->nullable();
                $table->text('ev_ri-ri_su_2_id-fk')->nullable();
                $table->text('ev_ri-ri_su_1_nomrisn1-fk')->nullable();
                $table->text('ev_ri-ri_su_2_nomrisn2-fk')->nullable();
                $table->index(['ev_ri-rie_id-fk']);
                $table->index(['ev_ri-ri_su_1_id-fk']);
                $table->index(['ev_ri-ri_su_2_id-fk']);
                $table->index(['ev_ri-ev_lu_id-fk']);
                $table->index(['ev_ri-el_vu_id-fk']);
                $table->index(['ev_ri-el_vu_cod-fk']);
            });
        }
    }

    private function importData(): void
    {
        $sub1Rows = $this->readSheetRows('RIESGO_SUB1.csv');
        if (! empty($sub1Rows)) {
            DB::table('riesgo_sub1_cat')->truncate();
            foreach ($sub1Rows as $row) {
                $id = $this->clean($row['ri_su_1-id'] ?? null);
                if ($id === '') {
                    continue;
                }
                DB::table('riesgo_sub1_cat')->insert([
                    'ri_su_1-id' => $id,
                    'ri_su_1-nomrisn1' => $this->nullable($row['ri_su_1-nomrisn1'] ?? null),
                    'ri_su_1-rie_id-fk' => $this->nullable($row['ri_su_1-rie_id-fk'] ?? null),
                ]);
            }
        }

        $sub2Rows = $this->readSheetRows('RIESGO_SUB2.csv');
        if (! empty($sub2Rows)) {
            DB::table('riesgo_sub2_cat')->truncate();
            foreach ($sub2Rows as $row) {
                $id = $this->clean($row['ri_su_2-id'] ?? null);
                if ($id === '') {
                    continue;
                }
                DB::table('riesgo_sub2_cat')->insert([
                    'ri_su_2-id' => $id,
                    'ri_su_2-ri_su_1_id-fk' => $this->nullable($row['ri_su_2-ri_su_1_id-fk'] ?? null),
                    'ri_su_2-nomrisn2' => $this->nullable($row['ri_su_2-nomrisn2'] ?? null),
                    'ri_su_2-nomrisn3' => $this->nullable($row['ri_su_2-nomrisn3'] ?? null),
                ]);
            }
        }

        $evRows = $this->readSheetRows('EV_RIESGO.csv');
        if (empty($evRows)) {
            return;
        }

        $evById = [];
        $evByCode = [];
        if (Schema::hasTable('ev_lugar_mst')) {
            $rows = DB::table('ev_lugar_mst')->get(['ev_lu-id', 'ev_lu-cod']);
            foreach ($rows as $r) {
                $id = $this->clean($r->{'ev_lu-id'} ?? null);
                $code = $this->clean($r->{'ev_lu-cod'} ?? null);
                if ($id !== '') {
                    $evById[strtoupper($id)] = $id;
                }
                if ($code !== '') {
                    $evByCode[strtoupper($code)] = $id;
                }
            }
        }

        DB::table('ev_riesgo_cfg')->truncate();
        foreach ($evRows as $row) {
            $id = $this->clean($row['ev_ri-id'] ?? null);
            if ($id === '') {
                $id = (string) Str::uuid();
            }

            $rawEvId = $this->clean($row['ev_ri-el_vu_id-fk'] ?? null);
            $rawEvCode = $this->clean($row['ev_ri-el_vu_cod-fk'] ?? null);
            $mappedEvId = null;
            if ($rawEvId !== '') {
                $mappedEvId = $evById[strtoupper($rawEvId)] ?? null;
            }
            if ($mappedEvId === null && $rawEvCode !== '') {
                $mappedEvId = $evByCode[strtoupper($rawEvCode)] ?? null;
            }

            DB::table('ev_riesgo_cfg')->insert([
                'ev_ri-id' => $id,
                'ev_ri-el_vu_id-fk' => $this->nullable($rawEvId),
                'ev_ri-el_vu_cod-fk' => $this->nullable($rawEvCode),
                'ev_ri-ev_lu_id-fk' => $mappedEvId,
                'ev_ri-rie_id-fk' => $this->nullable($row['ev_ri-rie_id-fk'] ?? null),
                'ev_ri-ri_su_1_id-fk' => $this->nullable($row['ev_ri-ri_su_1_id-fk'] ?? null),
                'ev_ri-ri_su_2_id-fk' => $this->nullable($row['ev_ri-ri_su_2_id-fk'] ?? null),
                'ev_ri-ri_su_1_nomrisn1-fk' => $this->nullable($row['ev_ri-ri_su_1_nomrisn1-fk'] ?? null),
                'ev_ri-ri_su_2_nomrisn2-fk' => $this->nullable($row['ev_ri-ri_su_2_nomrisn2-fk'] ?? null),
            ]);
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readSheetRows(string $fileName): array
    {
        $path = $this->resolvePath($fileName);
        if ($path === null) {
            return [];
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();

        $headers = $sheet->rangeToArray("A1:{$highestCol}1", null, true, false)[0] ?? [];
        $headers = array_map(fn ($h) => $this->clean($h), $headers);

        $rows = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $values = $sheet->rangeToArray("A{$row}:{$highestCol}{$row}", null, true, false)[0] ?? [];
            $item = [];
            $isEmpty = true;
            foreach ($headers as $idx => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $this->clean($values[$idx] ?? null);
                if ($value !== '') {
                    $isEmpty = false;
                }
                $item[$header] = $value;
            }
            if (! $isEmpty) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    private function resolvePath(string $fileName): ?string
    {
        $candidates = [
            base_path("../CSV-EV/{$fileName}"),
            base_path("../../CSV-EV/{$fileName}"),
            base_path($fileName),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function clean(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function nullable(mixed $value): ?string
    {
        $clean = $this->clean($value);
        if ($clean === '' || strtoupper($clean) === 'NA') {
            return null;
        }

        return $clean;
    }
};
