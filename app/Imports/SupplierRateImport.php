<?php

namespace App\Imports;

use App\Support\SupplierRateImportReport;
use App\Support\TariffOperationExpressionParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

HeadingRowFormatter::default('none');

class SupplierRateImport implements ToCollection, WithHeadingRow, WithMultipleSheets
{
    public function __construct(private readonly SupplierRateImportReport $report = new SupplierRateImportReport) {}

    public function sheets(): array
    {
        return [0 => $this];
    }

    /**
     * @param Collection $collection
     */


    public function collection(Collection $rows)
    {
        $this->report->reset();

        $operationExpressionParser = new TariffOperationExpressionParser;

        DB::transaction(function () use ($rows, $operationExpressionParser) {



            $tarifasInsert = [];
            $detallesInsert = [];

            $ubigeosOrigenInsert = [];
            $ubigeosDestinoInsert = [];

            $cleanRuc = fn($v) => trim(str_replace("\xc2\xa0", '', (string) $v));

            // Clientes
            $clientesRuc = $rows->pluck('ruc_cliente')
                ->map(fn($r) => $cleanRuc($r))
                ->unique()
                ->values();

            $clientes = DB::table('clientes')
                ->whereIn('cod_cli', $clientesRuc)
                ->pluck('id_cli', 'cod_cli');

            // Proveedores
            $proveedoresRuc = $rows->pluck('ruc_proveedor')
                ->map(fn($r) => $cleanRuc($r))
                ->unique()
                ->values();

            $proveedores = DB::table('proveedores')
                ->whereIn('cod_prv', $proveedoresRuc)
                ->pluck('id_prv', 'cod_prv');

            $tipoDeTransportes = DB::table('tipotransporte')
                ->pluck('idtt', 'nombrett')
                ->mapWithKeys(function ($value, $key) {
                    return [strtolower($key) => $value];
                });

            $tipoDeTarifas = DB::table('tipo_tarifa')
                ->pluck('id', 'nombre')
                ->mapWithKeys(function ($value, $key) {
                    return [strtoupper($key) => $value];
                });

            $tipoDeCobros = DB::table('condicion_pago')
                ->pluck('codigo', 'periodo_credito');

            //Unidad para los rangos
            $tipoUnidadMedidaRango = DB::table('tipos_unidad')
                ->pluck('id', 'unidad')
                ->mapWithKeys(function ($value, $key) {
                    return [strtolower($key) => $value];
                });

            //Unidad calcular precio
            $tipoUnidadMedidaCalc = DB::table('tipos_unidad_segunda')
                ->pluck('id', 'unidad')
                ->mapWithKeys(function ($value, $key) {
                    return [strtolower($key) => $value];
                });

            $operadoresMatematicos = DB::table('operadores_matematicos')
                ->pluck('id', 'operador')
                ->mapWithKeys(function ($value, $key) {
                    $operator = strtolower(trim($key));

                    return [$operator === '*' ? 'x' : $operator => $value];
                });

            foreach (['+', '-', '/', 'x'] as $operator) {
                if (!$operadoresMatematicos->has($operator)) {
                    throw new \RuntimeException("No existe el operador matematico '{$operator}' en el catalogo.");
                }
            }

            $tarifasAgrupadas = []; // De un origen a multiples destinos, pero mismo cliente, proveedor, tipo tarifa, tipo cobro, transporte y costos

            foreach ($rows as $rowIndex => $row) {
                if (!isset($row['ruc_proveedor'])) continue;

                $excelRow = is_numeric($rowIndex) ? ((int) $rowIndex + 2) : $rowIndex;

                $ubigeo_origen =
                    str_pad(trim($row['origen_dto']), 2, '0', STR_PAD_LEFT) .
                    str_pad(trim($row['origen_prov']), 2, '0', STR_PAD_LEFT) .
                    str_pad(trim($row['origen_distrito']), 2, '0', STR_PAD_LEFT);

                $ubigeo_destino =
                    str_pad(trim($row['destino_dto']), 2, '0', STR_PAD_LEFT) .
                    str_pad(trim($row['destino_prov']), 2, '0', STR_PAD_LEFT) .
                    str_pad(trim($row['destino_distrito']), 2, '0', STR_PAD_LEFT);

                $row['minimo'] = $this->toFloatOrNull($row['minimo'] ?? null);
                $row['sobre'] = $this->toFloatOrNull($row['sobre'] ?? null);

                // 🔑 clave compuesta (todos los campos que deben ser iguales)
                $operationColumns = [];
                foreach ($row as $col => $value) {
                    if (!$this->isImportableOperationColumn((string) $col)) {
                        continue;
                    }

                    try {
                        $parsedExpression = $operationExpressionParser->parse($value);
                    } catch (\InvalidArgumentException $exception) {
                        throw new \InvalidArgumentException(
                            "Fila {$excelRow}, columna '{$col}', valor '{$value}': {$exception->getMessage()}",
                            previous: $exception
                        );
                    }

                    if ($parsedExpression !== null) {
                        if ($this->parseOperationColumn((string) $col) === null) {
                            throw new \InvalidArgumentException(
                                "Fila {$excelRow}: el encabezado de operacion '{$col}' no tiene un formato valido."
                            );
                        }

                        $operationColumns[(string) $col] = $parsedExpression;
                    }
                }

                if (empty($operationColumns) && $row['minimo'] === null && $row['sobre'] === null) {
                    continue;
                }

                $keyParts = [
                    $row['tipo_tarifa'],
                    $cleanRuc($row['ruc_cliente']),
                    $cleanRuc($row['ruc_proveedor']),
                    $ubigeo_origen,
                    $row['tipo_cobro'],
                    $row['minimo'],
                    $row['sobre'],
                ];

                foreach ($operationColumns as $col => $value) {
                    $keyParts[] = $col;
                    $keyParts[] = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
                }

                $key = json_encode($keyParts);

                // 🆕 si no existe, creas estructura base
                if (!isset($tarifasAgrupadas[$key])) {
                  
                        $tarifasAgrupadas[$key] = $row->toArray();

                        // luego sobrescribes lo que cambia
                        $tarifasAgrupadas[$key]['ubigeo_origen'] = $ubigeo_origen;
                        $tarifasAgrupadas[$key]['ubigeo_destino'] = [];
                        $tarifasAgrupadas[$key]['operaciones_importadas'] = $operationColumns;
                        $tarifasAgrupadas[$key]['fila_excel'] = $excelRow;
                        $tarifasAgrupadas[$key]['filas_excel'] = [];
                }

                if (!in_array($excelRow, $tarifasAgrupadas[$key]['filas_excel'], true)) {
                    $tarifasAgrupadas[$key]['filas_excel'][] = $excelRow;
                }

                // evitando duplicados opcional
                if (!in_array($ubigeo_destino, $tarifasAgrupadas[$key]['ubigeo_destino'])) {
                    $tarifasAgrupadas[$key]['ubigeo_destino'][] = $ubigeo_destino;
                }
            }

            // 🔄 opcional: reindexar
            $tarifasAgrupadas = array_values($tarifasAgrupadas);

            foreach ($tarifasAgrupadas as $row) {
            
                $tipoTransporteId = $tipoDeTransportes['terrestre'];

                $proveedorRuc = $cleanRuc($row['ruc_proveedor']);
                $proveedorId = $proveedores[$proveedorRuc] ?? null;

                $clienteRuc = $cleanRuc($row['ruc_cliente']);
                $clienteId = $clientes[$clienteRuc] ?? null;

                if (!$clienteId) {
                    $this->report->addMissingClient(
                        $clienteRuc,
                        trim((string) ($row['cliente'] ?? '')),
                        $row['filas_excel']
                    );

                    continue;
                }

                if (!$proveedorId) {
                    $this->report->addMissingSupplier(
                        $proveedorRuc,
                        trim((string) ($row['proveedor'] ?? '')),
                        $row['filas_excel']
                    );

                    continue;
                }

                $tipoCobroId = $tipoDeCobros[$row['tipo_cobro']] ?? null;
                if (!$tipoCobroId) {
                    continue;
                }


                // 🔹 Ubigeos
                $ubigeo_origen = $row['ubigeo_origen'];

                $ubigeo_destino = $row['ubigeo_destino']; // Este es un array con múltiples destinos

                // 🔹 UUID 
                $uuidTarifa = (string) Str::uuid();

                // 🔹 Crear tarifa
                $tarifasInsert[$uuidTarifa] = [
                    'uuid_temp' => $uuidTarifa,
                    'id_proveedor' => $proveedorId,
                    'id_cliente' => $clienteId,
                    'area' => 'OPERACIONES',
                    'fecha_cotizacion' => '2026-01-01',
                    'fecha_inicio_vigencia' => '2026-01-01',
                    'fecha_termino_vigencia' => '2026-12-31',
                    'tipo_transporte' => $tipoTransporteId,
                    'tipo_cobro' => $tipoCobroId,
                    'tipo_tarifa' => $tipoDeTarifas[strtoupper($row['tipo_tarifa'])],
                    'valor_minimo' => $row['minimo'],
                    'flag_usar_rango' => !empty($row['operaciones_importadas']),
                    'precio_sobres' => $row['sobre']
                ];

                $this->report->incrementImportedTariffs();


                // Se guardaran las keys con su valor que son operaciones(detalles de la tarifa)
                /* $Opskeys = $row->filter(function ($value, $key) {
                    return str_starts_with($key, 'op');
                }); */

                $Opskeys = $row['operaciones_importadas'];

                foreach ($Opskeys as $key => $parsedExpression) {
                    // Separar por "_"
                    $operation = $this->parseOperationColumn((string) $key);
                    if (!$operation) {
                        throw new \InvalidArgumentException(
                            "Fila {$row['fila_excel']}: el encabezado de operacion '{$key}' no tiene un formato valido."
                        );
                    }

                    // Asignaciones
                    $unidadRango = strtolower($operation['unidad_rango']);

                    // Separar rango
                    $rangoDesde = $operation['desde'];
                    $rangoHasta = $operation['hasta'];

                    // Separar unidad cálculo
                    $unidadCalculo = strtolower($operation['unidad_calculo']);

                    $operationFields = [
                        'pri_tipo_operacion' => null,
                        'pri_valor' => null,
                        'seg_tipo_operacion' => null,
                        'seg_valor' => null,
                        'ter_tipo_operacion' => null,
                        'ter_valor' => null,
                    ];

                    foreach ($parsedExpression['operations'] as $position => $parsedOperation) {
                        $prefix = ['pri', 'seg', 'ter'][$position];
                        $operationFields["{$prefix}_tipo_operacion"] = $operadoresMatematicos[$parsedOperation['operator']];
                        $operationFields["{$prefix}_valor"] = $parsedOperation['value'];
                    }

                    $detallesInsert[] = [
                        'uuid_temp' => $uuidTarifa,
                        'unidad_medida' => $tipoUnidadMedidaRango[$unidadRango],
                        'desde' => $rangoDesde,
                        'hasta' => $rangoHasta,
                        'seg_unidad_medida' => $tipoUnidadMedidaCalc[$unidadCalculo],
                        ...$operationFields,
                        'aplica_igv' => $parsedExpression['aplica_igv'],
                    ];
                }

                // 🔹 Ubigeos
                $ubigeosOrigenInsert[] = [
                    'uuid_temp' => $uuidTarifa,
                    'cod_ubi' => $ubigeo_origen
                ];

                foreach ($ubigeo_destino as $ud) {
                    $ubigeosDestinoInsert[] = [
                    'uuid_temp' => $uuidTarifa,
                    'cod_ubi' => $ud
                    ];
                }
                
            }

            //PROCESO DE INSERCION EN LA BASE DE DATOS

            // 🔹 Insert tarifas
            if ($tarifasInsert === []) {
                return;
            }

            DB::table('tarifa_proveedores')->insert($tarifasInsert);

            //dd($proveedoresRuc);
            // 🔹 Obtener tarifa_id
            $tarifasDB = DB::table('tarifa_proveedores')
                //->whereIn('proveedores.cod_prv', $proveedoresRuc)
                ->pluck('tarifa_id', 'uuid_temp');

            // 🔹 Mapear detalle → tarifa_id
            foreach ($detallesInsert as &$detalle) {

                $uuid_temp = $detalle['uuid_temp'];

                $detalle['tarifa_id'] = $tarifasDB[$uuid_temp];
                unset($detalle['uuid_temp']);
            }
            unset($detalle);

            if ($detallesInsert !== []) {
                DB::table('tarifa_proveedores_operaciones')->insert($detallesInsert);
            }


            // 🔹 Mapear detalle → tarifa_id
            foreach ($ubigeosOrigenInsert as &$detalle) {

                $uuid_temp = $detalle['uuid_temp'];

                $detalle['tarifa_id'] = $tarifasDB[$uuid_temp];
                unset($detalle['uuid_temp']);
            }
            unset($detalle);

            // 🔹 Mapear detalle → tarifa_id
            foreach ($ubigeosDestinoInsert as &$detalle) {

                $uuid_temp = $detalle['uuid_temp'];

                $detalle['tarifa_id'] = $tarifasDB[$uuid_temp];
                unset($detalle['uuid_temp']);
            }
            unset($detalle);

            // 🔹 Insert ubigeos
            if ($ubigeosOrigenInsert !== []) {
                DB::table('ubigeos_salida_tarifa_proveedores')->insert($ubigeosOrigenInsert);
            }

            if ($ubigeosDestinoInsert !== []) {
                DB::table('ubigeos_destino_tarifa_proveedores')->insert($ubigeosDestinoInsert);
            }
        });
    }

    private function toFloatOrNull($value): ?float
    {
        $clean = trim(str_replace(["S/ ", "S/", ","], ['', '', '.'], (string) $value));

        return $clean === '' || $clean === '-' ? null : (float) $clean;
    }

    private function isImportableOperationColumn(string $column): bool
    {
        $column = strtolower(trim($column));

        return str_starts_with($column, 'op_') || str_starts_with($column, 'terre_reparto_');
    }

    private function parseOperationColumn(string $column): ?array
    {
        $column = strtolower(trim($column));

        if (preg_match('/^op_([^_]+)_([0-9]+-[0-9]+)_(.+)$/', $column, $matches)) {
            [$desde, $hasta] = explode('-', $matches[2], 2);

            return [
                'unidad_rango' => $this->normalizeUnidad($matches[1]),
                'desde' => (int) $desde,
                'hasta' => (int) $hasta,
                'unidad_calculo' => $this->normalizeUnidad(str_replace('-', ' ', $matches[3])),
            ];
        }

        if (preg_match('/^terre_reparto_([^_]+)_([0-9]+-[0-9]+)$/', $column, $matches)) {
            [$desde, $hasta] = explode('-', $matches[2], 2);
            $unidad = $this->normalizeUnidad($matches[1]);

            return [
                'unidad_rango' => $unidad,
                'desde' => (int) $desde,
                'hasta' => (int) $hasta,
                'unidad_calculo' => $unidad,
            ];
        }

        return null;
    }

    private function normalizeUnidad(string $unit): string
    {
        $unit = strtolower(trim($unit));

        return match ($unit) {
            'kilo', 'kilos', 'peso' => 'kg',
            default => $unit,
        };
    }
}
