<?php

namespace App\Support;

final class TariffOperationColumnParser
{
    public function isImportable(string $column): bool
    {
        $column = strtolower(trim($column));

        return str_starts_with($column, 'op_') || str_starts_with($column, 'terre_reparto_');
    }

    /**
     * @return array{unidad_rango: string, desde: int, hasta: int, unidad_calculo: string}|null
     */
    public function parse(string $column): ?array
    {
        $column = strtolower(trim($column));

        if (preg_match('/^op_([^_]+)_([0-9]+)-([0-9]+)_(.+)$/', $column, $matches)) {
            return [
                'unidad_rango' => $this->normalizeUnit($matches[1]),
                'desde' => (int) $matches[2],
                'hasta' => (int) $matches[3],
                'unidad_calculo' => $this->normalizeUnit(str_replace('-', ' ', $matches[4])),
            ];
        }

        if (preg_match('/^terre_reparto_([^_]+)_([0-9]+)-([0-9]+)$/', $column, $matches)) {
            $unit = $this->normalizeUnit($matches[1]);

            return [
                'unidad_rango' => $unit,
                'desde' => (int) $matches[2],
                'hasta' => (int) $matches[3],
                'unidad_calculo' => $unit,
            ];
        }

        return null;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));

        return match ($unit) {
            'kilo', 'kilos', 'peso' => 'kg',
            default => $unit,
        };
    }
}
