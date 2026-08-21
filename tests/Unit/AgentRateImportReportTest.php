<?php

namespace Tests\Unit;

use App\Support\AgentRateImportReport;
use PHPUnit\Framework\TestCase;

class AgentRateImportReportTest extends TestCase
{
    public function test_groups_missing_agent_rows_and_counts_omitted_tariffs(): void
    {
        $report = new AgentRateImportReport;

        $report->addMissingAgent('10479270428', 'CARLOS DEL ROSARIO', [59, 61]);
        $report->addMissingAgent('10479270428', 'CARLOS DEL ROSARIO', [61, 62]);

        $this->assertSame([
            [
                'ruc' => '10479270428',
                'agente' => 'CARLOS DEL ROSARIO',
                'filas_excel' => [59, 61, 62],
                'tarifas_omitidas' => 2,
            ],
        ], $report->missingAgents());
    }

    public function test_counts_imported_tariffs_and_can_be_reset(): void
    {
        $report = new AgentRateImportReport;

        $report->incrementImportedTariffs();
        $report->incrementImportedTariffs();

        $this->assertSame(2, $report->importedTariffs());

        $report->reset();

        $this->assertSame(0, $report->importedTariffs());
        $this->assertSame([], $report->missingAgents());
    }
}
