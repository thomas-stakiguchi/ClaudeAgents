import type ExcelJS from 'exceljs';
import { LANE_COLUMNS, STEP_COLUMNS, type LaneRow, type StepRow } from '../model/importTable';

/* Excel（.xlsx）と CSV を「業務の流れ」「枠」の行に読み込む（コマンドと画面の両方で使う） */

type Table = { header: string[]; rows: { row: number; cells: string[] }[] };

const clean = (v: unknown) => String(v ?? '').replace(/\r?\n/g, ' ').trim();

function cellText(v: ExcelJS.CellValue): string {
  if (v == null) return '';
  if (typeof v === 'object') {
    if ('richText' in v) return v.richText.map((t) => t.text).join('');
    if ('text' in v) return String(v.text);
    if ('result' in v) return clean(v.result);
    if (v instanceof Date) return v.toISOString().slice(0, 10);
  }
  return clean(v);
}

function sheetTable(ws: ExcelJS.Worksheet): Table {
  const rows: Table['rows'] = [];
  let header: string[] = [];
  ws.eachRow({ includeEmpty: false }, (row, n) => {
    const cells: string[] = [];
    for (let c = 1; c <= ws.columnCount; c++) cells.push(cellText(row.getCell(c).value));
    if (!header.length) header = cells;
    else rows.push({ row: n, cells });
  });
  return { header, rows };
}

/** CSV（ダブルクォート・改行入りの値に対応。先頭の BOM は外す） */
export function parseCsv(text: string): string[][] {
  const out: string[][] = [];
  let row: string[] = [], cell = '', q = false;
  const s = text.replace(/^﻿/, '');
  for (let i = 0; i < s.length; i++) {
    const ch = s[i];
    if (q) {
      if (ch === '"' && s[i + 1] === '"') (cell += '"'), i++;
      else if (ch === '"') q = false;
      else cell += ch;
    } else if (ch === '"') q = true;
    else if (ch === ',') row.push(cell), (cell = '');
    else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && s[i + 1] === '\n') i++;
      row.push(cell), out.push(row), (row = []), (cell = '');
    } else cell += ch;
  }
  if (cell || row.length) row.push(cell), out.push(row);
  return out;
}

function csvTable(text: string): Table {
  const all = parseCsv(text).map((r) => r.map(clean));
  const [header = [], ...rest] = all;
  return { header, rows: rest.map((cells, i) => ({ row: i + 2, cells })).filter((r) => r.cells.some(Boolean)) };
}

/** 見出しの名前で列を探す（少し違う書き方も受け付ける） */
function pick(t: Table, names: readonly string[]) {
  const alias: Record<string, string[]> = {
    No: ['No', 'NO', 'no', '番号', '#'],
    誰が: ['誰が', '担当', '担当者', '実施者'],
    何をする: ['何をする', '作業', '作業内容', 'やること'],
    形: ['形', '種類', '部品'],
    '誰・何に対して': ['誰・何に対して', '誰に対して', '何に対して', '相手', '対象'],
    受け渡すもの: ['受け渡すもの', '受け渡し', 'データ', 'もの'],
    向き: ['向き', '方向'],
    次のNo: ['次のNo', '次', '次の作業', '次No'],
    条件: ['条件', '分岐条件'],
    メモ: ['メモ', '備考', '補足'],
    名前: ['名前', '枠', '枠の名前'],
    区分: ['区分', '種別'],
    説明: ['説明', '備考'],
  };
  const idx = names.map((n) => t.header.findIndex((h) => (alias[n] ?? [n]).includes(h.replace(/\s/g, ''))));
  return t.rows.map((r) => ({ row: r.row, get: (k: number) => (idx[k] >= 0 ? r.cells[idx[k]] ?? '' : '') }));
}

function toSteps(t: Table): StepRow[] {
  return pick(t, STEP_COLUMNS).map(({ row, get }) => ({
    row, no: get(0), who: get(1), what: get(2), shape: get(3) || undefined, target: get(4) || undefined,
    thing: get(5) || undefined, direction: get(6) || undefined, next: get(7) || undefined, cond: get(8) || undefined, memo: get(9) || undefined,
  }));
}
function toLanes(t: Table): LaneRow[] {
  return pick(t, LANE_COLUMNS).map(({ row, get }) => ({ row, name: get(0), kind: get(1) || undefined, desc: get(2) || undefined }));
}

/** Excel のブックから読む（「業務の流れ」シート、なければ最初のシート。「枠」シートはあれば） */
export function readWorkbook(wb: ExcelJS.Workbook): { steps: StepRow[]; lanes: LaneRow[] } {
  const flowSheet = wb.getWorksheet('業務の流れ') ?? wb.worksheets[0];
  if (!flowSheet) throw new Error('Excel にシートがありません。');
  const laneSheet = wb.getWorksheet('枠');
  return { steps: toSteps(sheetTable(flowSheet)), lanes: laneSheet ? toLanes(sheetTable(laneSheet)) : [] };
}

/** CSV の文字から読む（業務の流れ） */
export const readCsvSteps = (text: string): StepRow[] => toSteps(csvTable(text));

/** 枠の CSV（名前,区分,説明） */
export const readCsvLanes = (text: string): LaneRow[] => toLanes(csvTable(text));
