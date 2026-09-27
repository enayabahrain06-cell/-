/*
 * WhatsApp bridge for the Ahl Al-Quran system (open-wa).
 *
 *   POST /send    {to: "97336000001@c.us", text}  -> {id}
 *   GET  /status                                   -> {connected, state}
 *   GET  /qr                                       -> {qr: "data:image/png;base64,..."} or 204
 *   GET  /health                                   -> {ok: true}
 *
 * Every request needs the header x-api-key = API_KEY. Inbound messages and delivery acks are
 * forwarded to INBOUND_WEBHOOK_URL with the header X-Webhook-Secret = INBOUND_WEBHOOK_SECRET,
 * in the format backend/app/Services/WhatsApp/Inbound/InboundParser.php accepts.
 * The WhatsApp session is kept in SESSION_DIR (mount a volume there).
 */
const express = require('express')
const { create, ev } = require('@open-wa/wa-automate')

const PORT = Number(process.env.PORT || 8085)
const API_KEY = process.env.API_KEY || ''
const WEBHOOK_URL = process.env.INBOUND_WEBHOOK_URL || ''
const WEBHOOK_SECRET = process.env.INBOUND_WEBHOOK_SECRET || ''
const SESSION_DIR = process.env.SESSION_DIR || '/data/session'

if (!API_KEY) {
  console.error('API_KEY is required')
  process.exit(1)
}

let client = null
let state = 'STARTING'
let lastQr = null

ev.on('qr.**', (qr) => {
  lastQr = qr
  state = 'QR_REQUIRED'
})

async function forward(payload) {
  if (!WEBHOOK_URL || !WEBHOOK_SECRET) return
  try {
    const res = await fetch(WEBHOOK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Webhook-Secret': WEBHOOK_SECRET, 'X-Provider': 'openwa' },
      body: JSON.stringify(payload),
    })
    if (!res.ok) console.warn('webhook answered', res.status)
  } catch (e) {
    console.warn('webhook failed', e.message)
  }
}

async function start() {
  client = await create({
    sessionId: 'ahl-alquran',
    sessionDataPath: SESSION_DIR,
    headless: true,
    qrTimeout: 0,
    authTimeout: 0,
    multiDevice: true,
    useChrome: true,
    cacheEnabled: false,
    killProcessOnBrowserClose: true,
    chromiumArgs: ['--no-sandbox', '--disable-dev-shm-usage'],
  })
  state = 'CONNECTED'
  lastQr = null

  client.onStateChanged((s) => {
    state = s
    if (s === 'CONFLICT' || s === 'UNLAUNCHED') client.forceRefocus()
  })
  client.onMessage((m) => forward({
    event: 'message', from: m.from, body: m.body, caption: m.caption, type: m.type,
    id: m.id, timestamp: m.timestamp, isGroupMsg: m.isGroupMsg, fromMe: m.fromMe,
  }))
  client.onAck((a) => forward({ event: 'ack', id: typeof a.id === 'object' ? a.id._serialized : a.id, ack: a.ack }))
}

const app = express()
app.use(express.json({ limit: '1mb' }))
app.get('/health', (_req, res) => res.json({ ok: true }))
app.use((req, res, next) => (req.get('x-api-key') === API_KEY ? next() : res.status(401).json({ message: 'unauthorized' })))

app.get('/status', async (_req, res) => {
  let connected = false
  try {
    connected = !!client && (await client.isConnected())
  } catch (_) { /* browser still starting */ }
  res.json({ connected, state })
})

app.get('/qr', (_req, res) => (lastQr ? res.json({ qr: lastQr }) : res.status(204).end()))

app.post('/send', async (req, res) => {
  const { to, text } = req.body || {}
  if (!to || !text) return res.status(422).json({ message: 'to and text are required' })
  if (!client) return res.status(503).json({ message: 'not connected', state })
  try {
    const id = await client.sendText(to, text)
    if (!id) return res.status(502).json({ message: 'send failed' })
    return res.json({ id: typeof id === 'object' ? id._serialized : id })
  } catch (e) {
    return res.status(502).json({ message: e.message })
  }
})

app.listen(PORT, () => console.log(`whatsapp bridge on :${PORT}`))
start().catch((e) => {
  state = 'FAILED'
  console.error(e)
})
