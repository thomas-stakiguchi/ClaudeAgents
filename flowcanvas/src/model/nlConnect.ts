import { COLOR_WORDS } from './colors';
import type { EdgeKind, Node, NodeType, Route } from './types';

/*
 * 「作業とデータを点線で繋ぐ。線は赤。」のような文から、つなぐ部品・線の種類・色・ラベルを読み取る。
 * AI ではなく決まった言葉を探す方式なので、オフラインでも同じ結果になる。
 */

export type NlCommand = {
  from?: Node;
  to?: Node;
  kind?: EdgeKind;
  route?: Route;
  color?: string;
  label?: string;
};
export type NlResult = { ok: true; cmd: NlCommand } | { ok: false; error: string };

const TYPE_WORDS: [string, NodeType][] = [
  ['タスク', 'task'], ['作業', 'task'],
  ['データ', 'data'],
  ['判断', 'decision'], ['分岐', 'decision'],
  ['開始', 'start'], ['スタート', 'start'],
  ['終了', 'end'], ['ゴール', 'end'],
  ['Excel', 'excel'], ['EXCEL', 'excel'], ['excel', 'excel'], ['エクセル', 'excel'],
  ['CSV', 'csv'], ['csv', 'csv'],
  ['パソコン', 'pc'], ['PC', 'pc'], ['pc', 'pc'],
  ['メール', 'mail'],
  ['書類', 'doc'], ['帳票', 'doc'],
  ['顧客', 'person'], ['取引先', 'person'],
  ['時計', 'clock'], ['タイマー', 'clock'], ['定期処理', 'clock'],
  ['付せん', 'note'], ['付箋', 'note'], ['メモ', 'note'],
];

const LABEL_RES = [
  /ラベル(?:は|を|に|が|:)?\s*[「『"]([^」』"]+)[」』"](?:と(?:書く|書いて|入れる|入れて|する|して)|に(?:する|して))?/,
  /ラベル(?:は|を|に|が|:)\s*([^「『"。、,\n]+?)(?:と(?:書く|書いて|書き|入れる|入れて|入力|表示|する|して)|に(?:する|して)|で(?=[。、,\s]|$)|(?=[。、,\n])|$)/,
  /[「『]([^」』]+)[」』]/,
];

const DATA_WORDS = ['点線', '破線', 'データの流れ', '点々'];
const FLOW_WORDS = ['実線', '業務の流れ', '普通の線'];

export function parseConnect(text: string, nodes: Node[]): NlResult {
  let rest = text.normalize('NFKC');
  const cmd: NlCommand = {};
  // 読み取った部分は同じ長さの空白に置き換える（位置がずれないように）
  const blank = (s: string) => ' '.repeat(s.length);

  // ラベル：「ラベルは〇〇」「ラベルに〇〇と書く」「ラベルを〇〇にする」または 「〇〇」
  const lm = LABEL_RES.map((re) => rest.match(re)).find((m) => m && m[1].trim());
  if (lm) {
    cmd.label = lm[1].trim();
    rest = rest.replace(lm[0], blank(lm[0]));
  }

  // 部品の名前を先に探す（名前に「赤」などが入っていても色と間違えないように）
  const byName = findByName(rest, nodes);
  if (!byName.ok) return byName;
  const hits = byName.hits;
  for (const h of hits) rest = rest.slice(0, h.pos) + blank(h.word) + rest.slice(h.end);

  // 線の種類
  for (const w of DATA_WORDS) if (rest.includes(w)) (cmd.kind = 'data'), (rest = rest.split(w).join(blank(w)));
  for (const w of FLOW_WORDS) if (rest.includes(w)) (cmd.kind = 'flow'), (rest = rest.split(w).join(blank(w)));

  // 線の形
  for (const w of ['直角', 'カギ線', 'かぎ線']) if (rest.includes(w)) (cmd.route = 'ortho'), (rest = rest.split(w).join(blank(w)));
  for (const w of ['曲線']) if (rest.includes(w)) (cmd.route = 'curve'), (rest = rest.split(w).join(blank(w)));

  // 色
  const hm = rest.match(/#[0-9a-fA-F]{6}\b/);
  if (hm) {
    cmd.color = hm[0].toUpperCase();
    rest = rest.replace(hm[0], blank(hm[0]));
  } else {
    for (const [w, hex] of COLOR_WORDS) {
      if (rest.includes(w)) {
        cmd.color = hex;
        rest = rest.split(w).join(blank(w));
        break;
      }
    }
  }

  // 「作業」「データ」など種類の言葉
  const byType = findByType(rest, nodes);
  if (!byType.ok) return byType;
  const all = [...hits, ...byType.hits].sort((a, b) => a.pos - b.pos);
  const uniq = all.filter((h, i) => all.findIndex((x) => x.node.id === h.node.id) === i);

  if (uniq.length === 1) return { ok: false, error: `「${uniq[0].word}」の相手が見つかりません。部品の名前を2つ書いてください。` };
  if (uniq.length >= 2) {
    let [a, b] = uniq;
    // 「AへBから」のように「から」が後ろの部品に付いていたら向きを入れ替える
    const kara = (h: Hit) => /^\s*から/.test(text.normalize('NFKC').slice(h.end));
    if (kara(b) && !kara(a)) [a, b] = [b, a];
    cmd.from = a.node;
    cmd.to = b.node;
  }
  if (!cmd.from && cmd.kind === undefined && cmd.color === undefined && cmd.label === undefined && cmd.route === undefined) {
    return { ok: false, error: '読み取れませんでした。例：「見積を作成すると商談を点線で繋ぐ。線は赤。ラベルは金額を登録」' };
  }
  return { ok: true, cmd };
}

type Hit = { word: string; node: Node; pos: number; end: number };
type Found = { ok: true; hits: Hit[] } | { ok: false; error: string };

/** 部品の名前そのもの（長い名前から順に。「見積を顧客に送付」を「見積」より先に） */
function findByName(text: string, nodes: Node[]): Found {
  const byLabel = new Map<string, Node[]>();
  for (const n of nodes) {
    const l = n.label.normalize('NFKC').trim();
    if (l) byLabel.set(l, [...(byLabel.get(l) ?? []), n]);
  }
  const taken: [number, number][] = [];
  const free = (s: number, e: number) => taken.every(([a, b]) => e <= a || s >= b);
  const hits: Hit[] = [];
  for (const l of [...byLabel.keys()].sort((a, b) => b.length - a.length)) {
    for (let i = text.indexOf(l); i >= 0; i = text.indexOf(l, i + 1)) {
      if (!free(i, i + l.length)) continue;
      const ns = byLabel.get(l)!;
      if (ns.length > 1) return { ok: false, error: `「${l}」という名前の部品が${ns.length}個あります。名前を変えて区別してください。` };
      hits.push({ word: l, node: ns[0], pos: i, end: i + l.length });
      taken.push([i, i + l.length]);
    }
  }
  return { ok: true, hits };
}

/** 種類の言葉（その種類の部品が1個だけのときに使える） */
function findByType(text: string, nodes: Node[]): Found {
  const hits: Hit[] = [];
  for (const [w, type] of TYPE_WORDS) {
    for (let i = text.indexOf(w); i >= 0; i = text.indexOf(w, i + 1)) {
      const ns = nodes.filter((n) => n.type === type);
      if (ns.length === 1) {
        hits.push({ word: w, node: ns[0], pos: i, end: i + w.length });
      } else if (ns.length > 1) {
        const names = ns.slice(0, 5).map((n) => `「${n.label}」`).join('');
        return { ok: false, error: `「${w}」の部品が${ns.length}個あります。名前で書いてください（${names}${ns.length > 5 ? ' など' : ''}）。` };
      }
    }
  }
  return { ok: true, hits };
}
