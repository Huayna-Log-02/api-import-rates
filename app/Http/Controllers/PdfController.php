<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfController extends Controller
{
        public function getPdf()
    {
        // Contenido HTML del PDF
        $html = "
            <h1>Documento de prueba</h1>
            <p>Este PDF viene desde una API externa.</p>
            <p>No tiene firma digital.</p>
        ";

        // Generar PDF
        $pdf = Pdf::loadHTML($html);

        // Devolver como stream (sin guardar)
        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename=\"documento.pdf\"');
    }
}
