<?php

namespace Tests\Unit;

use App\Support\TariffOperationColumnParser;
use PHPUnit\Framework\TestCase;

class TariffOperationColumnParserTest extends TestCase
{
    private TariffOperationColumnParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new TariffOperationColumnParser;
    }

    public function test_parses_box_operation_column(): void
    {
        $this->assertSame([
            'unidad_rango' => 'caja',
            'desde' => 1,
            'hasta' => 2,
            'unidad_calculo' => 'caja a',
        ], $this->parser->parse('op_caja_1-2_caja-a'));
    }

    public function test_parses_terrestrial_delivery_column_including_zero_range(): void
    {
        $this->assertSame([
            'unidad_rango' => 'kg',
            'desde' => 0,
            'hasta' => 99,
            'unidad_calculo' => 'kg',
        ], $this->parser->parse('terre_reparto_kg_0-99'));
    }

    public function test_ignores_columns_outside_the_confirmed_scope(): void
    {
        $this->assertFalse($this->parser->isImportable('log_inversa'));
        $this->assertFalse($this->parser->isImportable('aereo_reparto_kg_0-10'));
        $this->assertFalse($this->parser->isImportable('terre_ofi_kg_0-99'));
        $this->assertNull($this->parser->parse('terre_ofi_kg_0-99'));
    }
}
