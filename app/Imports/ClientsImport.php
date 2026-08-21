<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ClientsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {

        DB::transaction(function () use ($rows) {
            $tiposClienteSunat = [
                'NATURAL' => 1,
                'JURIDICA' => 2,
            ];

            // Cargar todos los lookups UNA SOLA VEZ
            $tiposCliente = DB::table('tipos_cliente')
                ->pluck('id_tipo', 'descripcion')
                ->mapWithKeys(fn($id, $desc) => [strtoupper(trim($desc)) => $id])
                ->toArray();

            $tiposDocumento = DB::table('tipos_de_documento_clientes')
                ->pluck('cod_ctd', 'abr_ctd')
                ->mapWithKeys(fn($id, $desc) => [strtoupper(trim($desc)) => $id])
                ->toArray();

            $ubigeos = DB::table('ubigeo')
                ->pluck('cod_ubi')
                ->toArray();

            //Datos no proporcionados en el Excel pero necesarios para la inserción, se asignan valores por defecto
            $operacion_venta = DB::table('operaciones_de_venta')->value('id_ovt');

            $clientesData = [];

            foreach ($rows as $index => $row) {

                if (
                    empty($row['tipo_cliente_sunat']) || empty($row['tipo_cliente']) ||
                    empty($row['tipo_doc']) || empty($row['ruc']) || empty($row['razon_social']) ||
                    empty($row['ubigeo']) || empty($row['telefono']) || empty($row['correo_contacto']) ||
                    empty($row['contacto']) || empty($row['ejecutivo_venta'])
                ) {
                    continue;
                }

                $tipoClienteSunatTxt   = strtoupper(trim($row['tipo_cliente_sunat'] ?? ''));
                $tipoClienteTxt = strtoupper(trim($row['tipo_cliente'] ?? ''));
                $tipoDocumentoTxt = strtoupper(trim($row['tipo_doc'] ?? ''));

                $tipoClienteSunatId   = $tiposClienteSunat[$tipoClienteSunatTxt] ?? null;
                $tipoClienteId = $tiposCliente[$tipoClienteTxt] ?? null;
                $tipoDocumentoId = $tiposDocumento[$tipoDocumentoTxt] ?? null;

                if (!$tipoClienteSunatId || !$tipoClienteId || !$tipoDocumentoId || !in_array(trim($row['ubigeo']), $ubigeos)) {
                    throw new \Exception("Error en fila " . ($index + 2) . ": tipo_cliente_sunat o tipo_cliente o tipo_doc o ubigeo inválido");
                }


                $clientesData[] = [
                    'tpe_cli'         => $tipoClienteSunatId,
                    'cod_cli'            => isset($row['ruc']) ? trim($row['ruc']) : null,
                    'des_cli'            => isset($row['razon_social']) ? trim($row['razon_social']) : null,
                    'dir_cli'           => isset($row['direccion']) ? trim($row['direccion']) : null,
                    'ubi_cli'          => isset($row['ubigeo']) ? trim($row['ubigeo']) : null,
                    'ref_cli'          => isset($row['referencia']) ? trim($row['referencia']) : null,
                    'ema_cli'          => isset($row['correo_contacto']) ? trim($row['correo_contacto']) : null,
                    'tel_cli'          => isset($row['telefono']) ? trim($row['telefono']) : null,
                    'con_cli'          => isset($row['contacto']) ? trim($row['contacto']) : null,
                    'ven_cli'          => isset($row['ejecutivo_venta']) ? trim($row['ejecutivo_venta']) : null,
                    'id_tipo' => $tipoClienteId,
                    'tipo_identificacion'        => $tipoDocumentoId,
                    // Campos adicionales con valores por defecto porque no se proporcionan en el Excel
                    'cla_ent' => 0,
                    'tdo_cli' => 0,
                    'ovt_cli' => $operacion_venta,
                ];
            }

            DB::table('clientes')->upsert(
                $clientesData,
                ['cod_cli'],
                [
                    'tpe_cli',
                    'des_cli',
                    'dir_cli',
                    'ubi_cli',
                    'ref_cli',
                    'ema_cli',
                    'tel_cli',
                    'con_cli',
                    'ven_cli',
                    'id_tipo',
                    'tipo_identificacion',
                    'cla_ent',
                    'tdo_cli',
                    'ovt_cli'
                ]
            );
        });
    }
}
