import { useSyncExternalStore } from 'react';
import { produce, type Draft } from 'immer';
import type { Flow, FlowSummary, EdgeKind, Route } from '../model/types';

export type Sel = { kind: 'node' | 'edge' | 'lane'; id: string } | { kind: 'nodes'; ids: string[] } | null;

/** 選んでいる部品の id（1つでも複数でも） */
export const selectedNodeIds = (sel: Sel): string[] => (sel?.kind === 'node' ? [sel.id] : sel?.kind === 'nodes' ? sel.ids : []);
export type Tool = 'select' | 'connect';
export type View = { x: number; y: number; k: number };
export type SaveState = 'saved' | 'dirty' | 'saving' | 'error';

export type State = {
  flows: FlowSummary[];
  flow: Flow | null;
  sel: Sel;
  tool: Tool;
  connectSrc: string | null;
  edgeKind: EdgeKind;
  edgeRoute: Route; // これから引く線の形
  editing: string | null; // 図の上で名前を書いている部品
  view: View;
  saveState: SaveState;
  past: Flow[];
  future: Flow[];
  exporting: boolean; // 画像書き出し中は選択枠やポートを消す
  flash: string | null;
  confirmDel: string | null; // 2回押しで削除するときの1回目の対象（レーン id か 'flow'）
};

const HISTORY_MAX = 50;
const SAVE_DELAY = 500;

let state: State = {
  flows: [],
  flow: null,
  sel: null,
  tool: 'select',
  connectSrc: null,
  edgeKind: 'flow',
  edgeRoute: 'curve',
  editing: null,
  view: { x: 20, y: 20, k: 1 },
  saveState: 'saved',
  past: [],
  future: [],
  exporting: false,
  flash: null,
  confirmDel: null,
};

const listeners = new Set<() => void>();
export const getState = () => state;
export function setState(patch: Partial<State> | ((s: State) => Partial<State>)) {
  const p = typeof patch === 'function' ? patch(state) : patch;
  state = { ...state, ...p };
  listeners.forEach((l) => l());
}
const subscribe = (l: () => void) => {
  listeners.add(l);
  return () => listeners.delete(l);
};
export function useStore<T>(selector: (s: State) => T): T {
  return useSyncExternalStore(subscribe, () => selector(state));
}

/* ---------- API ---------- */
async function api<T>(method: string, url: string, body?: unknown): Promise<T> {
  const res = await fetch(url, {
    method,
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw new Error(`${method} ${url}: ${res.status}`);
  return res.status === 204 ? (undefined as T) : res.json();
}

/* ---------- 自動保存 ---------- */
let saveTimer: ReturnType<typeof setTimeout> | null = null;

function scheduleSave() {
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = setTimeout(() => void flushSave(), SAVE_DELAY);
}

/** 保存ボタン・Ctrl+S：変更がなくても今の内容をすぐ書き込む */
export async function saveNow() {
  if (!state.flow) return false;
  setState({ saveState: 'dirty' });
  await flushSave();
  return getState().saveState === 'saved';
}

export async function flushSave() {
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = null;
  const flow = state.flow;
  if (!flow || state.saveState === 'saved') return;
  setState({ saveState: 'saving' });
  try {
    const sum = await api<FlowSummary>('PUT', `/api/flows/${flow.id}`, flow);
    setState((s) => ({
      // 保存中にさらに編集されていたら dirty のまま
      saveState: s.flow === flow ? 'saved' : 'dirty',
      flows: s.flows.map((f) => (f.id === sum.id ? sum : f)),
    }));
  } catch {
    setState({ saveState: 'error' });
  }
}
// タブを閉じる直前に未保存分を送る（keepalive でページ終了後も送信される）
window.addEventListener('pagehide', () => {
  const flow = state.flow;
  if (!flow || state.saveState === 'saved') return;
  fetch(`/api/flows/${flow.id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(flow),
    keepalive: true,
  }).catch(() => {});
});

/* ---------- 編集と履歴 ---------- */
let lastCoalesce: { key: string; at: number } | null = null;

function pushHistory(prev: Flow) {
  setState((s) => ({ past: [...s.past.slice(-(HISTORY_MAX - 1)), prev], future: [] }));
}

function commit(next: Flow) {
  setState({ flow: next, saveState: 'dirty' });
  scheduleSave();
}

/**
 * 図を編集する。履歴に1件積む。
 * coalesce に同じキーを続けて渡すと（文字入力など）1件にまとめる。
 */
export function edit(recipe: (d: Draft<Flow>) => void, opts: { coalesce?: string } = {}) {
  const prev = state.flow;
  if (!prev) return;
  const next = produce(prev, recipe);
  if (next === prev) return;
  const now = Date.now();
  const merge = opts.coalesce && lastCoalesce && lastCoalesce.key === opts.coalesce && now - lastCoalesce.at < 1500;
  if (!merge) pushHistory(prev);
  lastCoalesce = opts.coalesce ? { key: opts.coalesce, at: now } : null;
  commit(next);
}

/** ドラッグ開始時に1回だけ履歴を積み、その後は editSilent で動かす */
export function beginGesture() {
  if (state.flow) pushHistory(state.flow);
  lastCoalesce = null;
}
export function editSilent(recipe: (d: Draft<Flow>) => void) {
  const prev = state.flow;
  if (!prev) return;
  const next = produce(prev, recipe);
  if (next !== prev) commit(next);
}
function validSel(flow: Flow, sel: Sel): Sel {
  if (!sel) return null;
  if (sel.kind === 'nodes') {
    const ids = sel.ids.filter((id) => flow.nodes.some((n) => n.id === id));
    return ids.length > 1 ? { kind: 'nodes', ids } : ids.length === 1 ? { kind: 'node', id: ids[0] } : null;
  }
  const list = sel.kind === 'node' ? flow.nodes : sel.kind === 'edge' ? flow.edges : flow.lanes;
  return list.some((x) => x.id === sel.id) ? sel : null;
}

export function undo() {
  const { past, flow, future, sel } = state;
  if (!past.length || !flow) return;
  const prev = past[past.length - 1];
  lastCoalesce = null;
  setState({ past: past.slice(0, -1), future: [flow, ...future], sel: validSel(prev, sel), connectSrc: null });
  commit(prev);
}
export function redo() {
  const { past, flow, future, sel } = state;
  if (!future.length || !flow) return;
  const next = future[0];
  lastCoalesce = null;
  setState({ past: [...past, flow], future: future.slice(1), sel: validSel(next, sel), connectSrc: null });
  commit(next);
}

/* ---------- 図の切替・新規・複製・削除 ---------- */
const LAST_KEY = 'flowcanvas.lastId';

export async function loadFlows() {
  const flows = await api<FlowSummary[]>('GET', '/api/flows');
  setState({ flows });
  let last: string | null = null;
  try {
    last = localStorage.getItem(LAST_KEY);
  } catch {
    /* 使えない環境では無視 */
  }
  const id = flows.find((f) => f.id === last)?.id ?? flows[0]?.id;
  if (id) await openFlow(id);
  else await createFlow();
}

export async function openFlow(id: string) {
  await flushSave();
  const flow = await api<Flow>('GET', `/api/flows/${id}`);
  lastCoalesce = null;
  setState({ flow, sel: null, connectSrc: null, past: [], future: [], saveState: 'saved' });
  try {
    localStorage.setItem(LAST_KEY, id);
  } catch {
    /* noop */
  }
}

async function addAndOpen(body?: Partial<Flow>) {
  await flushSave();
  const flow = await api<Flow>('POST', '/api/flows', body);
  setState((s) => ({ flows: [{ id: flow.id, title: flow.title, updatedAt: flow.updatedAt }, ...s.flows] }));
  await openFlow(flow.id);
}

export const createFlow = () => addAndOpen({ title: '新しい図', lanes: [], nodes: [], edges: [] });
export const createSampleFlow = () => addAndOpen();
export function duplicateFlow() {
  const f = state.flow;
  if (f) return addAndOpen({ ...f, title: f.title + ' のコピー' });
}

export async function deleteFlow() {
  const f = state.flow;
  if (!f) return;
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = null;
  await api('DELETE', `/api/flows/${f.id}`);
  const rest = state.flows.filter((x) => x.id !== f.id);
  setState({ flows: rest, flow: null, saveState: 'saved' });
  if (rest.length) await openFlow(rest[0].id);
  else await createFlow();
}

/** モックの「書き出し → JSON」を今の図に読み込む（id は今の図のまま） */
export function importFlow(data: Pick<Flow, 'title' | 'lanes' | 'nodes' | 'edges'>) {
  edit((d) => {
    d.title = data.title ?? d.title;
    d.lanes = data.lanes;
    d.nodes = data.nodes;
    d.edges = data.edges ?? [];
  });
  setState({ sel: null, connectSrc: null });
}

export function flash(msg: string) {
  setState({ flash: msg });
  setTimeout(() => setState((s) => (s.flash === msg ? { flash: null } : {})), 1800);
}
