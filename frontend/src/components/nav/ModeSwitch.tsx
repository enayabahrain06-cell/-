import { useNavigate } from 'react-router-dom'
import { Segmented } from '../ui'

export interface ModeOption { value: string; label: string; href: string }

/**
 * nav_v2: the modes of one tab (record | view | monitor …) as a segmented control. Choosing a mode pushes its URL
 * (?mode=), so there is no reload, back / forward step through modes and a refresh keeps the mode.
 * `onDeep` on the tab page's banner (desktop); the light, full-width 44px variant sits under the header on phones.
 */
export default function ModeSwitch({ name, label, value, options, onDeep = false }: { name: string; label: string; value: string; options: ModeOption[]; onDeep?: boolean }) {
  const navigate = useNavigate()
  if (options.length < 2) return null
  return (
    <Segmented name={name} label={label} value={value} onDeep={onDeep} size={onDeep ? 'md' : 'lg'} fill={!onDeep}
      options={options.map((o) => ({ value: o.value, label: o.label }))}
      onChange={(v) => { const o = options.find((x) => x.value === v); if (o) navigate(o.href) }} />
  )
}
