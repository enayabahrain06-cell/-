/**
 * Mobile on/off switch (spec §6.25: 44×26 track). A real button with role="switch" and aria-checked; the label is
 * either visible text passed by the row (`labelledBy`) or an aria-label.
 */
export default function MSwitch({ checked, onChange, label, labelledBy, disabled }: { checked: boolean; onChange: (v: boolean) => void; label?: string; labelledBy?: string; disabled?: boolean }) {
  return (
    <button type="button" role="switch" aria-checked={checked} aria-label={labelledBy ? undefined : label} aria-labelledby={labelledBy} disabled={disabled}
      onClick={() => onChange(!checked)}
      className="relative inline-flex h-11 w-14 shrink-0 items-center justify-center rounded-full disabled:opacity-50">
      <span aria-hidden className={`relative block h-[26px] w-11 rounded-full transition ${checked ? 'bg-brand-600' : 'bg-ink/20'}`}>
        <span className={`absolute top-[3px] block size-5 rounded-full bg-white shadow-card transition-[inset-inline-start] ${checked ? 'start-[21px]' : 'start-[3px]'}`} />
      </span>
    </button>
  )
}
