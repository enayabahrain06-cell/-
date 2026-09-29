/**
 * Card reader parsing and safety rules (node --test, no browser, no reader needed).
 * Run: npm run test:unit
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { cardErrorCode, normalizeCpr, parseCard, parseDate, parseGender, readCard, watchCards, type CardData } from '../src/client.ts'

const CPR = '140312345'
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGNk2MnAwMDAxMDAwMDAAAAO4AFBN5wK6wAAAABJRU5ErkJggg=='
const card = (over: Record<string, unknown> = {}) => ({ IdNumber: CPR, ArabicFullName: 'فاطمة علي', EnglishFullName: 'FATEMA ALI', BirthDate: '15/03/2014', Gender: 'F', PhotoB64Encoded: PNG, ErrorDescription: '', ...over })

test('birth dates in the formats the service can return', () => {
  const cases: [string, string | null][] = [
    ['15/03/2014', '2014-03-15'], // dd/MM/yyyy (Bahrain)
    ['5/3/2014', '2014-03-05'],
    ['15-03-2014', '2014-03-15'],
    ['15.03.2014', '2014-03-15'],
    ['2014-03-15', '2014-03-15'],
    ['2014-03-15T00:00:00', '2014-03-15'],
    ['2014-03-15 00:00:00', '2014-03-15'],
    ['2014/03/15', '2014-03-15'],
    ['20140315', '2014-03-15'],
    ['/Date(1394841600000)/', '2014-03-15'], // .NET JSON
    ['03/15/2014', '2014-03-15'], // MM/dd when the day cannot be a month
    ['3/5/2014 12:00:00 AM', '2014-03-05'], // .NET en-US M/d/yyyy h:mm tt
    ['15-Mar-2014', '2014-03-15'],
    ['15 March 2014', '2014-03-15'],
    ['15 مارس 2014', '2014-03-15'],
    ['١٥/٠٣/٢٠١٤', '2014-03-15'], // Arabic-Indic digits
    ['1435/05/14', null], // Hijri year: refuse rather than fill a wrong date
    ['31/02/2014', null],
    ['', null],
    ['Data Not Found', null],
  ]
  for (const [input, want] of cases) assert.equal(parseDate(input), want, input)
})

test('gender values map to the application values', () => {
  for (const v of ['M', 'm', 'Male', 'MALE', 'ذكر', '1']) assert.equal(parseGender(v), 'male', v)
  for (const v of ['F', 'f', 'Female', 'FEMALE', 'أنثى', 'انثى', '2']) assert.equal(parseGender(v), 'female', v)
  for (const v of ['', 'X', 'unknown', null]) assert.equal(parseGender(v as string | null), null, String(v))
})

test('a card fills the form fields, including the CPR, which stays out of file names and the card key', () => {
  const c = parseCard(card())
  assert.deepEqual({ ar: c.nameAr, en: c.nameEn, birth: c.birthDate, gender: c.gender }, { ar: 'فاطمة علي', en: 'FATEMA ALI', birth: '2014-03-15', gender: 'female' })
  assert.ok(c.photo instanceof File)
  assert.equal(c.photo!.type, 'image/png')
  // The CPR is returned for the form's CPR field (stored on the student), and nowhere else.
  assert.equal(c.cpr, CPR)
  assert.ok(!c.photo!.name.includes(CPR), c.photo!.name)
  assert.ok(!c.cardKey!.includes(CPR))
  assert.match(c.cardKey!, /^[0-9a-f]{8}$/)
  assert.equal(parseCard(card()).cardKey, c.cardKey, 'same card, same key')
  assert.notEqual(parseCard(card({ IdNumber: '120798765' })).cardKey, c.cardKey, 'another card, another key')
})

test('CPR numbers are normalised to nine Latin digits', () => {
  assert.equal(normalizeCpr('140312345'), '140312345')
  assert.equal(normalizeCpr('١٤٠٣١٢٣٤٥'), '140312345')
  assert.equal(normalizeCpr('140-312-345'), '140312345')
  assert.equal(normalizeCpr(' 1403 12345 '), '140312345')
  for (const bad of ['12345', '1403123456', 'ABC', '', null]) assert.equal(normalizeCpr(bad as string | null), null, String(bad))
  assert.equal(parseCard(card({ IdNumber: '12' })).cpr, null, 'an invalid number is not passed on')
})

test('English name is the fallback, and name parts are joined when there is no full name', () => {
  assert.equal(parseCard(card({ ArabicFullName: null })).nameAr, null)
  assert.equal(parseCard(card({ ArabicFullName: '', ArabicFirstName: 'فاطمة', ArabicMiddleName2: 'علي', ArabicLastName: 'الستراوي' })).nameAr, 'فاطمة علي الستراوي')
  assert.equal(parseCard({ SmartcardData: card() }).nameEn, 'FATEMA ALI', 'wrapped payload')
})

test('service answers map to the five staff-facing states', () => {
  const code = (raw: unknown) => { try { parseCard(raw); return 'ok' } catch (e) { return cardErrorCode(e) } }
  const empty = { IdNumber: null, ArabicFullName: null, EnglishFullName: null }
  assert.equal(code({ ...empty, ErrorDescription: 'The Smart card resource manager is not running.' }), 'reader_off')
  assert.equal(code({ ...empty, ErrorDescription: 'No reader found' }), 'reader_off')
  assert.equal(code({ ...empty, ErrorDescription: 'No card present in the reader.' }), 'no_card')
  assert.equal(code({ ...empty, ErrorDescription: '' }), 'no_card')
  assert.equal(code({ ...empty, ErrorDescription: 'Failed to read card data (APDU 6A82)' }), 'failed')
  assert.equal(code({ ...empty, ErrorDescription: 'Object reference not set' }), 'service_error')
  // Card present but not understood by the service (e.g. read as another country, names empty): read failed, not 'no card'.
  assert.equal(code({ ...empty, CardCountry: 'KWT', ErrorDescription: '' }), 'failed')
  assert.equal(code({ ...empty, CardSerialNumber: 'A1B2C3', ErrorDescription: null }), 'failed')
  // The service's own wording is never carried in the error.
  try { parseCard({ ...empty, ErrorDescription: 'The Smart card resource manager is not running.' }) } catch (e) { assert.equal((e as Error).message, 'reader_off') }
})

test('readCard: service not running, unexpected answers, and the request shape', async () => {
  const realFetch = globalThis.fetch
  try {
    globalThis.fetch = (async () => { throw new TypeError('Failed to fetch') }) as typeof fetch
    await assert.rejects(readCard(), (e) => cardErrorCode(e) === 'not_running')

    globalThis.fetch = (async () => new Response('<html>oops</html>', { status: 200 })) as typeof fetch
    await assert.rejects(readCard(), (e) => cardErrorCode(e) === 'service_error')

    globalThis.fetch = (async () => new Response(JSON.stringify({ Title: 'Unexpected Error', ErrorCode: 'NullReferenceException' }), { status: 500 })) as typeof fetch
    await assert.rejects(readCard(), (e) => cardErrorCode(e) === 'service_error')

    let seen: RequestInit | undefined
    globalThis.fetch = (async (_url: string, init: RequestInit) => { seen = init; return new Response(JSON.stringify(card())) }) as typeof fetch
    const c = await readCard(undefined, true)
    assert.equal(c.nameAr, 'فاطمة علي')
    // A "simple" request (no CORS preflight), personal info only, silent when polling.
    assert.equal((seen!.headers as Record<string, string>)['Content-Type'], 'application/x-www-form-urlencoded; charset=UTF-8')
    const body = JSON.parse(String(seen!.body))
    assert.equal(body.ReadPersonalInfo, true)
    assert.equal(body.ReadBiometrics, true, 'the photo only comes with biometrics')
    assert.equal(body.SilentReading, true)
    // The quick poll read skips the photo (under 1 s instead of about 4 s).
    await readCard(undefined, true, false)
    assert.equal(JSON.parse(String(seen!.body)).ReadBiometrics, false)
  } finally {
    globalThis.fetch = realFetch
  }
})

/** Minimal WebSocket stand-in driven by the test. */
class FakeSocket {
  static last: FakeSocket | null = null
  static failToOpen = false
  sent: string[] = []
  onopen: (() => void) | null = null
  onmessage: ((e: { data: string }) => void) | null = null
  onclose: (() => void) | null = null
  onerror: (() => void) | null = null
  url: string
  constructor(url: string) {
    this.url = url
    FakeSocket.last = this
    queueMicrotask(() => (FakeSocket.failToOpen ? this.onclose?.() : this.onopen?.()))
  }
  send(m: string) { this.sent.push(m) }
  close() { /* test controls closing */ }
  emit(data: string) { this.onmessage?.({ data }) }
}

const tick = (ms = 0) => new Promise((r) => setTimeout(r, ms))

test('automatic (WebSocket): each card once, repeats ignored, a new card after removal', async () => {
  const realWS = globalThis.WebSocket
  ;(globalThis as { WebSocket: unknown }).WebSocket = FakeSocket
  FakeSocket.failToOpen = false
  const got: CardData[] = []
  try {
    const stop = watchCards((c) => got.push(c), () => {})
    await tick()
    const ws = FakeSocket.last!
    assert.deepEqual(ws.sent, ['RunCardDetection'])
    ws.emit('Card inserted,reader=ACS ACR39U 0:3B7F')
    assert.ok(ws.sent[1].startsWith('ReadCard{'))
    ws.emit(JSON.stringify(card()))
    ws.emit(JSON.stringify(card())) // repeated detection of the same card: must not overwrite staff edits
    assert.equal(got.length, 1)
    ws.emit('Card removed,reader=ACS ACR39U')
    ws.emit(JSON.stringify(card({ IdNumber: '120798765', ArabicFullName: 'محمد جعفر', Gender: 'M' })))
    assert.equal(got.length, 2)
    assert.equal(got[1].gender, 'male')
    ws.emit(JSON.stringify(card())) // the first card again, after a different one: it is new again
    assert.equal(got.length, 3)
    stop()
    assert.equal(ws.sent.at(-1), 'CancelCardDetection')
    ws.emit(JSON.stringify(card({ IdNumber: '999' })))
    assert.equal(got.length, 3, 'nothing after stop')
  } finally {
    ;(globalThis as { WebSocket: unknown }).WebSocket = realWS
  }
})

test('automatic (REST fallback): polls when there is no WebSocket, once per card, new card after removal', async () => {
  const realWS = globalThis.WebSocket, realFetch = globalThis.fetch, realSetTimeout = globalThis.setTimeout
  ;(globalThis as { WebSocket: unknown }).WebSocket = FakeSocket
  FakeSocket.failToOpen = true
  const empty = { IdNumber: null, ArabicFullName: null, EnglishFullName: null, ErrorDescription: 'No card present in the reader.' }
  const answers = [empty, card(), card(), card(), empty, card({ IdNumber: '120798765', ArabicFullName: 'محمد جعفر' }), card({ IdNumber: '120798765', ArabicFullName: 'محمد جعفر' })]
  let calls = 0
  const delays: number[] = []
  globalThis.fetch = (async () => new Response(JSON.stringify(answers[Math.min(calls++, answers.length - 1)]))) as typeof fetch
  // Run the poll loop without waiting 2 s per round, but record the delay it asked for.
  globalThis.setTimeout = ((fn: () => void, ms?: number) => { delays.push(ms ?? 0); return realSetTimeout(fn, 0) }) as typeof setTimeout
  const got: CardData[] = []
  try {
    const stop = watchCards((c) => got.push(c), () => {})
    await new Promise((r) => realSetTimeout(r, 120)) // real time: the patched timer would end this wait at once
    stop()
    assert.ok(calls >= answers.length, `polled ${calls} times`)
    assert.deepEqual(got.map((c) => c.nameAr), ['فاطمة علي', 'محمد جعفر'])
    assert.ok(delays.includes(2000), 'polls every 2 s')
  } finally {
    globalThis.setTimeout = realSetTimeout
    globalThis.fetch = realFetch
    ;(globalThis as { WebSocket: unknown }).WebSocket = realWS
    FakeSocket.failToOpen = false
  }
})

test('the father\'s name is the full name without the first name, keeping two-word first names together', async () => {
  const { fatherNameFrom } = await import('../src/client.ts')
  assert.equal(fatherNameFrom('أحمد محمد علي الدوسري'), 'محمد علي الدوسري')
  assert.equal(fatherNameFrom('عبد الله محمد حسن المحروس'), 'محمد حسن المحروس')
  assert.equal(fatherNameFrom('نور الدين أحمد جعفر'), 'أحمد جعفر')
  assert.equal(fatherNameFrom('  YUSUF   AHMED ALI  AL HADDAD '), 'AHMED ALI AL HADDAD')
  assert.equal(fatherNameFrom('Abdul Latif Hassan Mahdi'), 'Hassan Mahdi')
  // too short to be sure: no guess
  assert.equal(fatherNameFrom('أحمد الدوسري'), null)
  assert.equal(fatherNameFrom(''), null)
  assert.equal(fatherNameFrom(null), null)
})

test('the address is read and tidied to one line, and the request asks for it', async () => {
  const c = parseCard({ IdNumber: '010203040', ArabicFullName: 'فاطمة علي', AddressArabic: '  شقة 1   مبنى 2  طريق 3   مجمع 4   ', AddressEnglish: 'Flat 1  Bldg 2   Road 3  Block 4    ' })
  assert.equal(c.addressAr, 'شقة 1 مبنى 2 طريق 3 مجمع 4')
  assert.equal(c.addressEn, 'Flat 1 Bldg 2 Road 3 Block 4')
  assert.equal(parseCard({ IdNumber: '010203040', ArabicFullName: 'فاطمة علي', AddressArabic: '    ' }).addressAr, null)

  const realFetch = globalThis.fetch
  let body: Record<string, unknown> = {}
  globalThis.fetch = (async (_u: unknown, init?: RequestInit) => {
    body = JSON.parse(String(init?.body))
    return new Response(JSON.stringify({ IdNumber: '010203040', ArabicFullName: 'فاطمة علي' }), { status: 200 })
  }) as typeof fetch
  try { await readCard(undefined, true, false) } finally { globalThis.fetch = realFetch }
  assert.equal(body.ReadAddressDetails, true)
})
