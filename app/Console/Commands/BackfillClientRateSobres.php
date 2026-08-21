<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Corrige importaciones historicas de tarifas de cliente.
 *
 * Este comando existe porque el importador anterior guardo el valor del Excel
 * "sobre" en valores_adicionales_tarifa.precio_sobres, pero el sistema calcula
 * Sobres como un detalle independiente de articulo. Su finalidad es crear esos
 * detalles faltantes sin duplicar solicitudes, costeos ni cabeceras de tarifa.
 * 
 * 
 * 
 * (ACTUALIZACION) Este comando ya no debe ser necesario, porque el importador actual ya crea los detalles de Sobres
 * correctamente. Sin embargo, se mantiene por si se necesita corregir importaciones historicas.
 */
class BackfillClientRateSobres extends Command
{
    protected $signature = 'tariffs:backfill-client-sobres {--dry-run : Simula la correccion sin insertar registros}';

    protected $description = 'Crea detalles de Sobres faltantes desde precio_sobres en tarifas de cliente ya importadas.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sobresId = DB::table('articulo')
            ->whereRaw('LOWER(TRIM(descripcion)) = ?', ['sobres'])
            ->value('codigo');

        if (!$sobresId) {
            $this->error('No se encontro el articulo Sobres.');

            return self::FAILURE;
        }

        $sourceDetails = DB::table('valores_adicionales_tarifa')
            ->select('id', 'tarifa_id', 'precio_sobres')
            ->whereNotNull('precio_sobres')
            ->where('precio_sobres', '>', 0)
            ->orderBy('tarifa_id')
            ->orderBy('id')
            ->get();

        if ($sourceDetails->isEmpty()) {
            $this->info('No hay detalles con precio_sobres pendiente de corregir.');

            return self::SUCCESS;
        }

        $sourceIds = $sourceDetails->pluck('id')->values();
        $sourceRoutes = $this->loadRoutes($sourceIds);

        $existingSobres = DB::table('valores_adicionales_tarifa')
            ->select('id', 'tarifa_id', 'precio_base')
            ->where('unidad', $sobresId)
            ->get();

        $existingKeys = [];
        if ($existingSobres->isNotEmpty()) {
            $existingRoutes = $this->loadRoutes($existingSobres->pluck('id')->values());

            foreach ($existingSobres as $detail) {
                $route = $existingRoutes[$detail->tarifa_id . '|' . $detail->id] ?? null;
                $existingKeys[$this->makeKey(
                    $detail->tarifa_id,
                    $detail->precio_base,
                    $route['origins'] ?? [],
                    $route['destinations'] ?? []
                )] = true;
            }
        }

        $planned = [];
        $plannedKeys = [];
        $skippedExisting = 0;
        $skippedDuplicateSource = 0;
        $skippedMissingRoute = 0;

        foreach ($sourceDetails as $detail) {
            $route = $sourceRoutes[$detail->tarifa_id . '|' . $detail->id] ?? null;

            if (!$route || empty($route['origins']) || empty($route['destinations'])) {
                $skippedMissingRoute++;
                continue;
            }

            $key = $this->makeKey(
                $detail->tarifa_id,
                $detail->precio_sobres,
                $route['origins'],
                $route['destinations']
            );

            if (isset($existingKeys[$key])) {
                $skippedExisting++;
                continue;
            }

            if (isset($plannedKeys[$key])) {
                $skippedDuplicateSource++;
                continue;
            }

            $plannedKeys[$key] = true;
            $planned[] = [
                'source_id' => $detail->id,
                'tarifa_id' => $detail->tarifa_id,
                'precio_base' => $detail->precio_sobres,
                'origins' => $route['origins'],
                'destinations' => $route['destinations'],
            ];
        }

        $this->line("Articulo Sobres: {$sobresId}");
        $this->line("Detalles con precio_sobres: {$sourceDetails->count()}");
        $this->line("Detalles Sobres existentes equivalentes: {$skippedExisting}");
        $this->line("Duplicados en origen saltados: {$skippedDuplicateSource}");
        $this->line("Detalles sin ruta saltados: {$skippedMissingRoute}");
        $this->line('Detalles Sobres a crear: ' . count($planned));

        if ($dryRun) {
            $this->warn('Dry-run activo: no se inserto ningun registro.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($planned, $sobresId) {
            foreach ($planned as $detail) {
                $newDetailId = DB::table('valores_adicionales_tarifa')->insertGetId([
                    'uuid_temp' => (string) Str::uuid(),
                    'unidad' => $sobresId,
                    'precio_base' => $detail['precio_base'],
                    'tarifa_id' => $detail['tarifa_id'],
                    'precio_tarifa' => null,
                    'precio_sobres' => null,
                ], 'id');

                foreach ($detail['origins'] as $origin) {
                    DB::table('tarifa_cliente_ubigeo_origen')->insert([
                        'item' => $newDetailId,
                        'tarifa_id' => $detail['tarifa_id'],
                        'ubigeo' => $origin,
                    ]);
                }

                foreach ($detail['destinations'] as $destination) {
                    DB::table('tarifa_cliente_ubigeo_destino')->insert([
                        'item' => $newDetailId,
                        'tarifa_id' => $detail['tarifa_id'],
                        'ubigeo' => $destination,
                    ]);
                }
            }
        });

        $this->info('Detalles Sobres creados: ' . count($planned));

        return self::SUCCESS;
    }

    private function loadRoutes($detailIds): array
    {
        $origins = DB::table('tarifa_cliente_ubigeo_origen')
            ->select('tarifa_id', 'item', 'ubigeo')
            ->whereIn('item', $detailIds)
            ->get();

        $destinations = DB::table('tarifa_cliente_ubigeo_destino')
            ->select('tarifa_id', 'item', 'ubigeo')
            ->whereIn('item', $detailIds)
            ->get();

        $routes = [];
        foreach ($origins as $origin) {
            $key = $origin->tarifa_id . '|' . $origin->item;
            $routes[$key]['origins'][] = $origin->ubigeo;
        }

        foreach ($destinations as $destination) {
            $key = $destination->tarifa_id . '|' . $destination->item;
            $routes[$key]['destinations'][] = $destination->ubigeo;
        }

        foreach ($routes as &$route) {
            $route['origins'] = $this->normalizeUbigeos($route['origins'] ?? []);
            $route['destinations'] = $this->normalizeUbigeos($route['destinations'] ?? []);
        }
        unset($route);

        return $routes;
    }

    private function makeKey($tarifaId, $price, array $origins, array $destinations): string
    {
        return implode('|', [
            $tarifaId,
            $this->normalizePrice($price),
            implode(',', $this->normalizeUbigeos($origins)),
            implode(',', $this->normalizeUbigeos($destinations)),
        ]);
    }

    private function normalizePrice($value): string
    {
        $normalized = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }

    private function normalizeUbigeos(array $ubigeos): array
    {
        return collect($ubigeos)
            ->map(fn ($ubigeo) => trim((string) $ubigeo))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
