import fs from 'node:fs'
import { LAYOUT_DIR } from './paths'

/** Start each run with an empty layout report so stale pages never show up in it. */
export default function globalSetup() {
  fs.rmSync(LAYOUT_DIR, { recursive: true, force: true })
  fs.mkdirSync(LAYOUT_DIR, { recursive: true })
}
