/*
 * 取り込み用の Excel のひな形を作る。
 *   npm run template            → templates/業務フロー_取り込みひな形.xlsx
 *   npm run template -- 出力先.xlsx
 */
import ExcelJS from 'exceljs';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildTemplate } from '../src/import/template';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

async function main() {
  const out = path.resolve(process.argv[2] ?? path.join(ROOT, 'templates', '業務フロー_取り込みひな形.xlsx'));
  const wb = buildTemplate(ExcelJS.Workbook);
  await fs.mkdir(path.dirname(out), { recursive: true });
  await wb.xlsx.writeFile(out);
  console.log(`ひな形を作りました：${out}`);
  console.log('「業務の流れ」の記入例（経費精算）を書き換えて使ってください。書き方は「書き方」シートにあります。');
}

main().catch((e) => {
  console.error('エラー：' + (e instanceof Error ? e.message : String(e)));
  process.exit(1);
});
