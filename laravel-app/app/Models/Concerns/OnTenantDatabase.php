<?php

namespace App\Models\Concerns;

use App\Tenancy\TenantDatabase;

/**
 * For models holding CLIENT data (the data layer): they live on the
 * client's own database when it has one, otherwise on the platform
 * database as before. See App\Tenancy\TenantDatabase.
 */
trait OnTenantDatabase
{
    public function getConnectionName()
    {
        return TenantDatabase::connectionName() ?? $this->connection;
    }
}
