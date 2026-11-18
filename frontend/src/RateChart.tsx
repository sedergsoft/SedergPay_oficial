import { useEffect, useMemo, useRef, useState } from 'react';
import { AreaSeries, ColorType, CrosshairMode, LineSeries, LineStyle, LineType, createChart } from 'lightweight-charts';
import type { IChartApi, ISeriesApi, Time } from 'lightweight-charts';
import type { ExchangeRateHistoryPoint } from './api';
import { THEME_EVENT } from './theme';

// -----------------------------------------------------------------------------
// Historial de tasas — Lightweight Charts 5
//
// Modulo aparte (y cargado con `React.lazy`): la libreria pesa ~171 kB y solo
// debe descargarla quien ve el historial, no toda la aplicacion.
//
// Hay DOS tasas y se dibujan por separado, porque mezclarlas inventa saltos:
//  - Oficial  (`bcc_official` / `cbr_official`): area azul, la que publica la
//    fuente oficial.
//  - Administrador (`admin_override`): linea escalonada ambar, el valor fijado
//    a mano y vigente hasta que se reemplaza.
// El porcentaje de la cabecera se calcula sobre la serie que esta gobernando
// las cotizaciones (`activeSource`), nunca sobre una mezcla de ambas.
//
// Otras correcciones respecto al grafico SVG anterior:
//  - `effective_date` llega como 'YYYY-MM-DD'. Se parsea a mediodia LOCAL:
//    `new Date('2026-11-16')` es medianoche UTC y en La Habana (UTC-5) restaba
//    un dia al eje.
//  - Lightweight Charts exige tiempos unicos y ascendentes: se conserva la
//    ultima lectura de cada dia para cada fuente.
//  - La tipografia y la escala las resuelve el canvas: desaparece el
//    `preserveAspectRatio="none"` que deformaba las etiquetas.
//  - Los colores se leen de los tokens del tema activo (el canvas no entiende
//    variables CSS) y se reaplican cuando cambia el tema.
//  - Crosshair y tooltip tactiles los aporta la libreria.
// -----------------------------------------------------------------------------

export type RateChartLabels = {
  title: string;
  loading: string;
  empty: string;
  official: string;
  admin: string;
  active: string;
};

type RateChartProps = {
  points: ExchangeRateHistoryPoint[];
  base: string;
  quote: string;
  /** Idioma ya resuelto ('es-ES' | 'ru-RU'); evita depender del locale del navegador. */
  locale: string;
  labels: RateChartLabels;
  /** Fuente que gobierna las cotizaciones ahora mismo. */
  activeSource?: string | null;
  loading?: boolean;
};

type RateSource = 'official' | 'admin';
type RateSeriesPoint = { time: string; value: number };
type RateSeries = { official: RateSeriesPoint[]; admin: RateSeriesPoint[] };

function chartPalette() {
  const probe = getComputedStyle(document.documentElement);
  const read = (token: string, fallback: string) => probe.getPropertyValue(token).trim() || fallback;
  return {
    surface: read('--surface-0', '#ffffff'),
    grid: read('--line', '#e7e4dc'),
    axis: read('--ink-400', '#8a93a3'),
    official: read('--indigo-500', '#2e5bff'),
    admin: read('--amber-500', '#f2a93b'),
    font: read('--font-ui', 'system-ui, sans-serif'),
  };
}

/** #rrggbb -> rgba(): los degradados del area necesitan alfa explicito. */
function withAlpha(color: string, alpha: number): string {
  const match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(color.trim());
  if (!match) return color;
  let hex = match[1];
  if (hex.length === 3) hex = hex.split('').map((part) => part + part).join('');
  const value = parseInt(hex, 16);
  return `rgba(${(value >> 16) & 255}, ${(value >> 8) & 255}, ${value & 255}, ${alpha})`;
}

/** 'YYYY-MM-DD' -> Date local a mediodia (sin desfase de zona horaria). */
function localDay(iso: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!match) return null;
  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12, 0, 0);
}

/** Cualquier `Time` del chart -> 'YYYY-MM-DD'. */
function isoDay(time: unknown): string {
  if (typeof time === 'string') return time.slice(0, 10);
  if (typeof time === 'number') return new Date(time * 1000).toISOString().slice(0, 10);
  if (time && typeof time === 'object') {
    const day = time as { year?: number; month?: number; day?: number };
    if (typeof day.year === 'number' && typeof day.month === 'number' && typeof day.day === 'number') {
      return `${day.year}-${String(day.month).padStart(2, '0')}-${String(day.day).padStart(2, '0')}`;
    }
  }
  return '';
}

const rateDecimals = (value: number) => (value >= 100 ? 2 : value >= 1 ? 4 : 6);

function formatRate(value: number, locale: string): string {
  return value.toLocaleString(locale, { maximumFractionDigits: rateDecimals(value) });
}

function formatDay(iso: string, locale: string, withYear = false): string {
  const day = localDay(iso);
  if (!day) return iso;
  return day.toLocaleDateString(
    locale,
    withYear ? { day: '2-digit', month: 'short', year: 'numeric' } : { day: '2-digit', month: 'short' },
  );
}

const sourceOf = (point: ExchangeRateHistoryPoint): RateSource =>
  point.source === 'admin_override' ? 'admin' : 'official';

/** Separa las dos tasas: cada fuente conserva la ultima lectura de cada dia. */
function buildRateSeries(points: ExchangeRateHistoryPoint[]): RateSeries {
  const official = new Map<string, number>();
  const admin = new Map<string, number>();
  for (const point of points) {
    const day = isoDay(String(point.effective_date ?? ''));
    const value = Number(point.rate);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(day) || !Number.isFinite(value) || value <= 0) continue;
    (sourceOf(point) === 'admin' ? admin : official).set(day, value);
  }
  const sort = (series: Map<string, number>) => [...series.entries()]
    .sort((left, right) => (left[0] < right[0] ? -1 : left[0] > right[0] ? 1 : 0))
    .map(([time, value]) => ({ time, value }));
  return { official: sort(official), admin: sort(admin) };
}

function priceFormatFor(series: RateSeries): { precision: number; minMove: number } {
  const values = [...series.official, ...series.admin].map((point) => point.value);
  const smallest = values.length > 0 ? Math.min(...values) : 1;
  const precision = rateDecimals(smallest);
  return { precision, minMove: Number(`1e-${precision}`) };
}

export default function RateChart({ points, base, quote, locale, labels, activeSource, loading = false }: RateChartProps) {
  const data = useMemo(() => buildRateSeries(points), [points]);
  const hostRef = useRef<HTMLDivElement | null>(null);
  const chartRef = useRef<IChartApi | null>(null);
  const officialRef = useRef<ISeriesApi<'Area'> | null>(null);
  const adminRef = useRef<ISeriesApi<'Line'> | null>(null);
  const [hover, setHover] = useState<{ time: string; value: number; source: RateSource } | null>(null);
  const [theme, setTheme] = useState(() => document.documentElement.dataset.theme ?? '');

  useEffect(() => {
    const sync = () => setTheme(document.documentElement.dataset.theme ?? '');
    window.addEventListener(THEME_EVENT, sync);
    return () => window.removeEventListener(THEME_EVENT, sync);
  }, []);

  const lastOfficial = data.official[data.official.length - 1];
  const lastAdmin = data.admin[data.admin.length - 1];
  const hasData = data.official.length >= 2 || data.admin.length >= 2;
  const adminIsActive = activeSource === 'admin_override';

  // El chart se crea una sola vez. El idioma forma parte de las opciones, asi
  // que cambiarlo recrea la instancia (operacion infrecuente y barata).
  useEffect(() => {
    const host = hostRef.current;
    if (!host) return undefined;
    const palette = chartPalette();
    const chart = createChart(host, {
      autoSize: true,
      layout: {
        background: { type: ColorType.Solid, color: 'transparent' },
        textColor: palette.axis,
        fontFamily: palette.font,
        fontSize: 11,
        attributionLogo: false,
      },
      grid: { vertLines: { visible: false }, horzLines: { color: palette.grid, style: LineStyle.Dotted } },
      rightPriceScale: { borderVisible: false, scaleMargins: { top: 0.18, bottom: 0.12 } },
      timeScale: {
        borderVisible: false,
        fixLeftEdge: true,
        fixRightEdge: true,
        tickMarkFormatter: (time: Time) => formatDay(isoDay(time), locale),
      },
      crosshair: {
        mode: CrosshairMode.Magnet,
        vertLine: { color: palette.axis, width: 1, style: LineStyle.Dashed, labelVisible: false },
        horzLine: { color: palette.axis, width: 1, style: LineStyle.Dashed, labelVisible: true },
      },
      handleScroll: false,
      handleScale: false,
      localization: {
        locale,
        priceFormatter: (price: number) => formatRate(price, locale),
      },
    });
    const official = chart.addSeries(AreaSeries, {
      lineWidth: 2,
      priceLineVisible: false,
      lastValueVisible: false,
      crosshairMarkerVisible: true,
      crosshairMarkerRadius: 4,
      crosshairMarkerBorderWidth: 2,
      crosshairMarkerBorderColor: palette.surface,
    });
    const admin = chart.addSeries(LineSeries, {
      lineWidth: 2,
      lineStyle: LineStyle.Dashed,
      lineType: LineType.WithSteps,
      priceLineVisible: false,
      lastValueVisible: false,
      crosshairMarkerVisible: true,
      crosshairMarkerRadius: 4,
      crosshairMarkerBorderWidth: 2,
      crosshairMarkerBorderColor: palette.surface,
    });
    chartRef.current = chart;
    officialRef.current = official;
    adminRef.current = admin;
    chart.subscribeCrosshairMove((param) => {
      if (!param.time) { setHover(null); return; }
      const adminEntry = param.seriesData.get(admin) as { value?: number } | undefined;
      const officialEntry = param.seriesData.get(official) as { value?: number } | undefined;
      if (typeof adminEntry?.value === 'number') {
        setHover({ time: isoDay(param.time), value: adminEntry.value, source: 'admin' });
      } else if (typeof officialEntry?.value === 'number') {
        setHover({ time: isoDay(param.time), value: officialEntry.value, source: 'official' });
      } else {
        setHover(null);
      }
    });
    return () => {
      chart.remove();
      chartRef.current = null;
      officialRef.current = null;
      adminRef.current = null;
    };
  }, [locale]);

  // Cambio de tema: la paleta del canvas se lee de los tokens CSS.
  useEffect(() => {
    const chart = chartRef.current;
    if (!chart) return;
    const palette = chartPalette();
    chart.applyOptions({
      layout: { textColor: palette.axis, fontFamily: palette.font },
      grid: { horzLines: { color: palette.grid, style: LineStyle.Dotted } },
      crosshair: { vertLine: { color: palette.axis }, horzLine: { color: palette.axis } },
    });
    officialRef.current?.applyOptions({
      lineColor: palette.official,
      topColor: withAlpha(palette.official, 0.22),
      bottomColor: withAlpha(palette.official, 0.02),
      crosshairMarkerBorderColor: palette.surface,
    });
    adminRef.current?.applyOptions({ color: palette.admin, crosshairMarkerBorderColor: palette.surface });
  }, [theme]);

  // Datos: se reemplazan ambas series (<= 60 puntos cada una, coste despreciable).
  useEffect(() => {
    const chart = chartRef.current;
    const official = officialRef.current;
    const admin = adminRef.current;
    if (!chart || !official || !admin) return;
    const palette = chartPalette();
    const priceFormat = { type: 'price' as const, ...priceFormatFor(data) };
    official.applyOptions({
      lineColor: palette.official,
      topColor: withAlpha(palette.official, 0.22),
      bottomColor: withAlpha(palette.official, 0.02),
      priceFormat,
    });
    admin.applyOptions({ color: palette.admin, priceFormat });
    official.setData(data.official);
    admin.setData(data.admin);
    chart.timeScale().fitContent();
  }, [data, theme, locale]);

  // Variacion de la serie que gobierna; si aun no tiene dos puntos, la otra.
  const primary = adminIsActive
    ? (data.admin.length >= 2 ? data.admin : data.official)
    : (data.official.length >= 2 ? data.official : data.admin);
  const primarySource: RateSource = primary === data.admin ? 'admin' : 'official';
  const first = primary[0];
  const last = primary[primary.length - 1];
  const delta = primary.length >= 2 ? last.value - first.value : 0;
  const change = primary.length >= 2 && first.value !== 0 ? (delta / first.value) * 100 : 0;
  const direction = delta > 0 ? 'up' : delta < 0 ? 'down' : 'flat';
  const sourceLabel = (source: RateSource) => (source === 'admin' ? labels.admin : labels.official);

  const summary = [
    `${base}/${quote}`,
    lastOfficial && `${labels.official} ${formatRate(lastOfficial.value, locale)}`,
    lastAdmin && `${labels.admin} ${formatRate(lastAdmin.value, locale)}`,
    primary.length >= 2 && `${change >= 0 ? '+' : ''}${change.toFixed(2)}%`,
  ].filter(Boolean).join(' · ');

  return <div className="rate-chart">
    <div className="rate-chart-header">
      <span className="rate-chart-pair">{base}/{quote}</span>
      {primary.length >= 2 && <span className={`rate-chart-change ${direction}`}>{direction === 'up' ? '▲' : direction === 'down' ? '▼' : '•'} {change >= 0 ? '+' : ''}{change.toFixed(2)}% <small>{sourceLabel(primarySource)}</small></span>}
      {hover && <span className="rate-chart-hover">{formatRate(hover.value, locale)} · {sourceLabel(hover.source)} · {formatDay(hover.time, locale, true)}</span>}
    </div>
    {(lastOfficial || lastAdmin) && <div className="rate-chart-legend">
      {lastOfficial && <span className="rate-chart-legend-item">
        <i className="rate-chart-swatch official" aria-hidden="true" />
        {labels.official} <b>{formatRate(lastOfficial.value, locale)}</b>
        {!adminIsActive && <em>{labels.active}</em>}
      </span>}
      {lastAdmin && <span className="rate-chart-legend-item">
        <i className="rate-chart-swatch admin" aria-hidden="true" />
        {labels.admin} <b>{formatRate(lastAdmin.value, locale)}</b>
        {adminIsActive && <em>{labels.active}</em>}
      </span>}
    </div>}
    <div className={`rate-chart-canvas${hasData ? '' : ' is-empty'}`}>
      <div
        className="rate-chart-host"
        ref={hostRef}
        role="img"
        aria-label={`${labels.title} ${base}/${quote}`}
      />
      {!hasData && <div className="rate-chart-empty muted">{loading ? labels.loading : labels.empty}</div>}
    </div>
    {hasData && <p className="sr-only">{summary}</p>}
  </div>;
}
