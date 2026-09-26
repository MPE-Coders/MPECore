# Dependencies, references and licenses

The authored MPE-Core sources are distributed under GPL-3.0-or-later; see LICENSE. Third-party packages retain their respective notices and licenses. This source archive does not vendor the PHP engine, RakLib or NetherGames packages. The installation scripts fetch them from their upstream sources.

| Project | Pinned source/version | Upstream license / reference |
|---|---|---|
| RakLib | 1.2.1 | GPL-3.0-or-later; see upstream for its specific PocketMine exception |
| NetherGames BedrockProtocol | aae6ba55aa75b2c188aade6cadca5fb6d2633d33 | LGPL-3.0; package license governs |
| NetherGames BedrockData | b5fd03f4bcc790abe3d0dcb31151566ca29d42cb | CC0-1.0 |
| PMMP PHP Binaries | pm5-php-8.4-latest Linux x86_64 asset, SHA-256 required from GitHub asset digest or explicit URL+SHA256 | PHP and bundled extension/library licenses apply; not re-licensed by this project |
| Other Composer dependencies | exact direct versions in composer.json | Retain installed package LICENSE/NOTICE files |

References consulted for protocol implementation and API interoperability:

- NetherGames server: <https://github.com/NetherGamesMC/PocketMine-MP>
- Protocol: <https://github.com/NetherGamesMC/BedrockProtocol/tree/aae6ba55aa75b2c188aade6cadca5fb6d2633d33>
- Data: <https://github.com/NetherGamesMC/BedrockData/tree/b5fd03f4bcc790abe3d0dcb31151566ca29d42cb>
- RakLib: <https://github.com/pmmp/RakLib/tree/1.2.1>
- PHP runtime releases: <https://github.com/pmmp/PHP-Binaries/releases>
- Console style inspiration: <https://github.com/XackiGiFF/MPE-Proxy>

The console implementation here is newly written; the user's repository was consulted for presentation style. Protocol structures, wire constants and authentication requirements are interoperability details, not a claim of authorship over upstream projects. Minecraft, Mojang and Microsoft names and trademarks belong to their owners. This is unofficial software.

No proprietary Minecraft textures, sounds, client executables or server executables are included. There is no automatic extraction or redistribution of resource packs.

The archive does not claim the special RakLib PocketMine exception applies to this independent project; GPL-3.0-or-later is used for MPE-Core. Keep dependency notices when distributing a built bundle. If packages change, review their actual installed notices rather than relying solely on this summary.

## Client and adapter dependencies

The optional standalone client and modern codec bridge depend on PrismarineJS
bedrock-protocol 3.60.1 and node-minecraft-data 3.117.0 (upstream MIT). Dependencies
are installed separately, not bundled. No dependency binary or lock resolution is
claimed as generated in this environment.

https://github.com/PrismarineJS/bedrock-protocol
https://github.com/PrismarineJS/node-minecraft-data
https://github.com/PrismarineJS/minecraft-data

Protocol reference URLs and exact data-alias reasoning are in docs/PROTOCOLS.md.
MPE-Proxy remains an inspiration for console layout; no external proxy server is
required or hidden behind this implementation.

## 0.3 runtime integrity

The moving PHP release tag is no longer assigned a fixed stale archive hash. The installer requires
GitHub's HTTPS release asset SHA256 (or an explicitly supplied URL plus independently verified hash),
checks it before extraction, bounds archive size and rejects escaping paths/links. This provides
integrity relative to the chosen upstream trust source, not independent publisher authentication.
Dependency downloads and Composer/npm lock resolution were not executed in the authoring environment.
The source archive contains no PHP/Rust/Node/Minecraft binary.
