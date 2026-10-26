export type LaneKind = 'actor' | 'system';
export type NodeType = 'start' | 'end' | 'task' | 'decision' | 'data';
export type EdgeKind = 'flow' | 'data';

export type Lane = { id: string; kind: LaneKind; name: string; desc?: string };
export type Node = {
  id: string;
  type: NodeType;
  lane: string; // Lane.id
  x: number; // レーン内の横位置（px）
  ly: number; // レーン上端からの縦位置（px）
  label: string;
  note?: string;
};
export type Edge = { id: string; from: string; to: string; kind: EdgeKind; label?: string; color?: string /* 未設定なら種類ごとの既定色 */ };
export type Flow = { id: string; title: string; lanes: Lane[]; nodes: Node[]; edges: Edge[]; updatedAt: string };
export type FlowSummary = Pick<Flow, 'id' | 'title' | 'updatedAt'>;

export const LANE_H = 150;
export const HEAD_W = 120;
export const MIN_WORLD_W = 2600;

export const uid = () => Math.random().toString(36).slice(2, 9);

export const nodeSize = (t: NodeType): [number, number] =>
  t === 'task' ? [150, 56] : t === 'decision' ? [130, 74] : t === 'data' ? [140, 52] : [48, 48];
