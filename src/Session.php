<?php

declare(strict_types=1);

namespace EUI;

use EUI\Proto\Batch;
use EUI\Proto\Caps;
use EUI\Proto\EventKind;
use EUI\Proto\Frame;
use EUI\Proto\Op;
use EUI\Proto\Protocol;
use EUI\Proto\Value;
use EUI\Proto\Viewport;
use EUI\View\Encoder;

/**
 * One socket, one component instance, one tree.
 *
 * The shape is the whole protocol in twenty lines: the client says Hello,
 * the server answers Welcome and mounts a tree, and from then on every event
 * is a handler, a render, and the *difference* between what the client holds
 * and what the view now says.
 */
final class Session
{
    /** Whichever side has been silent for this long sends a Ping. */
    public const IDLE_PING = 30.0;
    /** Two unanswered pings and the socket is gone, whatever it still says. */
    public const MAX_UNANSWERED_PINGS = 2;
    /**
     * The code on the `Error` that ends a session the application itself
     * closed. Codes 1–8 are the decoder's and 100–104 the client's; this is
     * a server's, and it says the session ended on purpose.
     */
    public const CLOSED_BY_APPLICATION = 200;

    public string $id;
    public int $protocol = Protocol::VERSION;
    public Viewport $viewport;
    public ?Component $component = null;

    private Encoder $encoder;
    private int $seq = 0;
    private int $acked = 0;
    private int $granted = 0;
    private bool $open = true;
    private bool $wantsRender = false;
    private ?string $closing = null;

    /** @var list<array{string,string,string}> */
    private array $notifications = [];

    public function __construct(
        private readonly WebSocket $ws,
        private readonly string $componentClass,
        private readonly ?App $app = null,
        private readonly mixed $logger = null,
    ) {
        $this->id = random_bytes(16);
        $this->encoder = new Encoder($app?->assets);
        $this->viewport = new Viewport();
    }

    public function run(): void
    {
        try {
            $hello = $this->handshake();
            if ($hello === null) {
                return;
            }

            $this->protocol = min($hello['version'], Protocol::VERSION);
            $this->granted = $hello['granted'];
            $this->viewport = $hello['viewport'];
            $this->encoder->protocol = $this->protocol;
            // A session that starts empty, which is every first Hello's
            // answer. Resuming one whose socket broke is the server's to
            // offer, and this one does not yet.
            $this->send(Frame::welcome($this->protocol, $this->id, false));

            // The faces this application draws in, bound to their roles
            // before any view names one.
            foreach ($this->app?->fonts ?? [] as $family => $hashes) {
                $this->encoder->font((string) $family, $hashes);
            }

            $class = $this->componentClass;
            $this->component = new $class($this);
            $this->component->mount(['viewport' => $this->viewport->toArray()]);
            $this->render();

            $this->pump();
            $this->component->unmount();
        } catch (EUIException $e) {
            $this->log("session ended: {$e->getMessage()}");
        } finally {
            $this->open = false;
            $this->ws->close();
        }
    }

    public function refresh(): void
    {
        $this->wantsRender = true;
    }

    /**
     * Say one line to the person through the machine they are using. Shown
     * only if they granted `notifications`, and nothing comes back either
     * way — not that it was shown, not that it was not.
     */
    public function notify(string $title, string $body = '', string $tag = ''): void
    {
        $this->notifications[] = [$title, $body, $tag];
    }

    public function close(string $reason = 'the application closed the session'): void
    {
        $this->closing = $reason;
    }

    public function granted(string $capability): bool
    {
        return ($this->granted & Caps::bit($capability)) !== 0;
    }

    // -- frames in --------------------------------------------------------
    private function handshake(): ?array
    {
        $message = $this->ws->recv(10.0);
        if ($message === null) {
            return null;
        }
        [$kind, $raw] = $message;
        if ($kind === 'text') {
            // A text frame is not an extension point; it is something that
            // is not an EUI client.
            $this->ws->sendClose(1003, 'binary frames only');
            return null;
        }
        try {
            $frame = Frame::decode($raw);
        } catch (DecodeException $e) {
            $this->fail(400, $e->getMessage());
            return null;
        }
        if ($frame->kind !== Frame::HELLO || $frame->body['version'] < 1) {
            $this->fail(400, 'the first frame is a Hello');
            return null;
        }
        return $frame->body;
    }

    /**
     * The one loop. Everything that changes the tree comes through here, in
     * the order it arrived, so two events never render on top of each other.
     */
    private function pump(): void
    {
        $unanswered = 0;
        while ($this->open) {
            $message = $this->ws->recv(self::IDLE_PING);
            if ($message === null) {
                if ($this->ws->closed) {
                    return;
                }
                $unanswered++;
                if ($unanswered > self::MAX_UNANSWERED_PINGS) {
                    return;
                }
                $this->send(Frame::ping(random_bytes(8)));
                continue;
            }
            [$kind, $raw] = $message;
            if ($kind === 'text') {
                $this->fail(self::CLOSED_BY_APPLICATION, 'binary frames only');
                return;
            }
            $unanswered = 0;
            if (!$this->handle($raw)) {
                return;
            }
            if ($this->closing !== null) {
                $this->fail(self::CLOSED_BY_APPLICATION, $this->closing);
                return;
            }
        }
    }

    private function handle(string $raw): bool
    {
        try {
            $frame = Frame::decode($raw);
        } catch (DecodeException $e) {
            $this->fail(400, $e->getMessage());
            return false;
        }

        $this->trace(fn () => sprintf('frame kind 0x%x', $frame->kind));
        switch ($frame->kind) {
            case Frame::EVENT:
                $this->dispatch($frame->body);
                break;
            case Frame::ACK:
                $this->acked = $frame->body;
                break;
            case Frame::PING:
                $this->send(Frame::pong($frame->body));
                break;
            case Frame::PONG:
                break;
            case Frame::VIEWPORT:
                $this->viewport = $frame->body;
                $this->post('viewport', ['viewport' => $this->viewport->toArray()]);
                break;
            case Frame::RESYNC:
                // Not an error, and never answered with one: the client's
                // tree is unrecoverable and it wants the document again. The
                // tables it already holds are not repeated.
                $this->encoder->forgetTree();
                $this->render();
                break;
            case Frame::ERROR:
                $this->log("client error {$frame->body['code']}: {$frame->body['message']}");
                return false;
            case Frame::UPLOAD:
            case Frame::BLOB:
                $this->log('file transfers are not implemented yet; the chunk was dropped');
                break;
            default:
                $this->fail(400, "that frame is the server's to send");
                return false;
        }
        return true;
    }

    /**
     * An event on a node that carries no handler for it *now* is dropped.
     * Usually that is a race rather than an attack — a handler a render
     * removed is still in the client's tree for the one round trip it takes
     * the new one to arrive — and nothing is looked up for it either way.
     */
    private function dispatch(array $event): void
    {
        $target = $this->encoder->eventTarget($event['node'], $event['event']);
        if ($target === null) {
            $this->trace(fn () => "event on node {$event['node']} ("
                . EventKind::name($event['event']) . ') names no handler in the tree we last sent');
            return;
        }
        [$name, $props] = $target;
        $this->trace(fn () => 'event ' . EventKind::name($event['event'])
            . " on node {$event['node']} -> {$name}");
        $this->post($name, [
            'node' => $event['node'],
            'kind' => EventKind::name($event['event']),
            'payload' => $this->resolve($event['payload']),
            'props' => $props,
        ]);
    }

    private function post(string $name, array $params): void
    {
        try {
            $this->component->handle($name, $params);
        } catch (ViewException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // A handler that raised leaves the state unchanged and the
            // screen right; the next click still works. It is a log line,
            // not the end of somebody's session.
            $this->log("{$name}: " . $e::class . ': ' . $e->getMessage());
        }
        $this->render();
    }

    // -- frames out -------------------------------------------------------
    private function render(): void
    {
        $this->wantsRender = false;
        try {
            $ops = $this->encoder->render($this->component->render());
        } catch (ViewException $e) {
            // A view that cannot be encoded fails the same way on every
            // later render, and a server that only logged it would leave a
            // window that looks alive and answers nothing.
            $this->log("view: {$e->getMessage()}");
            $this->fail(400, $e->getMessage());
            $this->open = false;
            return;
        }
        foreach ($this->notifications as $notification) {
            $ops[] = Op::notify(...$notification);
        }
        $this->notifications = [];
        if ($ops !== []) {
            $this->sendBatch($ops);
        }
        if ($this->wantsRender) {
            $this->render();
        }
    }

    /** @param list<Op> $ops */
    private function sendBatch(array $ops): void
    {
        $this->trace(fn () => 'batch of ' . \count($ops) . ': '
            . implode(' ', array_map(static fn (Op $op) => sprintf('0x%02X', $op->opcode), $ops)));
        $this->seq++;
        $this->send(Frame::batch(new Batch($this->seq, $ops)));
    }

    private function send(Frame $frame): void
    {
        $this->ws->sendBinary($frame->encode());
    }

    private function fail(int $code, string $message): void
    {
        try {
            $this->send(Frame::error($code, $message));
            $this->ws->sendClose(1000, 'session ended');
        } catch (EUIException) {
            // The socket went first; there is nobody left to tell.
        }
        $this->open = false;
    }

    /**
     * Atoms the client sent back are ids in *this* session's table, so they
     * are resolved here rather than handed to a view as numbers.
     */
    private function resolve(Value $value): mixed
    {
        if ($value->tag === Value::ATOM) {
            return $this->encoder->atomValue($value->value);
        }
        if ($value->tag === Value::LIST) {
            return array_map(fn (Value $item) => $this->resolve($item), $value->value);
        }
        return $value->toPhp();
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)("[EUI] {$message}");
        }
    }

    /**
     * `EUI_TRACE=1` prints every frame and every event this session sees.
     * The one thing worth watching when a click does nothing.
     */
    private function trace(callable $message): void
    {
        if (getenv('EUI_TRACE')) {
            $this->log('trace: ' . $message());
        }
    }
}
