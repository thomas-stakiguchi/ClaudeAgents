import { sampleFlow } from '../model/sample';
import { uid, type Flow, type FlowSummary } from '../model/types';

/*
 * ファイル版（FlowCanvas.html をダブルクリックで開く使い方）の保存先。
 * サーバーの /api/flows と同じ受け答えを、ブラウザの中（localStorage）で行う。
 */

export const STANDALONE = import.meta.env.MODE === 'standalone';

const PREFIX = 'flowcanvas.flow.';

function read(id: string): Flow | null {
  try {
    return JSON.parse(localStorage.getItem(PREFIX + id) ?? 'null') as Flow | null;
  } catch {
    return null;
  }
}

function write(flow: Flow) {
  localStorage.setItem(PREFIX + flow.id, JSON.stringify(flow)); // いっぱいのときは例外 → 「保存できませんでした」
}

function list(): FlowSummary[] {
  const out: FlowSummary[] = [];
  for (let i = 0; i < localStorage.length; i++) {
    const k = localStorage.key(i);
    if (!k?.startsWith(PREFIX)) continue;
    const f = read(k.slice(PREFIX.length));
    if (f) out.push({ id: f.id, title: f.title, updatedAt: f.updatedAt });
  }
  if (!out.length) {
    // 初めて開いたときはサンプルの図を1つ置く（サーバー版と同じ）
    const s = sampleFlow();
    write(s);
    out.push({ id: s.id, title: s.title, updatedAt: s.updatedAt });
  }
  return out.sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
}

const isFlowBody = (b: unknown): b is Flow => {
  const o = b as Flow;
  return !!o && typeof o === 'object' && Array.isArray(o.lanes) && Array.isArray(o.nodes) && Array.isArray(o.edges);
};

/** サーバー版の api() と同じ形で呼べる */
export function localApi<T>(method: string, url: string, body?: unknown): T {
  const id = url.match(/^\/api\/flows\/([^/]+)$/)?.[1];
  if (method === 'GET' && !id) return list() as T;
  if (method === 'GET' && id) {
    const f = read(id);
    if (!f) throw new Error(`GET ${url}: 404`);
    return f as T;
  }
  if (method === 'PUT' && id) {
    if (!isFlowBody(body)) throw new Error(`PUT ${url}: 400`);
    const f: Flow = { ...body, title: body.title ?? '', id, updatedAt: new Date().toISOString() };
    write(f);
    return { id: f.id, title: f.title, updatedAt: f.updatedAt } as T;
  }
  if (method === 'POST' && !id) {
    const nid = uid();
    const b = isFlowBody(body) ? body : sampleFlow(nid);
    const f: Flow = { ...b, title: b.title ?? '無題', id: nid, updatedAt: new Date().toISOString() };
    write(f);
    return f as T;
  }
  if (method === 'DELETE' && id) {
    localStorage.removeItem(PREFIX + id);
    return undefined as T;
  }
  throw new Error(`${method} ${url}: 400`);
}
