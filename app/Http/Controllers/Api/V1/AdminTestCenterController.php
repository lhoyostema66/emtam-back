<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AdminTestCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdminTestCenterController extends Controller
{
    public function __construct(private readonly AdminTestCenterService $testCenterService)
    {
    }

    public function catalog(Request $request): JsonResponse
    {
        return response()->json($this->testCenterService->catalog());
    }

    public function run(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'scenario_id' => ['required', 'string'],
            'demo_user_ids' => ['nullable', 'array'],
            'demo_user_ids.*' => ['integer'],
            'riesgo_id' => ['nullable', 'string'],
            'nivel_inicial_id' => ['nullable', 'string'],
            'nivel_cambio_id' => ['nullable', 'string'],
        ]);

        $scenarioId = trim((string) ($payload['scenario_id'] ?? ''));
        if ($scenarioId !== 'critical-flow') {
            return response()->json(['message' => 'Scenario not supported.'], 422);
        }

        $demoUserIds = array_values(array_map(
            static fn($value): int => (int) $value,
            $payload['demo_user_ids'] ?? []
        ));
        $riesgoId = trim((string) ($payload['riesgo_id'] ?? ''));
        $nivelInicialId = trim((string) ($payload['nivel_inicial_id'] ?? ''));
        $nivelCambioId = trim((string) ($payload['nivel_cambio_id'] ?? ''));

        try {
            return response()->json(
                $this->testCenterService->runCriticalFlow($request->user(), $demoUserIds, [
                    'riesgo_id' => $riesgoId !== '' ? $riesgoId : null,
                    'nivel_inicial_id' => $nivelInicialId !== '' ? $nivelInicialId : null,
                    'nivel_cambio_id' => $nivelCambioId !== '' ? $nivelCambioId : null,
                ])
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
