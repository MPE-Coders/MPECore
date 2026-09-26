'use strict'
const catalog = require('../../resources/protocol-catalog.json')
function parseArgs(argv) {
  const o = { host: '127.0.0.1', port: 19132, version: 'auto', scenario: 'smoke', offline: false,
    username: 'MPE_Test', timeout: 30000, report: 'client-report.json', chat: 'MPE client test' }
  const positional = []
  const fields = new Set(['version','scenario','username','timeout','report','chat','palette','profiles-folder'])
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i]
    if (['--offline','--help','--list-protocols','--ping-only'].includes(a)) o[a.slice(2)] = true
    else if (a.startsWith('--')) {
      const k = a.slice(2)
      if (!fields.has(k) || !argv[i + 1] || argv[i + 1].startsWith('--')) throw Error(`Unknown or incomplete option ${a}`)
      o[k] = argv[++i]
    } else positional.push(a)
  }
  if (positional.length > 2) throw Error('Usage: ./client [host] [port] [options]')
  if (positional[0]) o.host = positional[0]
  if (positional[1]) o.port = Number(positional[1])
  o.timeout = Number(o.timeout)
  if (!Number.isInteger(o.port) || o.port < 1 || o.port > 65535) throw Error('Port must be 1..65535')
  if (!Number.isInteger(o.timeout) || o.timeout < 1000 || o.timeout > 300000) throw Error('Timeout must be 1000..300000 milliseconds')
  if (!['smoke','edit','connect','creative'].includes(o.scenario)) throw Error('Scenario must be connect, smoke, creative or edit')
  if (!/^[a-zA-Z0-9_ .@+-]{1,128}$/.test(o.username)) throw Error('Invalid username/account identifier')
  if (Buffer.byteLength(o.chat) > 160) throw Error('Chat message exceeds 160 bytes')
  if(o.version==='12193')throw Error('26.51 uses protocol 2193, not 12193')
  if(o.version.startsWith('26.'))o.version='1.'+o.version
  if (o.version !== 'auto') {
    const p = catalog.find(v => v.version === o.version || String(v.protocol) === o.version || (v.aliases||[]).includes(o.version))
    if (!p) throw Error('Version not in explicit catalog; use --list-protocols')
    o.version = p.version
  }
  return o
}
module.exports = { parseArgs, catalog }
