import type ExcelJS from 'exceljs';
import { LANE_COLUMNS, SHAPES, STEP_COLUMNS } from '../model/importTable';

/* 取り込み用の Excel のひな形（コマンドの npm run template と、画面の「ひな形をダウンロード」で共通） */

// 記入例：経費精算
const EXAMPLE: string[][] = [
  ['1', '申請者', '経費を入力する', '作業', '経費精算システム', '経費申請', '渡す', '2', '', 'PC から入力'],
  ['2', '上長', '申請内容を確認する', '作業', '経費精算システム', '経費申請', '受け取る', '3', '', ''],
  ['3', '上長', '承認する？', '判断', '', '', '', '4, 5', '承認, 差し戻し', ''],
  ['4', '経理', '支払いをする', '作業', '会計システム', '支払データ', '渡す', '6', '', ''],
  ['5', '申請者', '内容を修正する', '作業', '', '', '', '2', '', '差し戻しのとき'],
  ['6', '経理', '支払完了を知らせる', 'メール', '申請者', '支払完了の連絡', '渡す', '', '', ''],
];
const EXAMPLE_LANES: string[][] = [
  ['申請者', '担当者', '経費を使った社員'],
  ['上長', '担当者', ''],
  ['経理', '担当者', ''],
  ['経費精算システム', 'システム', ''],
  ['会計システム', 'システム', ''],
];
const WIDTH: Record<string, number> = { No: 6, 誰が: 14, 何をする: 26, 形: 9, '誰・何に対して': 18, 受け渡すもの: 18, 向き: 9, 次のNo: 10, 条件: 16, メモ: 24, 名前: 20, 区分: 10, 説明: 30 };

const HOW_TO = [
  ['業務フロー 取り込み用の表の書き方'],
  [''],
  ['1行に1つの作業を書きます。「誰が」「誰（何）に対して」「何をする」が基本です。'],
  [''],
  ['列', '書くこと', '例'],
  ['No', '作業の番号。重ならなければ数字でも文字でもよい（必須）', '1'],
  ['誰が', '作業をする人・部署・システム（必須）。同じ名前は同じ枠になる', '申請者'],
  ['何をする', '作業の名前（必須）', '経費を入力する'],
  ['形', '作業（空なら作業）・判断・開始・終了・メール・書類・人・時計・付せん・データ・Excel・CSV・PC', '判断'],
  ['誰・何に対して', '相手。システムなら「受け渡すもの」をデータとして点線でつなぐ。人なら次の作業への線に「受け渡すもの」を書く', '経費精算システム'],
  ['受け渡すもの', 'やり取りするデータや書類', '経費申請'],
  ['向き', '相手がシステムのとき。渡す（登録する）／受け取る（参照する）。空なら渡す', '受け取る'],
  ['次のNo', '次の作業の No。分岐は「4, 5」のように並べる。空なら次の行。終わりなら「なし」', '4, 5'],
  ['条件', '分岐の条件。次のNo と同じ順に並べる', '承認, 差し戻し'],
  ['メモ', '補足（図には出ず、部品のメモに入る）', ''],
  [''],
  ['「枠」シート：担当者とシステムを、出したい順に書きます（なくても動きます）。'],
  ['書いていない相手は、名前に「システム」などが入っていればシステム、そうでなければ担当者として扱います。'],
  [''],
  ['取り込み方：ターミナルで  npm run import -- このファイル.xlsx  を実行します。'],
  ['開始と終了は、書かなければ自動で付きます。並べ方は表の順（前の作業の右）です。前の No へ戻る線（差し戻しなど）は並べ方に影響しません。'],
];

export function buildTemplate(Workbook: typeof ExcelJS.Workbook): ExcelJS.Workbook {
  const wb = new Workbook();
  const head = (ws: ExcelJS.Worksheet) => {
    const r = ws.getRow(1);
    r.font = { bold: true, color: { argb: 'FFFFFFFF' } };
    r.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF2F6FDE' } };
    r.alignment = { vertical: 'middle' };
    r.height = 20;
    ws.views = [{ state: 'frozen', ySplit: 1 }];
  };
  const list = (ws: ExcelJS.Worksheet, col: string, items: string[]) => {
    for (let i = 2; i <= 300; i++)
      ws.getCell(`${col}${i}`).dataValidation = { type: 'list', allowBlank: true, formulae: [`"${items.join(',')}"`] };
  };

  const steps = wb.addWorksheet('業務の流れ');
  steps.columns = STEP_COLUMNS.map((c) => ({ header: c, key: c, width: WIDTH[c] }));
  EXAMPLE.forEach((r) => steps.addRow(r));
  head(steps);
  const shapes = ['作業', '判断', '開始', '終了', 'メール', '書類', '人', '時計', '付せん', 'データ', 'Excel', 'CSV', 'PC'];
  for (const s of shapes) if (!SHAPES[s]) throw new Error(`形「${s}」が取り込み側にありません`);
  list(steps, 'D', shapes);
  list(steps, 'G', ['渡す', '受け取る']);

  const lanes = wb.addWorksheet('枠');
  lanes.columns = LANE_COLUMNS.map((c) => ({ header: c, key: c, width: WIDTH[c] }));
  EXAMPLE_LANES.forEach((r) => lanes.addRow(r));
  head(lanes);
  list(lanes, 'B', ['担当者', 'システム']);

  const how = wb.addWorksheet('書き方');
  HOW_TO.forEach((r) => how.addRow(r));
  how.getColumn(1).width = 16;
  how.getColumn(2).width = 80;
  how.getColumn(3).width = 20;
  how.getRow(1).font = { bold: true, size: 14 };
  how.getRow(5).font = { bold: true };

  return wb;
}
