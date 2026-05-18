<?php

namespace App\Services;

final class TenantContext
{
    private ?string $tenantId;

    public function __construct(?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
    }

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }
}
