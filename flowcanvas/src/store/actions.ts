import { clampNode } from '../model/geometry';
import { HEAD_W, LANE_H, nodeSize, uid, type Flow, type LaneKind, type NodeType } from '../model/types';
import type { Draft } from 'immer';
import { edit, flash, getState, setState } from './flowStore';

const sortLanes = (d: Draft<Flow>) => {
  // Actor は上段、システムは下段（同じ区分の中の順番は保つ）
  d.lanes.sort((a, b) => Number(a.kind === 'system') - Number(b.kind === 'system'));
};

export function addLane(kind: LaneKind) {
  const id = uid();
  edit((d) => {
    d.lanes.push({ id, kind, name: kind === 'actor' ? '新しい担当' : '新しいシステム' });
    sortLanes(d);
  });
  setState({ sel: { kind: 'lane', id } });
  focusSoon('#p-lane-name');
}

export function setLaneKind(id: string, kind: LaneKind) {
  edit((d) => {
    const l = d.lanes.find((x) => x.id === id);
    if (l) l.kind = kind;
    sortLanes(d);
  });
}

export function moveLane(id: string, dir: -1 | 1) {
  edit((d) => {
    const i = d.lanes.findIndex((l) => l.id === id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= d.lanes.length || d.lanes[j].kind !== d.lanes[i].kind) return;
    [d.lanes[i], d.lanes[j]] = [d.lanes[j], d.lanes[i]];
  });
}

/** 部品が残っているレーンは2回押しで確定（confirm() は使わない） */
export function deleteLane(id: string) {
  const { flow, confirmDel } = getState();
  if (!flow) return;
  const cnt = flow.nodes.filter((n) => n.lane === id).length;
  if (cnt && confirmDel !== id) {
    setState({ confirmDel: id, sel: { kind: 'lane', id } });
    return;
  }
  edit((d) => {
    const gone = new Set(d.nodes.filter((n) => n.lane === id).map((n) => n.id));
    d.nodes = d.nodes.filter((n) => !gone.has(n.id));
    d.edges = d.edges.filter((e) => !gone.has(e.from) && !gone.has(e.to));
    d.lanes = d.lanes.filter((l) => l.id !== id);
  });
  setState((s) => ({ confirmDel: null, sel: s.sel?.id === id ? null : s.sel }));
}

const DEFAULT_LABEL: Record<NodeType, string> = { start: '開始', end: '終了', task: '作業', decision: '判断？', data: 'データ' };

export function addNode(type: NodeType) {
  const { flow, sel } = getState();
  if (!flow) return;
  let laneId =
    sel?.kind === 'lane' ? sel.id : sel?.kind === 'node' ? flow.nodes.find((n) => n.id === sel.id)?.lane : undefined;
  if (!laneId) {
    // 未選択なら最初のレーン。データは最初のシステムレーン
    const pref = flow.lanes.find((l) => l.kind === (type === 'data' ? 'system' : 'actor')) ?? flow.lanes[0];
    if (!pref) return flash('先にActorかシステムを追加してください');
    laneId = pref.id;
  }
  const [, h] = nodeSize(type);
  const inLane = flow.nodes.filter((n) => n.lane === laneId);
  const right = inLane.reduce((m, n) => Math.max(m, n.x + nodeSize(n.type)[0]), HEAD_W);
  const id = uid();
  edit((d) => {
    const n = { id, type, lane: laneId!, x: Math.round((right + 40) / 10) * 10, ly: Math.round((LANE_H - h) / 2), label: DEFAULT_LABEL[type] };
    clampNode(n);
    d.nodes.push(n);
  });
  setState({ sel: { kind: 'node', id } });
  focusSoon('#p-label', true);
}

export function connect(from: string, to: string) {
  if (from === to) return; // 同じ部品同士は繋がない
  const id = uid();
  const kind = getState().edgeKind;
  edit((d) => {
    d.edges.push({ id, from, to, kind, label: '' });
  });
  setState({ connectSrc: null, sel: { kind: 'edge', id } });
  focusSoon('#p-elabel'); // 確定直後にラベル欄へ
}

export function deleteSel() {
  const { sel } = getState();
  if (!sel) return;
  if (sel.kind === 'lane') return deleteLane(sel.id);
  edit((d) => {
    if (sel.kind === 'node') {
      d.nodes = d.nodes.filter((n) => n.id !== sel.id);
      d.edges = d.edges.filter((e) => e.from !== sel.id && e.to !== sel.id);
    } else {
      d.edges = d.edges.filter((e) => e.id !== sel.id);
    }
  });
  setState({ sel: null });
}

export function focusSoon(selector: string, select = false) {
  requestAnimationFrame(() => {
    const el = document.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector);
    if (!el) return;
    el.focus();
    if (select) el.select();
  });
}
