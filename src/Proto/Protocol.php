<?php

declare(strict_types=1);

namespace EUI\Proto;

final class Protocol
{
    /**
     * The version this library speaks. Four: `DefFont` and the font roles it
     * binds took the protocol there.
     *
     * A session speaks `min(client, server)`, negotiated in the Welcome —
     * the number a client names in its Hello is not a field id and not a
     * capability set, it is this.
     */
    public const VERSION = 4;
}
