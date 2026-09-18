<?php

declare(strict_types=1);

/**
 * The whole thing, over a real socket: handshake, Hello, Welcome, Mount, an
 * event, and the patch it costs.
 */

require_once __DIR__ . '/Client.php';

use EUI\App;
use EUI\Component;
use EUI\Dsl;
use EUI\Proto\EventKind;
use EUI\Proto\Frame;
use EUI\Proto\Op;
use EUI\Proto\Protocol;
use EUI\Proto\Value;
use EUI\Proto\Viewport;
use EUI\ViewException;

final class TestCounter extends Component
{
    private int $count = 0;

    public function onIncrement(array $params): void
    {
        $this->count++;
    }

    public function render(): array
    {
        return Dsl::column([
            Dsl::text((string) $this->count, ['size' => '2xl']),
            Dsl::button('+', 'increment'),
        ], ['gap' => 4]);
    }
}

final class TestFragile extends Component
{
    private string $state = 'ok';

    public function onBoom(array $params): void
    {
        throw new RuntimeException('the handler fell over');
    }

    public function onBreakTheView(array $params): void
    {
        $this->state = 'broken';
    }

    public function onCount(array $params): void
    {
        $this->state = 'counted';
    }

    public function render(): array
    {
        if ($this->state === 'broken') {
            throw new ViewException('a role nobody defined');
        }
        // Two handlers on the root, so a test can aim at one without the
        // button underneath answering first.
        return Dsl::column([
            Dsl::text($this->state),
            Dsl::button('go', 'count'),
        ], ['on' => ['click' => 'boom', 'double_click' => 'break_the_view']]);
    }
}

final class TestSizer extends Component
{
    public function render(): array
    {
        return Dsl::column([Dsl::text($this->width() . ' x ' . $this->height())]);
    }
}

// A test's server says nothing: what it would print belongs to the test
// that asked for it, and the harness prints the result.
$quiet = static function (string $line): void {
};

$counterApp = static function () use ($quiet): App {
    $app = new App(name: 'Counter', appId: 'counter.test', logger: $quiet);
    $app->mount('counter', TestCounter::class);
    return $app;
};

$firstClickNode = static function (Op $mount): ?int {
    $tree = $mount->get('subtree');
    foreach ($tree->nodes as $node) {
        foreach ($tree->handlersOf($node) as [$event, $_handler]) {
            if ($event === EventKind::code('click')) {
                return $node->id;
            }
        }
    }
    return null;
};

return [
    'a session welcomes, mounts and patches' => function () use ($counterApp, $firstClickNode): void {
        [$port, $pid] = eui_serve($counterApp);
        try {
            $client = new TestClient($port, '/_eui/session/counter');
            $client->hello();

            $welcome = $client->recv();
            Assert::same(Frame::WELCOME, $welcome->kind);
            Assert::same(Protocol::VERSION, $welcome->body['version']);
            Assert::same(16, \strlen($welcome->body['session']));
            Assert::false($welcome->body['resumed'], 'a first Hello is answered with a session that starts empty');

            $batch = $client->recv();
            Assert::same(Frame::BATCH, $batch->kind);
            Assert::same(1, $batch->body->seq);
            $ops = $batch->body->ops;
            $mount = $ops[\count($ops) - 1];
            Assert::same(Op::MOUNT, $mount->opcode);

            $node = $firstClickNode($mount);
            Assert::true($node !== null, 'the button carries a click handler');

            $client->click($node);
            $patch = $client->recv();
            Assert::same(Frame::BATCH, $patch->kind);
            Assert::same(2, $patch->body->seq);
            Assert::same([Op::SET_TEXT], array_map(static fn (Op $o) => $o->opcode, $patch->body->ops),
                'one changed number is one op');
            Assert::same('1', $patch->body->ops[0]->get('text')->inline);
            $client->close();
        } finally {
            eui_stop($pid);
        }
    },

    'an event on a node with no handler is dropped' => function () use ($counterApp): void {
        [$port, $pid] = eui_serve($counterApp);
        try {
            $client = new TestClient($port, '/_eui/session/counter');
            $client->hello();
            $client->recv();
            $client->recv();
            $client->click(9999);
            Assert::null($client->recv(0.4), 'nothing is looked up and nothing is answered');
            $client->close();
        } finally {
            eui_stop($pid);
        }
    },

    'a ping is answered and an ack is not' => function () use ($counterApp): void {
        [$port, $pid] = eui_serve($counterApp);
        try {
            $client = new TestClient($port, '/_eui/session/counter');
            $client->hello();
            $client->recv();
            $client->recv();
            $client->send(Frame::ping('12345678'));
            $pong = $client->recv();
            Assert::same(Frame::PONG, $pong->kind);
            Assert::same('12345678', $pong->body, 'the nonce comes back as it went');

            $client->send(Frame::ack(1));
            Assert::null($client->recv(0.3));
            $client->close();
        } finally {
            eui_stop($pid);
        }
    },

    'a resync is answered with a fresh mount' => function () use ($counterApp): void {
        [$port, $pid] = eui_serve($counterApp);
        try {
            $client = new TestClient($port, '/_eui/session/counter');
            $client->hello();
            $client->recv();
            $client->recv();
            $client->send(Frame::resync());
            $batch = $client->recv();
            $ops = $batch->body->ops;
            Assert::same(Op::MOUNT, $ops[\count($ops) - 1]->opcode);
            $client->close();
        } finally {
            eui_stop($pid);
        }
    },

    'the asset endpoint serves by content' => function (): void {
        [$port, $pid] = eui_serve(static function () use ($quiet): App {
            $app = new App(name: 'Counter', appId: 'counter.test', logger: $quiet);
            $app->mount('counter', TestCounter::class);
            $app->assets->addBytes('some bytes', 'image/png');
            return $app;
        });
        try {
            // The hash is content, so the parent can compute it without asking.
            $hex = bin2hex(\EUI\Blake3::hash('some bytes'));
            $response = file_get_contents("http://127.0.0.1:{$port}/_eui/asset/{$hex}");
            Assert::same('some bytes', $response);
            Assert::contains('public, max-age=31536000, immutable', implode("\n", $http_response_header ?? []));

            // A hash this server does not hold is a 404 and never a
            // redirect: the name is the content, so there is nowhere else it
            // could be. `file_get_contents` answers false on a 404, and the
            // status is in the header list either way.
            $missing = @file_get_contents("http://127.0.0.1:{$port}/_eui/asset/" . str_repeat('0', 64));
            Assert::false($missing);
            Assert::contains('404', implode("\n", $http_response_header ?? []));
        } finally {
            eui_stop($pid);
        }
    },

    'a session that does not exist is a 404' => function () use ($counterApp): void {
        [$port, $pid] = eui_serve($counterApp);
        try {
            @file_get_contents("http://127.0.0.1:{$port}/_eui/session/nobody");
            Assert::contains('404', implode("\n", $http_response_header ?? []));
        } finally {
            eui_stop($pid);
        }
    },

    // -- what goes wrong --------------------------------------------------
    'a handler that raises does not end the session' => function (): void {
        [$port, $pid] = eui_serve(static function () use ($quiet): App {
            $app = new App(name: 'Fragile', appId: 'fragile.test', logger: $quiet);
            $app->mount('fragile', TestFragile::class);
            return $app;
        });
        try {
            $client = new TestClient($port, '/_eui/session/fragile');
            $client->hello();
            $client->recv();
            $mount = $client->recv();
            $ops = $mount->body->ops;
            $root = $ops[\count($ops) - 1]->get('subtree')->nodes[0]->id;

            $client->send(Frame::event($root, EventKind::code('click'), 0, Value::null()));
            // The state did not change, so the view did not change, so there
            // is nothing to send. The session is still up, which is the point.
            Assert::null($client->recv(0.4));

            $client->send(Frame::viewport(new Viewport(500, 400, 100, 1, 1, 100)));
            Assert::null($client->recv(0.4), 'a viewport this view ignores costs nothing either');
            $client->close();
        } finally {
            eui_stop($pid);
        }
    },

    'a view that cannot be encoded ends the session with a reason' => function (): void {
        [$port, $pid] = eui_serve(static function () use ($quiet): App {
            $app = new App(name: 'Fragile', appId: 'fragile.test', logger: $quiet);
            $app->mount('fragile', TestFragile::class);
            return $app;
        });
        try {
            $client = new TestClient($port, '/_eui/session/fragile');
            $client->hello();
            $client->recv();
            $mount = $client->recv();
            $ops = $mount->body->ops;
            $root = $ops[\count($ops) - 1]->get('subtree')->nodes[0]->id;

            $client->send(Frame::event($root, EventKind::code('double_click'), 0, Value::null()));
            $error = $client->recv();
            Assert::same(Frame::ERROR, $error->kind);
            Assert::same(400, $error->body['code']);
            Assert::contains('a role nobody defined', $error->body['message']);
            $client->close();
        } finally {
            eui_stop($pid);
        }
    },

    'the viewport reaches the component' => function (): void {
        [$port, $pid] = eui_serve(static function () use ($quiet): App {
            $app = new App(name: 'Sizer', appId: 'sizer.test', logger: $quiet);
            $app->mount('sizer', TestSizer::class);
            return $app;
        });
        try {
            $client = new TestClient($port, '/_eui/session/sizer');
            $client->hello(1280, 900);
            $client->recv();
            $mount = $client->recv();
            $ops = $mount->body->ops;
            $tree = $ops[\count($ops) - 1]->get('subtree');
            $text = null;
            foreach ($tree->nodes as $node) {
                if ($node->text !== null) {
                    $text = $node->text->inline;
                    break;
                }
            }
            Assert::same('1280 x 900', $text, 'the Hello carried it');

            $client->send(Frame::viewport(new Viewport(640, 480, 100, 0, 1, 100)));
            $batch = $client->recv();
            Assert::same('640 x 480', $batch->body->ops[0]->get('text')->inline,
                'and a resize follows on its own');
            $client->close();
        } finally {
            eui_stop($pid);
        }
    },
];
