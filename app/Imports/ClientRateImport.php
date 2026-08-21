<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ClientRateImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        DB::transaction(function () use ($rows) {
            $detallesInsert = [];
            $tarifasMap = [];

            $ubigeosOrigenInsert = [];
            $ubigeosDestinoInsert = [];

            $errores = [];

            $cleanRuc = fn($v) => trim(str_replace("\xc2\xa0", '', $v));

            $clientesRuc = $rows->pluck('ruc')
                ->map(fn($r) => $cleanRuc($r))
                ->unique()
                ->values();

            $clientes = DB::table('clientes')
                ->whereIn('cod_cli', $clientesRuc)
                ->pluck('id_cli', 'cod_cli');

            $tipoDeTransportes = DB::table('tipotransporte')
                ->pluck('idtt', 'nombrett')
                ->mapWithKeys(function ($value, $key) {
                    return [strtolower($key) => $value];
                });

            $tipoDeCobros = DB::table('condicion_pago')
                ->pluck('codigo', 'periodo_credito');

            $tipoDeTarifas = DB::table('tipo_tarifa')
                ->pluck('id', 'nombre')
                ->mapWithKeys(function ($value, $key) {
                    return [strtoupper($key) => $value];
                });

            $tipoDeCosteo = DB::table('tipo_de_costeo')
                ->pluck('id_costeo', 'nombre_costeo')
                ->mapWithKeys(function ($value, $key) {
                    return [strtoupper($key) => $value];
                });

            $factores = DB::table('factor')->pluck('id', 'factor');

            $articulos = DB::table('articulo')
                ->pluck('codigo', 'descripcion')
                ->mapWithKeys(function ($value, $key) {
                    return [Str::lower(trim($key)) => $value];
                });

            $basePriceColumns = [
                ['col' => 'tarifa_kg', 'articuloName' => 'peso', 'valueTarget' => 'precio_tarifa'],
                ['col' => 'tarifa_cj', 'articuloName' => 'caja', 'valueTarget' => 'precio_tarifa'],
                ['col' => 'sobre', 'articuloName' => 'sobres', 'valueTarget' => 'precio_base'],
            ];

            foreach ($basePriceColumns as $pc) {
                if (!isset($articulos[$pc['articuloName']])) {
                    throw new \Exception("Articulo no encontrado: {$pc['articuloName']}");
                }
            }

            $maxCodigo = DB::table('solicitud_cotizacion')
                ->max('codigo_terminado') ?? 0;
            $codigoCounter = 0;

            $tarifasAgrupadas = [];

            foreach ($rows as $row) {
                if (!isset($row['ruc'])) continue;

                $ubigeo_origen =
                    str_pad(trim($row['origen_dto']), 2, '0', STR_PAD_LEFT) .
                    str_pad(trim($row['origen_prov']), 2, '0', STR_PAD_LEFT) .
                    str_pad(trim($row['origen_distrito']), 2, '0', STR_PAD_LEFT);

                $ubigeo_destino =
                    str_pad(trim($row['destino_dto']), 2, '0', STR_PAD_LEFT) .
                    str_pad(trim($row['destino_prov']), 2, '0', STR_PAD_LEFT) .
                    str_pad(trim($row['destino_distrito']), 2, '0', STR_PAD_LEFT);

                foreach (['tarifa_kg', 'tarifa_cj', 'minimo', 'base', 'sobre'] as $col) {
                    if (isset($row[$col])) {
                        $row[$col] = $this->toFloatOrNull($row[$col]);
                    }
                }

                $operations = [];
                $rangeOperations = [];

                foreach ($basePriceColumns as $pc) {
                    $col = $pc['col'];
                    if ($col === 'sobre') {
                        continue;
                    }

                    if (empty($row[$col]) || $row[$col] <= 0) {
                        continue;
                    }

                    $precioTarifa = $pc['valueTarget'] === 'precio_tarifa' ? $row[$col] : null;
                    $precioBase = $pc['valueTarget'] === 'precio_base' ? $row[$col] : $row['base'];

                    $operations[] = [
                        'unidad_id' => $articulos[$pc['articuloName']],
                        'precio_tarifa' => $precioTarifa,
                        'precio_base' => $precioBase,
                        'rango_min' => null,
                        'rango_max' => null,
                    ];
                }

                foreach ($row as $col => $value) {
                    $operationRange = $this->parseOperationRangeColumn((string) $col);
                    if (!$operationRange) {
                        continue;
                    }

                    $price = $this->toFloatOrNull($value);
                    if (empty($price) || $price <= 0) {
                        continue;
                    }

                    $articuloName = $this->normalizeArticuloName($operationRange['unit']);
                    if (!isset($articulos[$articuloName])) {
                        throw new \Exception("Articulo no encontrado para columna {$col}: {$articuloName}");
                    }

                    $rangeOperation = [
                        'unidad_id' => $articulos[$articuloName],
                        'precio_tarifa' => $price,
                        'precio_base' => $row['base'],
                        'rango_min' => $operationRange['from'],
                        'rango_max' => $operationRange['to'],
                    ];

                    $rangeOperations[] = $rangeOperation;
                    $operations[] = $rangeOperation;
                }

                if (!empty($row['sobre']) && $row['sobre'] > 0) {
                    if (!isset($articulos['sobres'])) {
                        throw new \Exception('Articulo no encontrado: sobres');
                    }

                    if (!empty($rangeOperations)) {
                        $rangeKeysWithSobre = [];
                        foreach ($rangeOperations as $rangeOperation) {
                            $rangeKey = $rangeOperation['rango_min'] . '-' . $rangeOperation['rango_max'];
                            if (isset($rangeKeysWithSobre[$rangeKey])) {
                                continue;
                            }

                            $rangeKeysWithSobre[$rangeKey] = true;
                            $operations[] = [
                                'unidad_id' => $articulos['sobres'],
                                'precio_tarifa' => null,
                                'precio_base' => $row['sobre'],
                                'rango_min' => $rangeOperation['rango_min'],
                                'rango_max' => $rangeOperation['rango_max'],
                            ];
                        }
                    } else {
                        $operations[] = [
                            'unidad_id' => $articulos['sobres'],
                            'precio_tarifa' => null,
                            'precio_base' => $row['sobre'],
                            'rango_min' => null,
                            'rango_max' => null,
                        ];
                    }
                }

                if (empty($operations)) {
                    continue;
                }

                foreach ($operations as $operation) {

                    $key = json_encode([
                        $row['tipo_tarifa'],
                        $row['tipo_cobro'],
                        $cleanRuc($row['ruc']),
                        $ubigeo_origen,
                        $operation['precio_tarifa'],
                        $operation['unidad_id'],
                        $operation['precio_base'],
                        $row['minimo'],
                        $operation['rango_min'],
                        $operation['rango_max'],
                    ]);

                    if (!isset($tarifasAgrupadas[$key])) {
                        $tarifasAgrupadas[$key] = $row->toArray();
                        $tarifasAgrupadas[$key]['ubigeo_origen'] = $ubigeo_origen;
                        $tarifasAgrupadas[$key]['ubigeo_destino'] = [];
                        $tarifasAgrupadas[$key]['_precio_tarifa'] = $operation['precio_tarifa'];
                        $tarifasAgrupadas[$key]['_precio_base'] = $operation['precio_base'];
                        $tarifasAgrupadas[$key]['_unidad_id'] = $operation['unidad_id'];
                        $tarifasAgrupadas[$key]['_rango_min'] = $operation['rango_min'];
                        $tarifasAgrupadas[$key]['_rango_max'] = $operation['rango_max'];
                    }

                    if (!in_array($ubigeo_destino, $tarifasAgrupadas[$key]['ubigeo_destino'])) {
                        $tarifasAgrupadas[$key]['ubigeo_destino'][] = $ubigeo_destino;
                    }
                }
            }

            $tarifasAgrupadas = array_values($tarifasAgrupadas);

            $tarifasExpandidas = [];
            foreach ($tarifasAgrupadas as $row) {
                $ruc = $cleanRuc($row['ruc'] ?? '');
                $destinos = $row['ubigeo_destino'];
                if ($ruc === '20101639275') {
                    $aqpCusco = [];
                    $others = [];
                    foreach ($destinos as $d) {
                        $dept = substr($d, 0, 2);
                        if (in_array($dept, ['04', '08'], true)) {
                            $aqpCusco[] = $d;
                        } else {
                            $others[] = $d;
                        }
                    }
                    if (!empty($aqpCusco)) {
                        $r = $row;
                        $r['ubigeo_destino'] = $aqpCusco;
                        $r['_factor'] = $factores[6000];
                        $tarifasExpandidas[] = $r;
                    }
                    if (!empty($others)) {
                        $r = $row;
                        $r['ubigeo_destino'] = $others;
                        $r['_factor'] = $factores[4000];
                        $tarifasExpandidas[] = $r;
                    }
                } else {
                    $row['_factor'] = $factores[4000];
                    $tarifasExpandidas[] = $row;
                }
            }
            $tarifasAgrupadas = $tarifasExpandidas;

            foreach ($tarifasAgrupadas as $index => $row) {
                $clienteRuc = $cleanRuc($row['ruc']);
                $clienteId = $clientes[$clienteRuc] ?? null;

                $tipoTransporteId = $tipoDeTransportes['terrestre'];
                $tipoCobroId = $tipoDeCobros[$row['tipo_cobro']];
                $tipoTarifaId = $tipoDeTarifas[strtoupper($row['tipo_tarifa'])];

                if (!$clienteId) {
                    $errores[] = "Fila " . ($index + 1) . ": Cliente no encontrado ({$clienteRuc})";
                    continue;
                }

                $tarifaKey = json_encode([
                    $clienteId,
                    $tipoTarifaId,
                    $tipoCobroId,
                    $row['_factor'],
                    $row['minimo'],
                    $row['_rango_min'],
                    $row['_rango_max'],
                ]);

                $ubigeo_origen = $row['ubigeo_origen'];
                $ubigeo_destino = $row['ubigeo_destino'];

                if (!array_key_exists($tarifaKey, $tarifasMap)) {
                    $codigoCounter++;
                    $codigoTerminado = $maxCodigo + $codigoCounter;

                    $costeoTypeTxt = strtoupper(trim($row['tipo_tarifa'] ?? ''));
                    $costeoTypeId = $tipoDeCosteo[$costeoTypeTxt] ?? null;

                    DB::table('solicitud_cotizacion')->insert([
                        'cliente' => $clienteId,
                        'factor' => $row['_factor'],
                        'id_costeo' => $costeoTypeId,
                        'codigo_terminado' => $codigoTerminado,
                        'fecha_solicitud' => now(),
                        'estado_solicitud' => 1,
                    ]);

                    $detalleCosteoId = DB::table('detalleclientecosteo')->insertGetId([
                        'idcliente' => $clienteId,
                        'id_costeo' => $costeoTypeId,
                        'solicutud_codigo_terminado' => $codigoTerminado,
                        'area' => 'OPERACIONES',
                        'fechacotizacion' => '2026-01-01',
                        'fechavigencia' => '2026-01-01',
                        'fechaterminovigencia' => '2026-12-31',
                    ], 'iddetallecosteo');

                    $tarifaId = DB::table('tarifa_clientes')->insertGetId([
                        'id_cliente' => $clienteId,
                        'area' => 'OPERACIONES',
                        'fechadesolicituddecotizacion' => '2026-01-01',
                        'fechadeiniciodevigencia' => '2026-01-01',
                        'fechadeterminodevigencia' => '2026-12-31',
                        'tipo_transporte' => $tipoTransporteId,
                        'tipo_cobro' => $tipoCobroId,
                        'tipotarifa' => $tipoTarifaId,
                        'valorminimodeos' => $row['minimo'],
                        'rango_min' => $row['_rango_min'],
                        'rango_max' => $row['_rango_max'],
                        'rango' => $row['_rango_min'] !== null && $row['_rango_max'] !== null ? '1' : null,
                        'id_costeo' => $detalleCosteoId,
                    ], 'tarifa_id');

                    DB::table('tarifa_clientes')
                        ->where('tarifa_id', $tarifaId)
                        ->update(['codigodetipodetarifa' => $tarifaId]);

                    $tarifasMap[$tarifaKey] = $tarifaId;
                }

                $uuidDetalle = (string) Str::uuid();

                $detallesInsert[] = [
                    'uuid_temp' => $uuidDetalle,
                    'tarifa_key' => $tarifaKey,
                    'unidad' => $row['_unidad_id'],
                    'precio_tarifa' => $row['_precio_tarifa'],
                    'precio_base' => $row['_precio_base'],
                    'precio_sobres' => null
                ];

                $ubigeosOrigenInsert[] = [
                    'uuid_temp' => $uuidDetalle,
                    'ubigeo' => $ubigeo_origen
                ];

                foreach ($ubigeo_destino as $ud) {
                    $ubigeosDestinoInsert[] = [
                        'uuid_temp' => $uuidDetalle,
                        'ubigeo' => $ud
                    ];
                }
            }


            foreach ($detallesInsert as &$detalle) {
                $detalle['tarifa_id'] = $tarifasMap[$detalle['tarifa_key']];
                unset($detalle['tarifa_key']);
            }
            unset($detalle);

            DB::table('valores_adicionales_tarifa')->insert($detallesInsert);

            $detallesDB = DB::table('valores_adicionales_tarifa')
                ->whereIn('uuid_temp', collect($detallesInsert)->pluck('uuid_temp'))
                ->get()
                ->keyBy('uuid_temp');

            foreach ($ubigeosOrigenInsert as &$item) {
                $detalle = $detallesDB[$item['uuid_temp']];
                $item['item'] = $detalle->id;
                $item['tarifa_id'] = $detalle->tarifa_id;
                unset($item['uuid_temp']);
            }
            unset($item);

            foreach ($ubigeosDestinoInsert as &$item) {
                $detalle = $detallesDB[$item['uuid_temp']];
                $item['item'] = $detalle->id;
                $item['tarifa_id'] = $detalle->tarifa_id;
                unset($item['uuid_temp']);
            }
            unset($item);

            DB::table('tarifa_cliente_ubigeo_origen')->insert($ubigeosOrigenInsert);
            DB::table('tarifa_cliente_ubigeo_destino')->insert($ubigeosDestinoInsert);
        });
    }

    private function toFloatOrNull($value): ?float
    {
        $clean = trim(str_replace(["S/ ", "S/", ","], ['', '', '.'], (string) $value));

        return $clean !== '' ? (float) $clean : null;
    }

    private function parseOperationRangeColumn(string $column): ?array
    {
        $column = Str::lower(trim($column));

        if (!preg_match('/^op_(\d+)[_-](\d+)_(.+)$/', $column, $matches)) {
            return null;
        }

        return [
            'from' => (int) $matches[1],
            'to' => (int) $matches[2],
            'unit' => $matches[3],
        ];
    }

    private function normalizeArticuloName(string $unit): string
    {
        $unit = Str::lower(trim($unit));

        return match ($unit) {
            'kg', 'kilo', 'kilos', 'peso' => 'peso',
            'cj', 'caja', 'cajas' => 'caja',
            'sobre', 'sobres' => 'sobres',
            default => $unit,
        };
    }
}
