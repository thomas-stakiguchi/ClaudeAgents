/*
 * Excel／CSV の表から業務フローの図を作る。
 *   npm run import -- 業務一覧.xlsx [--title "図のタイトル"]
 * 枠が別の CSV のときは --lanes 枠.csv を付ける。
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { tableToFlow } from '../src/model/importTable';
import { uid, type Flow } from '../src/model/types';
import { parseCsv, readTable } from './table-io';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const API = 'http://127.0.0.1:3001/api/flows';

function arg(name: string) {
  const i = process.argv.indexOf(name);
  return i > 0 ? process.argv[i + 1] : undefined;
}

async function main() {
  const file = process.argv.slice(2).find((a, i, all) => !a.startsWith('--') && !(all[i - 1] ?? '').startsWith('--'));
  if (!file) {
    console.log('使い方：npm run import -- 業務一覧.xlsx [--title "図のタイトル"] [--lanes 枠.csv]');
    console.log('ひな形は npm run template で作れます。');
    process.exit(1);
  }
  const { steps, lanes } = await readTable(path.resolve(file));
  const lanesCsv = arg('--lanes');
  if (lanesCsv) {
    const rows = parseCsv(await fs.readFile(path.resolve(lanesCsv), 'utf8'));
    rows.slice(1).forEach((r, i) => lanes.push({ row: i + 2, name: (r[0] ?? '').trim(), kind: r[1]?.trim(), desc: r[2]?.trim() }));
  }
  const res = tableToFlow(steps, lanes);
  if (!res.ok) {
    console.error('図を作れませんでした。表を直してもう一度実行してください：');
    for (const e of res.errors) console.error('  ・' + e);
    process.exit(1);
  }
  for (const w of res.warnings) console.warn('（注意）' + w);

  const title = arg('--title') ?? path.basename(file, path.extname(file));
  const body = { title, lanes: res.lanes, nodes: res.nodes, edges: res.edges };
  let saved: Flow;
  try {
    // アプリ（npm run dev）が動いていれば、保存の窓口を通す
    const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    if (!r.ok) throw new Error(String(r.status));
    saved = (await r.json()) as Flow;
  } catch {
    // 動いていなければ、保存先のフォルダへ直接書く
    saved = { ...body, id: uid(), updatedAt: new Date().toISOString() };
    await fs.mkdir(path.join(ROOT, 'data'), { recursive: true });
    await fs.writeFile(path.join(ROOT, 'data', `${saved.id}.json`), JSON.stringify(saved, null, 2));
  }
  const n = res.nodes.length, e = res.edges.length;
  const a = res.lanes.filter((l) => l.kind === 'actor').length, s = res.lanes.length - a;
  console.log(`図「${saved.title}」を作りました（担当者 ${a}・システム ${s}・部品 ${n}・線 ${e}）。`);
  console.log('ブラウザで http://localhost:5173 を開き（開いていれば再読み込みして）、上の「図：」から選んでください。');
}

main().catch((err) => {
  console.error('エラー：' + (err instanceof Error ? err.message : String(err)));
  process.exit(1);
});
