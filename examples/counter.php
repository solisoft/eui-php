<?php

declare(strict_types=1);

/**
 * The counter, as an EUI application in PHP.
 *
 *     php examples/counter.php
 *     EUI_ALLOW_INSECURE_LOOPBACK=1 eui ws://127.0.0.1:5097/_eui/session/counter
 *
 * What to look at: `render` is a pure function of `$this->count`, and
 * pressing a button sends one `SetText` — not a page, not a diffed DOM, not
 * a frame of JSON. The style records were sent once, at mount, and every
 * later render references them by id.
 */

require __DIR__ . '/../src/autoload.php';

use EUI\App;
use EUI\Component;
use EUI\Dsl;

final class Counter extends Component
{
    private int $count = 0;

    public function onIncrement(array $params): void
    {
        $this->count++;
    }

    public function onDecrement(array $params): void
    {
        $this->count--;
    }

    public function onReset(array $params): void
    {
        $this->count = 0;
    }

    public function render(): array
    {
        return Dsl::column([
            Dsl::text('COUNTER', ['size' => 'sm', 'weight' => 'semibold', 'fg' => 'text.muted', 'font' => 'mono']),
            Dsl::text((string) $this->count, ['size' => '4xl', 'weight' => 'bold', 'fg' => $this->tone()]),
            Dsl::row([
                Dsl::button('−', 'decrement', ['tone' => 'quiet', 'size' => 'lg']),
                Dsl::button('Reset', 'reset', ['tone' => 'quiet']),
                Dsl::button('+', 'increment', ['size' => 'lg']),
            ], ['gap' => 4]),
            Dsl::divider(['width' => 320]),
            Dsl::text($this->footnote(), ['size' => 'xs', 'fg' => 'text.muted', 'font' => 'mono']),
        ], [
            'justify' => 'center', 'align' => 'center', 'gap' => 7,
            'bg' => 'surface.base', 'width' => '100%', 'height' => '100%', 'pad' => 8,
        ]);
    }

    /**
     * Colour by what the number *is*, so it reads as a legend rather than
     * decoration — and reads right in either theme, because the client
     * resolves the role and this server never learns which one they are in.
     */
    private function tone(): string
    {
        if ($this->count < 0) {
            return 'danger.base';
        }
        return $this->count === 0 ? 'text.muted' : 'success.base';
    }

    private function footnote(): string
    {
        return $this->width() . ' × ' . $this->height() . ' · ' . ($this->viewport['mode'] ?? 'light');
    }
}

$app = new App(name: 'Counter', appId: 'counter.eui-php');
$app->mount('counter', Counter::class);
$app->run(port: (int) (getenv('PORT') ?: 5097));
