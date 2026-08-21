<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExcelImportService;
use Illuminate\Http\Request;

class ExcelImportController extends Controller
{
    public function import(Request $request, string $type)
    {

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480'
        ]);

        return app(ExcelImportService::class)->handle($type, $request->file('file'));
    }
}
