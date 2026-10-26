import { uid, type Flow, type Lane, type Node, type Edge, type NodeType, type EdgeKind } from './types';

// モック（flowcanvas_mock.html）のサンプル「受注〜請求」を移植
export function sampleFlow(id = uid()): Flow {
  const A: Lane[] = ['営業', '営業事務', '経理'].map((name) => ({ id: uid(), name, kind: 'actor' }));
  const S: Lane[] = ['Salesforce', '会計システム'].map((name) => ({ id: uid(), name, kind: 'system' }));
  const [a1, a2, a3] = A;
  const [s1, s2] = S;
  const N = (type: NodeType, lane: Lane, x: number, ly: number, label: string): Node => ({ id: uid(), type, lane: lane.id, x, ly, label });
  const n = {
    st: N('start', a1, 160, 51, '開始'),
    quote: N('task', a1, 260, 47, '見積を作成する'),
    send: N('task', a1, 460, 47, '見積を顧客に送付'),
    chk: N('decision', a2, 420, 38, '金額100万円以上？'),
    appr: N('task', a2, 620, 47, '部長承認を取る'),
    order: N('task', a2, 820, 47, '受注を登録する'),
    inv: N('task', a3, 1040, 47, '請求書を発行する'),
    en: N('end', a3, 1240, 51, '終了'),
    opp: N('data', s1, 270, 49, '商談'),
    ord: N('data', s1, 830, 49, '受注'),
    jnl: N('data', s2, 1050, 49, '売上仕訳'),
  };
  type K = keyof typeof n;
  const E = (f: K, t: K, kind: EdgeKind, label = ''): Edge => ({ id: uid(), from: n[f].id, to: n[t].id, kind, label });
  return {
    id,
    title: '受注〜請求 業務フロー',
    lanes: [...A, ...S],
    nodes: Object.values(n),
    edges: [
      E('st', 'quote', 'flow'), E('quote', 'send', 'flow'), E('send', 'chk', 'flow'),
      E('chk', 'appr', 'flow', 'Yes'), E('chk', 'order', 'flow', 'No'), E('appr', 'order', 'flow'),
      E('order', 'inv', 'flow'), E('inv', 'en', 'flow'),
      E('quote', 'opp', 'data', '見積金額を登録'), E('order', 'ord', 'data', '受注レコード作成'),
      E('ord', 'jnl', 'data', '日次連携'), E('jnl', 'inv', 'data', '請求データ参照'),
    ],
    updatedAt: new Date().toISOString(),
  };
}
