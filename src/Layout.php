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

    /**
     * The number printed to the right of a label, matched by position rather
     * than by row, so it still works when the photo is tilted or the label wraps
     * onto two lines ("Energy" / "Bought(kWh)" with the value between them).
     *
     * Looks at every line matching $label (and not $exclude), then at every
     * mostly-numeric line that starts to its right, and returns the one whose
     * vertical centre is closest, within $reach label-heights.
     *
     * @param  array<int, array{text: string, left: int|float, top: int|float, right: int|float, bottom: int|float}>  $boxes
     */
    public static function valueBeside(array $boxes, string $label, ?string $exclude = null, float $reach = 2.5): ?string
    {
        $boxes = array_values(array_filter($boxes, fn ($box) => isset($box['text'], $box['left'], $box['top'], $box['right'], $box['bottom'])));

        $labels = array_filter($boxes, fn ($box) => preg_match($label, $box['text']) && ($exclude === null || ! preg_match($exclude, $box['text'])));
        $values = array_filter($boxes, fn ($box) => preg_match('/^\s*(?:[A-Z$]{1,4}\s*)?[\d][\d\s.,]*\s*$/i', $box['text']));

        $best = null;
        $bestDistance = INF;

        foreach ($labels as $labelBox) {
            $height = max($labelBox['bottom'] - $labelBox['top'], 1e-9);
            $centre = ($labelBox['top'] + $labelBox['bottom']) / 2;

            foreach ($values as $valueBox) {
                if ($valueBox['left'] < $labelBox['right'] - $height) {
                    continue;
                }

                $distance = abs(($valueBox['top'] + $valueBox['bottom']) / 2 - $centre);

                if ($distance <= $height * $reach && $distance < $bestDistance) {
                    $best = trim($valueBox['text']);
                    $bestDistance = $distance;
                }
            }
        }

        return $best;
    }
}
