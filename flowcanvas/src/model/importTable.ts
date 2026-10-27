import { HEAD_W, LANE_H, nodeSize, uid, type Edge, type Lane, type LaneKind, type Node, type NodeType } from './types';

/*
 * 「誰が・誰（何）に対して・何をする」の表を、業務フローの図にする。
 * Excel／CSV の読み込みはコマンド側（scripts/flow-import.ts）で行い、ここは表の行だけを扱う。
 *
 * 業務の流れ（1行＝1つの作業）
 *   No / 誰が / 何をする / 形 / 誰・何に対して / 受け渡すもの / 向き / 次のNo / 条件 / メモ
 * 枠（なくてもよい）
 *   名前 / 区分（担当者・システム） / 説明
 */

export type StepRow = {
  row: number; // 元の表の行番号（エラーの案内用）
  no: string;
  who: string;
  what: string;
  shape?: string;
  target?: string;
  thing?: string;
  direction?: string;
  next?: string;
  cond?: string;
  memo?: string;
};
export type LaneRow = { row: number; name: string; kind?: string; desc?: string };

export type ImportResult =
  | { ok: true; lanes: Lane[]; nodes: Node[]; edges: Edge[]; warnings: string[] }
  | { ok: false; errors: string[] };

export const STEP_COLUMNS = ['No', '誰が', '何をする', '形', '誰・何に対して', '受け渡すもの', '向き', '次のNo', '条件', 'メモ'] as const;
export const LANE_COLUMNS = ['名前', '区分', '説明'] as const;

export const SHAPES: Record<string, NodeType> = {
  作業: 'task', 判断: 'decision', 分岐: 'decision', 開始: 'start', 終了: 'end',
  データ: 'data', Excel: 'excel', エクセル: 'excel', CSV: 'csv', PC: 'pc', パソコン: 'pc',
  メール: 'mail', 書類: 'doc', 帳票: 'doc', 人: 'person', 時計: 'clock', 付せん: 'note', メモ: 'note',
};

// 枠のシートに無い相手は、名前からシステムかどうかを見当を付ける（警告で知らせる）
const SYSTEM_HINT = /システム|system|sfa|crm|erp|db|データベース|サーバ|salesforce|kintone|sap|基幹|クラウド|api/i;

const COL_GAP = 190; // 作業と作業の横の間隔
const STACK_GAP = 12; // 同じ枠・同じ列に重なるときの縦の間隔

const split = (s?: string) =>
  (s ?? '')
    .split(/[,、，\s]+/)
    .map((x) => x.trim())
    .filter(Boolean);

export function tableToFlow(steps: StepRow[], laneRows: LaneRow[] = []): ImportResult {
  const errors: string[] = [];
  const warnings: string[] = [];

  /* ---------- 行の確認 ---------- */
  const rows = steps.filter((r) => r.no || r.who || r.what);
  if (!rows.length) return { ok: false, errors: ['「業務の流れ」に作業が1行もありません。'] };
  const byNo = new Map<string, StepRow>();
  for (const r of rows) {
    if (!r.no) errors.push(`${r.row}行目：No が空です。`);
    else if (byNo.has(r.no)) errors.push(`${r.row}行目：No「${r.no}」が ${byNo.get(r.no)!.row}行目と重なっています。`);
    else byNo.set(r.no, r);
    if (!r.who) errors.push(`${r.row}行目：「誰が」が空です。`);
    if (!r.what) errors.push(`${r.row}行目：「何をする」が空です。`);
  }
  const nextOf = new Map<string, { no: string; cond?: string }[]>();
  rows.forEach((r, i) => {
    const conds = split(r.cond);
    let nos = split(r.next);
    if (nos.length === 1 && nos[0] === 'なし') nos = [];
    else if (!nos.length && r.shape !== '終了' && i + 1 < rows.length) nos = [rows[i + 1].no]; // 空なら次の行へ
    for (const n of nos) if (!byNo.has(n)) errors.push(`${r.row}行目：次のNo「${n}」が見つかりません。`);
    if (conds.length && conds.length !== nos.length) warnings.push(`${r.row}行目：「次のNo」と「条件」の数が合いません（条件は前から順に付けました）。`);
    nextOf.set(r.no, nos.map((no, k) => ({ no, cond: conds[k] })));
  });
  if (errors.length) return { ok: false, errors };

  /* ---------- 枠 ---------- */
  const lanes: Lane[] = [];
  const laneByName = new Map<string, Lane>();
  const addLane = (name: string, kind: LaneKind, desc?: string) => {
    const l: Lane = { id: uid(), name, kind, ...(desc ? { desc } : {}) };
    lanes.push(l);
    laneByName.set(name, l);
    return l;
  };
  for (const lr of laneRows) {
    if (!lr.name || laneByName.has(lr.name)) continue;
    const kind: LaneKind = lr.kind?.includes('システム') ? 'system' : 'actor';
    addLane(lr.name, kind, lr.desc);
  }
  for (const r of rows) if (!laneByName.has(r.who)) addLane(r.who, 'actor');
  const guessed: string[] = [];
  for (const r of rows) {
    if (!r.target || laneByName.has(r.target)) continue;
    const kind: LaneKind = SYSTEM_HINT.test(r.target) ? 'system' : 'actor';
    addLane(r.target, kind);
    guessed.push(`${r.target}（${kind === 'system' ? 'システム' : '担当者'}）`);
  }
  if (guessed.length) warnings.push(`枠の一覧（Excel の「枠」シート、または --lanes の CSV）に無い相手を、名前から判断して枠にしました：${guessed.join('、')}。違うときは枠の一覧に書いてください。`);
  // 担当者を上、システムを下に（同じ区分の中は出てきた順）
  lanes.sort((a, b) => Number(a.kind === 'system') - Number(b.kind === 'system'));

  /* ---------- 部品と線 ---------- */
  const nodes: Node[] = [];
  const edges: Edge[] = [];
  const nodeOfNo = new Map<string, Node>();
  const shapeOf = (r: StepRow): NodeType => {
    if (!r.shape) return 'task';
    const t = SHAPES[r.shape];
    if (!t) warnings.push(`${r.row}行目：形「${r.shape}」は分からないので「作業」にしました。`);
    return t ?? 'task';
  };
  for (const r of rows) {
    const n: Node = { id: uid(), type: shapeOf(r), lane: laneByName.get(r.who)!.id, x: 0, ly: 0, label: r.what, ...(r.memo ? { note: r.memo } : {}) };
    nodes.push(n);
    nodeOfNo.set(r.no, n);
  }

  // 業務の流れ（次のNo）
  const order = new Map(rows.map((r, i) => [r.no, i]));
  for (const r of rows) {
    const from = nodeOfNo.get(r.no)!;
    const target = r.target ? laneByName.get(r.target) : undefined;
    for (const nx of nextOf.get(r.no)!) {
      const to = nodeOfNo.get(nx.no)!;
      // 条件がなく、相手が担当者で次の作業もその人なら、受け渡すものを線のラベルにする
      const handoff = !nx.cond && target?.kind === 'actor' && to.lane === target.id ? r.thing : undefined;
      // 表で前に戻る線（差し戻しなど）は、直角で上を回して行きの線と重ならないようにする
      const back = order.get(nx.no)! <= order.get(r.no)!;
      edges.push({
        id: uid(), from: from.id, to: to.id, kind: 'flow', label: nx.cond ?? handoff ?? '',
        ...(back ? { route: 'ortho' as const, fromSide: 'top' as const, toSide: 'top' as const } : {}),
      });
    }
  }

  // データの流れ（相手がシステムなら、受け渡すものをデータ部品にして点線でつなぐ）
  const dataNodes = new Map<string, Node>();
  const dataCol = new Map<string, string>(); // データ部品 → 最初に使った作業の No（横の位置合わせ用）
  for (const r of rows) {
    const target = r.target ? laneByName.get(r.target) : undefined;
    if (!target || target.kind !== 'system') continue;
    const name = r.thing || `${r.target}のデータ`;
    const key = `${target.id}\u0000${name}`;
    let dn = dataNodes.get(key);
    if (!dn) {
      dn = { id: uid(), type: 'data', lane: target.id, x: 0, ly: 0, label: name };
      dataNodes.set(key, dn);
      dataCol.set(dn.id, r.no);
      nodes.push(dn);
    }
    const task = nodeOfNo.get(r.no)!;
    const receive = /受け取|参照|取得|読/.test(r.direction ?? '');
    edges.push({ id: uid(), from: receive ? dn.id : task.id, to: receive ? task.id : dn.id, kind: 'data', label: '' });
  }

  // 開始・終了が書かれていなければ足す
  const hasStart = rows.some((r) => SHAPES[r.shape ?? ''] === 'start');
  const hasEnd = rows.some((r) => SHAPES[r.shape ?? ''] === 'end');
  let startNode: Node | undefined;
  if (!hasStart) {
    const first = nodeOfNo.get(rows[0].no)!;
    startNode = { id: uid(), type: 'start', lane: first.lane, x: 0, ly: 0, label: '開始' };
    nodes.push(startNode);
    edges.push({ id: uid(), from: startNode.id, to: first.id, kind: 'flow', label: '' });
  }
  const terminals = rows.filter((r) => !nextOf.get(r.no)!.length && SHAPES[r.shape ?? ''] !== 'end');
  const endNodes = new Map<string, Node>(); // 枠ごとに1つ
  if (!hasEnd) {
    for (const r of terminals) {
      const t = nodeOfNo.get(r.no)!;
      let en = endNodes.get(t.lane);
      if (!en) {
        en = { id: uid(), type: 'end', lane: t.lane, x: 0, ly: 0, label: '終了' };
        endNodes.set(t.lane, en);
        nodes.push(en);
      }
      edges.push({ id: uid(), from: t.id, to: en.id, kind: 'flow', label: '' });
    }
  }

  /* ---------- 並べ方 ---------- */
  // 列：表の順に、前の作業より1つ右。表で前に戻る線（差し戻しなど）は並べ方には使わない
  const col = new Map<string, number>();
  const base = startNode ? 1 : 0;
  rows.forEach((r) => col.set(r.no, col.get(r.no) ?? base));
  for (const r of rows) {
    for (const nx of nextOf.get(r.no)!) {
      if (order.get(nx.no)! <= order.get(r.no)!) continue;
      col.set(nx.no, Math.max(col.get(nx.no)!, col.get(r.no)! + 1));
    }
  }
  const colOfNode = new Map<string, number>();
  for (const r of rows) colOfNode.set(nodeOfNo.get(r.no)!.id, col.get(r.no)!);
  if (startNode) colOfNode.set(startNode.id, 0);
  for (const [id, no] of dataCol) colOfNode.set(id, col.get(no)!);
  const lastCol = Math.max(...rows.map((r) => col.get(r.no)!));
  for (const en of endNodes.values()) colOfNode.set(en.id, lastCol + 1);

  // 同じ枠・同じ列に複数あるときは縦に積み、必要なら枠を高くする
  const cells = new Map<string, Node[]>();
  for (const n of nodes) {
    const k = `${n.lane}\u0000${colOfNode.get(n.id)}`;
    cells.set(k, [...(cells.get(k) ?? []), n]);
  }
  const laneNeed = new Map<string, number>();
  for (const ns of cells.values()) {
    const need = ns.reduce((t, n) => t + nodeSize(n.type, n.label)[1], 0) + STACK_GAP * (ns.length - 1) + 16;
    laneNeed.set(ns[0].lane, Math.max(laneNeed.get(ns[0].lane) ?? 0, need));
  }
  for (const l of lanes) {
    const need = laneNeed.get(l.id) ?? 0;
    if (need > LANE_H) l.h = Math.ceil(need / 10) * 10;
  }
  // 列ごとの左端：その列で一番広い部品に合わせて間を空ける
  const colW = new Map<number, number>();
  for (const n of nodes) {
    const c = colOfNode.get(n.id)!;
    colW.set(c, Math.max(colW.get(c) ?? 150, nodeSize(n.type, n.label)[0]));
  }
  const colX = new Map<number, number>();
  let cx = HEAD_W + 40;
  for (const c of [...colW.keys()].sort((a2, b2) => a2 - b2)) {
    colX.set(c, cx);
    cx += colW.get(c)! + COL_GAP - 150;
  }
  for (const ns of cells.values()) {
    const lane = lanes.find((l) => l.id === ns[0].lane)!;
    const lh = lane.h ?? LANE_H;
    const total = ns.reduce((t, n) => t + nodeSize(n.type, n.label)[1], 0) + STACK_GAP * (ns.length - 1);
    let y = Math.round((lh - total) / 2);
    for (const n of ns) {
      const [w, h] = nodeSize(n.type, n.label);
      // 列の中心にそろえる
      const c = colOfNode.get(n.id)!;
      n.x = colX.get(c)! + Math.round((colW.get(c)! - w) / 2);
      n.ly = y;
      y += h + STACK_GAP;
    }
  }

  return { ok: true, lanes, nodes, edges, warnings };
}
