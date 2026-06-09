<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class AdminTestCenterService
{
    /** @var array<string, string> */
    private array $tenantTimezoneCache = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function catalog(): array
    {
        $tenantId = $this->requireTenantId();
        $tenant = Tenant::query()->where('tenant_id', $tenantId)->first();
        $selectionOptions = $this->resolveRiskLevelOptions($tenantId);
        $seed = $this->resolveScenarioSeed($tenantId, [], $selectionOptions);
        $demoUsers = $this->resolveDemoUsers($tenantId);

        return [
            'mode' => 'sandbox',
            'tenant_id' => $tenantId,
            'readiness' => [
                'available' => $seed['available'],
                'warnings' => $seed['warnings'],
            ],
            'stats' => [
                'active_users' => $this->countActiveUsers($tenantId),
                'active_personnel' => $this->countActivePersonnel($tenantId),
                'risks' => $this->countTenantTable('riesgo_cat', 'rie-tenant_id', $tenantId),
                'alert_levels' => $this->countTenantTable('nivel_alerta_cat', 'ni_al-tenant_id', $tenantId),
                'documents' => Schema::hasTable('tenant_documents')
                    ? (int) DB::table('tenant_documents')->where('tenant_id', $tenantId)->count()
                    : 0,
                'document_links' => Schema::hasTable('tenant_document_links')
                    ? (int) DB::table('tenant_document_links')->where('tenant_id', $tenantId)->count()
                    : 0,
                'notification_channel' => (string) ($tenant?->notifications_channel ?? 'email'),
                'notifications_production_mode' => (bool) ($tenant?->notifications_production_mode ?? false),
                'notifications_email_enabled' => (bool) ($tenant?->notifications_email_enabled ?? false),
                'notifications_sms_enabled' => (bool) ($tenant?->notifications_sms_enabled ?? false),
            ],
            'demo_users' => $demoUsers,
            'recommended_demo_user_ids' => array_values(array_map(
                static fn(array $row): int => (int) ($row['user_id'] ?? 0),
                array_slice($demoUsers, 0, 3)
            )),
            'scenarios' => [
                [
                    'id' => 'critical-flow',
                    'name' => 'critical-flow',
                    'description' => 'Sandbox end-to-end validation for activation, notifications, confirmations, control progress, document view, level change, and finalization.',
                    'steps' => [
                        'activation',
                        'notifications',
                        'confirmations',
                        'control_panel',
                        'document_view',
                        'level_change',
                        'finalization',
                    ],
                ],
            ],
            'selection' => [
                'risks' => $selectionOptions,
                'default' => [
                    'riesgo_id' => $seed['riesgo_id'] ?? null,
                    'nivel_inicial_id' => $seed['nivel_inicial_id'] ?? null,
                    'nivel_cambio_id' => $seed['nivel_cambio_id'] ?? null,
                ],
            ],
            'seed' => [
                'riesgo_id' => $seed['riesgo_id'] ?? null,
                'riesgo_nombre' => $seed['riesgo_nombre'] ?? null,
                'nivel_inicial_id' => $seed['nivel_inicial_id'] ?? null,
                'nivel_inicial_nombre' => $seed['nivel_inicial_nombre'] ?? null,
                'nivel_cambio_id' => $seed['nivel_cambio_id'] ?? null,
                'nivel_cambio_nombre' => $seed['nivel_cambio_nombre'] ?? null,
            ],
        ];
    }

    /**
     * @param list<int> $selectedUserIds
     * @param array<string, string|null> $selection
     * @return array<string, mixed>
     */
    public function runCriticalFlow(?User $requestedBy, array $selectedUserIds = [], array $selection = []): array
    {
        $tenantId = $this->requireTenantId();
        $tenant = Tenant::query()->where('tenant_id', $tenantId)->first();
        $selectionOptions = $this->resolveRiskLevelOptions($tenantId);
        $seed = $this->resolveScenarioSeed($tenantId, $selection, $selectionOptions);
        $startedAt = $this->tenantNow($tenantId);

        if (!($seed['available'] ?? false)) {
            throw new RuntimeException(implode(' ', $seed['warnings'] ?? ['Sandbox not ready for execution.']));
        }

        $resolvedDemoUsers = $this->resolveSelectedDemoUsers($tenantId, $selectedUserIds);
        $actor = $this->resolveActor($tenantId, $resolvedDemoUsers, $requestedBy);
        $steps = [];
        $warnings = [];

        DB::beginTransaction();
        try {
            $activationId = 'ACPL-' . Str::uuid()->toString();
            $activationAt = $this->tenantNow($tenantId);
            $actionSetIds = $this->getActionSets($tenantId, (string) $seed['riesgo_id'], (string) $seed['nivel_inicial_id']);
            $actionSetId = $actionSetIds[0] ?? null;

            if ($actionSetId === null) {
                throw new RuntimeException('No se encontró action set para el flujo sandbox.');
            }

            $this->insertActivation($tenantId, $activationId, $seed, $actor, $activationAt);
            $this->insertInitialLevel($tenantId, $activationId, (string) $seed['nivel_inicial_id'], $actionSetId, $actor, $activationAt);
            $hydration = $this->hydrateExecutionsForActionSet(
                $tenantId,
                $activationId,
                $actionSetIds,
                (string) ($actor['per_id'] ?? ''),
                $activationAt->toDateTimeString()
            );

            $steps[] = [
                'key' => 'activation',
                'status' => ($hydration['ejecucion_count'] ?? 0) > 0 ? 'passed' : 'warning',
                'detail' => 'Se generó una activación sandbox con sus acciones operativas.',
                'metrics' => [
                    'activation_id' => $activationId,
                    'scenario' => 'ACTIVACION',
                    'action_set_id' => $actionSetId,
                    'execution_rows' => (int) ($hydration['ejecucion_count'] ?? 0),
                    'unassigned_actions' => count($hydration['unassigned_actions'] ?? []),
                ],
                'warnings' => array_values($hydration['warnings'] ?? []),
            ];

            $people = $this->resolveActivationPeople($tenantId, $activationId);
            $selectedPeople = $this->pickPeopleForSimulation($people, $resolvedDemoUsers);
            $notificationResult = $this->simulateNotifications($tenantId, $activationId, $selectedPeople, $tenant, $activationAt);
            $steps[] = [
                'key' => 'notifications',
                'status' => ($notificationResult['sent_count'] ?? 0) > 0 ? 'passed' : 'warning',
                'detail' => 'Se simularon notificaciones sin usar canales externos reales.',
                'metrics' => [
                    'recipients' => (int) ($notificationResult['recipient_count'] ?? 0),
                    'logged_notifications' => (int) ($notificationResult['sent_count'] ?? 0),
                    'channel' => (string) ($notificationResult['channel'] ?? 'email'),
                    'production_mode' => (bool) ($notificationResult['production_mode'] ?? false),
                    'email_enabled' => (bool) ($notificationResult['email_enabled'] ?? false),
                    'sms_enabled' => (bool) ($notificationResult['sms_enabled'] ?? false),
                ],
                'warnings' => array_values($notificationResult['warnings'] ?? []),
            ];

            $confirmationResult = $this->simulateConfirmations($tenantId, $activationId, $notificationResult['notifications'] ?? [], $selectedPeople);
            $steps[] = [
                'key' => 'confirmations',
                'status' => ($confirmationResult['confirmed_people'] ?? 0) > 0 ? 'passed' : 'warning',
                'detail' => 'Se registraron confirmaciones sandbox para usuarios demo vinculados al flujo.',
                'metrics' => [
                    'confirmed_people' => (int) ($confirmationResult['confirmed_people'] ?? 0),
                    'updated_actions' => (int) ($confirmationResult['updated_actions'] ?? 0),
                    'confirmation_logs' => (int) ($confirmationResult['confirmation_logs'] ?? 0),
                ],
                'warnings' => array_values($confirmationResult['warnings'] ?? []),
            ];

            $controlSnapshot = $this->buildControlSnapshot($tenantId, $activationId);
            $steps[] = [
                'key' => 'control_panel',
                'status' => ($controlSnapshot['overall_total'] ?? 0) > 0 ? 'passed' : 'warning',
                'detail' => 'Se calculó el estado esperado del panel de control después de las confirmaciones.',
                'metrics' => $controlSnapshot,
                'warnings' => [],
            ];

            $documentResult = $this->simulateDocumentView($tenantId, $activationId, $requestedBy);
            $steps[] = [
                'key' => 'document_view',
                'status' => (string) ($documentResult['status'] ?? 'warning'),
                'detail' => (string) ($documentResult['detail'] ?? 'No se encontraron documentos o enlaces para simular visualización.'),
                'metrics' => $documentResult['metrics'] ?? [],
                'warnings' => $documentResult['warnings'] ?? [],
            ];

            $levelChangeResult = $this->simulateLevelChange($tenantId, $activationId, $seed, $actor, $activationAt);
            $steps[] = [
                'key' => 'level_change',
                'status' => (string) ($levelChangeResult['status'] ?? 'warning'),
                'detail' => (string) ($levelChangeResult['detail'] ?? 'No fue posible simular cambio de nivel.'),
                'metrics' => $levelChangeResult['metrics'] ?? [],
                'warnings' => $levelChangeResult['warnings'] ?? [],
            ];

            $finalization = $this->simulateFinalization($tenantId, $activationId, $activationAt);
            $steps[] = [
                'key' => 'finalization',
                'status' => 'passed',
                'detail' => 'Se cerró el plan sandbox y se dejaron los niveles en estado finalizado.',
                'metrics' => $finalization,
                'warnings' => [],
            ];

            $warnings = array_values(array_filter(array_merge(
                $warnings,
                $seed['warnings'] ?? [],
                ...array_map(static fn(array $step): array => array_values($step['warnings'] ?? []), $steps)
            )));

            // Sandbox safety: everything runs in a transaction and is reverted before responding.
            DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $finishedAt = $this->tenantNow($tenantId);
        $summary = $this->buildSummary($steps, $warnings, $startedAt, $finishedAt);

        return [
            'mode' => 'sandbox',
            'scenario_id' => 'critical-flow',
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => $finishedAt->toDateTimeString(),
            'status' => $summary['status'],
            'summary' => $summary,
            'context' => [
                'tenant_id' => $tenantId,
                'actor' => $actor,
                'riesgo_id' => $seed['riesgo_id'] ?? null,
                'riesgo_nombre' => $seed['riesgo_nombre'] ?? null,
                'nivel_inicial_id' => $seed['nivel_inicial_id'] ?? null,
                'nivel_inicial_nombre' => $seed['nivel_inicial_nombre'] ?? null,
                'nivel_cambio_id' => $seed['nivel_cambio_id'] ?? null,
                'nivel_cambio_nombre' => $seed['nivel_cambio_nombre'] ?? null,
                'selected_demo_users' => array_values(array_map(
                    static fn(array $row): array => [
                        'user_id' => (int) ($row['user_id'] ?? 0),
                        'name' => (string) ($row['name'] ?? ''),
                        'perfil' => (string) ($row['perfil'] ?? ''),
                        'persona_id' => $row['persona_id'] ?? null,
                    ],
                    $resolvedDemoUsers
                )),
            ],
            'warnings' => $warnings,
            'steps' => $steps,
            'rollback_applied' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function discoverScenarioSeed(string $tenantId): array
    {
        return $this->resolveScenarioSeed($tenantId, [], $this->resolveRiskLevelOptions($tenantId));
    }

    /**
     * @param array<string, string|null> $selection
     * @param array<int, array<string, mixed>>|null $selectionOptions
     * @return array<string, mixed>
     */
    private function resolveScenarioSeed(string $tenantId, array $selection = [], ?array $selectionOptions = null): array
    {
        $warnings = [];
        $requiredTables = [
            'activacion_del_plan_trs',
            'activacion_nivel_hist_trs',
            'accion_set_detalle_cfg',
            'persona_rol_cfg',
            'persona_rol_grupo_cfg',
            'persona_mst',
            'riesgo_cat',
            'nivel_alerta_cat',
            'tipo_emergencia_cat',
        ];

        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                $warnings[] = 'Falta la tabla requerida: ' . $table;
            }
        }

        if (!empty($warnings)) {
            return [
                'available' => false,
                'warnings' => $warnings,
            ];
        }

        $tipoEmergencia = $this->resolveEmergencyType($tenantId);
        if ($tipoEmergencia === null) {
            return [
                'available' => false,
                'warnings' => ['No se encontró un tipo de emergencia disponible para el sandbox.'],
            ];
        }

        $selectionOptions ??= $this->resolveRiskLevelOptions($tenantId);
        $selectedRiskId = trim((string) ($selection['riesgo_id'] ?? ''));
        $selectedInitialLevelId = trim((string) ($selection['nivel_inicial_id'] ?? ''));
        $selectedTargetLevelId = trim((string) ($selection['nivel_cambio_id'] ?? ''));

        if ($selectedRiskId !== '' || $selectedInitialLevelId !== '' || $selectedTargetLevelId !== '') {
            return $this->buildSelectedScenarioSeed(
                $tipoEmergencia,
                $selectionOptions,
                $selectedRiskId,
                $selectedInitialLevelId,
                $selectedTargetLevelId
            );
        }

        foreach ($selectionOptions as $riskOption) {
            $levels = array_values($riskOption['levels'] ?? []);
            $initial = $levels[0] ?? null;
            if (!is_array($initial)) {
                continue;
            }
            $targets = array_values($initial['target_levels'] ?? []);
            $target = $targets[0] ?? null;

            return [
                'available' => true,
                'warnings' => $target === null
                    ? ['No se encontró un segundo nivel para validar el cambio de nivel; ese paso quedará en advertencia.']
                    : [],
                'ti_em_id' => (string) ($tipoEmergencia['id'] ?? ''),
                'ti_em_nombre' => (string) ($tipoEmergencia['name'] ?? ''),
                'riesgo_id' => (string) ($riskOption['id'] ?? ''),
                'riesgo_nombre' => (string) ($riskOption['name'] ?? ''),
                'nivel_inicial_id' => (string) ($initial['id'] ?? ''),
                'nivel_inicial_nombre' => (string) ($initial['name'] ?? ''),
                'nivel_cambio_id' => is_array($target) ? ($target['id'] ?? null) : null,
                'nivel_cambio_nombre' => is_array($target) ? ($target['name'] ?? null) : null,
            ];
        }

        return [
            'available' => false,
            'warnings' => ['No se encontró una combinación válida de riesgo, nivel y action set para el sandbox.'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveRiskLevelOptions(string $tenantId): array
    {
        if (!Schema::hasTable('riesgo_cat') || !Schema::hasTable('nivel_alerta_cat')) {
            return [];
        }

        $riskRows = DB::table('riesgo_cat')
            ->when(
                Schema::hasColumn('riesgo_cat', 'rie-tenant_id'),
                static fn($q) => $q->where('rie-tenant_id', $tenantId),
            )
            ->orderBy('rie-id')
            ->get(['rie-id', 'rie-nombre']);

        $levelRows = DB::table('nivel_alerta_cat')
            ->when(
                Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                static fn($q) => $q->where(function ($sub) use ($tenantId): void {
                    $sub->whereNull('ni_al-tenant_id')->orWhere('ni_al-tenant_id', $tenantId);
                }),
            )
            ->orderBy('ni_al-id')
            ->get(['ni_al-id', 'ni_al-nombre']);

        $options = [];
        foreach ($riskRows as $risk) {
            $riskId = trim((string) ($risk->{'rie-id'} ?? ''));
            if ($riskId === '') {
                continue;
            }

            $validLevels = [];
            foreach ($levelRows as $level) {
                $levelId = trim((string) ($level->{'ni_al-id'} ?? ''));
                if ($levelId === '') {
                    continue;
                }
                $actionSetIds = $this->getActionSets($tenantId, $riskId, $levelId);
                if (empty($actionSetIds) || !$this->hasActiveActionSetDetails($tenantId, $actionSetIds)) {
                    continue;
                }

                $validLevels[] = [
                    'id' => $levelId,
                    'name' => trim((string) ($level->{'ni_al-nombre'} ?? '')) ?: $levelId,
                    'action_set_ids' => array_values($actionSetIds),
                ];
            }

            if (empty($validLevels)) {
                continue;
            }

            $levelIds = array_map(static fn(array $level): string => (string) ($level['id'] ?? ''), $validLevels);
            $validLevels = array_values(array_map(static function (array $level) use ($validLevels, $levelIds): array {
                $currentId = (string) ($level['id'] ?? '');
                $targets = [];
                foreach ($validLevels as $candidate) {
                    $candidateId = (string) ($candidate['id'] ?? '');
                    if ($candidateId === '' || $candidateId === $currentId) {
                        continue;
                    }
                    $targets[] = [
                        'id' => $candidateId,
                        'name' => (string) ($candidate['name'] ?? $candidateId),
                    ];
                }
                $level['target_levels'] = $targets;
                $level['has_target_levels'] = count($targets) > 0;
                $level['all_valid_level_ids'] = $levelIds;
                return $level;
            }, $validLevels));

            $options[] = [
                'id' => $riskId,
                'name' => trim((string) ($risk->{'rie-nombre'} ?? '')) ?: $riskId,
                'levels' => $validLevels,
            ];
        }

        return $options;
    }

    /**
     * @param array<string, string> $tipoEmergencia
     * @param array<int, array<string, mixed>> $selectionOptions
     * @return array<string, mixed>
     */
    private function buildSelectedScenarioSeed(
        array $tipoEmergencia,
        array $selectionOptions,
        string $selectedRiskId,
        string $selectedInitialLevelId,
        string $selectedTargetLevelId
    ): array {
        if ($selectedRiskId === '') {
            return [
                'available' => false,
                'warnings' => ['Selecciona un riesgo para ejecutar el sandbox.'],
            ];
        }

        $riskOption = null;
        foreach ($selectionOptions as $option) {
            if (trim((string) ($option['id'] ?? '')) === $selectedRiskId) {
                $riskOption = $option;
                break;
            }
        }

        if (!is_array($riskOption)) {
            return [
                'available' => false,
                'warnings' => ['El riesgo seleccionado no tiene niveles utilizables en sandbox.'],
            ];
        }

        $levels = array_values($riskOption['levels'] ?? []);
        $initial = null;
        if ($selectedInitialLevelId !== '') {
            foreach ($levels as $level) {
                if (trim((string) ($level['id'] ?? '')) === $selectedInitialLevelId) {
                    $initial = $level;
                    break;
                }
            }
        }
        if (!is_array($initial)) {
            $initial = $levels[0] ?? null;
        }

        if (!is_array($initial)) {
            return [
                'available' => false,
                'warnings' => ['El riesgo seleccionado no tiene un nivel inicial utilizable.'],
            ];
        }

        $targets = array_values($initial['target_levels'] ?? []);
        $target = null;
        if ($selectedTargetLevelId !== '') {
            foreach ($targets as $candidate) {
                if (trim((string) ($candidate['id'] ?? '')) === $selectedTargetLevelId) {
                    $target = $candidate;
                    break;
                }
            }
        } elseif (!empty($targets)) {
            $target = $targets[0];
        }

        $warnings = [];
        if ($target === null) {
            $warnings[] = 'No se encontró un segundo nivel para validar el cambio de nivel; ese paso quedará en advertencia.';
        }

        return [
            'available' => true,
            'warnings' => $warnings,
            'ti_em_id' => (string) ($tipoEmergencia['id'] ?? ''),
            'ti_em_nombre' => (string) ($tipoEmergencia['name'] ?? ''),
            'riesgo_id' => (string) ($riskOption['id'] ?? ''),
            'riesgo_nombre' => (string) ($riskOption['name'] ?? ''),
            'nivel_inicial_id' => (string) ($initial['id'] ?? ''),
            'nivel_inicial_nombre' => (string) ($initial['name'] ?? ''),
            'nivel_cambio_id' => is_array($target) ? ($target['id'] ?? null) : null,
            'nivel_cambio_nombre' => is_array($target) ? ($target['name'] ?? null) : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveDemoUsers(string $tenantId): array
    {
        $query = User::query()
            ->where('tenant_id', $tenantId)
            ->orderByRaw("
                CASE LOWER(COALESCE(perfil, ''))
                    WHEN 'director' THEN 0
                    WHEN 'recurso' THEN 1
                    WHEN 'recurso-visor' THEN 2
                    WHEN 'tenant_admin' THEN 3
                    WHEN 'admin' THEN 4
                    ELSE 9
                END ASC
            ")
            ->orderBy('name');

        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        $users = $query
            ->limit(12)
            ->get(['id', 'name', 'email', 'perfil', 'persona_id']);

        $personaIds = array_values(array_filter(array_map(
            static fn(User $user): string => trim((string) ($user->persona_id ?? '')),
            $users->all()
        )));
        $personaNames = $this->resolvePersonaNames($tenantId, $personaIds);
        $roleCounts = $this->resolvePersonaRoleCounts($tenantId, $personaIds);
        $groupCounts = $this->resolvePersonaGroupCounts($tenantId, $personaIds);

        return array_values(array_map(function (User $user) use ($personaNames, $roleCounts, $groupCounts): array {
            $personaId = trim((string) ($user->persona_id ?? ''));
            return [
                'user_id' => (int) $user->id,
                'name' => (string) ($user->name ?? ''),
                'email' => (string) ($user->email ?? ''),
                'perfil' => (string) ($user->perfil ?? ''),
                'persona_id' => $personaId !== '' ? $personaId : null,
                'persona_nombre' => $personaId !== '' ? ($personaNames[$personaId] ?? null) : null,
                'role_count' => $personaId !== '' ? (int) ($roleCounts[$personaId] ?? 0) : 0,
                'group_count' => $personaId !== '' ? (int) ($groupCounts[$personaId] ?? 0) : 0,
            ];
        }, $users->all()));
    }

    /**
     * @param list<int> $selectedUserIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveSelectedDemoUsers(string $tenantId, array $selectedUserIds): array
    {
        $catalogUsers = $this->resolveDemoUsers($tenantId);
        if (empty($selectedUserIds)) {
            return array_slice($catalogUsers, 0, 3);
        }

        $selected = array_values(array_filter($catalogUsers, static function (array $row) use ($selectedUserIds): bool {
            return in_array((int) ($row['user_id'] ?? 0), $selectedUserIds, true);
        }));

        return !empty($selected) ? $selected : array_slice($catalogUsers, 0, 3);
    }

    /**
     * @param array<int, array<string, mixed>> $demoUsers
     * @return array<string, mixed>
     */
    private function resolveActor(string $tenantId, array $demoUsers, ?User $requestedBy): array
    {
        $personaIdCandidates = [];
        foreach ($demoUsers as $demoUser) {
            $personaId = trim((string) ($demoUser['persona_id'] ?? ''));
            if ($personaId !== '') {
                $personaIdCandidates[] = $personaId;
            }
        }

        if ($requestedBy) {
            $requestedPersonaId = trim((string) ($requestedBy->persona_id ?? ''));
            if ($requestedPersonaId !== '') {
                array_unshift($personaIdCandidates, $requestedPersonaId);
            }
        }

        $personaIdCandidates = array_values(array_unique(array_filter($personaIdCandidates)));
        foreach ($personaIdCandidates as $personaId) {
            $role = DB::table('persona_rol_cfg')
                ->when(
                    Schema::hasColumn('persona_rol_cfg', 'pe_ro-tenant_id'),
                    static fn($q) => $q->where('pe_ro-tenant_id', $tenantId),
                )
                ->where('pe_ro-per_id-fk', $personaId)
                ->whereRaw("UPPER(COALESCE(`pe_ro-activo`, 'SI')) <> 'NO'")
                ->orderBy('pe_ro-id')
                ->first();

            if ($role) {
                return [
                    'per_id' => $personaId,
                    'rol_id' => trim((string) ($role->{'pe_ro-rol_id-fk'} ?? '')),
                    'source' => 'demo_user',
                ];
            }
        }

        $fallback = DB::table('persona_rol_cfg')
            ->when(
                Schema::hasColumn('persona_rol_cfg', 'pe_ro-tenant_id'),
                static fn($q) => $q->where('pe_ro-tenant_id', $tenantId),
            )
            ->whereRaw("UPPER(COALESCE(`pe_ro-activo`, 'SI')) <> 'NO'")
            ->orderBy('pe_ro-id')
            ->first();

        if (!$fallback) {
            throw new RuntimeException('No se encontró una combinación per_id/rol_id para ejecutar el sandbox.');
        }

        return [
            'per_id' => trim((string) ($fallback->{'pe_ro-per_id-fk'} ?? '')),
            'rol_id' => trim((string) ($fallback->{'pe_ro-rol_id-fk'} ?? '')),
            'source' => 'fallback',
        ];
    }

    /**
     * @param array<string, mixed> $seed
     * @param array<string, mixed> $actor
     */
    private function insertActivation(string $tenantId, string $activationId, array $seed, array $actor, Carbon $activationAt): void
    {
        DB::table('activacion_del_plan_trs')->insert([
            'ac_de_pl-id' => $activationId,
            'ac_de_pl-tenant_id' => $tenantId,
            'ac_de_pl-ti_em_id-fk' => (string) ($seed['ti_em_id'] ?? ''),
            'ac_de_pl-rie_id-fk' => (string) ($seed['riesgo_id'] ?? ''),
            'ac_de_pl-plan_espec' => 'SANDBOX_TEST_CENTER',
            'ac_de_pl-ni_al_id-fk-inicial' => (string) ($seed['nivel_inicial_id'] ?? ''),
            'ac_de_pl-per_id-fk-activador' => (string) ($actor['per_id'] ?? ''),
            'ac_de_pl-rol_id-fk-activador' => (string) ($actor['rol_id'] ?? ''),
            'ac_de_pl-cargo_declarado' => 'SANDBOX',
            'ac_de_pl-fecha_activac' => $activationAt->toDateString(),
            'ac_de_pl-hora_activac' => $activationAt->toTimeString(),
            'ac_de_pl-estado' => 'ACTIVA',
            'ac_de_pl-mensaje_inic' => 'Sandbox Test Center',
            'ac_de_pl-mensaje_simul' => 'Sandbox Test Center',
            'ac_de_pl-observ' => 'Ejecución transaccional para validación interna.',
        ]);
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function insertInitialLevel(string $tenantId, string $activationId, string $levelId, string $actionSetId, array $actor, Carbon $activationAt): void
    {
        DB::table('activacion_nivel_hist_trs')->insert([
            'ac_ni_hi-id' => 'ACNI-' . Str::uuid()->toString(),
            'ac_ni_hi-tenant_id' => $tenantId,
            'ac_ni_hi-ac_de_pl_id-fk' => $activationId,
            'ac_ni_hi-ni_al_id-fk' => $levelId,
            'ac_ni_hi-ac_se_id-fk' => $actionSetId,
            'ac_ni_hi-fech_ini' => $activationAt->toDateString(),
            'ac_ni_hi-hora_ini' => $activationAt->toTimeString(),
            'ac_ni_hi-fech_fin' => null,
            'ac_ni_hi-hora_fin' => null,
            'ac_ni_hi-nivel_inicial' => 'SI',
            'ac_ni_hi-per_id-fk-registrador' => (string) ($actor['per_id'] ?? ''),
            'ac_ni_hi-rol_id-fk-registrador' => (string) ($actor['rol_id'] ?? ''),
            'ac_ni_hi-fuente_cambio' => 'sandbox',
            'ac_ni_hi-activo' => 'SI',
            'ac_ni_hi-justificacion' => 'Sandbox Test Center',
        ]);
    }

    /**
     * @param list<string> $actionSetIds
     * @return array<string, mixed>
     */
    private function hydrateExecutionsForActionSet(
        string $tenantId,
        string $activationId,
        array $actionSetIds,
        string $delegatorPerId,
        string $now
    ): array {
        $warnings = [];
        $unassignedActions = [];
        $ejecucionCount = 0;
        $personaRolGrupoByRol = [];
        $asignacionByKey = [];

        $detalles = DB::table('accion_set_detalle_cfg')
            ->when(
                Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id'),
                static fn($q) => $q->where('ac_se_de-tenant_id', $tenantId),
            )
            ->whereIn('ac_se_de-ac_se_id-fk', $actionSetIds)
            ->whereRaw("UPPER(COALESCE(`ac_se_de-activo`, 'SI')) <> 'NO'")
            ->orderByRaw("CAST(COALESCE(`ac_se_de-ord_ejec`, '999') AS UNSIGNED) ASC")
            ->orderBy('ac_se_de-id')
            ->get();

        foreach ($detalles as $de) {
            $detalleId = trim((string) ($de->{'ac_se_de-id'} ?? ''));
            $rolId = trim((string) ($de->{'ac_se_de-rol_id-fk'} ?? ''));
            if ($detalleId === '') {
                continue;
            }

            $recipients = [];
            if ($rolId !== '' && Schema::hasTable('persona_rol_grupo_cfg')) {
                if (!array_key_exists($rolId, $personaRolGrupoByRol)) {
                    $personaRolGrupoByRol[$rolId] = DB::table('persona_rol_grupo_cfg')
                        ->when(
                            Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                            static fn($q) => $q->where('pe_ro_gr-tenant_id', $tenantId),
                        )
                        ->where('pe_ro_gr-rol_id-fk', $rolId)
                        ->whereRaw("UPPER(COALESCE(`pe_ro_gr-activo`, 'SI')) <> 'NO'")
                        ->whereNull('pe_ro_gr-fech_fin')
                        ->get();
                }
                $recipients = $this->resolveRoleRecipientsForActivation($personaRolGrupoByRol[$rolId]);
            }

            if (empty($recipients)) {
                $unassignedActions[] = $detalleId;
                DB::table('ejecucion_accion_trs')->insert([
                    'ej_ac-id' => 'EJAC-' . Str::uuid()->toString(),
                    'ej_ac-tenant_id' => $tenantId,
                    'ej_ac-ac_de_pl_id-fk' => $activationId,
                    'ej_ac-gr_op_id-fk' => null,
                    'ej_ac-ac_se_de_id-fk' => $detalleId,
                    'ej_ac-as_en_fu_id-fk' => null,
                    'ej_ac-estado' => 'PENDIENTE',
                    'ej_ac-ts_ini' => $now,
                    'ej_ac-ts_fin' => null,
                    'ej_ac-observ' => 'Sandbox sin destinatario asignado',
                ]);
                $ejecucionCount++;
                continue;
            }

            foreach ($recipients as $recipient) {
                $asignacionId = null;
                if (Schema::hasTable('asignacion_en_funciones_trs')) {
                    $key = trim((string) ($recipient['per_id'] ?? '')) . '|' . trim((string) ($recipient['gr_op_id'] ?? '')) . '|' . trim((string) ($recipient['tipo_asignacion'] ?? ''));
                    $asignacionId = $asignacionByKey[$key] ?? null;
                    if ($asignacionId === null) {
                        $asignacionId = 'ASEF-' . Str::uuid()->toString();
                        DB::table('asignacion_en_funciones_trs')->insert([
                            'as_en_fu-id' => $asignacionId,
                            'as_en_fu-tenant_id' => $tenantId,
                            'as_en_fu-ac_de_pl_id-fk' => $activationId,
                            'as_en_fu-gr_op_id-fk' => $recipient['gr_op_id'],
                            'as_en_fu-per_id-fk' => $recipient['per_id'],
                            'as_en_fu-tipo_asignacion' => $recipient['tipo_asignacion'],
                            'as_en_fu-per_id-fk-delegador' => $delegatorPerId !== '' ? $delegatorPerId : null,
                            'as_en_fu-motivo' => 'SANDBOX_TEST_CENTER',
                            'as_en_fu-ts_ini' => $now,
                            'as_en_fu-ts_fin' => null,
                            'as_en_fu-estado' => 'ACTIVA',
                        ]);
                        $asignacionByKey[$key] = $asignacionId;
                    }
                }

                DB::table('ejecucion_accion_trs')->insert([
                    'ej_ac-id' => 'EJAC-' . Str::uuid()->toString(),
                    'ej_ac-tenant_id' => $tenantId,
                    'ej_ac-ac_de_pl_id-fk' => $activationId,
                    'ej_ac-gr_op_id-fk' => $recipient['gr_op_id'],
                    'ej_ac-ac_se_de_id-fk' => $detalleId,
                    'ej_ac-as_en_fu_id-fk' => $asignacionId,
                    'ej_ac-estado' => 'PENDIENTE',
                    'ej_ac-ts_ini' => $now,
                    'ej_ac-ts_fin' => null,
                    'ej_ac-observ' => 'Sandbox Test Center',
                ]);
                $ejecucionCount++;
            }
        }

        if (empty($detalles->all())) {
            $warnings[] = 'El action set seleccionado no tiene detalles activos.';
        }

        return [
            'ejecucion_count' => $ejecucionCount,
            'unassigned_actions' => array_values(array_unique($unassignedActions)),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveActivationPeople(string $tenantId, string $activationId): array
    {
        if (
            !Schema::hasTable('ejecucion_accion_trs')
            || !Schema::hasTable('asignacion_en_funciones_trs')
            || !Schema::hasTable('persona_mst')
        ) {
            return [];
        }

        $rows = DB::table('ejecucion_accion_trs as ej')
            ->join('asignacion_en_funciones_trs as asg', 'asg.as_en_fu-id', '=', 'ej.ej_ac-as_en_fu_id-fk')
            ->join('persona_mst as p', 'p.per-id', '=', 'asg.as_en_fu-per_id-fk')
            ->where('ej.ej_ac-tenant_id', $tenantId)
            ->where('ej.ej_ac-ac_de_pl_id-fk', $activationId)
            ->get([
                'p.per-id as per_id',
                'p.per-nombre as nombre',
                'p.per-apellido_1 as apellido_1',
                'p.per-apellido_2 as apellido_2',
                'p.per-email as email',
                'p.per-tel_mov as tel_mov',
            ]);

        $people = [];
        foreach ($rows as $row) {
            $perId = trim((string) ($row->per_id ?? ''));
            if ($perId === '') {
                continue;
            }
            $people[$perId] = [
                'per_id' => $perId,
                'nombre' => trim(implode(' ', array_filter([
                    (string) ($row->nombre ?? ''),
                    (string) ($row->apellido_1 ?? ''),
                    (string) ($row->apellido_2 ?? ''),
                ]))) ?: $perId,
                'email' => ($row->email ?? null) !== null ? trim((string) $row->email) : null,
                'tel_mov' => ($row->tel_mov ?? null) !== null ? trim((string) $row->tel_mov) : null,
            ];
        }

        return array_values($people);
    }

    /**
     * @param array<int, array<string, mixed>> $people
     * @param array<int, array<string, mixed>> $demoUsers
     * @return array<int, array<string, mixed>>
     */
    private function pickPeopleForSimulation(array $people, array $demoUsers): array
    {
        $peopleById = [];
        foreach ($people as $person) {
            $perId = trim((string) ($person['per_id'] ?? ''));
            if ($perId !== '') {
                $peopleById[$perId] = $person;
            }
        }

        $selected = [];
        foreach ($demoUsers as $demoUser) {
            $personaId = trim((string) ($demoUser['persona_id'] ?? ''));
            if ($personaId !== '' && isset($peopleById[$personaId])) {
                $selected[] = $peopleById[$personaId];
            }
        }

        if (!empty($selected)) {
            return array_values($selected);
        }

        return array_slice($people, 0, 3);
    }

    /**
     * @param array<int, array<string, mixed>> $selectedPeople
     * @return array<string, mixed>
     */
    private function simulateNotifications(
        string $tenantId,
        string $activationId,
        array $selectedPeople,
        ?Tenant $tenant,
        Carbon $now
    ): array {
        $warnings = [];
        $notifications = [];
        $channel = strtolower(trim((string) ($tenant?->notifications_channel ?? 'email')));
        if ($channel === '') {
            $channel = 'email';
        }

        foreach ($selectedPeople as $person) {
            $notificationId = 'NOEN-' . Str::uuid()->toString();
            if (Schema::hasTable('notificacion_envio_trs')) {
                $insert = [
                    'no_en-id' => $notificationId,
                    'no_en-tenant_id' => $tenantId,
                    'no_en-ac_de_pl_id-fk' => $activationId,
                    'no_en-per_id-fk' => $person['per_id'],
                    'no_en-gr_op_id-fk' => null,
                    'no_en-rol_id-fk' => null,
                    'no_en-ca_co_id-fk' => null,
                    'no_en-mensaje' => 'Sandbox notification for critical flow validation',
                    'no_en-ts' => $now->toDateTimeString(),
                    'no_en-estado' => 'SIMULADO',
                    'no_en-num_de_intento' => '0',
                ];
                if (Schema::hasColumn('notificacion_envio_trs', 'no_en-modo')) {
                    $insert['no_en-modo'] = 'SANDBOX';
                }
                DB::table('notificacion_envio_trs')->insert($insert);
            } else {
                $warnings[] = 'La tabla notificacion_envio_trs no existe; la notificación solo se validó a nivel lógico.';
            }

            $notifications[] = [
                'notification_id' => $notificationId,
                'per_id' => (string) ($person['per_id'] ?? ''),
            ];
        }

        if (empty($selectedPeople)) {
            $warnings[] = 'No se encontraron destinatarios para la simulación de notificaciones.';
        }

        return [
            'recipient_count' => count($selectedPeople),
            'sent_count' => count($notifications),
            'channel' => $channel,
            'production_mode' => (bool) ($tenant?->notifications_production_mode ?? false),
            'email_enabled' => (bool) ($tenant?->notifications_email_enabled ?? false),
            'sms_enabled' => (bool) ($tenant?->notifications_sms_enabled ?? false),
            'notifications' => $notifications,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $notifications
     * @param array<int, array<string, mixed>> $selectedPeople
     * @return array<string, mixed>
     */
    private function simulateConfirmations(string $tenantId, string $activationId, array $notifications, array $selectedPeople): array
    {
        $warnings = [];
        $updatedActions = 0;
        $confirmationLogs = 0;
        $confirmedPeople = 0;
        $notificationByPerson = [];

        foreach ($notifications as $notification) {
            $perId = trim((string) ($notification['per_id'] ?? ''));
            if ($perId !== '') {
                $notificationByPerson[$perId] = (string) ($notification['notification_id'] ?? '');
            }
        }

        foreach ($selectedPeople as $person) {
            $perId = trim((string) ($person['per_id'] ?? ''));
            if ($perId === '') {
                continue;
            }

            $updated = DB::table('ejecucion_accion_trs as ej')
                ->join('asignacion_en_funciones_trs as asg', 'asg.as_en_fu-id', '=', 'ej.ej_ac-as_en_fu_id-fk')
                ->where('ej.ej_ac-tenant_id', $tenantId)
                ->where('ej.ej_ac-ac_de_pl_id-fk', $activationId)
                ->where('asg.as_en_fu-per_id-fk', $perId)
                ->whereRaw("UPPER(COALESCE(`ej`.`ej_ac-estado`, '')) <> 'CONFIRMADO'")
                ->update([
                    'ej_ac-estado' => 'CONFIRMADO',
                ]);

            if ($updated > 0) {
                $updatedActions += (int) $updated;
                $confirmedPeople++;
            }

            if (Schema::hasTable('notificacion_confirmacion_trs')) {
                $payload = [];
                if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-id')) {
                    $payload['no_co-id'] = 'NOCO-' . Str::uuid()->toString();
                }
                if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-tenant_id')) {
                    $payload['no_co-tenant_id'] = $tenantId;
                }
                if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-no_en_id-fk')) {
                    $payload['no_co-no_en_id-fk'] = $notificationByPerson[$perId] ?? ('NOEN-' . Str::uuid()->toString());
                }
                if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-confirmado')) {
                    $payload['no_co-confirmado'] = 'SI';
                }
                if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-ts')) {
                    $payload['no_co-ts'] = $this->tenantNowDateTime($tenantId);
                }
                if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-respuesta')) {
                    $payload['no_co-respuesta'] = 'CONFIRMADO_SANDBOX';
                }
                if (!empty($payload)) {
                    DB::table('notificacion_confirmacion_trs')->insert($payload);
                    $confirmationLogs++;
                }
            } else {
                $warnings[] = 'La tabla notificacion_confirmacion_trs no existe; la confirmación solo actualizó ejecuciones.';
            }
        }

        if ($confirmedPeople === 0) {
            $warnings[] = 'No se pudo confirmar a ningún usuario demo dentro del flujo seleccionado.';
        }

        return [
            'confirmed_people' => $confirmedPeople,
            'updated_actions' => $updatedActions,
            'confirmation_logs' => $confirmationLogs,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function buildControlSnapshot(string $tenantId, string $activationId): array
    {
        $rows = DB::table('ejecucion_accion_trs')
            ->where('ej_ac-tenant_id', $tenantId)
            ->where('ej_ac-ac_de_pl_id-fk', $activationId)
            ->get(['ej_ac-gr_op_id-fk', 'ej_ac-estado', 'ej_ac-ts_fin']);

        $overallTotal = 0;
        $overallDone = 0;
        $groups = [];

        foreach ($rows as $row) {
            $overallTotal++;
            $groupId = trim((string) ($row->{'ej_ac-gr_op_id-fk'} ?? ''));
            if ($groupId !== '') {
                $groups[$groupId] = true;
            }
            $estado = strtoupper(trim((string) ($row->{'ej_ac-estado'} ?? '')));
            $done = $estado === 'REALIZADA' || $estado === 'REALIZADO' || $estado === 'CONFIRMADO' || (string) ($row->{'ej_ac-ts_fin'} ?? '') !== '';
            if ($done) {
                $overallDone++;
            }
        }

        return [
            'groups' => count($groups),
            'overall_total' => $overallTotal,
            'overall_done' => $overallDone,
            'overall_pending' => max(0, $overallTotal - $overallDone),
            'overall_percent' => $overallTotal > 0 ? round(($overallDone / $overallTotal) * 100, 2) : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simulateDocumentView(string $tenantId, string $activationId, ?User $requestedBy): array
    {
        $doc = null;
        if (Schema::hasTable('tenant_documents')) {
            $doc = DB::table('tenant_documents')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->first(['id', 'name', 'folder_id']);
        }

        if ($doc) {
            $this->auditLogger->logForUser($requestedBy, $tenantId, null, [
                'plan_id' => $activationId,
                'event_type' => 'document_viewed',
                'module' => 'documents',
                'entity_id' => (string) ($doc->id ?? ''),
                'entity_type' => 'tenant_documents',
                'new_value' => [
                    'document_name' => (string) ($doc->name ?? ''),
                    'folder_id' => isset($doc->folder_id) ? (string) $doc->folder_id : null,
                    'source' => 'FILE',
                ],
            ]);

            return [
                'status' => 'passed',
                'detail' => 'Se simuló la visualización de un documento del tenant.',
                'metrics' => [
                    'document_id' => (int) ($doc->id ?? 0),
                    'document_name' => (string) ($doc->name ?? ''),
                    'source' => 'FILE',
                ],
                'warnings' => [],
            ];
        }

        if (Schema::hasTable('tenant_document_links')) {
            $link = DB::table('tenant_document_links')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->first(['id', 'title', 'url', 'folder_id']);
            if ($link) {
                $this->auditLogger->logForUser($requestedBy, $tenantId, null, [
                    'plan_id' => $activationId,
                    'event_type' => 'document_viewed',
                    'module' => 'documents',
                    'entity_id' => (string) ($link->id ?? ''),
                    'entity_type' => 'tenant_document_links',
                    'new_value' => [
                        'document_name' => (string) ($link->title ?? ''),
                        'document_url' => (string) ($link->url ?? ''),
                        'folder_id' => isset($link->folder_id) ? (string) $link->folder_id : null,
                        'source' => 'LINK',
                    ],
                ]);

                return [
                    'status' => 'passed',
                    'detail' => 'Se simuló la visualización de un enlace documental del tenant.',
                    'metrics' => [
                        'link_id' => (int) ($link->id ?? 0),
                        'document_name' => (string) ($link->title ?? ''),
                        'source' => 'LINK',
                    ],
                    'warnings' => [],
                ];
            }
        }

        return [
            'status' => 'warning',
            'detail' => 'No hay documentos ni enlaces configurados para validar la visualización.',
            'metrics' => [],
            'warnings' => ['Agrega al menos un documento o enlace al repositorio del tenant para validar este paso.'],
        ];
    }

    /**
     * @param array<string, mixed> $seed
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    private function simulateLevelChange(string $tenantId, string $activationId, array $seed, array $actor, Carbon $now): array
    {
        $newLevelId = trim((string) ($seed['nivel_cambio_id'] ?? ''));
        if ($newLevelId === '') {
            return [
                'status' => 'warning',
                'detail' => 'No existe un segundo nivel disponible para validar el cambio de nivel.',
                'metrics' => [],
                'warnings' => ['Configura al menos dos niveles utilizables para el mismo riesgo si deseas validar este paso.'],
            ];
        }

        DB::table('activacion_nivel_hist_trs')
            ->where('ac_ni_hi-tenant_id', $tenantId)
            ->where('ac_ni_hi-ac_de_pl_id-fk', $activationId)
            ->update([
                'ac_ni_hi-activo' => 'NO',
                'ac_ni_hi-fech_fin' => $now->toDateString(),
                'ac_ni_hi-hora_fin' => $now->toTimeString(),
            ]);

        $actionSetIds = $this->getActionSets($tenantId, (string) ($seed['riesgo_id'] ?? ''), $newLevelId);
        $actionSetId = $actionSetIds[0] ?? null;
        if ($actionSetId === null) {
            return [
                'status' => 'warning',
                'detail' => 'El nivel alterno no tiene action set resoluble en sandbox.',
                'metrics' => [
                    'new_level_id' => $newLevelId,
                ],
                'warnings' => ['Revisa el mapeo de riesgo/nivel/action set para el segundo nivel.'],
            ];
        }

        DB::table('activacion_nivel_hist_trs')->insert([
            'ac_ni_hi-id' => 'ACNI-' . Str::uuid()->toString(),
            'ac_ni_hi-tenant_id' => $tenantId,
            'ac_ni_hi-ac_de_pl_id-fk' => $activationId,
            'ac_ni_hi-ni_al_id-fk' => $newLevelId,
            'ac_ni_hi-ac_se_id-fk' => $actionSetId,
            'ac_ni_hi-fech_ini' => $now->toDateString(),
            'ac_ni_hi-hora_ini' => $now->toTimeString(),
            'ac_ni_hi-fech_fin' => null,
            'ac_ni_hi-hora_fin' => null,
            'ac_ni_hi-nivel_inicial' => 'NO',
            'ac_ni_hi-per_id-fk-registrador' => (string) ($actor['per_id'] ?? ''),
            'ac_ni_hi-rol_id-fk-registrador' => (string) ($actor['rol_id'] ?? ''),
            'ac_ni_hi-fuente_cambio' => 'sandbox',
            'ac_ni_hi-activo' => 'SI',
            'ac_ni_hi-justificacion' => 'Sandbox Test Center',
        ]);

        $hydration = $this->hydrateExecutionsForActionSet(
            $tenantId,
            $activationId,
            $actionSetIds,
            (string) ($actor['per_id'] ?? ''),
            $this->tenantNowDateTime($tenantId)
        );

        return [
            'status' => 'passed',
            'detail' => 'Se simuló el cambio de nivel y se regeneraron acciones del nuevo action set.',
            'metrics' => [
                'new_level_id' => $newLevelId,
                'new_level_name' => (string) ($seed['nivel_cambio_nombre'] ?? $newLevelId),
                'action_set_id' => $actionSetId,
                'actions_created' => (int) ($hydration['ejecucion_count'] ?? 0),
                'unassigned_actions' => count($hydration['unassigned_actions'] ?? []),
            ],
            'warnings' => $hydration['warnings'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simulateFinalization(string $tenantId, string $activationId, Carbon $now): array
    {
        $closedLevels = DB::table('activacion_nivel_hist_trs')
            ->where('ac_ni_hi-tenant_id', $tenantId)
            ->where('ac_ni_hi-ac_de_pl_id-fk', $activationId)
            ->update([
                'ac_ni_hi-fech_fin' => $now->toDateString(),
                'ac_ni_hi-hora_fin' => $now->toTimeString(),
                'ac_ni_hi-activo' => 'NO',
            ]);

        DB::table('activacion_del_plan_trs')
            ->where('ac_de_pl-tenant_id', $tenantId)
            ->where('ac_de_pl-id', $activationId)
            ->update([
                'ac_de_pl-estado' => 'FINALIZADA',
            ]);

        return [
            'closed_levels' => (int) $closedLevels,
            'final_status' => 'FINALIZADA',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private function buildSummary(array $steps, array $warnings, Carbon $startedAt, Carbon $finishedAt): array
    {
        $passed = 0;
        $failed = 0;
        $withWarnings = 0;

        foreach ($steps as $step) {
            $status = (string) ($step['status'] ?? '');
            if ($status === 'passed') {
                $passed++;
                continue;
            }
            if ($status === 'failed') {
                $failed++;
                continue;
            }
            $withWarnings++;
        }

        return [
            'steps_total' => count($steps),
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => count($warnings),
            'steps_with_warnings' => $withWarnings,
            'duration_seconds' => max(0, $finishedAt->getTimestamp() - $startedAt->getTimestamp()),
            'status' => $failed > 0 ? 'failed' : (count($warnings) > 0 || $withWarnings > 0 ? 'passed_with_warnings' : 'passed'),
        ];
    }

    private function requireTenantId(): string
    {
        $tenantId = trim((string) ($this->tenantContext->tenantId() ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('Tenant no disponible para el Test Center.');
        }

        return $tenantId;
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveEmergencyType(string $tenantId): ?array
    {
        $row = DB::table('tipo_emergencia_cat')
            ->when(
                Schema::hasColumn('tipo_emergencia_cat', 'ti_em-tenant_id'),
                static fn($q) => $q->where(function ($sub) use ($tenantId): void {
                    $sub->whereNull('ti_em-tenant_id')->orWhere('ti_em-tenant_id', $tenantId);
                }),
            )
            ->when(
                Schema::hasColumn('tipo_emergencia_cat', 'ti_em-activo'),
                static fn($q) => $q->whereRaw("UPPER(COALESCE(`ti_em-activo`, 'SI')) <> 'NO'"),
            )
            ->orderBy('ti_em-id')
            ->first(['ti_em-id', 'ti_em-nombre']);

        if (!$row) {
            return null;
        }

        return [
            'id' => trim((string) ($row->{'ti_em-id'} ?? '')),
            'name' => trim((string) ($row->{'ti_em-nombre'} ?? '')),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function findAlternativeLevel(string $tenantId, string $riesgoId, string $currentLevelId): ?array
    {
        $current = DB::table('nivel_alerta_cat')
            ->when(
                Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                static fn($q) => $q->where(function ($sub) use ($tenantId): void {
                    $sub->whereNull('ni_al-tenant_id')->orWhere('ni_al-tenant_id', $tenantId);
                }),
            )
            ->where('ni_al-id', $currentLevelId)
            ->first(['ni_al-id', 'ni_al-nombre', 'ni_al-ni_em_id-fk']);

        $query = DB::table('nivel_alerta_cat')
            ->when(
                Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                static fn($q) => $q->where(function ($sub) use ($tenantId): void {
                    $sub->whereNull('ni_al-tenant_id')->orWhere('ni_al-tenant_id', $tenantId);
                }),
            )
            ->where('ni_al-id', '<>', $currentLevelId);

        $emergencyId = trim((string) ($current?->{'ni_al-ni_em_id-fk'} ?? ''));
        if ($emergencyId !== '') {
            $query->where('ni_al-ni_em_id-fk', $emergencyId);
        }

        $levels = $query
            ->orderBy('ni_al-id')
            ->get(['ni_al-id', 'ni_al-nombre']);

        foreach ($levels as $level) {
            $levelId = trim((string) ($level->{'ni_al-id'} ?? ''));
            if ($levelId === '') {
                continue;
            }
            $actionSetIds = $this->getActionSets($tenantId, $riesgoId, $levelId);
            if (!empty($actionSetIds) && $this->hasActiveActionSetDetails($tenantId, $actionSetIds)) {
                return [
                    'id' => $levelId,
                    'name' => trim((string) ($level->{'ni_al-nombre'} ?? '')) ?: $levelId,
                ];
            }
        }

        return null;
    }

    /**
     * @param list<string> $actionSetIds
     */
    private function hasActiveActionSetDetails(string $tenantId, array $actionSetIds): bool
    {
        if (empty($actionSetIds)) {
            return false;
        }

        return DB::table('accion_set_detalle_cfg')
            ->when(
                Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id'),
                static fn($q) => $q->where('ac_se_de-tenant_id', $tenantId),
            )
            ->whereIn('ac_se_de-ac_se_id-fk', $actionSetIds)
            ->whereRaw("UPPER(COALESCE(`ac_se_de-activo`, 'SI')) <> 'NO'")
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function getActionSets(string $tenantId, string $riesgoId, string $nivelAlertaId): array
    {
        $targetLevels = [$nivelAlertaId];

        if (Schema::hasTable('nivel_alerta_cat')) {
            $currentLevel = DB::table('nivel_alerta_cat')
                ->when(
                    Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                    static fn($q) => $q->where(function ($sub) use ($tenantId): void {
                        $sub->whereNull('ni_al-tenant_id')->orWhere('ni_al-tenant_id', $tenantId);
                    }),
                )
                ->where('ni_al-id', $nivelAlertaId)
                ->first();

            $emergencyId = $currentLevel?->{'ni_al-ni_em_id-fk'} ?? null;
            if ($emergencyId) {
                $siblings = DB::table('nivel_alerta_cat')
                    ->when(
                        Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                        static fn($q) => $q->where(function ($sub) use ($tenantId): void {
                            $sub->whereNull('ni_al-tenant_id')->orWhere('ni_al-tenant_id', $tenantId);
                        }),
                    )
                    ->where('ni_al-ni_em_id-fk', $emergencyId)
                    ->where('ni_al-id', '<>', $nivelAlertaId)
                    ->pluck('ni_al-id')
                    ->toArray();
                $targetLevels = array_merge($targetLevels, $siblings);
            }
        }

        $actionSetIds = [];

        if (Schema::hasTable('riesgo_nivel_accion_set_cfg')) {
            $mapping = DB::table('riesgo_nivel_accion_set_cfg')
                ->when(
                    Schema::hasColumn('riesgo_nivel_accion_set_cfg', 'ri_ni_ac_se-tenant_id'),
                    static fn($q) => $q->where('ri_ni_ac_se-tenant_id', $tenantId),
                )
                ->where('ri_ni_ac_se-rie_id-fk', $riesgoId)
                ->whereIn('ri_ni_ac_se-ni_al_id-fk', $targetLevels)
                ->whereRaw("UPPER(COALESCE(`ri_ni_ac_se-activo`, 'SI')) <> 'NO'")
                ->orderByRaw("CAST(COALESCE(`ri_ni_ac_se-prioridad`, '999') AS UNSIGNED) ASC")
                ->get();

            foreach ($targetLevels as $levelId) {
                $foundForLevel = false;
                foreach ($mapping as $row) {
                    if (($row->{'ri_ni_ac_se-ni_al_id-fk'} ?? '') !== $levelId) {
                        continue;
                    }
                    $id = trim((string) ($row->{'ri_ni_ac_se-ac_se_id-fk'} ?? ''));
                    if ($id !== '') {
                        $actionSetIds[] = $id;
                        $foundForLevel = true;
                    }
                }
                if ($foundForLevel) {
                    return array_values(array_unique($actionSetIds));
                }
            }
        }

        if (Schema::hasTable('riesgo_cat') && Schema::hasTable('tipo_riesgo_nivel_accion_set_cfg')) {
            $risk = DB::table('riesgo_cat')
                ->when(
                    Schema::hasColumn('riesgo_cat', 'rie-tenant_id'),
                    static fn($q) => $q->where('rie-tenant_id', $tenantId),
                )
                ->where('rie-id', $riesgoId)
                ->first();
            $tipoRiesgoId = trim((string) ($risk?->{'rie-ti_ri_id-fk'} ?? ''));

            if ($tipoRiesgoId !== '') {
                $mapping = DB::table('tipo_riesgo_nivel_accion_set_cfg')
                    ->when(
                        Schema::hasColumn('tipo_riesgo_nivel_accion_set_cfg', 'ti_ri_ni_ac_se-tenant_id'),
                        static fn($q) => $q->where('ti_ri_ni_ac_se-tenant_id', $tenantId),
                    )
                    ->where('ti_ri_ni_ac_se-ti_ri_id-fk', $tipoRiesgoId)
                    ->whereIn('ti_ri_ni_ac_se-ni_al_id-fk', $targetLevels)
                    ->whereRaw("UPPER(COALESCE(`ti_ri_ni_ac_se-activo`, 'SI')) <> 'NO'")
                    ->orderByRaw("CAST(COALESCE(`ti_ri_ni_ac_se-orden`, '999') AS UNSIGNED) ASC")
                    ->get();

                foreach ($targetLevels as $levelId) {
                    $foundForLevel = false;
                    foreach ($mapping as $row) {
                        if (($row->{'ti_ri_ni_ac_se-ni_al_id-fk'} ?? '') !== $levelId) {
                            continue;
                        }
                        $id = trim((string) ($row->{'ti_ri_ni_ac_se-ac_se_id-fk'} ?? ''));
                        if ($id !== '') {
                            $actionSetIds[] = $id;
                            $foundForLevel = true;
                        }
                    }
                    if ($foundForLevel) {
                        return array_values(array_unique($actionSetIds));
                    }
                }
            }
        }

        return array_values(array_unique($actionSetIds));
    }

    /**
     * @param iterable<object> $rawRows
     * @return array<int, array<string, string|null>>
     */
    private function resolveRoleRecipientsForActivation(iterable $rawRows): array
    {
        $destinatariosByGrupo = [];
        foreach ($rawRows as $row) {
            $groupId = trim((string) ($row->{'pe_ro_gr-gr_op_id-fk'} ?? ''));
            $destinatariosByGrupo[$groupId] ??= [];
            $destinatariosByGrupo[$groupId][] = $row;
        }

        $groupIds = array_keys($destinatariosByGrupo);
        $hasMultipleGroups = count($groupIds) > 1;
        $leaderCandidates = [];

        foreach ($destinatariosByGrupo as $groupId => $items) {
            foreach ($items as $item) {
                $tipo = strtoupper(trim((string) ($item->{'pe_ro_gr-tipo_asignacion'} ?? '')));
                if ($tipo !== 'LIDER') {
                    continue;
                }
                $leaderCandidates[] = [
                    'group_id' => $groupId,
                    'order' => (int) trim((string) ($item->{'pe_ro_gr-orden_sust'} ?? '999')),
                    'row_id' => (string) ($item->{'pe_ro_gr-id'} ?? ''),
                ];
            }
        }

        $selectedLeaderGroupId = null;
        if ($hasMultipleGroups && !empty($leaderCandidates)) {
            usort($leaderCandidates, static function (array $a, array $b): int {
                $orderA = (int) ($a['order'] ?? 999);
                $orderB = (int) ($b['order'] ?? 999);
                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }

                return strcmp((string) ($a['row_id'] ?? ''), (string) ($b['row_id'] ?? ''));
            });
            $selectedLeaderGroupId = (string) ($leaderCandidates[0]['group_id'] ?? '');
        }

        $groupsToProcess = $destinatariosByGrupo;
        if ($hasMultipleGroups && $selectedLeaderGroupId !== null && array_key_exists($selectedLeaderGroupId, $destinatariosByGrupo)) {
            $groupsToProcess = [$selectedLeaderGroupId => $destinatariosByGrupo[$selectedLeaderGroupId]];
        }

        $recipients = [];
        foreach ($groupsToProcess as $groupId => $items) {
            usort($items, static function ($a, $b): int {
                $orderA = (int) trim((string) ($a->{'pe_ro_gr-orden_sust'} ?? '999'));
                $orderB = (int) trim((string) ($b->{'pe_ro_gr-orden_sust'} ?? '999'));
                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }

                return strcmp((string) ($a->{'pe_ro_gr-id'} ?? ''), (string) ($b->{'pe_ro_gr-id'} ?? ''));
            });

            $titular = null;
            $lider = null;
            $suplente = null;

            foreach ($items as $item) {
                $tipo = strtoupper(trim((string) ($item->{'pe_ro_gr-tipo_asignacion'} ?? '')));
                if ($tipo !== '' && $tipo !== 'TITULAR' && $tipo !== 'SUPLENTE' && $tipo !== 'LIDER') {
                    continue;
                }
                $perId = trim((string) ($item->{'pe_ro_gr-per_id-fk'} ?? ''));
                if ($perId === '') {
                    continue;
                }
                if ($tipo === 'TITULAR' && $titular === null) {
                    $titular = ['per_id' => $perId, 'gr_op_id' => $groupId !== '' ? $groupId : null, 'tipo_asignacion' => 'TITULAR'];
                    continue;
                }
                if ($tipo === 'LIDER' && $lider === null) {
                    $lider = ['per_id' => $perId, 'gr_op_id' => $groupId !== '' ? $groupId : null, 'tipo_asignacion' => 'LIDER'];
                    continue;
                }
                if (($tipo === 'SUPLENTE' || $tipo === '') && $suplente === null) {
                    $suplente = ['per_id' => $perId, 'gr_op_id' => $groupId !== '' ? $groupId : null, 'tipo_asignacion' => 'SUPLENTE'];
                }
            }

            $selected = $titular ?? $lider ?? $suplente;
            if ($selected !== null) {
                $recipients[] = $selected;
            }
        }

        $unique = [];
        foreach ($recipients as $recipient) {
            $perId = trim((string) ($recipient['per_id'] ?? ''));
            $groupId = trim((string) ($recipient['gr_op_id'] ?? ''));
            $tipo = strtoupper(trim((string) ($recipient['tipo_asignacion'] ?? 'SUPLENTE')));
            if ($perId === '') {
                continue;
            }
            if ($tipo !== 'TITULAR' && $tipo !== 'LIDER') {
                $tipo = 'SUPLENTE';
            }
            $key = $perId . '|' . $groupId . '|' . $tipo;
            $unique[$key] = [
                'per_id' => $perId,
                'gr_op_id' => $groupId !== '' ? $groupId : null,
                'tipo_asignacion' => $tipo,
            ];
        }

        return array_values($unique);
    }

    /**
     * @param list<string> $personaIds
     * @return array<string, string>
     */
    private function resolvePersonaNames(string $tenantId, array $personaIds): array
    {
        if (empty($personaIds) || !Schema::hasTable('persona_mst')) {
            return [];
        }

        $rows = DB::table('persona_mst')
            ->when(
                Schema::hasColumn('persona_mst', 'per-tenant_id'),
                static fn($q) => $q->where('per-tenant_id', $tenantId),
            )
            ->whereIn('per-id', $personaIds)
            ->get(['per-id', 'per-nombre', 'per-apellido_1', 'per-apellido_2']);

        $names = [];
        foreach ($rows as $row) {
            $perId = trim((string) ($row->{'per-id'} ?? ''));
            if ($perId === '') {
                continue;
            }
            $names[$perId] = trim(implode(' ', array_filter([
                (string) ($row->{'per-nombre'} ?? ''),
                (string) ($row->{'per-apellido_1'} ?? ''),
                (string) ($row->{'per-apellido_2'} ?? ''),
            ]))) ?: $perId;
        }

        return $names;
    }

    /**
     * @param list<string> $personaIds
     * @return array<string, int>
     */
    private function resolvePersonaRoleCounts(string $tenantId, array $personaIds): array
    {
        if (empty($personaIds) || !Schema::hasTable('persona_rol_cfg')) {
            return [];
        }

        $rows = DB::table('persona_rol_cfg')
            ->select('pe_ro-per_id-fk', DB::raw('COUNT(*) as total'))
            ->when(
                Schema::hasColumn('persona_rol_cfg', 'pe_ro-tenant_id'),
                static fn($q) => $q->where('pe_ro-tenant_id', $tenantId),
            )
            ->whereIn('pe_ro-per_id-fk', $personaIds)
            ->whereRaw("UPPER(COALESCE(`pe_ro-activo`, 'SI')) <> 'NO'")
            ->groupBy('pe_ro-per_id-fk')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) ($row->{'pe_ro-per_id-fk'} ?? '')] = (int) ($row->total ?? 0);
        }

        return $counts;
    }

    /**
     * @param list<string> $personaIds
     * @return array<string, int>
     */
    private function resolvePersonaGroupCounts(string $tenantId, array $personaIds): array
    {
        if (empty($personaIds) || !Schema::hasTable('persona_rol_grupo_cfg')) {
            return [];
        }

        $rows = DB::table('persona_rol_grupo_cfg')
            ->select('pe_ro_gr-per_id-fk', DB::raw('COUNT(*) as total'))
            ->when(
                Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                static fn($q) => $q->where('pe_ro_gr-tenant_id', $tenantId),
            )
            ->whereIn('pe_ro_gr-per_id-fk', $personaIds)
            ->whereRaw("UPPER(COALESCE(`pe_ro_gr-activo`, 'SI')) <> 'NO'")
            ->whereNull('pe_ro_gr-fech_fin')
            ->groupBy('pe_ro_gr-per_id-fk')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) ($row->{'pe_ro_gr-per_id-fk'} ?? '')] = (int) ($row->total ?? 0);
        }

        return $counts;
    }

    private function countActiveUsers(string $tenantId): int
    {
        $query = User::query()->where('tenant_id', $tenantId);
        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        return (int) $query->count();
    }

    private function countActivePersonnel(string $tenantId): int
    {
        if (!Schema::hasTable('persona_mst')) {
            return 0;
        }

        $query = DB::table('persona_mst')
            ->when(
                Schema::hasColumn('persona_mst', 'per-tenant_id'),
                static fn($q) => $q->where('per-tenant_id', $tenantId),
            );

        if (Schema::hasColumn('persona_mst', 'per-activo')) {
            $query->whereRaw("UPPER(COALESCE(`per-activo`, 'SI')) <> 'NO'");
        }

        return (int) $query->count();
    }

    private function countTenantTable(string $table, string $tenantColumn, string $tenantId): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        if (Schema::hasColumn($table, $tenantColumn)) {
            $query->where(function ($sub) use ($tenantColumn, $tenantId): void {
                $sub->whereNull($tenantColumn)->orWhere($tenantColumn, $tenantId);
            });
        }

        return (int) $query->count();
    }

    private function tenantNowDateTime(string $tenantId): string
    {
        return $this->tenantNow($tenantId)->toDateTimeString();
    }

    private function tenantNow(string $tenantId): Carbon
    {
        $defaultTimezone = (string) config('app.timezone', 'UTC');
        if ($tenantId === '') {
            return Carbon::now($defaultTimezone);
        }

        if (!isset($this->tenantTimezoneCache[$tenantId])) {
            $timezone = trim((string) (
                Tenant::query()
                    ->where('tenant_id', $tenantId)
                    ->value('timezone')
            ));
            if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
                $timezone = $defaultTimezone;
            }
            $this->tenantTimezoneCache[$tenantId] = $timezone;
        }

        return Carbon::now($this->tenantTimezoneCache[$tenantId]);
    }
}
