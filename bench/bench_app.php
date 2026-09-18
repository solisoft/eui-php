<?php

declare(strict_types=1);

/**
 * The PHP half of the comparison: the same application as the Ruby gem's
 * `bench/bench_app.rb`, the Python package's and the Soli app beside them,
 * node for node.
 *
 *     ROWS=10000 PORT=5104 php bench/bench_app.php
 *
 * Raw arrays rather than the DSL, so the files can be read side by side and
 * the trees compared line by line. What is being measured is what it costs
 * each server to turn this into bytes — not four different views.
 */

require __DIR__ . '/../src/autoload.php';

use EUI\App;
use EUI\Component;

final class Bench extends Component
{
    private const WIDTHS = [90, 160, 90, 90];

    private string $order = 'asc';
    private int $ticks = 0;

    public function onSort(array $params): void
    {
        $this->order = $this->order === 'asc' ? 'desc' : 'asc';
    }

    public function onTick(array $params): void
    {
        $this->ticks++;
    }

    /** @return array{string,string,string,string} */
    private function cells(int $i): array
    {
        return ["FA-{$i}", 'Client ' . ($i % 37) . ' SARL',
                $i % 3 === 0 ? 'Paid' : 'Open', (100 + $i * 37) . ' EUR'];
    }

    private function rowNode(int $i): array
    {
        $values = $this->cells($i);
        $cells = [];
        for ($c = 0; $c < 4; $c++) {
            $cells[] = ['k' => 'text', 't' => $values[$c],
                        's' => ['width' => self::WIDTHS[$c], 'size' => 1, 'fg' => 'text.default']];
        }
        return [
            'k' => 'box',
            'key' => "r{$i}",
            's' => ['display' => 'row', 'gap' => 4, 'pad' => [1, 3, 1, 3],
                    'border' => [0, 0, 1, 0], 'border_color' => 'border.subtle'],
            'c' => $cells,
        ];
    }

    public function render(): array
    {
        $rows_count = (int) (getenv('ROWS') ?: 10000);
        $ids = range(0, $rows_count - 1);
        if ($this->order === 'desc') {
            $ids = array_reverse($ids);
        }
        $rows = array_map($this->rowNode(...), $ids);

        return [
            'k' => 'box',
            's' => ['display' => 'column', 'pad' => 6, 'gap' => 3, 'bg' => 'surface.base',
                    'width' => '100%', 'height' => '100%'],
            'c' => [
                [
                    'k' => 'box',
                    's' => ['display' => 'row', 'gap' => 3, 'align' => 'center'],
                    'c' => [
                        ['k' => 'text', 't' => 'Invoices', 's' => ['size' => 5, 'weight' => 'bold']],
                        ['k' => 'text', 't' => (string) $this->ticks,
                         's' => ['size' => 2, 'fg' => 'text.muted', 'font' => 'mono']],
                        ['k' => 'spacer', 's' => ['grow' => 1]],
                        [
                            'k' => 'box',
                            's' => ['bg' => 'surface.raised', 'radius' => 2, 'pad' => [2, 4], 'cursor' => 'pointer'],
                            'on' => ['click' => 'sort'],
                            'c' => [['k' => 'text', 't' => $this->order === 'asc' ? 'Sort down' : 'Sort up',
                                     's' => ['weight' => 'medium']]],
                        ],
                        [
                            'k' => 'box',
                            's' => ['bg' => 'accent.base', 'radius' => 2, 'pad' => [2, 4], 'cursor' => 'pointer'],
                            'on' => ['click' => 'tick'],
                            'c' => [['k' => 'text', 't' => 'Tick', 's' => ['fg' => 'accent.on', 'weight' => 'medium']]],
                        ],
                    ],
                ],
                ['k' => 'scroll', 's' => ['grow' => 1, 'width' => '100%'],
                 'c' => [['k' => 'box', 's' => ['display' => 'column'], 'c' => $rows]]],
            ],
        ];
    }
}

$app = new App(name: 'Bench', appId: 'bench.eui-php');
$app->mount('bench', Bench::class);
$app->run(port: (int) (getenv('PORT') ?: 5104));
