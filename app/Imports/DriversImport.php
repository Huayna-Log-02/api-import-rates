<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Carbon\Carbon;

class DriversImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {

        DB::transaction(function () use ($rows) {

            $estCivil = [
                'DIVORCIADO' => 1,
                'SOLTERO' => 2,
                'CASADO' => 3,
                'VIUDO' => 4,
                'CONVIVIENTE' => 5
            ];

            $catA4 = [
                'SI' => true,
                'NO' => false
            ];



            //Datos no proporcionados en el Excel pero necesarios para la inserción, se asignan valores por defecto
            $ctdCon = '1';
            $ubigeos = DB::table('ubigeo')
                ->pluck('cod_ubi')
                ->toArray();


            $driversData = [];

            foreach ($rows as $index => $row) {

                if (
                    empty($row['apellidos']) || empty($row['nombres']) ||
                    empty($row['dni'])
                ) {
                    continue;
                }

                $estCivilTxt   = strtoupper(trim($row['estado_civil'] ?? ''));
                $estCivilId   = $estCivil[$estCivilTxt] ?? null;
                $a4Txt   = strtoupper(trim($row['categoria_a4'] ?? 'NO'));
                $a4Val   = $catA4[$a4Txt] ?? false;


                if (!$estCivilId || !in_array(trim($row['ubigeo']), $ubigeos)) {
                    throw new \Exception("Error en fila " . ($index + 2) . ": estado_civil o ubigeo inválido");
                }

                $driversData[] = [
                    'des_con'            => isset($row['apellidos']) && isset($row['nombres']) ? trim($row['apellidos'] . ' ' . $row['nombres']) : null,
                    'nom_con'         => isset($row['nom_con']) ? trim($row['nom_con']) : null,
                    'ape_con'         => isset($row['ape_con']) ? trim($row['ape_con']) : null,
                    'dir_con'           => isset($row['direccion']) ? trim($row['direccion']) : null,
                    'dis_con'           => isset($row['ubigeo']) ? trim($row['ubigeo']) : null,
                    'dni_con'         => isset($row['dni']) ? trim($row['dni']) : null,
                    'fdni_con'          => isset($row['fecha_dni_venc']) ? $this->excelDateToSql($row['fecha_dni_venc']) : null,
                    'tel_con'            => isset($row['celular']) ? trim($row['celular']) : null,
                    'civ_con'            => isset($estCivilId) ? $estCivilId : null,
                    'fna_con'          => isset($row['fecha_nacimiento']) ? $this->excelDateToSql($row['fecha_nacimiento']) : null,
                    'fin_con'          => isset($row['fecha_ingreso']) ? $this->excelDateToSql($row['fecha_ingreso']) : null,
                    'nlc_con'          => isset($row['licencia']) ? trim($row['licencia']) : null,
                    'fexp_con'          => isset($row['fecha_expedicion_licencia']) ? $this->excelDateToSql($row['fecha_expedicion_licencia']) : null,
                    'cat_con'          => isset($row['categoria_brevete']) ? trim($row['categoria_brevete']) : null,
                    'flic_con'          => isset($row['fecha_vencimiento_licencia']) ? $this->excelDateToSql($row['fecha_vencimiento_licencia']) : null,
                    'a4_con'          => $a4Val,
                    'emidni_con'          => isset($row['fecha_emision_licencia_a4']) ? $this->excelDateToSql($row['fecha_emision_licencia_a4']) : null,
                    'vendni_con'          => isset($row['vendni_con']) ? trim($row['vendni_con']) : null,
                    // Campos adicionales con valores por defecto porque no se proporcionan en el Excel
                    'ctd_con' => $ctdCon,
                    'sta_con' => 1
                ];
            }

            DB::table('conductores')->upsert(
                $driversData,
                ['dni_con'],
                [
                    'des_con',
                    'nom_con',
                    'ape_con',
                    'dir_con',
                    'dis_con',
                    'dni_con',
                    'fdni_con',
                    'tel_con',
                    'civ_con',
                    'fna_con',
                    'fin_con',
                    'nlc_con',
                    'fexp_con',
                    'cat_con',
                    'flic_con',
                    'a4_con',
                    'emidni_con',
                    'vendni_con'
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
