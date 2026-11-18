import { useEffect, useMemo, useRef, useState } from 'react';
import { AreaSeries, ColorType, CrosshairMode, LineStyle, createChart } from 'lightweight-charts';
import type { IChartApi, ISeriesApi, Time } from 'lightweight-charts';
import type { ExchangeRateHistoryPoint } from './api';
import { THEME_EVENT } from './theme';

// -----------------------------------------------------------------------------
// Historial de tasas — Lightweight Charts 5
//
// Modulo aparte (y cargado con `React.lazy`): la libreria pesa ~171 kB y solo
// debe descargarla quien ve el historial, no toda la aplicacion.
//
// Correcciones respecto al grafico SVG anterior:
//  - `effective_date` llega como 'YYYY-MM-DD'. Se parsea a mediodia LOCAL:
//    `new Date('2026-11-16')` es medianoche UTC y en La Habana (UTC-5) restaba
//    un dia al eje.
//  - Lightweight Charts exige tiempos unicos y ascendentes: se conserva la
//    ultima tasa de cada dia. Antes se apilaban varios puntos en la misma X y
//    aparecian segmentos verticales.
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
};

type RateChartProps = {
  points: ExchangeRateHistoryPoint[];
  base: string;
  quote: string;
  /** Idioma ya resuelto ('es-ES' | 'ru-RU'); evita depender del locale del navegador. */
  locale: string;
  labels: RateChartLabels;
  loading?: boolean;
};

function chartPalette() {
  const probe = getComputedStyle(document.documentElement);
  const read = (token: string, fallback: string) => probe.getPropertyValue(token).trim() || fallback;
  return {
    surface: read('--surface-0', '#ffffff'),
    grid: read('--line', '#e7e4dc'),
    axis: read('--ink-400', '#8a93a3'),
    up: read('--jade-600', '#0b8a63'),
    down: read('--coral-600', '#e8503a'),
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

type RateSeriesPoint = { time: string; value: number };

function buildRateSeries(points: ExchangeRateHistoryPoint[]): RateSeriesPoint[] {
  const byDay = new Map<string, number>();
  for (const point of points) {
    const day = isoDay(String(point.effective_date ?? ''));
    const value = Number(point.rate);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(day) || !Number.isFinite(value) || value <= 0) continue;
    byDay.set(day, value); // la ultima lectura del dia es la vigente
  }
  return [...byDay.entries()]
    .sort((left, right) => (left[0] < right[0] ? -1 : left[0] > right[0] ? 1 : 0))
    .map(([time, value]) => ({ time, value }));
}

export default function RateChart({ points, base, quote, locale, labels, loading = false }: RateChartProps) {
  const data = useMemo(() => buildRateSeries(points), [points]);
  const hostRef = useRef<HTMLDivElement | null>(null);
  const chartRef = useRef<IChartApi | null>(null);
  const seriesRef = useRef<ISeriesApi<'Area'> | null>(null);
  const [hover, setHover] = useState<RateSeriesPoint | null>(null);
  const [theme, setTheme] = useState(() => document.documentElement.dataset.theme ?? '');

  useEffect(() => {
    const sync = () => setTheme(document.documentElement.dataset.theme ?? '');
    window.addEventListener(THEME_EVENT, sync);
    return () => window.removeEventListener(THEME_EVENT, sync);
  }, []);

  const hasData = data.length >= 2;

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
    const series = chart.addSeries(AreaSeries, {
      lineWidth: 2,
      priceLineVisible: false,
      lastValueVisible: false,
      crosshairMarkerVisible: true,
      crosshairMarkerRadius: 4,
      crosshairMarkerBorderWidth: 2,
      crosshairMarkerBorderColor: palette.surface,
    });
    chartRef.current = chart;
    seriesRef.current = series;
    chart.subscribeCrosshairMove((param) => {
      const entry = param.seriesData.get(series) as { value?: number } | undefined;
      if (!param.time || !entry || typeof entry.value !== 'number') { setHover(null); return; }
      setHover({ time: isoDay(param.time), value: entry.value });
    });
    return () => { chart.remove(); chartRef.current = null; seriesRef.current = null; };
  }, [locale]);

  // Cambio de tema: solo paleta y tipografia, sin recrear el chart.
  useEffect(() => {
    const chart = chartRef.current;
    if (!chart) return;
    const palette = chartPalette();
    chart.applyOptions({
      layout: { textColor: palette.axis, fontFamily: palette.font },
      grid: { horzLines: { color: palette.grid, style: LineStyle.Dotted } },
      crosshair: { vertLine: { color: palette.axis }, horzLine: { color: palette.axis } },
    });
    seriesRef.current?.applyOptions({ crosshairMarkerBorderColor: palette.surface });
  }, [theme]);

  // Datos: se reemplaza la serie completa (<= 60 puntos, coste despreciable).
  useEffect(() => {
    const series = seriesRef.current;
    const chart = chartRef.current;
    if (!series || !chart) return;
    if (data.length < 2) { series.setData([]); return; }
    const palette = chartPalette();
    const rising = data[data.length - 1].value >= data[0].value;
    const color = rising ? palette.up : palette.down;
    const decimals = rateDecimals(data[data.length - 1].value);
    series.applyOptions({
      lineColor: color,
      topColor: withAlpha(color, 0.26),
      bottomColor: withAlpha(color, 0.02),
      priceFormat: { type: 'price', precision: decimals, minMove: Number(`1e-${decimals}`) },
    });
    series.setData(data);
    chart.timeScale().fitContent();
  }, [data, theme, locale]);

  const first = data[0];
  const last = data[data.length - 1];
  const delta = hasData ? last.value - first.value : 0;
  const change = hasData && first.value !== 0 ? (delta / first.value) * 100 : 0;
  const direction = delta > 0 ? 'up' : delta < 0 ? 'down' : 'flat';
  const summary = hasData
    ? `${base}/${quote} · ${formatRate(first.value, locale)} → ${formatRate(last.value, locale)} · ${change >= 0 ? '+' : ''}${change.toFixed(2)}%`
    : '';

  return <div className="rate-chart">
    <div className="rate-chart-header">
      <span className="rate-chart-pair">{base}/{quote}</span>
      {hasData && <span className={`rate-chart-change ${direction}`}>{direction === 'up' ? '▲' : direction === 'down' ? '▼' : '•'} {change >= 0 ? '+' : ''}{change.toFixed(2)}%</span>}
      {hover && <span className="rate-chart-hover">{formatRate(hover.value, locale)} · {formatDay(hover.time, locale, true)}</span>}
    </div>
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
