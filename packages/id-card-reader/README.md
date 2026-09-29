# @ahl/id-card-reader

Read Bahrain smart ID cards (CPR) in the browser, for any web system. It has three parts:

| Part | Import | What it is |
|---|---|---|
| Client | `@ahl/id-card-reader` | Framework-free TypeScript: read a card, watch the reader, parse to a clean `CardData`. |
| React | `@ahl/id-card-reader/react` | A "Read ID card" bar (with Automatic mode) and a compare-and-apply dialog, in Arabic and English. |
| Setup kit | `setup/` | Scripts and a guide for each reception PC (the card reader program and the Bahrain update). |

No backend is needed to read a card. The browser talks to iGA's **GCC CardRead Server**, which runs on the PC with
the card reader (`http://localhost:5050`, `ws://localhost:5060`). Your own server only receives what staff save.

## 1. Prepare each card-reading PC

See [setup/GUIDE.html](setup/GUIDE.html). In short: plug in a USB smart card reader, install iGA's GCC CardRead
Server, and (until iGA ships a newer installer) run the Bahrain update. Build the USB kit with:

```bat
setup\make-kit.cmd <folder with the iGA SDK> [output folder]
```

The iGA SDK is licensed by iGA and is **not** part of this package; neither is the kit it produces. Keep both out of
git.

## 2. Use the client (any framework)

```ts
import { readCard, watchCards, cardErrorCode, configureCardReader } from '@ahl/id-card-reader'

configureCardReader({ restUrl: 'http://localhost:5050/api/operation/ReadCard' }) // optional, these are the defaults

try {
  const card = await readCard()          // { cpr, nameAr, nameEn, birthDate, gender, photo, cardKey }
  form.fullName = card.nameAr ?? card.nameEn
  form.cpr = card.cpr                    // nine Latin digits or null
} catch (e) {
  showMessage(cardErrorCode(e))          // 'not_running' | 'reader_off' | 'no_card' | 'failed' | 'service_error'
}

const stop = watchCards((card) => fill(card), (status, code) => showStatus(status, code)) // automatic mode
```

- `readCard(signal?, silent = false, withPhoto = true)`: one read. The photo (a JPEG `File` named
  `id-card-photo.jpg`, never the CPR) comes only with `withPhoto` and takes about 4 s instead of under 1 s.
- `watchCards(onCard, onStatus)`: WebSocket first, REST polling as the fallback. Each inserted card is delivered
  once; the next card after removal is new.
- `parseCard`, `parseDate`, `parseGender`, `normalizeCpr`: the parsers, exported for tests and other transports.
- Error codes carry no service text, so no card data or vendor message is ever shown or logged.

## 3. Use the React components

Peer dependencies: `react`, `i18next` and `react-i18next`. The components use Tailwind v4 classes.

```tsx
import { registerIdCardI18n, IdCardProvider, CardReaderBar, CardApplyDialog } from '@ahl/id-card-reader/react'

registerIdCardI18n(i18n)   // namespace "idCard"; keys the host already has win (e.g. "gender_male": "Boy")

<IdCardProvider ui={{ PrimaryButton, SecondaryButton, Modal, Notice, Icon }} formatDate={fmt} parseError={toMessage}>
  <CardReaderBar onRead={(card) => fillForm(card)} storageKey="myapp.cardReader.auto" />

  <CardApplyDialog
    title="ID card"
    current={{ full_name, birth_date, gender, cpr }}   // what is on file
    allowPhoto
    onApply={async (patch, photo) => { await save(patch); if (photo) await uploadPhoto(photo) }}
    onClose={close}
  />
</IdCardProvider>
```

- `IdCardProvider` is optional: without it the package's own buttons, dialog and notice are used.
- Styling: a host with its own design system defines the colour tokens (`brand`, `ink`, `gold`, `danger`) in its
  `@theme` and adds `@source "<path>/node_modules/@ahl/id-card-reader/src";`. Otherwise also
  `@import "@ahl/id-card-reader/theme.css";`.
- The dialog pre-ticks fields that differ from the file, never the photo, and ticks nothing (with a warning) when the
  card's CPR differs from the one on file.

## 4. Storing the CPR (optional, on your server)

The package only reads. If your system stores the CPR, validate it as nine digits (after turning Arabic digits into
Latin ones), keep it unique per person, and show it to staff only. The Ahl Al-Quran backend does this in
`App\Support\Cpr`.

## Tests

```bash
npm test          # node --test, no browser or reader needed (9 tests)
```

## Privacy

Card data stays in the browser tab until the host saves it. Nothing in this package logs card data, the CPR is never
put in a file name, status text or the `cardKey` (a one-way fingerprint for "same card again"), and the setup scripts
show only whether names were read.
