<?php

namespace Protendai\Ocr;

/**
 * Rebuilds reading order from line positions.
 *
 * OCR engines read two-column layouts (receipts, invoices, forms) a column at
 * a time, so a label and the value printed beside it come out far apart. Kept
 * free of Laravel so it can be unit-tested and reused on its own.
 */
final class Layout
{
    /**
     * @param  array<int, array{text: string, left: int|float, top: int|float, right: int|float, bottom: int|float}>  $boxes
     */
    public static function rows(array $boxes): ?string
    {
        $boxes = array_values(array_filter($boxes, fn ($box) => isset($box['text'], $box['top'], $box['bottom'], $box['left'])));

        if ($boxes === []) {
            return null;
        }

        usort($boxes, fn ($a, $b) => ($a['top'] + $a['bottom']) <=> ($b['top'] + $b['bottom']));

        $rows = [];

        foreach ($boxes as $box) {
            $centre = ($box['top'] + $box['bottom']) / 2;
            $placed = false;

            foreach ($rows as &$row) {
                // Same row when this line's centre sits within the middle of the row's first line.
                $height = $row['bottom'] - $row['top'];

                if (abs($centre - $row['centre']) <= $height * 0.45) {
                    $row['boxes'][] = $box;
                    $placed = true;
                    break;
                }
            }
            unset($row);

            if (! $placed) {
                $rows[] = ['centre' => $centre, 'top' => $box['top'], 'bottom' => $box['bottom'], 'boxes' => [$box]];
            }
        }

        usort($rows, fn ($a, $b) => $a['centre'] <=> $b['centre']);

        return implode("\n", array_map(function (array $row): string {
            usort($row['boxes'], fn ($a, $b) => $a['left'] <=> $b['left']);

            return implode('   ', array_column($row['boxes'], 'text'));
        }, $rows));
    }
}
