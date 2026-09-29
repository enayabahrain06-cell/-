/**
 * Shared Recharts styling for charts on white cards (grid, axes, tooltip cursor).
 * SVG attributes cannot read CSS variables, so CHART_INK mirrors `--color-ink` in index.css.
 * Series colours stay in one constant per chart file.
 */
export const CHART_INK = '#1B2B28'
export const CHART_GRID = { stroke: CHART_INK, strokeOpacity: 0.07 }
export const CHART_AXIS_LINE = { stroke: CHART_INK, strokeOpacity: 0.15 }
export const CHART_TICK = { fill: CHART_INK, fillOpacity: 0.55, fontSize: 12 }
export const CHART_BAR_CURSOR = { fill: CHART_INK, fillOpacity: 0.05 }
export const CHART_LINE_CURSOR = { stroke: CHART_INK, strokeOpacity: 0.2 }
