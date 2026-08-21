<?php

namespace App\Services;

use App\Imports\AgentRateImport;
use App\Imports\AgentsImport;
use App\Imports\ClientRateImport;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ClientsImport;
use App\Imports\DriversImport;
use App\Imports\SupplierRateImport;
use App\Imports\SuppliersImport;
use App\Imports\UnitsImport;
use App\Support\AgentRateImportReport;
use App\Support\SupplierRateImportReport;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

class ExcelImportService
{
    public function handle(string $type, UploadedFile $file)
    {
        return match ($type) {
            'clients' => $this->importClients($file),
            'drivers' => $this->importDrivers($file),
            'units' => $this->importUnits($file),
            'suppliers' => $this->importSuppliers($file),
            'agents' => $this->importAgents($file),
            'agents-rate' => $this->importAgentsRate($file),
            'clients-rate' => $this->importClientsRate($file),
            'suppliers-rate' => $this->importSuppliersRate($file),
            default   => response()->json(['error' => 'Tipo de importación no válido'], 400)
        };
    }

    private function importClients(UploadedFile $file)
    {
        Excel::import(new ClientsImport, $file);
        return response()->json([
            'status' => 'ok',
            'message' => 'Clientes importados correctamente'
        ]);
    }

    private function importDrivers(UploadedFile $file)
    {
        Excel::import(new DriversImport, $file);
        return response()->json([
            'status' => 'ok',
            'message' => 'Conductores y auxiliares importados correctamente'
        ]);
    }

    private function importUnits(UploadedFile $file)
    {
        Excel::import(new UnitsImport, $file);
        return response()->json([
            'status' => 'ok',
            'message' => 'Unidades importadas correctamente'
        ]);
    }

    private function importSuppliers(UploadedFile $file)
    {
        Excel::import(new SuppliersImport, $file);
        return response()->json([
            'status' => 'ok',
            'message' => 'Proveedores importados correctamente'
        ]);
    }

    private function importAgents(UploadedFile $file)
    {
        Excel::import(new AgentsImport, $file);
        return response()->json([
            'status' => 'ok',
            'message' => 'Agentes importados correctamente'
        ]);
    }

    private function importClientsRate(UploadedFile $file)
    {
        Excel::import(new ClientRateImport, $file);
        return response()->json([
            'status' => 'ok',
            'message' => 'Tarifas de clientes importadas correctamente'
        ]);
    }

    private function importAgentsRate(UploadedFile $file)
    {
        $report = new AgentRateImportReport;
        Excel::import(new AgentRateImport($report), $file);

        $missingAgents = $report->missingAgents();

        return response()->json([
            'status' => 'ok',
            'message' => $missingAgents === []
                ? 'Tarifas de agentes importadas correctamente'
                : 'Tarifas importadas parcialmente: algunos agentes no fueron encontrados',
            'tarifas_importadas' => $report->importedTariffs(),
            'agentes_no_encontrados' => $missingAgents,
        ]);
    }

    private function importSuppliersRate(UploadedFile $file)
    {
        $report = new SupplierRateImportReport;
        Excel::import(new SupplierRateImport($report), $file);

        $missingClients = $report->missingClients();
        $missingSuppliers = $report->missingSuppliers();
        $hasMissingEntities = $missingClients !== [] || $missingSuppliers !== [];

        return response()->json([
            'status' => 'ok',
            'message' => $hasMissingEntities
                ? 'Tarifas importadas parcialmente: algunos clientes o proveedores no fueron encontrados'
                : 'Tarifas de proveedores importadas correctamente',
            'tarifas_importadas' => $report->importedTariffs(),
            'clientes_no_encontrados' => $missingClients,
            'proveedores_no_encontrados' => $missingSuppliers,
        ]);
    }
}
