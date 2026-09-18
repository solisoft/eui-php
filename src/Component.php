<?php

declare(strict_types=1);

namespace EUI;

/**
 * One component: state, handlers, and a view that is a function of the state.
 *
 *     class Counter extends Component
 *     {
 *         private int $count = 0;
 *
 *         public function onIncrement(array $params): void { $this->count++; }
 *
 *         public function render(): array
 *         {
 *             return Dsl::column([
 *                 Dsl::text((string) $this->count, ['size' => '4xl']),
 *                 Dsl::button('+', 'increment'),
 *             ]);
 *         }
 *     }
 *
 * A handler changes state and returns; it never touches the tree. What
 * reaches the client is the *difference* the change made, which is the one
 * thing this protocol is for.
 */
abstract class Component
{
    public ?Session $session = null;
    /** @var array<string,mixed> */
    public array $viewport = [];

    public function __construct(?Session $session = null)
    {
        $this->session = $session;
    }

    /**
     * Called once, when the socket has said Hello. `$params['viewport']` is
     * the window as it is right now; a `viewport` event follows every
     * resize, so nothing has to ask.
     */
    public function mount(array $params): void
    {
        $this->viewport = $params['viewport'] ?? [];
    }

    /** Called when the session ends, for whatever reason. */
    public function unmount(): void
    {
    }

    /**
     * The view: an array, and a pure function of the state. It is called
     * after every handler, so it must be cheap and must not have effects.
     */
    abstract public function render(): array;

    /**
     * Dispatch. A method named `onEventName` wins; otherwise a public method
     * of the event's own name; otherwise the event is dropped with a line in
     * the log, because a view naming a handler nobody wrote is a typo and
     * not a reason to end somebody's session.
     *
     * The name is the one the view put on a node, not the event kind:
     * `['on' => ['wake' => 'tick']]` arrives here as `tick`.
     */
    public function handle(string $name, array $params): void
    {
        if ($name === 'viewport' && isset($params['viewport'])) {
            $this->viewport = $params['viewport'];
        }

        $camel = 'on' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        foreach ([$camel, $name] as $method) {
            if (method_exists($this, $method)) {
                $this->{$method}($params);
                return;
            }
        }
        if ($name === 'viewport') {
            return;
        }
        throw new EUIException("no handler for '{$name}'");
    }

    /**
     * Render again although nothing arrived. In this runtime a session is
     * one process with one loop, so this is what a handler calls when it
     * changed something the view has not been asked about yet; it is not a
     * way for another process to push.
     */
    public function refresh(): void
    {
        $this->session?->refresh();
    }

    public function notify(string $title, string $body = '', string $tag = ''): void
    {
        $this->session?->notify($title, $body, $tag);
    }

    public function close(string $reason = 'the application closed the session'): void
    {
        $this->session?->close($reason);
    }

    /**
     * Whether the person granted this application something it asked for.
     * Being granted is a separate act from asking, and this is the only
     * place the answer shows up.
     */
    public function granted(string $capability): bool
    {
        return $this->session?->granted($capability) ?? false;
    }

    public function width(): int
    {
        return (int) ($this->viewport['width'] ?? 0);
    }

    public function height(): int
    {
        return (int) ($this->viewport['height'] ?? 0);
    }

    public function isDark(): bool
    {
        return ($this->viewport['mode'] ?? null) === 'dark';
    }
}
