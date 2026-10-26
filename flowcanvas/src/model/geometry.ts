import { HEAD_W, LANE_H, MIN_WORLD_W, nodeSize, type Flow, type Node } from './types';

export type Rect = { x: number; y: number; w: number; h: number; cx: number; cy: number };

export function nodeRect(n: Node, laneTop: number): Rect {
  const [w, h] = nodeSize(n.type);
  const y = laneTop + n.ly;
  return { x: n.x, y, w, h, cx: n.x + w / 2, cy: y + h / 2 };
}

/** レーン id → 上端 y */
export function laneTops(flow: Flow): Map<string, number> {
  return new Map(flow.lanes.map((l, i) => [l.id, i * LANE_H]));
}

export function clampNode(n: Node) {
  const [, h] = nodeSize(n.type);
  n.ly = Math.max(8, Math.min(LANE_H - h - 8, n.ly));
  n.x = Math.max(HEAD_W + 10, n.x);
}

/** 部品の右端（図全体の幅を決めるのに使う） */
export function contentRight(flow: Flow) {
  let maxX = HEAD_W + 300;
  for (const n of flow.nodes) maxX = Math.max(maxX, n.x + nodeSize(n.type)[0]);
  return maxX;
}
export const worldWidth = (flow: Flow) => Math.max(MIN_WORLD_W, contentRight(flow) + 400);

/** 横方向が主なら左右ポート、縦方向が主なら上下ポートからのベジェ（モックの edgePath と同じ） */
export function edgePath(a: Rect, b: Rect) {
  const dx = b.cx - a.cx, dy = b.cy - a.cy;
  let p1: number[], p2: number[], c1: number[], c2: number[];
  if (Math.abs(dx) > Math.abs(dy) * 0.8 && Math.abs(dx) > a.w / 2 + b.w / 2 + 20) {
    const s = dx > 0 ? 1 : -1;
    p1 = [a.cx + (s * a.w) / 2, a.cy];
    p2 = [b.cx - (s * b.w) / 2, b.cy];
    const o = Math.max(40, Math.abs(dx) / 2);
    c1 = [p1[0] + s * o, p1[1]];
    c2 = [p2[0] - s * o, p2[1]];
  } else {
    const s = dy > 0 ? 1 : -1;
    p1 = [a.cx, a.cy + (s * a.h) / 2];
    p2 = [b.cx, b.cy - (s * b.h) / 2];
    const o = Math.max(30, Math.abs(dy) / 2);
    c1 = [p1[0], p1[1] + s * o];
    c2 = [p2[0], p2[1] - s * o];
  }
  const mx = (p1[0] + 3 * c1[0] + 3 * c2[0] + p2[0]) / 8;
  const my = (p1[1] + 3 * c1[1] + 3 * c2[1] + p2[1]) / 8;
  return { d: `M${p1[0]} ${p1[1]}C${c1[0]} ${c1[1]},${c2[0]} ${c2[1]},${p2[0]} ${p2[1]}`, mx, my };
}

export type Port = 'top' | 'right' | 'bottom' | 'left';
export function portPoint(r: Rect, p: Port): [number, number] {
  return p === 'top' ? [r.cx, r.y] : p === 'bottom' ? [r.cx, r.y + r.h] : p === 'left' ? [r.x, r.cy] : [r.x + r.w, r.cy];
}
