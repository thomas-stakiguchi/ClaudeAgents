/*
 * Excel の中身をシートごとに CSV の形で表示する（Claude Code などが Excel を読むため）。
 *   npm run dump -- 資料.xlsx
 */
import ExcelJS from 'exceljs';
import path from 'node:path';

const q = (s: string) => (/[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s);

function text(v: ExcelJS.CellValue): string {
  if (v == null) return '';
  if (typeof v === 'object') {
    if ('richText' in v) return v.richText.map((t) => t.text).join('');
    if ('text' in v) return String(v.text);
    if ('result' in v) return String(v.result ?? '');
    if (v instanceof Date) return v.toISOString().slice(0, 10);
  }
  return String(v);
}

async function main() {
  const file = process.argv[2];
  if (!file) {
    console.log('使い方：npm run dump -- 資料.xlsx');
    process.exit(1);
  }
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(path.resolve(file));
  for (const ws of wb.worksheets) {
    console.log(`### シート「${ws.name}」（${ws.actualRowCount}行）`);
    ws.eachRow({ includeEmpty: false }, (row) => {
      const cells: string[] = [];
      for (let c = 1; c <= ws.columnCount; c++) cells.push(q(text(row.getCell(c).value).trim()));
      console.log(cells.join(','));
    });
    console.log('');
  }
}

main().catch((e) => {
  console.error('エラー：' + (e instanceof Error ? e.message : String(e)));
  process.exit(1);
});
