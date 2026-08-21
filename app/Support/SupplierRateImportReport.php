<?php

namespace App\Support;

final class SupplierRateImportReport
{
    /** @var array<string, array{ruc: string, cliente: string, filas_excel: array<int, int|string>, tarifas_omitidas: int}> */
    private array $missingClients = [];

    /** @var array<string, array{ruc: string, proveedor: string, filas_excel: array<int, int|string>, tarifas_omitidas: int}> */
    private array $missingSuppliers = [];

    private int $importedTariffs = 0;

    public function reset(): void
    {
        $this->missingClients = [];
        $this->missingSuppliers = [];
        $this->importedTariffs = 0;
    }

    /** @param array<int, int|string> $excelRows */
    public function addMissingClient(string $ruc, string $name, array $excelRows): void
    {
        $this->addMissingEntity($this->missingClients, $ruc, 'cliente', $name, $excelRows);
    }

    /** @param array<int, int|string> $excelRows */
    public function addMissingSupplier(string $ruc, string $name, array $excelRows): void
    {
        $this->addMissingEntity($this->missingSuppliers, $ruc, 'proveedor', $name, $excelRows);
    }

    public function incrementImportedTariffs(): void
    {
        $this->importedTariffs++;
    }

    /** @return array<int, array{ruc: string, cliente: string, filas_excel: array<int, int|string>, tarifas_omitidas: int}> */
    public function missingClients(): array
    {
        return array_values($this->missingClients);
    }

    /** @return array<int, array{ruc: string, proveedor: string, filas_excel: array<int, int|string>, tarifas_omitidas: int}> */
    public function missingSuppliers(): array
    {
        return array_values($this->missingSuppliers);
    }

    public function importedTariffs(): int
    {
        return $this->importedTariffs;
    }

    /**
     * @param  array<string, array<string, mixed>>  $entities
     * @param  array<int, int|string>  $excelRows
     */
    private function addMissingEntity(
        array &$entities,
        string $ruc,
        string $nameKey,
        string $name,
        array $excelRows
    ): void {
        if (! isset($entities[$ruc])) {
            $entities[$ruc] = [
                'ruc' => $ruc,
                $nameKey => $name,
                'filas_excel' => [],
                'tarifas_omitidas' => 0,
            ];
        }

        foreach ($excelRows as $excelRow) {
            if (! in_array($excelRow, $entities[$ruc]['filas_excel'], true)) {
                $entities[$ruc]['filas_excel'][] = $excelRow;
            }
        }

        $entities[$ruc]['tarifas_omitidas']++;
    }
}
