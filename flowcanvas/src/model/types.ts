export type LaneKind = 'actor' | 'system';
export type NodeType =
  | 'start' | 'end' | 'task' | 'decision' | 'data'
  | 'excel' | 'csv' | 'pc' | 'mail' | 'doc' | 'person' | 'clock'
  | 'note';
export type EdgeKind = 'flow' | 'data';

export type Lane = { id: string; kind: LaneKind; name: string; desc?: string; h?: number /* 枠の高さ。未設定なら LANE_H */ };
export type Node = {
  id: string;
  type: NodeType;
  lane: string; // Lane.id
  x: number; // レーン内の横位置（px）
  ly: number; // レーン上端からの縦位置（px）
  label: string;
  note?: string;
};
export type Side = 'top' | 'right' | 'bottom' | 'left';
export type Route = 'curve' | 'ortho';
export type Edge = {
  id: string;
  from: string;
  to: string;
  kind: EdgeKind;
  label?: string;
  color?: string; // 未設定なら種類ごとの既定色
  route?: Route; // 未設定なら曲線
  fromSide?: Side; // 未設定なら向きから自動で決める
  toSide?: Side;
};
export type Flow = { id: string; title: string; lanes: Lane[]; nodes: Node[]; edges: Edge[]; updatedAt: string };
export type FlowSummary = Pick<Flow, 'id' | 'title' | 'updatedAt'>;

export const LANE_H = 150;
export const LANE_H_MIN = 100;
export const LANE_H_MAX = 800;
export const LANE_H_STEP = 50;
export const HEAD_W = 120;
export const MIN_WORLD_W = 2600;

export const uid = () => Math.random().toString(36).slice(2, 9);

/** アイコンで描く部品 */
export const ICON_TYPES: readonly NodeType[] = ['excel', 'csv', 'pc', 'mail', 'doc', 'person', 'clock'];
export const isIconType = (t: NodeType) => ICON_TYPES.includes(t);

const baseSize = (t: NodeType): [number, number] =>
  t === 'task' ? [150, 56]
  : t === 'decision' ? [130, 74]
  : t === 'data' ? [140, 52]
  : t === 'note' ? [160, 72]
  : isIconType(t) ? [110, 80]
  : [48, 48];

// 名前の長さに合わせて広げる部品と、その文字の入る余白・広げる上限
const GROW: Partial<Record<NodeType, { pad: number; padY: number; maxW: number }>> = {
  task: { pad: 24, padY: 16, maxW: 300 },
  data: { pad: 24, padY: 18, maxW: 280 },
  note: { pad: 24, padY: 18, maxW: 300 },
  decision: { pad: 64, padY: 44, maxW: 260 }, // ひし形は文字の入る所が狭い
};

/** 文字の幅の目安（13px の文字：全角 13px、半角 7.5px） */
export function textWidth(s: string) {
  let w = 0;
  for (const ch of s) w += /[\x20-\x7e]/.test(ch) ? 7.5 : 13;
  return w;
}

/**
 * 部品の大きさ。作業・判断・データ・付せんは、名前が長いと横に広げ（2行に収まるまで）、
 * それでも入らなければ縦に伸ばす。
 */
const sizeCache = new Map<string, [number, number]>();

export function nodeSize(t: NodeType, label = ''): [number, number] {
  const [bw, bh] = baseSize(t);
  const g = GROW[t];
  if (!g || !label) return [bw, bh];
  // 同じ種類・同じ名前なら前に計算した大きさを使う（大きな図で毎回計算し直さないように）
  const key = t + '\u0000' + label;
  const hit = sizeCache.get(key);
  if (hit) return hit;
  if (sizeCache.size > 20000) sizeCache.clear();
  const size = calcSize(bw, bh, g, label);
  sizeCache.set(key, size);
  return size;
}

function calcSize(bw: number, bh: number, g: { pad: number; padY: number; maxW: number }, label: string): [number, number] {
  const lines = label.split('\n');
  const widest = Math.max(...lines.map(textWidth));
  let w = bw;
  if (widest > (bw - g.pad) * 2) w = Math.min(g.maxW, Math.ceil(widest / 2 + g.pad));
  const rows = lines.reduce((t2, l) => t2 + Math.max(1, Math.ceil(textWidth(l) / (w - g.pad))), 0);
  const h = Math.max(bh, rows * 17 + g.padY);
  return [Math.ceil(w / 10) * 10, Math.ceil(h / 2) * 2];
}
