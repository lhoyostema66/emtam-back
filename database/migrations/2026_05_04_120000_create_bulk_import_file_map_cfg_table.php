<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bulk_import_file_map_cfg')) {
            Schema::create('bulk_import_file_map_cfg', function (Blueprint $table): void {
                $table->id();
                $table->string('file_name', 180)->unique();
                $table->string('destination_table', 120);
                $table->unsignedInteger('upload_order')->default(100);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        $seed = [
            ['ACCION_OPERATIVA.csv', 'accion_operativa_cfg', 10],
            ['ACCION_SET.csv', 'accion_set_cfg', 20],
            ['ACCION_SET_DETALLE.csv', 'accion_set_detalle_cfg', 30],
            ['ACCION_SET_DETALLE_CANAL.csv', 'accion_set_detalle_canal_cfg', 40],
            ['CANAL_COMUNICACION.csv', 'canal_comunicacion_cat', 50],
            ['FASE_ACTIVACION.csv', 'fase_activacion_cat', 60],
            ['GRUPO_OPERATIVO.csv', 'grupo_operativo_cfg', 70],
            ['LUGAR_TIPO.csv', 'lugar_tipo_cat', 80],
            ['NIVEL_ALERTA.csv', 'nivel_alerta_cat', 90],
            ['NIVEL_EMERGENCIA.csv', 'nivel_emergencia_cat', 100],
            ['ROL.csv', 'rol_cat', 110],
            ['TIPO_EMERGENCIA.csv', 'tipo_emergencia_cat', 120],
            ['TIPO_RIESGO.csv', 'tipo_riesgo_cat', 130],
            ['RIESGO.csv', 'riesgo_cat', 140],
            ['RIESGO_SUB1.csv', 'riesgo_sub1_cat', 150],
            ['RIESGO_SUB2.csv', 'riesgo_sub2_cat', 160],
            ['TIPO_RIESGO_NIVEL_ACCION_SET.csv', 'tipo_riesgo_nivel_accion_set_cfg', 170],
            ['RIESGO_NIVEL_ACCION_SET.csv', 'riesgo_nivel_accion_set_cfg', 180],
            ['CRITERIOS_RIESGO_NI_AL.csv', 'criterios_nivel_alerta_cfg', 190],
            ['PERSONA.csv', 'persona_mst', 200],
            ['users.csv', 'users', 205],
            ['PERSONA_ROL.csv', 'persona_rol_cfg', 210],
            ['PERSONA_ROL_GRUPO.csv', 'persona_rol_grupo_cfg', 220],
            ['ELEMENTO_VULN.csv', 'elemento_vuln_mst', 230],
            ['EV_LUGAR.csv', 'ev_lugar_mst', 240],
            ['EV_LUGAR_CONTACTO.csv', 'ev_lugar_contacto_mst', 250],
            ['EV_LUGAR_COORDENADA.csv', 'ev_lugar_coordenada_mst', 260],
            ['EV_RIESGO.csv', 'ev_riesgo_trs', 270],
            ['ACTIVACION_DEL_PLAN.csv', 'activacion_del_plan_trs', 280],
            ['ACTIVACION_NIVEL_HIST.csv', 'activacion_nivel_hist_trs', 290],
            ['ASIGNACION_EN_FUNCIONES.csv', 'asignacion_en_funciones_trs', 300],
            ['CRONOLOGIA_EMERGENCIA.csv', 'cronologia_emergencia_trs', 310],
            ['EJECUCION_ACCION.csv', 'ejecucion_accion_trs', 320],
            ['NOTAS_OPERATIVAS.csv', 'notas_operativas_trs', 330],
            ['NOTIFICACION_CONFIRMACION.csv', 'notificacion_confirmacion_trs', 340],
            ['NOTIFICACION_ENVIO.csv', 'notificacion_envio_trs', 350],
            ['DICCIONARIO_DATOS.csv', 'diccionario_datos_cfg', 360],
            ['INDICE.csv', 'informacion_tablas', 370],
            ['Reglas_Por_Tabla.csv', 'reglas_por_tabla_cfg', 380],
        ];

        foreach ($seed as [$fileName, $destination, $order]) {
            DB::table('bulk_import_file_map_cfg')->updateOrInsert(
                ['file_name' => $fileName],
                [
                    'destination_table' => $destination,
                    'upload_order' => $order,
                    'is_active' => 1,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_import_file_map_cfg');
    }
};
