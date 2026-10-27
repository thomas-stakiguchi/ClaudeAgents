import { HEAD_W, LANE_H, LANE_H_MAX, LANE_H_MIN, MIN_WORLD_W, nodeSize, type Edge, type Flow, type Lane, type Node, type Side } from './types';

export type Rect = { x: number; y: number; w: number; h: number; cx: number; cy: number };

export function nodeRect(n: Node, laneTop: number): Rect {
  const [w, h] = nodeSize(n.type);
  const y = laneTop + n.ly;
  return { x: n.x, y, w, h, cx: n.x + w / 2, cy: y + h / 2 };
}

export const laneHeight = (l: Pick<Lane, 'h'>) => l.h ?? LANE_H;
export const clampLaneH = (h: number) => Math.min(LANE_H_MAX, Math.max(LANE_H_MIN, Math.round(h / 10) * 10));

/** レーン id → 上端 y（上の枠の高さを順に足す） */
export function laneTops(flow: Pick<Flow, 'lanes'>): Map<string, number> {
  let y = 0;
  return new Map(
    flow.lanes.map((l) => {
      const top = y;
      y += laneHeight(l);
      return [l.id, top];
    }),
  );
}

export const totalHeight = (flow: Pick<Flow, 'lanes'>) => flow.lanes.reduce((t, l) => t + laneHeight(l), 0);

/** 縦の位置 y にある枠の番号（範囲の外なら端の枠） */
export function laneIndexAt(lanes: Pick<Lane, 'h'>[], y: number) {
  let top = 0;
  for (let i = 0; i < lanes.length; i++) {
    top += laneHeight(lanes[i]);
    if (y < top) return i;
  }
  return lanes.length - 1;
}

/** 部品を枠の中に収める（枠の高さが分かるよう lanes も渡す） */
export function clampNode(n: Node, lanes: Pick<Lane, 'id' | 'h'>[]) {
  const [, h] = nodeSize(n.type);
  const lane = lanes.find((l) => l.id === n.lane);
  const lh = lane ? laneHeight(lane) : LANE_H;
  n.ly = Math.max(8, Math.min(lh - h - 8, n.ly));
  n.x = Math.max(HEAD_W + 10, n.x);
}

/** 部品の右端（図全体の幅を決めるのに使う） */
export function contentRight(flow: Flow) {
  let maxX = HEAD_W + 300;
  for (const n of flow.nodes) maxX = Math.max(maxX, n.x + nodeSize(n.type)[0]);
  return maxX;
}
export const worldWidth = (flow: Flow) => Math.max(MIN_WORLD_W, contentRight(flow) + 400);

const NORMAL: Record<Side, [number, number]> = { top: [0, -1], right: [1, 0], bottom: [0, 1], left: [-1, 0] };
const isH = (s: Side) => s === 'left' || s === 'right';

/** 辺を指定していないときの決め方：横方向が主なら左右、縦方向が主なら上下（モックの edgePath と同じ） */
export function autoSides(a: Rect, b: Rect): [Side, Side] {
  const dx = b.cx - a.cx, dy = b.cy - a.cy;
  if (Math.abs(dx) > Math.abs(dy) * 0.8 && Math.abs(dx) > a.w / 2 + b.w / 2 + 20) return dx > 0 ? ['right', 'left'] : ['left', 'right'];
  return dy > 0 ? ['bottom', 'top'] : ['top', 'bottom'];
}

type EdgeShape = Pick<Edge, 'route' | 'fromSide' | 'toSide'>;

/** 線の経路（SVG の d）とラベルを置く位置 */
export function edgePath(a: Rect, b: Rect, e: EdgeShape = {}) {
  const [as, bs] = autoSides(a, b);
  const s1 = e.fromSide ?? as, s2 = e.toSide ?? bs;
  const p1 = portPoint(a, s1), p2 = portPoint(b, s2);
  const n1 = NORMAL[s1], n2 = NORMAL[s2];

  if (e.route === 'ortho') return orthoPath(p1, s1, p2, s2);

  // ベジェ曲線：それぞれの辺から外向きに引っぱる
  // 引っぱる強さは今までと同じ（横なら横の距離の半分、縦なら縦の距離の半分）
  const o = isH(s1) ? Math.max(40, Math.abs(b.cx - a.cx) / 2) : Math.max(30, Math.abs(b.cy - a.cy) / 2);
  const c1 = [p1[0] + n1[0] * o, p1[1] + n1[1] * o];
  const c2 = [p2[0] + n2[0] * o, p2[1] + n2[1] * o];
  const mx = (p1[0] + 3 * c1[0] + 3 * c2[0] + p2[0]) / 8;
  const my = (p1[1] + 3 * c1[1] + 3 * c2[1] + p2[1]) / 8;
  return { d: `M${p1[0]} ${p1[1]}C${c1[0]} ${c1[1]},${c2[0]} ${c2[1]},${p2[0]} ${p2[1]}`, mx, my };
}

/** 直角の線：辺から少し外へ出てから、縦横だけで結ぶ */
function orthoPath(p1: [number, number], s1: Side, p2: [number, number], s2: Side) {
  const GAP = 20;
  const a: [number, number] = [p1[0] + NORMAL[s1][0] * GAP, p1[1] + NORMAL[s1][1] * GAP];
  const b: [number, number] = [p2[0] + NORMAL[s2][0] * GAP, p2[1] + NORMAL[s2][1] * GAP];
  const pts: [number, number][] = [p1, a];
  if (isH(s1) && isH(s2)) {
    // 同じ向きの辺どうし（右と右など）は、両方より外側を回る
    const mx = s1 !== s2 ? (a[0] + b[0]) / 2 : s1 === 'right' ? Math.max(a[0], b[0]) : Math.min(a[0], b[0]);
    pts.push([mx, a[1]], [mx, b[1]]);
  } else if (!isH(s1) && !isH(s2)) {
    // 上と上なら両方より上、下と下なら両方より下を回る（戻りの線が行きの線と重ならないように）
    const my = s1 !== s2 ? (a[1] + b[1]) / 2 : s1 === 'bottom' ? Math.max(a[1], b[1]) : Math.min(a[1], b[1]);
    pts.push([a[0], my], [b[0], my]);
  } else if (isH(s1)) {
    pts.push([b[0], a[1]]);
  } else {
    pts.push([a[0], b[1]]);
  }
  pts.push(b, p2);
  // 同じ点が続くところは除く
  const clean = pts.filter((p, i) => i === 0 || p[0] !== pts[i - 1][0] || p[1] !== pts[i - 1][1]);
  const [mx, my] = midpoint(clean);
  return { d: 'M' + clean.map((p) => `${p[0]} ${p[1]}`).join('L'), mx, my };
}

/** 折れ線の長さの真ん中の点（ラベルの位置） */
function midpoint(pts: [number, number][]): [number, number] {
  const seg = pts.slice(1).map((p, i) => Math.hypot(p[0] - pts[i][0], p[1] - pts[i][1]));
  let rest = seg.reduce((x, y) => x + y, 0) / 2;
  for (let i = 0; i < seg.length; i++) {
    if (rest <= seg[i] && seg[i] > 0) {
      const t = rest / seg[i];
      return [pts[i][0] + (pts[i + 1][0] - pts[i][0]) * t, pts[i][1] + (pts[i + 1][1] - pts[i][1]) * t];
    }
    rest -= seg[i];
  }
  return pts[0];
}

export type Port = Side;
export function portPoint(r: Rect, p: Port): [number, number] {
  return p === 'top' ? [r.cx, r.y] : p === 'bottom' ? [r.cx, r.y + r.h] : p === 'left' ? [r.x, r.cy] : [r.x + r.w, r.cy];
}
