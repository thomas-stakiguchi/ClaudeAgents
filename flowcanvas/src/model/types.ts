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

export const nodeSize = (t: NodeType): [number, number] =>
  t === 'task' ? [150, 56]
  : t === 'decision' ? [130, 74]
  : t === 'data' ? [140, 52]
  : t === 'note' ? [160, 72]
  : isIconType(t) ? [110, 80]
  : [48, 48];
