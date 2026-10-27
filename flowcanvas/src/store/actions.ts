import { clampLaneH, clampNode, laneHeight } from '../model/geometry';
import { colorName } from '../model/colors';
import { parseConnect } from '../model/nlConnect';
import { HEAD_W, LANE_H_STEP, nodeSize, uid, type Edge, type EdgeKind, type Flow, type LaneKind, type Node, type NodeType, type Route, type Side } from '../model/types';
import type { Draft } from 'immer';
import { edit, editSilent, flash, getState, selectedNodeIds, setState, type Sel } from './flowStore';

const sortLanes = (d: Draft<Flow>) => {
  // 担当者は上段、システムは下段（同じ区分の中の順番は保つ）
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

/** 枠の高さを変える。dir=+1 で広く、-1 で狭く（50px ずつ） */
export function resizeLane(id: string, dir: 1 | -1) {
  const lane = getState().flow?.lanes.find((l) => l.id === id);
  if (lane) setLaneHeight(id, laneHeight(lane) + dir * LANE_H_STEP);
}

export function setLaneHeight(id: string, h: number, opts: { silent?: boolean } = {}) {
  const apply = (d: Draft<Flow>) => {
    const l = d.lanes.find((x) => x.id === id);
    if (!l) return;
    l.h = clampLaneH(h);
    // 狭くしたときに部品がはみ出さないよう枠の中へ戻す
    for (const n of d.nodes) if (n.lane === id) clampNode(n, d.lanes);
  };
  if (opts.silent) editSilent(apply);
  else edit(apply, { coalesce: `laneh:${id}` });
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
  setState((s) => ({ confirmDel: null, sel: s.sel?.kind === 'lane' && s.sel.id === id ? null : s.sel }));
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

/** 部品を置く。at を渡すとその場所に（パレットからドラッグしたとき）、なければ枠の右端に */
export function addNode(type: NodeType, at?: { lane: string; x: number; ly: number }) {
  const { flow, sel } = getState();
  if (!flow) return;
  const target = at ? { id: at.lane } : targetLane(flow, sel, type);
  if (!target) return flash('先に「① 枠を作る」で担当かシステムを追加してください');
  const laneId = target.id;
  const [, h] = nodeSize(type);
  const lh = laneHeight(flow.lanes.find((l) => l.id === laneId)!);
  const inLane = flow.nodes.filter((n) => n.lane === laneId);
  const right = inLane.reduce((m, n) => Math.max(m, n.x + nodeSize(n.type, n.label)[0]), HEAD_W);
  const id = uid();
  edit((d) => {
    const n = at
      ? { id, type, lane: laneId, x: at.x, ly: at.ly, label: DEFAULT_LABEL[type] }
      : { id, type, lane: laneId, x: Math.round((right + 40) / 10) * 10, ly: Math.round((lh - h) / 2), label: DEFAULT_LABEL[type] };
    clampNode(n, d.lanes);
    d.nodes.push(n);
  });
  setState({ sel: { kind: 'node', id } });
  revealNode(id); // 画面の外に置かれたら、そこまで動かす
  focusSoon('#p-label', true);
}

export function connect(from: string, to: string, opts: { kind?: EdgeKind; color?: string; label?: string; route?: Route; fromSide?: Side } = {}) {
  if (from === to) return; // 同じ部品同士は繋がない
  const id = uid();
  const kind = opts.kind ?? getState().edgeKind;
  const route = opts.route ?? getState().edgeRoute;
  edit((d) => {
    d.edges.push({
      id, from, to, kind, label: opts.label ?? '',
      ...(opts.color ? { color: opts.color } : {}),
      ...(route === 'ortho' ? { route } : {}),
      ...(opts.fromSide ? { fromSide: opts.fromSide } : {}),
    });
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
  const { from, to, kind, color, label, route } = r.cmd;
  const style = [
    kind && (kind === 'data' ? '点線' : '実線'),
    route && (route === 'ortho' ? '直角' : '曲線'),
    color && colorName(color),
    label && `ラベル「${label}」`,
  ].filter(Boolean).join('・');

  if (from && to) {
    // すでに同じ向きの矢印があれば、新しく作らずにそれを変える
    const exist = flow.edges.find((e) => e.from === from.id && e.to === to.id);
    if (exist) {
      updateEdge(exist.id, { kind, color, label, route });
      setState({ sel: { kind: 'edge', id: exist.id } });
      return { ok: true, message: `「${from.label}」→「${to.label}」の線を変えました${style ? `（${style}）` : ''}。` };
    }
    connect(from.id, to.id, { kind, color, label: label ?? '', route });
    return { ok: true, message: `「${from.label}」→「${to.label}」を繋ぎました${style ? `（${style}）` : ''}。` };
  }
  // 部品の名前がなければ、選んでいる矢印を変える
  if (sel?.kind === 'edge') {
    updateEdge(sel.id, { kind, color, label, route });
    return { ok: true, message: `選んでいる線を変えました（${style}）。` };
  }
  return { ok: false, message: 'つなぐ部品の名前を2つ書いてください。線の色だけ変えるときは、先に線をクリックして選んでください。' };
}

function updateEdge(id: string, p: { kind?: EdgeKind; color?: string; label?: string; route?: Route }) {
  edit((d) => {
    const e = d.edges.find((x) => x.id === id);
    if (!e) return;
    if (p.kind) e.kind = p.kind;
    if (p.route === 'ortho') e.route = 'ortho';
    if (p.route === 'curve') delete e.route;
    if (p.color) e.color = p.color;
    if (p.label !== undefined) e.label = p.label;
  });
}

export function deleteSel() {
  const { sel } = getState();
  if (!sel) return;
  if (sel.kind === 'lane') return deleteLane(sel.id);
  if (sel.kind === 'edge') {
    edit((d) => {
      d.edges = d.edges.filter((e) => e.id !== sel.id);
    });
  } else {
    const gone = new Set(selectedNodeIds(sel));
    edit((d) => {
      d.nodes = d.nodes.filter((n) => !gone.has(n.id));
      d.edges = d.edges.filter((e) => !gone.has(e.from) && !gone.has(e.to));
    });
  }
  setState({ sel: null });
}

/** 部品を選ぶ。add=true（Shift／Ctrl+クリック）なら今の選択に足す・外す */
export function selectNodes(ids: string[], add = false) {
  const cur = add ? selectedNodeIds(getState().sel) : [];
  let next = [...cur];
  for (const id of ids) next = next.includes(id) && add ? next.filter((x) => x !== id) : next.includes(id) ? next : [...next, id];
  setState({ sel: next.length > 1 ? { kind: 'nodes', ids: next } : next.length === 1 ? { kind: 'node', id: next[0] } : null });
}

export function selectAll() {
  const f = getState().flow;
  if (f) selectNodes(f.nodes.map((n) => n.id));
}

/* ---------- コピー・貼り付け ---------- */
// 図を切り替えても残るので、別の図へも貼り付けられる
let clip: { nodes: Node[]; edges: Edge[]; laneNames: Record<string, string> } | null = null;
let pasteCount = 0;

export function copySel() {
  const { flow, sel } = getState();
  const ids = new Set(selectedNodeIds(sel));
  if (!flow || !ids.size) return false;
  clip = {
    nodes: flow.nodes.filter((n) => ids.has(n.id)),
    // 選んだ部品どうしをつなぐ線だけ一緒にコピーする
    edges: flow.edges.filter((e) => ids.has(e.from) && ids.has(e.to)),
    laneNames: Object.fromEntries(flow.lanes.map((l) => [l.id, l.name])),
  };
  pasteCount = 0;
  flash(`${ids.size}個の部品をコピーしました`);
  return true;
}

export function paste() {
  const { flow } = getState();
  if (!flow || !clip) return;
  if (!flow.lanes.length) return flash('先に「① 枠を作る」で枠を追加してください');
  pasteCount++;
  const src = clip;
  // 枠は同じ id → 同じ名前 → 最初の枠 の順で探す
  const laneFor = (laneId: string) =>
    flow.lanes.find((l) => l.id === laneId)?.id ?? flow.lanes.find((l) => l.name === src.laneNames[laneId])?.id ?? flow.lanes[0].id;
  const idMap = new Map(src.nodes.map((n) => [n.id, uid()]));
  const nodes: Node[] = src.nodes.map((n) => ({ ...n, id: idMap.get(n.id)!, lane: laneFor(n.lane), x: n.x + 40 * pasteCount }));
  const edges: Edge[] = src.edges.map((e) => ({ ...e, id: uid(), from: idMap.get(e.from)!, to: idMap.get(e.to)! }));
  edit((d) => {
    d.nodes.push(...nodes);
    d.edges.push(...edges);
  });
  selectNodes(nodes.map((n) => n.id));
}

/* ---------- 整列 ---------- */
export type AlignKind = 'left' | 'center' | 'right' | 'middle' | 'hspace';

export function alignNodes(kind: AlignKind) {
  const { flow, sel } = getState();
  const ids = new Set(selectedNodeIds(sel));
  if (!flow || ids.size < 2) return;
  const snap = (v: number) => Math.round(v / 10) * 10;
  const ns = flow.nodes.filter((n) => ids.has(n.id));
  const w = (n: Node) => nodeSize(n.type, n.label)[0];
  const left = Math.min(...ns.map((n) => n.x));
  const right = Math.max(...ns.map((n) => n.x + w(n)));
  const center = ns.reduce((t, n) => t + n.x + w(n) / 2, 0) / ns.length;
  // 横に等間隔：左右の端の部品はそのまま、間を同じ間隔に
  const sorted = [...ns].sort((a, b) => a.x + w(a) / 2 - (b.x + w(b) / 2));
  const c0 = sorted[0].x + w(sorted[0]) / 2, c1 = sorted[sorted.length - 1].x + w(sorted[sorted.length - 1]) / 2;
  const step = (c1 - c0) / (sorted.length - 1);
  const order = new Map(sorted.map((n, i) => [n.id, i]));

  edit((d) => {
    for (const n of d.nodes) {
      if (!ids.has(n.id)) continue;
      const nw = w(n), nh = nodeSize(n.type, n.label)[1];
      if (kind === 'left') n.x = left;
      if (kind === 'right') n.x = right - nw;
      if (kind === 'center') n.x = snap(center - nw / 2);
      if (kind === 'middle') n.ly = snap((laneHeight(d.lanes.find((l) => l.id === n.lane) ?? {}) - nh) / 2); // それぞれの枠の中で上下の真ん中
      if (kind === 'hspace') n.x = snap(c0 + step * order.get(n.id)! - nw / 2);
      clampNode(n, d.lanes);
    }
  });
  // 同じ枠の部品を左右でそろえると重なってしまうので、気付けるように知らせる
  const after = getState().flow!.nodes.filter((n) => ids.has(n.id));
  const overlap = after.some((a, i) =>
    after.slice(i + 1).some((b) => {
      if (a.lane !== b.lane) return false;
      const [aw, ah] = nodeSize(a.type, a.label), [bw, bh] = nodeSize(b.type, b.label);
      return a.x < b.x + bw && b.x < a.x + aw && a.ly < b.ly + bh && b.ly < a.ly + ah;
    }),
  );
  if (overlap) flash('同じ枠の部品が重なりました。Ctrl+Z で元に戻せます');
}

/* ---------- 図の上で名前を書く ---------- */
export function startEdit(id: string) {
  setState({ sel: { kind: 'node', id }, editing: id, connectSrc: null });
}
export function commitEdit(id: string, label: string) {
  setState({ editing: null });
  edit((d) => {
    const n = d.nodes.find((x) => x.id === id);
    if (n && n.label !== label) n.label = label;
  });
}

/** 部品が画面に入っていなければ、入るように画面を動かす（Canvas が中身を差し込む） */
export const revealHook: { fn: (id: string) => void } = { fn: () => {} };
const revealNode = (id: string) => requestAnimationFrame(() => revealHook.fn(id));

export function focusSoon(selector: string, select = false) {
  requestAnimationFrame(() => {
    const el = document.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector);
    if (!el) return;
    el.focus();
    if (select) el.select();
  });
}
