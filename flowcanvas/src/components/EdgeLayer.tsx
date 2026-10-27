import { memo, useMemo, useRef } from 'react';
import { buildObstacles, edgePath, edgeSides, nodeRect, type Rect } from '../model/geometry';
import type { Edge, Node, Side } from '../model/types';

export type EdgeGeo = { d: string; mx: number; my: number; crosses: boolean };

/**
 * すべての線の経路をまとめて計算する。
 * - 同じ部品の同じ辺に何本も付くときは、相手の位置の順に少しずつずらす
 * - 直角の線は途中の部品を避ける
 * - 線が両端以外の部品の下を通るかを調べる（通るものは部品の上に薄く重ねて見せる）
 */
export function useEdgeGeometry(edges: Edge[], nodes: Node[], tops: Map<string, number>) {
  // 前回の結果を覚えておき、動いた部品に関係する線と、その近くを通る線だけ計算し直す
  const cache = useRef<{ rects: Map<string, Rect>; edges: Map<string, Cached> }>({ rects: new Map(), edges: new Map() });
  return useMemo(() => {
    // 前回から位置や大きさが変わった部品（前と今の両方の四角）。変わっていなければ前の四角をそのまま使う
    const prev = cache.current;
    const rects = new Map<string, Rect>();
    const changed: Box[] = [];
    for (const n of nodes) {
      const r = nodeRect(n, tops.get(n.lane) ?? 0);
      const o = prev.rects.get(n.id);
      if (o && o.x === r.x && o.y === r.y && o.w === r.w && o.h === r.h) rects.set(n.id, o);
      else {
        rects.set(n.id, r);
        changed.push(toBox(r));
        if (o) changed.push(toBox(o));
      }
    }
    const obstacles = buildObstacles([...rects].map(([id, r]) => ({ id, r })));
    for (const [id, o] of prev.rects) if (!rects.has(id)) changed.push(toBox(o));

    // 辺ごとに付く線を集める
    const ends = new Map<string, { edge: string; which: 'from' | 'to'; other: Rect }[]>();
    for (const e of edges) {
      const a = rects.get(e.from), b = rects.get(e.to);
      if (!a || !b) continue;
      const sd = edgeSides(a, b, e);
      const push = (key: string, v: { edge: string; which: 'from' | 'to'; other: Rect }) => {
        const l = ends.get(key);
        if (l) l.push(v);
        else ends.set(key, [v]);
      };
      push(e.from + '|' + sd[0], { edge: e.id, which: 'from', other: b });
      push(e.to + '|' + sd[1], { edge: e.id, which: 'to', other: a });
    }
    const offs = new Map<string, { from?: number; to?: number }>();
    for (const [key, list] of ends) {
      if (list.length < 2) continue;
      const [nodeId, side] = key.split('|') as [string, Side];
      const r = rects.get(nodeId)!;
      const horiz = side === 'top' || side === 'bottom';
      list.sort((p, q) => (horiz ? p.other.cx - q.other.cx : p.other.cy - q.other.cy));
      const room = (horiz ? r.w : r.h) - 16;
      const step = Math.min(16, room / list.length);
      list.forEach((v, i) => {
        const o = offs.get(v.edge) ?? {};
        o[v.which] = Math.round((i - (list.length - 1) / 2) * step);
        offs.set(v.edge, o);
      });
    }

    const geo = new Map<string, EdgeGeo>();
    const nextCache = new Map<string, Cached>();
    for (const e of edges) {
      const a = rects.get(e.from), b = rects.get(e.to);
      if (!a || !b) continue;
      const o = offs.get(e.id);
      const old = prev.edges.get(e.id);
      // 端も形も同じで、近くの部品も動いていなければ前回のまま使う（四角は変わっていなければ同じもの）
      const same =
        old && old.a === a && old.b === b && old.e.route === e.route && old.e.fromSide === e.fromSide && old.e.toSide === e.toSide &&
        old.from === o?.from && old.to === o?.to;
      if (same && !changed.some((c) => overlap(c, old.box))) {
        geo.set(e.id, old.geo);
        nextCache.set(e.id, old);
        continue;
      }
      const ignore = [e.from, e.to];
      const { d, mx, my, pts } = edgePath(a, b, e, { fromOff: o?.from, toOff: o?.to, obstacles, ignore });
      const crosses = pts.some((p) => obstacles.hitsPoint(p, ignore));
      const g = { d, mx, my, crosses };
      geo.set(e.id, g);
      // この線が通る範囲と両端の部品の間（まっすぐ通れる道が空いたときにも気付けるように）
      let x0 = Math.min(a.x, b.x), y0 = Math.min(a.y, b.y), x1 = Math.max(a.x + a.w, b.x + b.w), y1 = Math.max(a.y + a.h, b.y + b.h);
      for (const [px, py] of pts) {
        if (px < x0) x0 = px;
        if (px > x1) x1 = px;
        if (py < y0) y0 = py;
        if (py > y1) y1 = py;
      }
      const box: Box = [x0 - 40, y0 - 40, x1 + 40, y1 + 40];
      nextCache.set(e.id, { a, b, e, from: o?.from, to: o?.to, geo: g, box });
    }
    cache.current = { rects, edges: nextCache };
    return geo;
  }, [edges, nodes, tops]);
}

type Box = [number, number, number, number];
type Cached = { a: Rect; b: Rect; e: Edge; from?: number; to?: number; geo: EdgeGeo; box: Box };
const toBox = (r: Rect): Box => [r.x, r.y, r.x + r.w, r.y + r.h];
const overlap = (p: Box, q: Box) => p[0] < q[2] && p[2] > q[0] && p[1] < q[3] && p[3] > q[1];

type Props = {
  edges: Edge[];
  geo: Map<string, EdgeGeo>;
  selId: string | null;
  width: number;
  height: number;
  pending: string | null; // 接続中の仮の線（path の d）
};

export const EdgeLayer = memo(function EdgeLayer({ edges, geo, selId, width, height, pending }: Props) {
  // 色を変えた線の矢じり。色ごとに1つ作る
  const colors = useMemo(() => [...new Set(edges.map((e) => e.color).filter((c): c is string => !!c))], [edges]);
  const markerOf = useMemo(() => new Map(colors.map((c, i) => [c, `arC${i}`])), [colors]);
  return (
    <svg className="edges" width={width} height={height}>
      <defs>
        {(['arF', 'arD'] as const).map((id) => (
          <marker key={id} id={id} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">
            <path className={id} d="M0 0L10 5L0 10z" style={{ fill: MARKER_FILL[id] }} />
          </marker>
        ))}
        {colors.map((c) => (
          <marker key={c} id={markerOf.get(c)} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">
            <path d="M0 0L10 5L0 10z" style={{ fill: c }} />
          </marker>
        ))}
      </defs>
      {edges.map((e) => {
        const g = geo.get(e.id);
        if (!g) return null;
        const marker = e.color ? markerOf.get(e.color)! : e.kind === 'flow' ? 'arF' : 'arD';
        return <EdgeView key={e.id} edge={e} d={g.d} mx={g.mx} my={g.my} selected={e.id === selId} marker={marker} />;
      })}
      {pending && <path className="line pending" d={pending} style={{ fill: 'none', stroke: 'var(--ok)', strokeWidth: 2, strokeDasharray: '4 4' }} />}
    </svg>
  );
});

type EdgeProps = { edge: Edge; d: string; mx: number; my: number; selected: boolean; marker: string };

const EdgeView = memo(function EdgeView({ edge, d, mx, my, selected, marker }: EdgeProps) {
  return (
    <g>
      <path className="hit" d={d} data-id={edge.id} style={{ fill: 'none', stroke: 'transparent' }} />
      {/* 選んでいる線は色を変えず、下に太い帯を敷いて目立たせる（変えた色をそのまま確かめられるように） */}
      {selected && <path d={d} style={SEL_HALO} />}
      <path className={`line ${edge.kind}${selected ? ' sel' : ''}`} d={d} markerEnd={`url(#${marker})`} style={lineStyle(edge, selected)} />
      {edge.label && (
        <text x={mx} y={my - 6} textAnchor="middle" style={TEXT_STYLE}>
          {edge.label}
        </text>
      )}
    </g>
  );
});

// 画像書き出し（html-to-image）では SVG にクラスの CSS が効かないので、見た目はインラインで持たせる
const MARKER_FILL = { arF: 'var(--flow)', arD: 'var(--dflow)' } as const;

function lineStyle({ kind, color }: Edge, selected: boolean): React.CSSProperties {
  return {
    fill: 'none',
    stroke: color ?? (kind === 'flow' ? 'var(--flow)' : 'var(--dflow)'),
    strokeWidth: selected ? 2.6 : color ? 2.2 : 1.8,
    strokeDasharray: kind === 'data' ? '6 4' : undefined,
  };
}

/** 部品の下を通る線を、部品の上に薄い点線で重ねて見せる（どこへ抜けているか分かるように） */
export const EdgeCrossLayer = memo(function EdgeCrossLayer({ edges, geo, width, height }: Omit<Props, 'selId' | 'pending'>) {
  return (
    <svg className="edges over" width={width} height={height} aria-hidden="true">
      {edges.map((e) => {
        const g = geo.get(e.id);
        if (!g?.crosses) return null;
        return <path key={e.id} d={g.d} style={{ ...lineStyle(e, false), strokeWidth: 1.4, strokeDasharray: '3 3', strokeOpacity: 0.55 }} />;
      })}
    </svg>
  );
});

const SEL_HALO: React.CSSProperties = { fill: 'none', stroke: 'var(--sel)', strokeWidth: 10, strokeOpacity: 0.55, strokeLinecap: 'round' };

const TEXT_STYLE: React.CSSProperties = {
  fontFamily: 'var(--font)',
  fontSize: 12,
  fill: 'var(--fg)',
  paintOrder: 'stroke',
  stroke: 'var(--bg)',
  strokeWidth: 4,
};
