<?php

namespace Tests\Unit;

use App\Support\SupplierRateImportReport;
use PHPUnit\Framework\TestCase;

class SupplierRateImportReportTest extends TestCase
{
    public function test_groups_missing_clients_and_suppliers(): void
    {
        $report = new SupplierRateImportReport;

        $report->addMissingClient('20501549801', 'FERCO MEDICAL S.A.C.', [4]);
        $report->addMissingClient('20501549801', 'FERCO MEDICAL S.A.C.', [4, 7]);
        $report->addMissingSupplier('20100000001', 'PROVEEDOR FALTANTE', [8]);

        $this->assertSame([
            [
                'ruc' => '20501549801',
                'cliente' => 'FERCO MEDICAL S.A.C.',
                'filas_excel' => [4, 7],
                'tarifas_omitidas' => 2,
            ],
        ], $report->missingClients());

        $this->assertSame([
            [
                'ruc' => '20100000001',
                'proveedor' => 'PROVEEDOR FALTANTE',
                'filas_excel' => [8],
                'tarifas_omitidas' => 1,
            ],
        ], $report->missingSuppliers());
    }

    public function test_counts_imported_tariffs_and_can_be_reset(): void
    {
        $report = new SupplierRateImportReport;

        $report->incrementImportedTariffs();
        $report->incrementImportedTariffs();

        $this->assertSame(2, $report->importedTariffs());

        $report->reset();

        $this->assertSame(0, $report->importedTariffs());
        $this->assertSame([], $report->missingClients());
        $this->assertSame([], $report->missingSuppliers());
    }
}
