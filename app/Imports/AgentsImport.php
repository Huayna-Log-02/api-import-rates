<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class AgentsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        DB::transaction(function () use ($rows) {
            $personType = [
                'NATURAL' => 1,
                'JURIDICA' => 2
            ];

            $ubigeos = DB::table('ubigeo')
                ->pluck('cod_ubi')
                ->toArray();

            $agentTypes = DB::table('tipos_proveedor')
                ->pluck('id_tipo', 'descripcion')
                ->mapWithKeys(function ($value, $key) {
                    return [strtoupper(trim($key)) => $value];
                })->toArray();

            $conditionSale = DB::table('condicion_pago')
                ->pluck('codigo', 'periodo_credito')
                ->toArray();

            $documentTypes = DB::table('tipo_de_documento')
                ->get()
                ->flatMap(function ($item) {
                    return [
                        strtoupper(trim($item->des_tdo)) => $item->id_tdo,
                        strtoupper(trim($item->abr_tdo)) => $item->id_tdo,
                    ];
                })->toArray();

            $rucs = $rows->pluck('ruc')
                ->map(fn($r) => trim($r))
                ->filter()
                ->unique()
                ->values();

            $existingAgents = DB::table('agentes')
                ->whereIn('cod_prv', $rucs)
                ->pluck('id_prv', 'cod_prv');

            $agentsData = [];

            foreach ($rows as $index => $row) {
                if (empty($row['ruc']) || empty($row['razon_social'])) {
                    continue;
                }

                $personTypeTxt = strtoupper(trim($row['tipo_agente'] ?? ''));
                $personTypeId = $personType[$personTypeTxt] ?? null;

                $agentTypeTxt = strtoupper(trim($row['tipo_contacto'] ?? ''));
                $agentTypeId = null;
                foreach ($agentTypes as $desc => $id) {
                    if (str_contains($desc, $agentTypeTxt) || str_contains($agentTypeTxt, $desc)) {
                        $agentTypeId = $id;
                        break;
                    }
                }

                $conditionSaleTxt = trim($row['condicion_pago'] ?? '');
                $conditionSaleId = $conditionSale[$conditionSaleTxt] ?? null;

                $documentTypeTxt = strtoupper(trim($row['documento_emitir'] ?? ''));
                $documentTypeId = $documentTypes[$documentTypeTxt] ?? null;

                $ubigeo = isset($row['ubigeo']) ? trim($row['ubigeo']) : null;

                if (!$personTypeId || ($ubigeo && !in_array($ubigeo, $ubigeos))) {
                    throw new \Exception("Error en fila " . ($index + 1) . ": tipo_agente o ubigeo inválido");
                }

                $ruc = trim($row['ruc']);

                $agentData = [
                    'tpe_prv'  => $personTypeId,
                    'id_tipo'  => $agentTypeId,
                    'cod_prv'  => $ruc,
                    'fee_prv'  => now(),
                    'des_prv'  => isset($row['razon_social']) ? trim($row['razon_social']) : null,
                    'dir_prv'  => isset($row['direccion']) ? trim($row['direccion']) : null,
                    'ubi_prv'  => $ubigeo,
                    'ref_prv'  => isset($row['referencia']) ? trim($row['referencia']) : null,
                    'ema_prv'  => isset($row['correo']) ? trim($row['correo']) : null,
                    'tel_prv'  => isset($row['celular']) ? trim($row['celular']) : null,
                    'con_prv'  => isset($row['contacto']) ? trim($row['contacto']) : null,
                    'car_cli'  => isset($row['cargo_contacto']) ? trim($row['cargo_contacto']) : null,
                    'tid_prv'  => isset($row['tipo_documento']) ? trim(strtoupper($row['tipo_documento'])) : null,
                    'cpa_prv'  => $conditionSaleId ?? $conditionSaleTxt,
                    'id_tde'   => $documentTypeId,
                    'not_prv'  => isset($row['destinos']) ? trim($row['destinos']) : null,
                    'sta_prv'  => 1
                ];

                if (isset($existingAgents[$ruc])) {
                    DB::table('agentes')
                        ->where('cod_prv', $ruc)
                        ->update($agentData);
                } else {
                    $agentsData[] = $agentData;
                }
            }

            if (!empty($agentsData)) {
                DB::table('agentes')->insert($agentsData);
            }
        });
    }
}
