<?php

namespace App\Support;

use InvalidArgumentException;

final class TariffOperationExpressionParser
{
    /**
     * Convierte una celda en las operaciones secuenciales de la tarifa.
     * Un valor numerico simple conserva la regla historica: valor base x tarifa.
     *
     * @return array{operations: array<int, array{operator: string, value: float}>, aplica_igv: bool}|null
     */
    public function parse(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $expression = strtolower(trim(str_replace("\xc2\xa0", '', (string) $value)));
        $expression = preg_replace('/\s+/u', '', $expression) ?? '';
        $expression = preg_replace('/^s\//', '', $expression) ?? '';
        $expression = str_replace(',', '.', $expression);

        if ($expression === '' || $expression === '-') {
            return null;
        }

        if (is_numeric($expression)) {
            $rate = (float) $expression;

            if ($rate <= 0) {
                return null;
            }

            return [
                'operations' => [
                    ['operator' => 'x', 'value' => $rate],
                ],
                'aplica_igv' => false,
            ];
        }

        $aplicaIgv = str_ends_with($expression, '+igv');
        if ($aplicaIgv) {
            $expression = substr($expression, 0, -4);
        }

        if (str_contains($expression, 'igv')) {
            throw new InvalidArgumentException('El indicador +igv solo puede aparecer al final de la formula.');
        }

        preg_match_all(
            '/([+\-*x\/])((?:\d+(?:\.\d+)?)|(?:\.\d+))/',
            $expression,
            $matches,
            PREG_SET_ORDER
        );

        if (empty($matches) || implode('', array_column($matches, 0)) !== $expression) {
            throw new InvalidArgumentException(
                'Formula no soportada. Use hasta tres operaciones, por ejemplo: -1*1.5+3+igv.'
            );
        }

        if (count($matches) > 3) {
            throw new InvalidArgumentException('La formula supera el maximo de tres operaciones permitido.');
        }

        $operations = [];
        foreach ($matches as $match) {
            $operator = $match[1] === '*' ? 'x' : $match[1];
            $operand = (float) $match[2];

            if ($operator === '/' && $operand == 0.0) {
                throw new InvalidArgumentException('La formula contiene una division entre cero.');
            }

            $operations[] = [
                'operator' => $operator,
                'value' => $operand,
            ];
        }

        return [
            'operations' => $operations,
            'aplica_igv' => $aplicaIgv,
        ];
    }
}
