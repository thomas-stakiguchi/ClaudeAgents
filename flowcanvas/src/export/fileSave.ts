import type { Flow } from '../model/types';
import { downloadText, fileName, toJson } from './text';

/* 保存ボタンを押したときに「ファイルにも残す」をすすめるかどうか。ブラウザに覚えておく */
const KEY = 'flowcanvas.askFileSave';

export function loadAskFile() {
  try {
    return localStorage.getItem(KEY) !== '0';
  } catch {
    return true;
  }
}

export function saveAskFile(on: boolean) {
  try {
    localStorage.setItem(KEY, on ? '1' : '0');
  } catch {
    /* 使えない環境では覚えない */
  }
}

type SavePicker = (o: {
  suggestedName: string;
  types: { description: string; accept: Record<string, string[]> }[];
}) => Promise<{ name: string; createWritable: () => Promise<{ write: (d: string) => Promise<void>; close: () => Promise<void> }> }>;

/**
 * 図を JSON ファイルにして PC に残す。「読み込み」→「JSON」で戻せる。
 * Chrome・Edge では保存先を選べる（Box Drive などのフォルダも選べる）。それ以外はダウンロードに保存する。
 * 保存先選びをやめたときは null を返す。
 */
export async function saveFlowFile(flow: Flow): Promise<string | null> {
  const name = fileName(flow.title, 'json');
  const picker = (window as unknown as { showSaveFilePicker?: SavePicker }).showSaveFilePicker;
  if (!picker) {
    downloadText(toJson(flow), name, 'application/json');
    return name;
  }
  try {
    const h = await picker({ suggestedName: name, types: [{ description: 'FlowCanvas の図（JSON）', accept: { 'application/json': ['.json'] } }] });
    const w = await h.createWritable();
    await w.write(toJson(flow));
    await w.close();
    return h.name;
  } catch (e) {
    if ((e as Error).name === 'AbortError') return null;
    throw e;
  }
}
