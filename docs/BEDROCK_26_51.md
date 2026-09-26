# Bedrock 26.51 / wire protocol 2193

`26.51`, `1.26.51`, `2193` and the explicit `26.50`/`1.26.50` aliases
select the same 2193 schema. `12193` is not a wire alias.
The reference implementations are PrismarineJS/minecraft-data (1.26.51)
and CloudburstMC/Protocol v2193 (1.26.50).

## Run

Rust, PHP with ext-encoding and **Node.js 24+ with npm** are required.
The JS bridge is temporary: Rust still owns world/game state; PHP/RakLib
owns network sessions and authentication. This is not a native Rust gateway.

```bash
./tools/node-install.sh
./start.sh --playtest --version 1.26.51
```

Playtest creates a separate online/encrypted LAN config on UDP 19132.
It does not change server.json or open router ports. Connect to the machine's
LAN IP, not 0.0.0.0. Run only one instance per port. Back up an existing world.
For a custom configuration: protocols=[2193], advertise-protocol=2193,
experimental-codecs=true. Leave online-mode and encryption enabled.

## Exact data, not an older palette with a new version number

The full ordered 2193 block-state sequence comes from Pumpkin commit
003d3c49eaf1ca21207671be55a91587bc1330b5, converted from Cloudburst/Data
26.50 commit a8a4341d7763d6eb8547cff3ca46b4153d60163d.
Item IDs/components and entity identifiers come from that pinned Cloudburst
dump. See resources/modern/2193.sources.json for paths, sizes and Git blob hashes.
Generated assets live in .runtime/bedrock/2193, with a SHA-256 receipt.
PHP and an independent Python NBT parser verify the loaded ordered palette.

The current world is flat Creative with vanilla plains chunk biome ID 1. The complete 89-entry vanilla biome
definition registry uses wire ID 65535, not chunk ID 1.
Only the existing canonical block/interaction set is implemented; an imported
full item registry is not implementation of all Minecraft items or mechanics.
Survival, vehicles, arbitrary crafting and production hardening are not added.
1.26.40/2168 and 1.26.45/2169 remain explicitly blocked without reviewed data.

## Runtime fixes, 2026-09-26

Private PHP libraries no longer leak into Node, curl, Cargo or Python.
PHP and PHP workers retain their own library/extension directories; non-PHP
children receive the original environment. This fixes sqlite3session_attach
and curl_multi_notify_enable failures caused by shadowing system libraries
with the bundled PHP SDK. No system package or library is replaced.

OPENSSL_CONF is selected explicitly: a readable user override, otherwise
/etc/ssl/openssl.cnf, otherwise resources/openssl.cnf. An invalid explicit
path fails; it does not silently disable validation. Actual P-384 key creation,
ECDH, ES384 and AES-256-CTR are checked before binding UDP. Private keys and
session secrets are never printed. This addresses Cannot create server P-384 key.
Node >=24 is selected automatically; an old system Node is left unchanged while
a pinned official project-local runtime is prepared. See [NODE_RUNTIME.md](NODE_RUNTIME.md).

## Native 1.26.30 metadata correction

The pinned b5fd03f BedrockData snapshot contains 16913 block states but an
erroneous 16914-entry metadata list. We download the corrected version-specific
block_state_meta_map-1.26.30.json from NetherGamesMC/BedrockData commit
e08720dda2f32bc7cab7f0bf5bde354f3434f56e, Git blob
910a64d51fb1a1dbf86c5a6e959bc8b4e9686837.
The entire source hash and integer list are verified. No truncation, padding,
nearest-version fallback or modification of vendor is performed. All other
native palette/items remain pinned to the original snapshot.

```bash
./start.sh --playtest --version 1.26.20
./start.sh --playtest --version 1.26.30
./start.sh --playtest --version 1.26.51
```

## Verification boundary

```bash
./start.sh --unit
./tools/e2e.sh --version 1.26.20 --scenario creative
./tools/e2e.sh --version 1.26.30 --scenario creative
./tools/e2e.sh --version 1.26.51 --scenario creative
```

The network tests run real Rust/RakLib/PHP/Prismarine over loopback UDP with
encryption and an offline test identity. They assert start/spawn, decoded
chunks and independent NBT palette IDs, chat, movement, inventory open/close,
and ordinary block placement/breaking. The JSP test transport sends RakNet 11
and subscribes before connecting; no node_modules/prototype patch is used.

These are NOT an official Minecraft playtest or a real Microsoft online login.
Read CI conclusions for the exact commit; unrun/failed stages are not passes.
The separate online playtest remains the check with a signed-in game client.
