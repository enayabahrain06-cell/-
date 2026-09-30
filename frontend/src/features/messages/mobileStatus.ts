import type { MessageStatus } from '../../api/messages'
import type { PillTone } from '../../components/mobile/atoms'
import { STATUS_META } from './status'

/** Message status → mobile pill tone (same meaning as the desktop badge tones in status.tsx). */
const TONE: Record<string, PillTone> = { brand: 'ok', info: 'info', danger: 'err', muted: 'neutral', gold: 'warn' }
export const statusTone = (s: string): PillTone => TONE[STATUS_META[s as MessageStatus]?.tone ?? 'muted'] ?? 'neutral'
