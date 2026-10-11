<?php

use Protendai\Ocr\Layout;

/**
 * A two-column receipt as ML Kit reports it: every label first, then every
 * value, with the right-hand column sitting a few pixels lower (slight skew).
 */
function receiptBoxes(): array
{
    $box = fn (string $text, int $left, int $top, int $right) => ['text' => $text, 'left' => $left, 'top' => $top, 'right' => $right, 'bottom' => $top + 28];

    return [
        $box('Receipt No.:', 50, 100, 300),
        $box('Meter No.:', 50, 140, 260),
        $box('Enter this code into your meter:', 120, 300, 700),
        $box('5078 6129 3634 5640 9991', 140, 340, 680),
        $box('Tariff:50.0@2.26:', 50, 420, 330),
        $box('Energy Bought(kWh)', 50, 500, 360),
        $box('Tender Amount', 50, 540, 330),
        $box('Energy Charge', 50, 580, 330),
        $box('0000126100108552414', 470, 103, 820),
        $box('37225230244', 600, 143, 820),
        $box('ZWG', 740, 424, 800),
        $box('297.18', 700, 506, 820),
        $box('1,400.00', 680, 545, 820),
        $box('1,320.75', 680, 583, 820),
    ];
}

it('rebuilds receipt rows so each label sits next to its value', function () {
    expect(Layout::rows(receiptBoxes()))->toBe(implode("\n", [
        'Receipt No.:   0000126100108552414',
        'Meter No.:   37225230244',
        'Enter this code into your meter:',
        '5078 6129 3634 5640 9991',
        'Tariff:50.0@2.26:   ZWG',
        'Energy Bought(kWh)   297.18',
        'Tender Amount   1,400.00',
        'Energy Charge   1,320.75',
    ]));
});

it('works with the 0-1 coordinates iOS reports', function () {
    $scaled = array_map(fn ($b) => ['text' => $b['text'], 'left' => $b['left'] / 1000, 'top' => $b['top'] / 1000, 'right' => $b['right'] / 1000, 'bottom' => $b['bottom'] / 1000], receiptBoxes());

    expect(Layout::rows($scaled))->toContain("Tender Amount   1,400.00\n");
});

it('returns null when no positions were reported, so callers fall back to the plain text', function () {
    expect(Layout::rows([]))->toBeNull()
        ->and(Layout::rows([['text' => 'no position']]))->toBeNull();
});
