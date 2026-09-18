<?php

declare(strict_types=1);

namespace EUI\Proto;

/**
 * Protocol limits, normative in `spec/02-wire-format.md` §6.
 *
 * Every one of them is checked while decoding, before the memory it bounds
 * is allocated: a hostile peer can be annoying, it cannot make this process
 * exhaust itself.
 */
final class Limits
{
    public const MAX_FRAME_BYTES = 8 * 1024 * 1024;
    public const MAX_TREE_DEPTH = 256;
    public const MAX_NODES = 1000000;
    public const MAX_ATOMS = 65535;
    public const MAX_ATOM_BYTES = 64 * 1024;
    public const MAX_ATOM_TOTAL_BYTES = 8 * 1024 * 1024;
    public const MAX_STYLES = 65535;
    public const MAX_COLORS = 4095;
    public const MAX_CHUNKS = 4095;
    public const MAX_CHILDREN = 65535;
    public const MAX_PROPS = 64;
    public const MAX_HANDLERS = 16;
    public const MAX_OPS_PER_BATCH = 65535;
    public const MAX_INLINE_STR = 4 * 1024;
    public const MAX_VALUE_DEPTH = 4;
    public const MAX_VALUE_LIST = 1000000;

    public const MAX_CHUNK_BYTES = 64 * 1024;
    public const MAX_TRANSFER_CHUNK_BYTES = 256 * 1024;
    public const MAX_UPLOAD_BYTES = 64 * 1024 * 1024;
    public const DEFAULT_UPLOAD_BYTES = 16 * 1024 * 1024;
    public const MAX_SAVE_BYTES = 256 * 1024 * 1024;
    public const MAX_ABORT_REASON = 256;

    public const MAX_NOTIFY_TITLE = 256;
    public const MAX_NOTIFY_BODY = 1024;
    public const MAX_NOTIFY_TAG = 64;
    public const MAX_NOTIFY_PER_BATCH = 4;

    public const STYLE_RECORD_BYTES = 64;
    public const HASH_BYTES = 32;

    public const MAX_FONT_ROLE = 9;
    public const MAX_FACES_PER_ROLE = 8;
}
