<?php

declare(strict_types=1);

namespace EUI;

/** Anything this library raises on its own. */
class EUIException extends \RuntimeException
{
}

/**
 * Bytes that are not a legal encoding of what they claim to be.
 *
 * The decoder refuses rather than repairs: a non-minimal varint, a value
 * outside an enumeration, a length that does not account for every byte.
 * "Ignore what you don't understand" is how one implementation's frame
 * becomes another's smuggling channel.
 */
class DecodeException extends EUIException
{
}

/**
 * A view the protocol has no way to carry: an unknown style key, a colour
 * role nobody defined, a tree deeper than the client accepts. Raised while
 * encoding, which is the only moment at which the author can still fix it.
 */
class ViewException extends EUIException
{
}
