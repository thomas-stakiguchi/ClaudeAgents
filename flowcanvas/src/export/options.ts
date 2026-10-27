// 画像・PDF の書き出し設定。ブラウザに覚えておく
export type ExportOptions = {
  header: boolean; // 図の上にタイトル・出力日・凡例を入れる
  plain: boolean; // 白い背景・マス目なし（印刷向け）
};

const KEY = 'flowcanvas.exportOptions';
const DEFAULT: ExportOptions = { header: true, plain: true };

/** 見出し（タイトル・出力日・凡例）の高さ */
export const EXPORT_HEAD_H = 76;

export function loadExportOptions(): ExportOptions {
  try {
    return { ...DEFAULT, ...JSON.parse(localStorage.getItem(KEY) ?? '{}') };
  } catch {
    return DEFAULT;
  }
}

export function saveExportOptions(o: ExportOptions) {
  try {
    localStorage.setItem(KEY, JSON.stringify(o));
  } catch {
    /* 使えない環境では覚えない */
  }
}
