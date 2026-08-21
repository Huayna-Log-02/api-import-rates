<?php

namespace Tests\Unit;

use App\Support\TariffOperationExpressionParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TariffOperationExpressionParserTest extends TestCase
{
    private TariffOperationExpressionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new TariffOperationExpressionParser;
    }

    public function test_numeric_rate_is_a_multiplication_of_the_base_value(): void
    {
        $this->assertSame([
            'operations' => [
                ['operator' => 'x', 'value' => 0.3],
            ],
            'aplica_igv' => false,
        ], $this->parser->parse('S/ 0.30'));
    }

    public function test_formula_is_parsed_in_written_order(): void
    {
        $this->assertSame([
            'operations' => [
                ['operator' => '-', 'value' => 1.0],
                ['operator' => 'x', 'value' => 0.35],
                ['operator' => '+', 'value' => 5.0],
            ],
            'aplica_igv' => false,
        ], $this->parser->parse('-1*0.35+5'));
    }

    public function test_formula_detects_igv_case_insensitively(): void
    {
        $result = $this->parser->parse(' -1 * 1,5 + 3 + IGV ');

        $this->assertSame([
            ['operator' => '-', 'value' => 1.0],
            ['operator' => 'x', 'value' => 1.5],
            ['operator' => '+', 'value' => 3.0],
        ], $result['operations']);
        $this->assertTrue($result['aplica_igv']);
    }

    public function test_agent_rate_formulas_are_supported(): void
    {
        $formulas = [
            '-1*0.3+3+igv',
            '-1*0.6+4',
            '-1*0.5+3.5',
            '-1*0.5+12',
            '-1*0.5+10',
            '-1*0.5+4',
            '-1*0.3+5',
            '-1*0.5+5',
            '-1*0.4+5',
            '-1*1.5+3+igv',
            '-1*1.5+40+igv',
            '-1*0.34+5',
            '-1*0.45+5',
            '-1*0.2+2',
        ];

        foreach ($formulas as $formula) {
            $result = $this->parser->parse($formula);

            $this->assertCount(3, $result['operations'], "No se pudo interpretar {$formula}");
        }
    }

    #[DataProvider('emptyValueProvider')]
    public function test_empty_or_non_positive_simple_values_are_ignored(mixed $value): void
    {
        $this->assertNull($this->parser->parse($value));
    }

    public static function emptyValueProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'dash' => ['-'],
            'zero number' => [0],
            'zero string' => ['0'],
        ];
    }

    #[DataProvider('invalidFormulaProvider')]
    public function test_invalid_formulas_are_rejected(string $formula, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->parser->parse($formula);
    }

    public static function invalidFormulaProvider(): array
    {
        return [
            'more than three operations' => ['-1*2+3/4', 'maximo de tres operaciones'],
            'igv in the middle' => ['-1+igv*2', '+igv solo puede aparecer al final'],
            'division by zero' => ['/0', 'division entre cero'],
            'unsupported variable' => ['peso*0.3', 'Formula no soportada'],
        ];
    }
}
