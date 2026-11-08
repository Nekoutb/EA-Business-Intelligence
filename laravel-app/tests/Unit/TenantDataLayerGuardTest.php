<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Tenancy\TenantDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Multi-server guard: client data must go through the tenant data layer
 * (ea_tdb() / ea_tconn() / ea_cache() / OnTenantDatabase), never the
 * platform connection directly — otherwise a client hosted on its own
 * server would silently read from or write to the EA database.
 */
class TenantDataLayerGuardTest extends TestCase
{
    /** Tables that belong to the client data layer. */
    private const CLIENT_TABLE = '/(?:DB|Facades\\\\DB)::table\(\s*[\'"](wh_[a-z_]+|tenant_productivity_months|business_insights|pl_commentaries|tenant_budgets|post_retirement_[a-z_]+|line_donation_categories|partner_donation_categories)[\'"]/';

    private const CLIENT_MODELS = [
        'BusinessInsight', 'PLCommentary', 'TenantBudget', 'LineDonationCategory', 'PartnerDonationCategory',
        'PostRetirementBenefits/AssumptionSet', 'PostRetirementBenefits/Cba', 'PostRetirementBenefits/Employee',
        'PostRetirementBenefits/Valuation', 'PostRetirementBenefits/ValuationLine',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_no_client_table_is_queried_on_the_platform_connection(): void
    {
        $hits = [];
        $files = [$this->root() . '/public/_ea_maint.php'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/app'));
        foreach ($it as $f) if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) $files[] = $f->getPathname();
        foreach ($files as $path) {
            foreach (file($path) as $i => $line) {
                if (preg_match(self::CLIENT_TABLE, $line)) $hits[] = str_replace($this->root() . DIRECTORY_SEPARATOR, '', $path) . ':' . ($i + 1);
            }
        }
        $this->assertSame([], $hits, "Use ea_tdb()->table(...) for client tables:\n" . implode("\n", $hits));
    }

    public function test_client_models_live_on_the_tenant_database(): void
    {
        $missing = [];
        foreach (self::CLIENT_MODELS as $m) {
            $src = (string) file_get_contents($this->root() . "/app/Models/{$m}.php");
            if (!str_contains($src, 'OnTenantDatabase')) $missing[] = $m;
        }
        $this->assertSame([], $missing, 'Client-data models must use OnTenantDatabase.');
    }

    public function test_shared_clients_are_not_isolated(): void
    {
        $t = new Tenant();
        $t->setRawAttributes(['id' => 7, 'slug' => 'acme']);
        $this->assertSame('shared', TenantDatabase::location($t));
        $this->assertFalse(TenantDatabase::isolated($t));
        $t->setRawAttributes(['id' => 7, 'slug' => 'acme', 'data_location' => 'bogus']);
        $this->assertSame('shared', TenantDatabase::location($t));
    }
}
