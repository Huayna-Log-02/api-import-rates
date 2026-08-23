<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Carbon\Carbon;

class UnitsImport implements ToCollection, WithHeadingRow
{
    /*
     * Valores por defecto para las columnas NOT NULL de la tabla vehiculos
     * cuando el Excel no trae la columna o el valor es inválido.
     */
    private const TIPO_VEHICULO_DEFECTO = 13; // CAMIÓN (tabla tipo_de_vehiculo)
    private const MARCA_DEFECTO         = 1;  // Primera marca de la tabla marcas

    public function collection(Collection $rows)
    {

        DB::transaction(function () use ($rows) {

            /*
             * RUC de la empresa propia: NO es un proveedor, por eso se omite
             * y no se busca en la tabla proveedores.
             */
            $rucEmpresaPropia = '20538843939';

            // Se buscan los proveedores por su RUC (columna cod_prv de proveedores)
            $rucs = $rows->pluck('ruc')
                ->filter()
                ->map(fn ($valor) => trim((string) $valor))
                ->unique()
                ->values();

            $proveedores = DB::table('proveedores')
                ->whereIn('cod_prv', $rucs)
                ->pluck('id_prv', 'cod_prv');

            $tipoVehiculo = [
                'CAMION' => 13,
                'CAMIONETA' => 14,
                'FURGONETA' => 15,
                'HATCHBACK' => 16,
                'LOWBOY' => 17,
                'MINIVAN' => 18,
                'MOTOCICLETA' => 19,
                'SEDAN' => 20,
                'SUV' => 21,
                'TRAILER CISTERNA' => 22,
                'TRAILER DE CARGA GENERAL' => 23,
                'TRAILER FRIGORIFICO' => 24,
                'TRAILER PLATAFORMA' => 25
            ];

            $marcas = [
                'ALFA ROMEO' => 1,
                'AUDI' => 2,
                'BAIC' => 3,
                'BENTLEY' => 4,
                'BMW' => 5,
                'BUICK' => 6,
                'BYD' => 7,
                'CHANGAN' => 8,
                'CHERY' => 9,
                'CHEVROLET' => 10,
                'CHRYSLER' => 11,
                'CITROËN' => 12,
                'DODGE' => 13,
                'DONGFENG' => 14,
                'FERRARI' => 15,
                'FIAT' => 16,
                'FORD' => 17,
                'FOTON' => 18,
                'GEELY' => 19,
                'GENESIS' => 20,
                'GMC' => 21,
                'GREAT WALL' => 22,
                'HAVAL' => 23,
                'HONDA' => 24,
                'HYUNDAI' => 25,
                'ISUZU' => 26,
                'JAC' => 27,
                'JAGUAR' => 28,
                'JEEP' => 29,
                'KIA' => 30,
                'LAND ROVER' => 31,
                'LEXUS' => 32,
                'LINCOLN' => 33,
                'MAHINDRA' => 34,
                'MASERATI' => 35,
                'MAZDA' => 36,
                'MERCEDES-BENZ' => 37,
                'MG' => 38,
                'MINI' => 39,
                'MITSUBISHI' => 40,
                'NISSAN' => 41,
                'OPEL' => 42,
                'PEUGEOT' => 43,
                'PORSCHE' => 44,
                'RAM' => 45,
                'RENAULT' => 46,
                'SEAT' => 47,
                'SKODA' => 48,
                'SSANGYONG' => 49,
                'SUBARU' => 50,
                'SUZUKI' => 51,
                'TATA' => 52,
                'TOYOTA' => 53,
                'VOLKSWAGEN' => 54,
                'VOLVO' => 55,
                'ZOTYE' => 56,
                'HINO' => 57
            ];

            $combustibles = [
                'DIESEL' => 1,
                'DUAL' => 2,
                'ELECTRICO' => 3,
                'GLP' => 4,
                'GNV' => 5,
                'GASOLINA' => 6
            ];

            $driversData = [];

            foreach ($rows as $index => $row) {

                // Se salta solo filas totalmente vacías (relleno del Excel)
                if (
                    empty($row['placa']) && empty($row['ruc'])
                    && empty($row['tipo_vehiculo']) && empty($row['marca'])
                ) {
                    continue;
                }

                /*
                 * Si la columna no existe en el Excel o el valor es inválido,
                 * se usa el valor por defecto (o null si la columna de la BD lo permite)
                 * en lugar de detener la importación.
                 */
                $tipoVehiculoTxt   = strtoupper(trim($row['tipo_vehiculo'] ?? ''));
                $tipoVehiculoId   = $tipoVehiculo[$tipoVehiculoTxt] ?? self::TIPO_VEHICULO_DEFECTO;

                $marcaTxt   = strtoupper(trim($row['marca'] ?? ''));
                $marcaId   = $marcas[$marcaTxt] ?? self::MARCA_DEFECTO;

                $combustibleTxt   = strtoupper(trim($row['combustible'] ?? ''));
                $combustibleId   = $combustibles[$combustibleTxt] ?? null;

                $placa = isset($row['placa']) ? trim($row['placa']) : null;

                if (empty($placa)) {
                    throw new \Exception("Error en fila " . ($index + 2) . ": la placa es obligatoria");
                }

                // Si el RUC es el de la empresa propia no se busca proveedor
                $ruc = isset($row['ruc']) ? trim($row['ruc']) : null;

                $proveedorId = (!empty($ruc) && $ruc !== $rucEmpresaPropia)
                    ? ($proveedores[$ruc] ?? null)
                    : null;

                $driversData[] = [
                    'pla_veh'         => $placa,
                    'proveedor_id'    => $proveedorId,
                    'tip_veh'         => $tipoVehiculoId,
                    // Marca
                    'mar_veh'           => $marcaId,
                    'mod_veh'           => isset($row['modelo']) ? trim($row['modelo']) : null,
                    'sch_veh'         => isset($row['vin']) ? trim($row['vin']) : null,

                    'año_veh'          => isset($row['anio_fabricacion']) ? $row['anio_fabricacion'] : null,
                    'km_veh'            => isset($row['kilometraje']) ? trim($row['kilometraje']) : null,
                    // COMBUSTIBLE
                    'com_veh'            => $combustibleId,
                    'tara_veh'          => isset($row['tonelaje']) ? $row['tonelaje'] : null,
                    'cub_veh'          => isset($row['metros_cubicos']) ? $row['metros_cubicos'] : null,
                    'fsoat_veh'          => isset($row['soat']) ? $this->excelDateToSql($row['soat']) : null,
                    'frte_veh'          => isset($row['revision_tecnica']) ? $this->excelDateToSql($row['revision_tecnica']) : null,
                    'fcir_veh'          => isset($row['fecha_venc_tarjeta_circulacion']) ? $this->excelDateToSql($row['fecha_venc_tarjeta_circulacion']) : null,
                    // Estado activo por defecto
                    'sta_veh' => 1
                ];
            }

            $normalizar = fn ($valor) => mb_strtoupper(trim((string) $valor));

            $placas = array_column($driversData, 'pla_veh');

            $placasExistentes = DB::table('vehiculos')
                ->whereIn('pla_veh', $placas)
                ->pluck('pla_veh')
                ->map($normalizar)
                ->all();

            $nuevos = array_values(array_filter(
                $driversData,
                fn ($vehiculo) => !in_array($normalizar($vehiculo['pla_veh']), $placasExistentes, true)
            ));

            if (empty($nuevos)) {
                return;
            }

            DB::table('vehiculos')->upsert(
                $nuevos,
                ['pla_veh'],
                [
                    'proveedor_id',
                    'tip_veh',
                    'mod_veh',
                    'sch_veh',
                    'año_veh',
                    'km_veh',
                    'tara_veh',
                    'cub_veh',
                    'fsoat_veh',
                    'frte_veh',
                    'fcir_veh',
                    'mar_veh',
                    'com_veh'
                ]
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
