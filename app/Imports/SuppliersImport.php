<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Carbon\Carbon;

class SuppliersImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {

        DB::transaction(function () use ($rows) {

            $supplierPersonType = [
                'NATURAL' => 1,
                'JURIDICA' => 2
            ];

            $ubigeos = DB::table('ubigeo')
                ->pluck('cod_ubi')
                ->toArray();

            $supplierType = DB::table('tipos_proveedor')
                ->pluck('id_tipo','descripcion')
                ->mapWithKeys(function ($value, $key) {
                    return [strtoupper(trim($key)) => $value];
                })->toArray();    
            
            $conditionSale = DB::table('condicion_pago')
                ->pluck('codigo','periodo_credito')
                ->toArray();  

            $suppliersData = [];

            foreach ($rows as $index => $row) {

                if (
                    empty($row['ruc']) || empty($row['razon_social'])
                ) {
                    continue;
                }

                $supplierTypeTxt   = strtoupper(trim($row['tipo_contacto'] ?? ''));
                $supplierTypeId   = $supplierType[$supplierTypeTxt] ?? null;
                $supplierPersonTypeTxt   = strtoupper(trim($row['tipo_proveedor'] ?? ''));
                $supplierPersonTypeId   = $supplierPersonType[$supplierPersonTypeTxt] ?? null;
                $conditionSaleTxt   = strtoupper(trim($row['condicion_pago'] ?? ''));
                $conditionSaleId   = $conditionSale[$conditionSaleTxt] ?? null;


                if (!$supplierTypeId || !$supplierPersonTypeId || !$conditionSaleId || !in_array(trim($row['ubigeo']), $ubigeos)) {
                    throw new \Exception("Error en fila " . ($index + 1) . ": tipo_proveedor, tipo_contacto, condicion_pago o ubigeo inválido");
                }

                $suppliersData[] = [
                    'tpe_prv'            => $supplierPersonTypeId,
                    'id_tipo'            => $supplierTypeId,
                    'cod_prv'         => isset($row['ruc']) ? trim($row['ruc']) : null,
                    'fec_prv'         => now(),
                    'des_prv'           => isset($row['razon_social']) ? trim($row['razon_social']) : null,
                    'dir_prv'           => isset($row['direccion']) ? trim($row['direccion']) : null,
                    'ubi_prv'         => isset($row['ubigeo']) ? trim($row['ubigeo']) : null,
                    'ref_prv'          => isset($row['referencia']) ? $row['referencia'] : null,
                    'ema_prv'            => isset($row['correo_liquidaciones']) ? trim($row['correo_liquidaciones']) : null,
                    'tel_prv'            => isset($row['celular']) ? trim($row['celular']) : null,
                    'con_prv'          => isset($row['contacto']) ? trim($row['contacto']) : null,
                    'car_cli'          => isset($row['cargo_contacto']) ? trim($row['cargo_contacto']) : null,
                    'tid_prv'          => isset($row['tipo_documento']) ? trim(strtoupper($row['tipo_documento'])) : null,
                    'cpa_prv'          => $conditionSaleId,
                ];
            }

            DB::table('proveedores')->upsert(
                $suppliersData,
                ['cod_prv'],
                [
                    'tpe_prv',
                    'fec_prv',
                    'des_prv',
                    'dir_prv',
                    'ubi_prv',
                    'ref_prv',
                    'ema_prv',
                    'tel_prv',
                    'con_prv',
                    'car_cli',
                    'tid_prv',
                    'cve_prv'
                ],
            );
        });
    }

    function excelDateToSql($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            // Caso 1: Excel manda número (45453)
            if (is_numeric($value)) {
                return Date::excelToDateTimeObject($value)->format('Y-m-d');
            }

            // Caso 2: Excel manda string tipo 10/06/2024
            return Carbon::createFromFormat('d/m/Y', trim($value))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null; // evita romper la importación
        }
    }
}
