import { clampNode } from '../model/geometry';
import { colorName } from '../model/colors';
import { parseConnect } from '../model/nlConnect';
import { HEAD_W, LANE_H, nodeSize, uid, type EdgeKind, type Flow, type LaneKind, type NodeType } from '../model/types';
import type { Draft } from 'immer';
import { edit, flash, getState, setState, type Sel } from './flowStore';

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
  focusSoon('#p-lane-name', true); // 既定の名前を選んでおき、そのまま打てば置き換わるように
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

const DEFAULT_LABEL: Record<NodeType, string> = {
  start: '開始', end: '終了', task: '作業', decision: '判断？', data: 'データ', excel: 'Excelファイル', csv: 'CSVファイル', pc: 'PC',
  mail: 'メール', doc: '書類', person: '顧客', clock: '定期処理', note: 'メモ',
};
// システム側のレーンに置きたい部品（ファイル・データ）
const SYSTEM_SIDE: NodeType[] = ['data', 'excel', 'csv'];

/** 部品を置く枠。選んでいる枠 → 選んでいる部品の枠 → 種類ごとの既定（データ類は最初のシステム、それ以外は最初の担当） */
export function targetLane(flow: Flow, sel: Sel, type: NodeType): { id: string; chosen: boolean } | null {
  const chosen = sel?.kind === 'lane' ? sel.id : sel?.kind === 'node' ? flow.nodes.find((n) => n.id === sel.id)?.lane : undefined;
  if (chosen) return { id: chosen, chosen: true };
  const pref = flow.lanes.find((l) => l.kind === (SYSTEM_SIDE.includes(type) ? 'system' : 'actor')) ?? flow.lanes[0];
  return pref ? { id: pref.id, chosen: false } : null;
}

export function addNode(type: NodeType) {
  const { flow, sel } = getState();
  if (!flow) return;
  const target = targetLane(flow, sel, type);
  if (!target) return flash('先に「① 枠を作る」で担当かシステムを追加してください');
  const laneId = target.id;
  const [, h] = nodeSize(type);
  const inLane = flow.nodes.filter((n) => n.lane === laneId);
  const right = inLane.reduce((m, n) => Math.max(m, n.x + nodeSize(n.type)[0]), HEAD_W);
  const id = uid();
  edit((d) => {
    const n = { id, type, lane: laneId, x: Math.round((right + 40) / 10) * 10, ly: Math.round((LANE_H - h) / 2), label: DEFAULT_LABEL[type] };
    clampNode(n);
    d.nodes.push(n);
  });
  setState({ sel: { kind: 'node', id } });
  focusSoon('#p-label', true);
}

export function connect(from: string, to: string, opts: { kind?: EdgeKind; color?: string; label?: string } = {}) {
  if (from === to) return; // 同じ部品同士は繋がない
  const id = uid();
  const kind = opts.kind ?? getState().edgeKind;
  edit((d) => {
    d.edges.push({ id, from, to, kind, label: opts.label ?? '', ...(opts.color ? { color: opts.color } : {}) });
  });
  setState({ connectSrc: null, sel: { kind: 'edge', id } });
  if (opts.label === undefined) focusSoon('#p-elabel'); // 確定直後にラベル欄へ
}

/** 文章でつなぐ。結果をひとことで返す */
export function runSentence(text: string): { ok: boolean; message: string } {
  const { flow, sel } = getState();
  if (!flow) return { ok: false, message: '図が開かれていません。' };
  const r = parseConnect(text, flow.nodes);
  if (!r.ok) return { ok: false, message: r.error };
  const { from, to, kind, color, label } = r.cmd;
  const style = [kind && (kind === 'data' ? '点線' : '実線'), color && colorName(color), label && `ラベル「${label}」`].filter(Boolean).join('・');

  if (from && to) {
    // すでに同じ向きの矢印があれば、新しく作らずにそれを変える
    const exist = flow.edges.find((e) => e.from === from.id && e.to === to.id);
    if (exist) {
      updateEdge(exist.id, { kind, color, label });
      setState({ sel: { kind: 'edge', id: exist.id } });
      return { ok: true, message: `「${from.label}」→「${to.label}」の線を変えました${style ? `（${style}）` : ''}。` };
    }
    connect(from.id, to.id, { kind, color, label: label ?? '' });
    return { ok: true, message: `「${from.label}」→「${to.label}」を繋ぎました${style ? `（${style}）` : ''}。` };
  }
  // 部品の名前がなければ、選んでいる矢印を変える
  if (sel?.kind === 'edge') {
    updateEdge(sel.id, { kind, color, label });
    return { ok: true, message: `選んでいる線を変えました（${style}）。` };
  }
  return { ok: false, message: 'つなぐ部品の名前を2つ書いてください。線の色だけ変えるときは、先に線をクリックして選んでください。' };
}

function updateEdge(id: string, p: { kind?: EdgeKind; color?: string; label?: string }) {
  edit((d) => {
    const e = d.edges.find((x) => x.id === id);
    if (!e) return;
    if (p.kind) e.kind = p.kind;
    if (p.color) e.color = p.color;
    if (p.label !== undefined) e.label = p.label;
  });
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
