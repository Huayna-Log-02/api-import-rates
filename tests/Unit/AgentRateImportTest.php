<?php

namespace Tests\Unit;

use App\Imports\AgentRateImport;
use App\Support\AgentRateImportReport;
use PHPUnit\Framework\TestCase;

class AgentRateImportTest extends TestCase
{
    public function test_only_processes_the_agent_tariff_sheet(): void
    {
        $import = new AgentRateImport(new AgentRateImportReport);

        $this->assertSame([
            'TARIFARIO - AGENTES' => $import,
        ], $import->sheets());
    }
}
