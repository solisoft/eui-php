# Changelog

## 0.1.0

The first cut: enough to write an EUI application in PHP and have the
reference client draw it.

- `EUI\Proto` — the wire format of `spec/02`: varints, the 64-byte style
  record, nodes, values, handlers, ops, batches and every session frame,
  checked against the byte vectors the spec pins.
- `EUI\View` — a view array compiled into interned atoms, styles and colours,
  and diffed against the tree the client holds: keyed children reconcile
  through a Fenwick tree, so a ten-thousand-row sort is *n* moves and not a
  scan per row.
- `EUI\Session` / `EUI\Server` — the HTTP and WebSocket halves of `spec/01`:
  manifest, content-addressed assets, and a session that welcomes, mounts,
  patches, answers a ping and rebuilds on a resync. One forked process per
  connection, because a session is a loop that blocks on its own socket and
  PHP has no threads to give it.
- `EUI\Component` — state, `onEventName` handlers, and a view that is a
  function of the state.
- `EUI\Blake3` — BLAKE3 in PHP, because an asset is named by the hash of its
  content and no extension here ships one. Ed25519 comes from OpenSSL, which
  this build does sign with.

Not yet: local handlers (`spec/07` bytecode), file transfers (`spec/01` §6),
session resume, and the windowed `list`'s `window` event. A component's
`refresh()` is for a handler that changed something the view has not been
asked about yet; it is not a way for another process to push.
