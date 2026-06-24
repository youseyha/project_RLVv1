<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Branches;
use App\Models\Tenants;
use App\Models\Transaction;
use App\Services\DailyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test daily report generation
     */
    public function test_daily_report_generation(): void
    {
        // Arrange: Create test data
        $tenant = Tenants::factory()->create();
        $branch = Branches::factory()->create(['tenant_id' => $tenant->tenant_id]);
        
        // Create transactions
        Transaction::factory()->count(10)->create([
            'branch_id' => $branch->branch_id,
            'transaction_date' => now()->subDay(),
            'status' => 'completed',
            'total_amount' => 100,
            'tax_amount' => 10,
            'discount_amount' => 5,
        ]);

        // Act: Generate report
        $service = new DailyReportService();
        $report = $service->generateDailyReport(
            tenantId: $tenant->tenant_id,
            branchId: $branch->branch_id,
            date: now()->subDay()->toDateString()
        );

        // Assert: Check results
        $this->assertNotNull($report);
        $this->assertEquals(1000, $report->total_sales); // 10 * 100
        $this->assertEquals(10, $report->transaction_count);
        $this->assertEquals(100, $report->average_transaction);
        $this->assertEquals(100, $report->total_tax); // 10 * 10
        $this->assertEquals(50, $report->total_discount); // 10 * 5
    }
}