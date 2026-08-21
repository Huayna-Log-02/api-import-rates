<?php

namespace App\Imports;

use App\Support\AgentRateImportReport;
use App\Support\TariffOperationColumnParser;
use App\Support\TariffOperationExpressionParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;
use RuntimeException;

HeadingRowFormatter::default('none');

class AgentRateImport implements ToCollection, WithHeadingRow, WithMultipleSheets
{
    public function __construct(private readonly AgentRateImportReport $report = new AgentRateImportReport) {}

    public function sheets(): array
    {
        return [
            'TARIFARIO - AGENTES' => $this,
        ];
    }

    public function collection(Collection $rows): void
    {
        $this->report->reset();

        $expressionParser = new TariffOperationExpressionParser;
        $columnParser = new TariffOperationColumnParser;

        DB::transaction(function () use ($rows, $expressionParser, $columnParser): void {
            $cleanRuc = fn (mixed $value): string => trim(str_replace("\xc2\xa0", '', (string) $value));

            $clientRucs = $rows->pluck('ruc_cliente')->map($cleanRuc)->filter()->unique()->values();
            $agentRucs = $rows->pluck('ruc_agente')->map($cleanRuc)->filter()->unique()->values();

            $clients = DB::table('clientes')
                ->whereIn('cod_cli', $clientRucs)
                ->pluck('id_cli', 'cod_cli');

            $agents = DB::table('agentes')
                ->whereIn('cod_prv', $agentRucs)
                ->pluck('id_prv', 'cod_prv');

            $transportTypes = DB::table('tipotransporte')
                ->pluck('idtt', 'nombrett')
                ->mapWithKeys(fn ($value, $key) => [strtolower(trim($key)) => $value]);

            $tariffTypes = DB::table('tipo_tarifa')
                ->pluck('id', 'nombre')
                ->mapWithKeys(fn ($value, $key) => [strtoupper(trim($key)) => $value]);

            $paymentTypes = DB::table('condicion_pago')->pluck('codigo', 'periodo_credito');

            $rangeUnits = DB::table('tipos_unidad')
                ->pluck('id', 'unidad')
                ->mapWithKeys(fn ($value, $key) => [strtolower(trim($key)) => $value]);

            $calculationUnits = DB::table('tipos_unidad_segunda')
                ->pluck('id', 'unidad')
                ->mapWithKeys(fn ($value, $key) => [strtolower(trim($key)) => $value]);

            $mathOperators = DB::table('operadores_matematicos')
                ->pluck('id', 'operador')
                ->mapWithKeys(function ($value, $key) {
                    $operator = strtolower(trim($key));

                    return [$operator === '*' ? 'x' : $operator => $value];
                });

            foreach (['+', '-', '/', 'x'] as $operator) {
                if (! $mathOperators->has($operator)) {
                    throw new RuntimeException("No existe el operador matematico '{$operator}' en el catalogo.");
                }
            }

            $transportTypeId = $transportTypes['terrestre'] ?? null;
            if ($transportTypeId === null) {
                throw new RuntimeException("No existe el tipo de transporte 'terrestre' en el catalogo.");
            }

            $groupedTariffs = [];

            foreach ($rows as $rowIndex => $row) {
                $excelRow = is_numeric($rowIndex) ? (int) $rowIndex + 2 : $rowIndex;
                $operations = [];

                foreach ($row as $column => $value) {
                    if (! $columnParser->isImportable((string) $column)) {
                        continue;
                    }

                    try {
                        $parsedExpression = $expressionParser->parse($value);
                    } catch (InvalidArgumentException $exception) {
                        throw new InvalidArgumentException(
                            "Fila {$excelRow}, columna '{$column}', valor '{$value}': {$exception->getMessage()}",
                            previous: $exception
                        );
                    }

                    if ($parsedExpression === null) {
                        continue;
                    }

                    $parsedColumn = $columnParser->parse((string) $column);
                    if ($parsedColumn === null) {
                        throw new InvalidArgumentException(
                            "Fila {$excelRow}: el encabezado de operacion '{$column}' no tiene un formato valido."
                        );
                    }

                    $operations[(string) $column] = [
                        'column' => $parsedColumn,
                        'expression' => $parsedExpression,
                    ];
                }

                // Igual que proveedores: filas sin op_ o terre_reparto_ no generan una tarifa.
                if ($operations === []) {
                    continue;
                }

                $agentRuc = $cleanRuc($row['ruc_agente'] ?? null);
                $clientRuc = $cleanRuc($row['ruc_cliente'] ?? null);
                $origin = $this->buildUbigeo($row, 'origen', $excelRow);
                $destination = $this->buildUbigeo($row, 'destino', $excelRow);
                $minimum = $this->toFloatOrNull($row['minimo'] ?? null);
                $envelopePrice = $this->toFloatOrNull($row['sobre'] ?? null);
                $paymentType = trim((string) ($row['tipo_cobro'] ?? ''));
                $tariffType = strtoupper(trim((string) ($row['tipo_tarifa'] ?? '')));

                $keyParts = [
                    $tariffType,
                    $clientRuc,
                    $agentRuc,
                    $origin,
                    $paymentType,
                    $minimum,
                    $envelopePrice,
                    $operations,
                ];
                $key = json_encode($keyParts, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

                if (! isset($groupedTariffs[$key])) {
                    $groupedTariffs[$key] = [
                        'excel_row' => $excelRow,
                        'agent_name' => trim((string) ($row['agente'] ?? '')),
                        'agent_ruc' => $agentRuc,
                        'client_ruc' => $clientRuc,
                        'tariff_type' => $tariffType,
                        'payment_type' => $paymentType,
                        'minimum' => $minimum,
                        'envelope_price' => $envelopePrice,
                        'origin' => $origin,
                        'destinations' => [],
                        'excel_rows' => [],
                        'operations' => $operations,
                    ];
                }

                $groupedTariffs[$key]['destinations'][$destination] = $destination;
                if (! in_array($excelRow, $groupedTariffs[$key]['excel_rows'], true)) {
                    $groupedTariffs[$key]['excel_rows'][] = $excelRow;
                }
            }

            foreach ($groupedTariffs as $tariff) {
                $excelRow = $tariff['excel_row'];
                $agentId = $agents[$tariff['agent_ruc']] ?? null;
                $clientId = $clients[$tariff['client_ruc']] ?? null;
                $paymentTypeId = $paymentTypes[$tariff['payment_type']] ?? null;
                $tariffTypeId = $tariffTypes[$tariff['tariff_type']] ?? null;

                if ($agentId === null) {
                    $this->report->addMissingAgent(
                        $tariff['agent_ruc'],
                        $tariff['agent_name'],
                        $tariff['excel_rows']
                    );

                    continue;
                }

                if ($clientId === null) {
                    throw new InvalidArgumentException(
                        "Fila {$excelRow}: cliente no encontrado ({$tariff['client_ruc']})."
                    );
                }

                if ($paymentTypeId === null) {
                    throw new InvalidArgumentException(
                        "Fila {$excelRow}: tipo de cobro no encontrado ({$tariff['payment_type']})."
                    );
                }

                if ($tariffTypeId === null) {
                    throw new InvalidArgumentException(
                        "Fila {$excelRow}: tipo de tarifa no encontrado ({$tariff['tariff_type']})."
                    );
                }

                $tariffId = DB::table('tarifa_agentes')->insertGetId([
                    'id_cliente' => $clientId,
                    'area' => 'OPERACIONES',
                    'tipo_tarifa' => $tariffTypeId,
                    'tipo_transporte' => $transportTypeId,
                    'logistica_inversa' => false,
                    'tipo_cobro' => $paymentTypeId,
                    'fecha_cotizacion' => '2026-01-01',
                    'fecha_inicio_vigencia' => '2026-01-01',
                    'fecha_termino_vigencia' => '2026-12-31',
                    'id_proveedor_agente' => $agentId,
                    'tipo_proveedor_agente' => 'Agente',
                    'precio_sobres' => $tariff['envelope_price'],
                    'flag_usar_rango' => true,
                    'valor_minimo' => $tariff['minimum'],
                ], 'id');

                $this->report->incrementImportedTariffs();

                $operationRows = [];
                foreach ($tariff['operations'] as $operation) {
                    $column = $operation['column'];
                    $expression = $operation['expression'];
                    $rangeUnit = $rangeUnits[$column['unidad_rango']] ?? null;
                    $calculationUnit = $calculationUnits[$column['unidad_calculo']] ?? null;

                    if ($rangeUnit === null) {
                        throw new InvalidArgumentException(
                            "Fila {$excelRow}: unidad de rango no encontrada ({$column['unidad_rango']})."
                        );
                    }

                    if ($calculationUnit === null) {
                        throw new InvalidArgumentException(
                            "Fila {$excelRow}: unidad de calculo no encontrada ({$column['unidad_calculo']})."
                        );
                    }

                    $operationFields = [
                        'pri_tipo_operacion' => null,
                        'pri_valor' => null,
                        'seg_tipo_operacion' => null,
                        'seg_valor' => null,
                        'ter_tipo_operacion' => null,
                        'ter_valor' => null,
                    ];

                    foreach ($expression['operations'] as $position => $parsedOperation) {
                        $prefix = ['pri', 'seg', 'ter'][$position];
                        $operationFields["{$prefix}_tipo_operacion"] = $mathOperators[$parsedOperation['operator']];
                        $operationFields["{$prefix}_valor"] = $parsedOperation['value'];
                    }

                    $operationRows[] = [
                        'tarifa_id' => $tariffId,
                        'unidad_medida' => $rangeUnit,
                        'desde' => $column['desde'],
                        'hasta' => $column['hasta'],
                        'seg_unidad_medida' => $calculationUnit,
                        ...$operationFields,
                        'aplica_igv' => $expression['aplica_igv'],
                    ];
                }

                DB::table('tarifa_agentes_operaciones')->insert($operationRows);

                DB::table('ubigeos_salida_tarifa_agentes')->insert([
                    'tarifa_id' => $tariffId,
                    'ubigeo' => $tariff['origin'],
                ]);

                $destinationRows = array_map(
                    fn (string $destination): array => [
                        'tarifa_id' => $tariffId,
                        'ubigeo' => $destination,
                    ],
                    array_values($tariff['destinations'])
                );

                DB::table('ubigeos_destino_tarifa_agentes')->insert($destinationRows);
            }
        });
    }

    private function buildUbigeo(Collection $row, string $prefix, int|string $excelRow): string
    {
        $parts = [];

        foreach (['dto', 'prov', 'distrito'] as $part) {
            $column = "{$prefix}_{$part}";
            $value = trim(str_replace("\xc2\xa0", '', (string) ($row[$column] ?? '')));

            if ($value === '' || ! ctype_digit($value) || strlen($value) > 2) {
                throw new InvalidArgumentException(
                    "Fila {$excelRow}: valor de ubigeo invalido en '{$column}' ({$value})."
                );
            }

            $parts[] = str_pad($value, 2, '0', STR_PAD_LEFT);
        }

        return implode('', $parts);
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        $clean = trim(str_replace(['S/ ', 'S/', ','], ['', '', '.'], (string) $value));

        return $clean === '' || $clean === '-' ? null : (float) $clean;
    }
}
