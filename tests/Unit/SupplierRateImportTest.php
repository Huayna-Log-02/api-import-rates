<?php

namespace Tests\Unit;

use App\Imports\SupplierRateImport;
use App\Support\SupplierRateImportReport;
use PHPUnit\Framework\TestCase;

class SupplierRateImportTest extends TestCase
{
    public function test_only_processes_the_first_workbook_sheet(): void
    {
        $import = new SupplierRateImport(new SupplierRateImportReport);

        $this->assertSame([0 => $import], $import->sheets());
    }
}
