import { HEAD_W, LANE_H, LANE_H_MAX, LANE_H_MIN, MIN_WORLD_W, nodeSize, type Edge, type Flow, type Lane, type Node, type Side } from './types';

export type Rect = { x: number; y: number; w: number; h: number; cx: number; cy: number };

export function nodeRect(n: Node, laneTop: number): Rect {
  const [w, h] = nodeSize(n.type, n.label);
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
  const [, h] = nodeSize(n.type, n.label);
  const lane = lanes.find((l) => l.id === n.lane);
  const lh = lane ? laneHeight(lane) : LANE_H;
  n.ly = Math.max(8, Math.min(lh - h - 8, n.ly));
  n.x = Math.max(HEAD_W + 10, n.x);
}

/** 部品の右端（図全体の幅を決めるのに使う） */
export function contentRight(flow: Flow) {
  let maxX = HEAD_W + 300;
  for (const n of flow.nodes) maxX = Math.max(maxX, n.x + nodeSize(n.type, n.label)[0]);
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
type Pt = [number, number];

/** 線が実際に使う辺（指定がなければ向きから決める） */
export function edgeSides(a: Rect, b: Rect, e: EdgeShape = {}): [Side, Side] {
  const [as, bs] = autoSides(a, b);
  return [e.fromSide ?? as, e.toSide ?? bs];
}

export type PathOpts = {
  fromOff?: number; // 辺の中央からずらす量（同じ辺に何本も線が付くとき）
  toOff?: number;
  obstacles?: Obstacles; // 直角の線が避ける部品
  ignore?: string[]; // 避けなくてよい部品（線の両端）
};

const crossesAny = (pts: Pt[], o: PathOpts) => !!o.obstacles && pts.some((p) => o.obstacles!.hitsPoint(p, o.ignore));

/** 線の経路（SVG の d）、ラベルを置く位置、経路上の点（部品の下を通るかを調べる用） */
export function edgePath(a: Rect, b: Rect, e: EdgeShape = {}, o: PathOpts = {}) {
  const [s1, s2] = edgeSides(a, b, e);
  const p1 = shift(portPoint(a, s1), s1, o.fromOff), p2 = shift(portPoint(b, s2), s2, o.toOff);

  if (e.route === 'ortho') return orthoPath(p1, s1, p2, s2, o);
  const curve = curvePath(a, b, p1, s1, p2, s2);
  // 曲線が途中の部品にぶつかるときは、部品を回り込む角の丸い直角の線にする
  if (o.obstacles && crossesAny(curve.pts, o)) {
    const alt = orthoPath(p1, s1, p2, s2, o, 12);
    if (!crossesAny(alt.pts, o)) return alt;
  }
  return curve;
}

function curvePath(a: Rect, b: Rect, p1: Pt, s1: Side, p2: Pt, s2: Side) {
  const n1 = NORMAL[s1], n2 = NORMAL[s2];
  // 向かい合う辺どうしで少しだけずれているとき（線をずらした結果など）は、
  // 曲線だと波打って見えるので、まっすぐ結ぶ
  const facing = n1[0] === -n2[0] && n1[1] === -n2[1] && (p2[0] - p1[0]) * n1[0] + (p2[1] - p1[1]) * n1[1] > 0;
  const off = isH(s1) ? Math.abs(p2[1] - p1[1]) : Math.abs(p2[0] - p1[0]);
  if (facing && off > 0 && off <= 24) {
    const pts: Pt[] = [];
    for (let i = 1; i < 12; i++) pts.push([p1[0] + ((p2[0] - p1[0]) * i) / 12, p1[1] + ((p2[1] - p1[1]) * i) / 12]);
    return { d: `M${p1[0]} ${p1[1]}L${p2[0]} ${p2[1]}`, mx: (p1[0] + p2[0]) / 2, my: (p1[1] + p2[1]) / 2, pts };
  }
  // ベジェ曲線：それぞれの辺から外向きに引っぱる
  // 引っぱる強さは今までと同じ（横なら横の距離の半分、縦なら縦の距離の半分）
  const k = isH(s1) ? Math.max(40, Math.abs(b.cx - a.cx) / 2) : Math.max(30, Math.abs(b.cy - a.cy) / 2);
  const c1: Pt = [p1[0] + n1[0] * k, p1[1] + n1[1] * k];
  const c2: Pt = [p2[0] + n2[0] * k, p2[1] + n2[1] * k];
  const mx = (p1[0] + 3 * c1[0] + 3 * c2[0] + p2[0]) / 8;
  const my = (p1[1] + 3 * c1[1] + 3 * c2[1] + p2[1]) / 8;
  const pts: Pt[] = [];
  for (let i = 1; i < 24; i++) {
    const t = i / 24, u = 1 - t;
    pts.push([
      u * u * u * p1[0] + 3 * u * u * t * c1[0] + 3 * u * t * t * c2[0] + t * t * t * p2[0],
      u * u * u * p1[1] + 3 * u * u * t * c1[1] + 3 * u * t * t * c2[1] + t * t * t * p2[1],
    ]);
  }
  return { d: `M${p1[0]} ${p1[1]}C${c1[0]} ${c1[1]},${c2[0]} ${c2[1]},${p2[0]} ${p2[1]}`, mx, my, pts };
}

/** 辺に沿って少しずらす（上下の辺なら横に、左右の辺なら縦に） */
function shift(p: Pt, s: Side, off = 0): Pt {
  return off ? (isH(s) ? [p[0], p[1] + off] : [p[0] + off, p[1]]) : p;
}

/** 直角の線：辺から少し外へ出てから、縦横だけで結ぶ。途中に部品があれば、ぶつからない所を探す */
function orthoPath(p1: Pt, s1: Side, p2: Pt, s2: Side, o: PathOpts, radius = 0) {
  const GAP = 20;
  const a: Pt = [p1[0] + NORMAL[s1][0] * GAP, p1[1] + NORMAL[s1][1] * GAP];
  const b: Pt = [p2[0] + NORMAL[s2][0] * GAP, p2[1] + NORMAL[s2][1] * GAP];
  const blocked = (pts: Pt[]) => !!o.obstacles && pts.slice(1).some((q, i) => o.obstacles!.hitsSegment(pts[i], q, o.ignore));
  // 真ん中の通り道を、元の位置から少しずつずらして試す
  const tryMid = (base: number, make: (m: number) => Pt[]) => {
    for (const d of [0, 20, -20, 40, -40, 60, -60, 90, -90, 120, -120, 160, -160, 200, -200]) {
      const mid = make(base + d);
      if (!blocked(mid)) return mid;
    }
    return make(base);
  };
  // 真ん中をずらしても通れないときは、部品の上か下（左か右）を回り込む
  const detour = (make: (d: number) => Pt[]) => {
    for (const d of [40, -40, 70, -70, 100, -100, 140, -140, 180, -180, 240, -240, 300, -300]) {
      const p = make(d);
      if (!blocked(p)) return p;
    }
    return null;
  };
  let mid: Pt[];
  if (isH(s1) && isH(s2)) {
    // 同じ向きの辺どうし（右と右など）は、両方より外側を回る
    const mx = s1 !== s2 ? (a[0] + b[0]) / 2 : s1 === 'right' ? Math.max(a[0], b[0]) : Math.min(a[0], b[0]);
    mid = tryMid(mx, (m) => [a, [m, a[1]], [m, b[1]], b]);
    if (blocked(mid)) {
      const base = (a[1] + b[1]) / 2;
      mid = detour((d) => [a, [a[0], base + d], [b[0], base + d], b]) ?? mid;
    }
  } else if (!isH(s1) && !isH(s2)) {
    // 上と上なら両方より上、下と下なら両方より下を回る（戻りの線が行きの線と重ならないように）
    const my = s1 !== s2 ? (a[1] + b[1]) / 2 : s1 === 'bottom' ? Math.max(a[1], b[1]) : Math.min(a[1], b[1]);
    mid = tryMid(my, (m) => [a, [a[0], m], [b[0], m], b]);
    if (blocked(mid)) {
      const base = (a[0] + b[0]) / 2;
      mid = detour((d) => [a, [base + d, a[1]], [base + d, b[1]], b]) ?? mid;
    }
  } else if (isH(s1)) {
    mid = [a, [b[0], a[1]], b];
  } else {
    mid = [a, [a[0], b[1]], b];
  }
  const pts = [p1, ...mid, p2];
  // 同じ点が続くところは除く
  const clean = pts.filter((p, i) => i === 0 || p[0] !== pts[i - 1][0] || p[1] !== pts[i - 1][1]);
  const [mx, my] = midpoint(clean);
  // 部品の下を通るかを調べるための点（折れ線の上を一定間隔で）
  const samples: Pt[] = [];
  clean.slice(1).forEach((q, i) => {
    const p = clean[i], n = Math.max(1, Math.ceil(Math.hypot(q[0] - p[0], q[1] - p[1]) / 12));
    for (let j = 1; j <= n; j++) samples.push([p[0] + ((q[0] - p[0]) * j) / n, p[1] + ((q[1] - p[1]) * j) / n]);
  });
  return { d: radius ? roundedPath(clean, radius) : 'M' + clean.map((p) => `${p[0]} ${p[1]}`).join('L'), mx, my, pts: samples };
}

/** 折れ線の角を丸める */
function roundedPath(pts: Pt[], r: number) {
  let d = `M${pts[0][0]} ${pts[0][1]}`;
  for (let i = 1; i < pts.length - 1; i++) {
    const [px, py] = pts[i - 1], [x, y] = pts[i], [nx, ny] = pts[i + 1];
    const l1 = Math.hypot(x - px, y - py), l2 = Math.hypot(nx - x, ny - y);
    const k = Math.min(r, l1 / 2, l2 / 2);
    const ax = x - ((x - px) / l1) * k, ay = y - ((y - py) / l1) * k;
    const bx = x + ((nx - x) / l2) * k, by = y + ((ny - y) / l2) * k;
    d += `L${ax} ${ay}Q${x} ${y} ${bx} ${by}`;
  }
  const [lx, ly] = pts[pts.length - 1];
  return d + `L${lx} ${ly}`;
}

/* ---------- 部品の当たり判定（線が部品を避ける・下を通るのを調べる） ---------- */
export type Obstacles = {
  hitsPoint: (p: Pt, ignore?: string[]) => boolean;
  hitsSegment: (p: Pt, q: Pt, ignore?: string[]) => boolean;
};

const CELL = 200;
/** 部品の四角を升目に分けて持ち、近くの部品だけを調べる */
export function buildObstacles(items: { id: string; r: Rect }[], pad = 4): Obstacles {
  const grid = new Map<string, { id: string; x0: number; y0: number; x1: number; y1: number }[]>();
  for (const { id, r } of items) {
    const box = { id, x0: r.x - pad, y0: r.y - pad, x1: r.x + r.w + pad, y1: r.y + r.h + pad };
    for (let gx = Math.floor(box.x0 / CELL); gx <= Math.floor(box.x1 / CELL); gx++)
      for (let gy = Math.floor(box.y0 / CELL); gy <= Math.floor(box.y1 / CELL); gy++) {
        const k = gx + ',' + gy;
        const list = grid.get(k);
        if (list) list.push(box);
        else grid.set(k, [box]);
      }
  }
  // 範囲にかかる升目の部品を順に見る（同じ部品を2回見ることがあるが、判定には影響しない）
  const some = (x0: number, y0: number, x1: number, y1: number, hit: (b: { id: string; x0: number; y0: number; x1: number; y1: number }) => boolean) => {
    for (let gx = Math.floor(x0 / CELL); gx <= Math.floor(x1 / CELL); gx++)
      for (let gy = Math.floor(y0 / CELL); gy <= Math.floor(y1 / CELL); gy++) {
        const list = grid.get(gx + ',' + gy);
        if (list) for (const b of list) if (hit(b)) return true;
      }
    return false;
  };
  return {
    hitsPoint: ([x, y], ignore = []) => some(x, y, x, y, (b) => x > b.x0 && x < b.x1 && y > b.y0 && y < b.y1 && !ignore.includes(b.id)),
    // 縦か横の線分だけを扱う（直角の線用）
    hitsSegment: ([x0, y0], [x1, y1], ignore = []) => {
      const lx = Math.min(x0, x1), hx = Math.max(x0, x1), ly = Math.min(y0, y1), hy = Math.max(y0, y1);
      return some(lx, ly, hx, hy, (b) => lx < b.x1 && hx > b.x0 && ly < b.y1 && hy > b.y0 && !ignore.includes(b.id));
    },
  };
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
