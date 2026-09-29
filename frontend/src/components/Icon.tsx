/** Small inline stroke icon set (24px grid, currentColor). */
const PATHS: Record<string, string> = {
  dashboard: 'M4 13h6V4H4zm10 7h6V11h-6zM4 20h6v-4H4zm10-9h6V4h-6z',
  lessons: 'M4 5.5C7 4 10 4 12 6c2-2 5-2 8-.5V19c-3-1.5-6-1.5-8 .5-2-2-5-2-8-.5zM12 6v13.5',
  enroll: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm-6 9c0-3.3 2.7-6 6-6 1.3 0 2.5.4 3.5 1.1M18 13v7M14.5 16.5h7',
  students: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm-6 9c0-3.3 2.7-6 6-6s6 2.7 6 6M16 4.5a3.5 3.5 0 0 1 0 6.5M18 14c2 .8 3 3 3 6',
  teachers: 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 10c0-3.9 3.1-7 7-7s7 3.1 7 7M9 14l3 3 3-3',
  attendance: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4m-7 8 2 2 4-4',
  evaluation: 'm12 3 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z',
  exams: 'M8 4h8v3H8zM6 5.5H5v15h14v-15h-1M9 12h6M9 16h4',
  lottery: 'M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5',
  packages: 'M12 3 20 7.5v9L12 21l-8-4.5v-9zM12 12l8-4.5M12 12v9M12 12 4 7.5',
  payments: 'M3 7h18v12H3zM3 11h18M16 15h2',
  messages: 'M4 5h16v11H9l-5 4z',
  reports: 'M5 20V10m7 10V4m7 16v-7',
  users: 'M12 3 5 6v6c0 4.4 3 7.7 7 9 4-1.3 7-4.6 7-9V6zm-3 9 2 2 4-4',
  settings: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM19 12l2-1.2-2-3.5-2.3.7a7 7 0 0 0-1.7-1L14.5 4h-5L9 7a7 7 0 0 0-1.7 1L5 7.3 3 10.8 5 12l-2 1.2 2 3.5 2.3-.7a7 7 0 0 0 1.7 1l.5 3h5l.5-3a7 7 0 0 0 1.7-1l2.3.7 2-3.5z',
  menu: 'M4 6h16M4 12h16M4 18h16',
  close: 'M6 6l12 12M18 6 6 18',
  logout: 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10',
  alert: 'M12 4 2.5 20h19zM12 10v4.5M12 17.5v.5',
  check: 'm5 12.5 4.5 4.5L19 7.5',
  clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm0-13v4.5l3 2',
  pin: 'M12 21s-6-5.6-6-11a6 6 0 1 1 12 0c0 5.4-6 11-6 11zm0-8.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  chevron: 'm9 6 6 6-6 6',
  table: 'M4 5h16v14H4zM4 10h16M4 14.5h16M10 5v14',
  chart: 'M4 20h16M7 16v-5m5 5V7m5 9v-3',
  search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zm9 2-4-4',
  camera: 'M4 8h3l2-3h6l2 3h3v11H4zm8 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  phone: 'M6 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L16 12l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z',
  edit: 'M4 20h4L19 9l-4-4L4 16zm11-15 4 4',
  refresh: 'M20 11a8 8 0 0 0-14.9-3M4 5v4h4m-4 4a8 8 0 0 0 14.9 3M20 19v-4h-4',
  certificate: 'M4 4h16v11H4zM8 8h8M8 11h5M15 13.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM13.5 18l-.5 3 2-1 2 1-.5-3',
  download: 'M12 4v11m-4.5-4.5L12 15l4.5-4.5M5 19.5h14',
  printer: 'M7 9V4h10v5M7 17H4v-7h16v7h-3M7 14h10v6H7z',
  share: 'M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 4M15.4 6.5l-6.8 4',
  eye: 'M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12zm9.5 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  trash: 'M4 7h16M9.5 7V4.5h5V7M6.5 7l1 13h9l1-13M10 11v5.5M14 11v5.5',
  trophy: 'M8 4h8v5a4 4 0 0 1-8 0zM8 6H4.5c0 3 1.5 4.5 3.7 4.8M16 6h3.5c0 3-1.5 4.5-3.7 4.8M12 13v4M8.5 21h7M9.5 17h5l.5 4h-6z',
  medal: 'M8 3h3l1 5M16 3h-3M7.5 3 10 9M16.5 3 14 9M12 21a5.5 5.5 0 1 0 0-11 5.5 5.5 0 0 0 0 11zm0-8 1 2 2.2.3-1.6 1.5.4 2.2-2-1.1-2 1.1.4-2.2-1.6-1.5 2.2-.3z',
  tv: 'M3 5h18v12H3zM8 21h8M12 17v4',
  flag: 'M5 21V4M5 4h11l-2 4 2 4H5',
  ban: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM5.6 5.6l12.8 12.8',
  // Mobile shell
  home: 'M3.5 11 12 4l8.5 7M6 9.5V20h4.5v-5.5h3V20H18V9.5',
  bell: 'M6 16.5V11a6 6 0 1 1 12 0v5.5l1.5 2H4.5zM10 21h4',
  more: 'M5 12h.01M12 12h.01M19 12h.01M5.5 12a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0zm7 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0zm7 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0z',
  plus: 'M12 5v14M5 12h14',
  filter: 'M4 5h16l-6 7.5V19l-4 1.5v-8z',
  sort: 'M7 4v16m0 0-3-3m3 3 3-3M17 20V4m0 0-3 3m3-3 3 3',
  history: 'M3.5 12a8.5 8.5 0 1 0 2.5-6M3.5 4v4h4M12 8v4.5l3 2',
  globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z',
  wallet: 'M4 7h15v12H4zM4 7V5.5A1.5 1.5 0 0 1 5.5 4H17v3M15 13h2',
}

export type IconName = keyof typeof PATHS

export default function Icon({ name, className = 'size-5', title }: { name: IconName | string; className?: string; title?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={className} fill="none" stroke="currentColor" strokeWidth={1.7} strokeLinecap="round" strokeLinejoin="round" aria-hidden={title ? undefined : true} role={title ? 'img' : undefined}>
      {title && <title>{title}</title>}
      <path d={PATHS[name] ?? PATHS.dashboard} />
    </svg>
  )
}
