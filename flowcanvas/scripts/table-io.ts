import ExcelJS from 'exceljs';
import fs from 'node:fs/promises';
import path from 'node:path';
import type { LaneRow, StepRow } from '../src/model/importTable';
import { readCsvSteps, readWorkbook } from '../src/import/table';

export { parseCsv } from '../src/import/table';

/* ファイルから「業務の流れ」「枠」を読む（読み方は src/import/table.ts と画面で共通） */
export async function readTable(file: string): Promise<{ steps: StepRow[]; lanes: LaneRow[] }> {
  const ext = path.extname(file).toLowerCase();
  if (ext === '.csv') return { steps: readCsvSteps(await fs.readFile(file, 'utf8')), lanes: [] };
  if (ext !== '.xlsx') throw new Error('読み込めるのは .xlsx と .csv です（.xls は Excel で .xlsx に保存し直してください）。');
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(file);
  return readWorkbook(wb);
}
