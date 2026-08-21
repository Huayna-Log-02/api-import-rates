<?php

namespace App\Support;

final class AgentRateImportReport
{
    /** @var array<string, array{ruc: string, agente: string, filas_excel: array<int, int|string>, tarifas_omitidas: int}> */
    private array $missingAgents = [];

    private int $importedTariffs = 0;

    public function reset(): void
    {
        $this->missingAgents = [];
        $this->importedTariffs = 0;
    }

    /** @param array<int, int|string> $excelRows */
    public function addMissingAgent(string $ruc, string $name, array $excelRows): void
    {
        if (! isset($this->missingAgents[$ruc])) {
            $this->missingAgents[$ruc] = [
                'ruc' => $ruc,
                'agente' => $name,
                'filas_excel' => [],
                'tarifas_omitidas' => 0,
            ];
        }

        foreach ($excelRows as $excelRow) {
            if (! in_array($excelRow, $this->missingAgents[$ruc]['filas_excel'], true)) {
                $this->missingAgents[$ruc]['filas_excel'][] = $excelRow;
            }
        }

        $this->missingAgents[$ruc]['tarifas_omitidas']++;
    }

    public function incrementImportedTariffs(): void
    {
        $this->importedTariffs++;
    }

    /**
     * @return array<int, array{ruc: string, agente: string, filas_excel: array<int, int|string>, tarifas_omitidas: int}>
     */
    public function missingAgents(): array
    {
        return array_values($this->missingAgents);
    }

    public function importedTariffs(): int
    {
        return $this->importedTariffs;
    }
}
