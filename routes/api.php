<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\ExcelImportController;

use App\Http\Controllers\PdfController;
use Illuminate\Support\Facades\DB;


Route::post('/import/{type}', [ExcelImportController::class, 'import']);

Route::get('/test', function () {
    return response()->json(['ok' => true]);
});

Route::get('/external-pdf', [PdfController::class, 'getPdf']);


Route::delete('/drivers/truncate', function () {
    DB::statement('TRUNCATE TABLE conductores RESTART IDENTITY CASCADE;');

    return response()->json([
        'message' => 'Tabla drivers truncada correctamente'
    ]);
});

Route::delete('/clients/truncate', function () {
    DB::statement('DELETE FROM clientes CASCADE;');

    return response()->json([
        'message' => 'Tabla clientes truncada correctamente'
    ]);
});

Route::delete('/suppliers/truncate', function () {
    DB::statement('TRUNCATE TABLE proveedores RESTART IDENTITY CASCADE;');

    return response()->json([
        'message' => 'Tabla proveedores truncada correctamente'
    ]);
});

Route::delete('/agents/truncate', function () {
    DB::statement('TRUNCATE TABLE agentes RESTART IDENTITY CASCADE;');

    return response()->json([
        'message' => 'Tabla agentes truncada correctamente'
    ]);
});

Route::delete('/clients-rate/truncate', function () {
    DB::statement('TRUNCATE TABLE tarifa_clientes RESTART IDENTITY CASCADE;');
    DB::statement('TRUNCATE TABLE tarifa_cliente_ubigeo_destino RESTART IDENTITY CASCADE;');
    DB::statement('TRUNCATE TABLE tarifa_cliente_ubigeo_origen RESTART IDENTITY CASCADE;');
    DB::statement('TRUNCATE TABLE valores_adicionales_tarifa RESTART IDENTITY CASCADE;');

    return response()->json([
        'message' => 'Tabla tarifa_clientes truncada correctamente'
    ]);
});

Route::delete('/suppliers-rate/truncate', function () {
    DB::statement('TRUNCATE TABLE tarifa_proveedores RESTART IDENTITY CASCADE;');
    DB::statement('TRUNCATE TABLE ubigeos_destino_tarifa_proveedores RESTART IDENTITY CASCADE;');
    DB::statement('TRUNCATE TABLE ubigeos_salida_tarifa_proveedores RESTART IDENTITY CASCADE;');
    DB::statement('TRUNCATE TABLE tarifa_proveedores_operaciones RESTART IDENTITY CASCADE;');

    return response()->json([
        'message' => 'Tabla tarifa_proveedores truncada correctamente'
    ]);
});



