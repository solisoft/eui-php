<?php

declare(strict_types=1);

namespace EUI;

use EUI\Proto\Protocol;

/**
 * An application: the components it serves, the assets they name, and the
 * manifest that says who published it.
 *
 *     $app = new App(name: 'Counter', appId: 'counter.example');
 *     $app->mount('counter', Counter::class);
 *     $app->run(port: 5012);
 *
 * The first component mounted is the one a bare origin opens, because the
 * protocol has one entry and a server here has many: `wss://host` has to
 * mean something, and the first one in the file is what a person reading it
 * top to bottom would say.
 */
final class App
{
    public readonly Assets $assets;
    /** @var array<string,class-string<Component>> */
    public array $components = [];
    /** @var array<string,list<string>> */
    public array $fonts = [];

    private ?string $default = null;
    private ?Manifest $manifest = null;
    public readonly string $appId;

    public function __construct(
        public readonly string $name,
        ?string $appId = null,
        public readonly string $version = '0.1.0',
        ?string $root = null,
        private readonly ?string $keyPath = null,
        private readonly array|int $capabilities = 0,
        private readonly mixed $logger = null,
    ) {
        $this->appId = $appId ?? trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $this->assets = new Assets($root);
    }

    /** @param class-string<Component> $componentClass */
    public function mount(string $path, string $componentClass): self
    {
        $name = ltrim($path, '/');
        $this->components[$name] = $componentClass;
        $this->default ??= $name;
        return $this;
    }

    /** @return class-string<Component>|null */
    public function componentFor(?string $name): ?string
    {
        if ($name === null) {
            return $this->components[$this->default ?? ''] ?? null;
        }
        return $this->components[$name] ?? null;
    }

    /**
     * The face an application draws in, bound to a font role and sent as
     * content-addressed assets — so the window talks to no font service and
     * opens no connection the session did not.
     *
     * @param list<string> $paths
     */
    public function font(string $family, array $paths): string
    {
        $this->fonts[$family] = array_map($this->assets->addFile(...), $paths);
        return $family;
    }

    /**
     * The signed record at `/.well-known/eui`. Without a key path there is
     * no manifest, which a debug client on loopback accepts and a release
     * client does not.
     */
    public function manifest(): ?Manifest
    {
        if ($this->keyPath === null) {
            return null;
        }
        return $this->manifest ??= new Manifest(
            appId: $this->appId,
            name: $this->name,
            key: Manifest::publisherKey($this->keyPath),
            version: $this->version,
            entry: "/_eui/session/{$this->default}",
            capabilities: $this->capabilities,
            protocolMin: 1,
            protocolMax: Protocol::VERSION,
        );
    }

    public function run(string $host = '127.0.0.1', int $port = 5012, ?array $tls = null): void
    {
        (new Server($this, $host, $port, $tls, $this->logger))->start();
    }
}
