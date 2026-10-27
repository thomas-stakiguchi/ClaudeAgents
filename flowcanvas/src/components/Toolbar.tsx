import { useState } from 'react';
import {
  createFlow, createSampleFlow, duplicateFlow, flash, getState, openFlow, redo, saveNow, setState, undo, useStore,
  type State,
} from '../store/flowStore';
import { saveAskFile, saveFlowFile } from '../export/fileSave';
import { STANDALONE } from '../store/localApi';
import { loadExportOptions, saveExportOptions, type ExportOptions } from '../export/options';
import { applyTheme, loadTheme, type Theme } from '../theme';
import { canvasApi } from './Canvas';
import { DropMenu, type MenuItem } from './DropMenu';
import { ConfirmDelete } from './ConfirmDelete';
import { ExportModal } from './ExportModal';
import { GuideModal } from './GuideModal';
import { ImportModal } from './ImportModal';
import { SaveNote } from './SaveNote';

const SAVE_LABEL = { saved: '保存済', dirty: '未保存', saving: '保存中…', error: '保存できませんでした' } as const;

export async function saveWithMessage() {
  const ok = await saveNow();
  if (!ok) flash(STANDALONE ? '保存できませんでした。ブラウザの保存領域がいっぱいか、保存が許可されていません' : '保存できませんでした。npm run dev が動いているか確認してください');
  else if (getState().askFile) setState({ saveNote: true });
  else flash('保存しました');
}

export function Toolbar() {
  const flows = useStore((s) => s.flows);
  const flowId = useStore((s) => s.flow?.id);
  const k = useStore((s) => s.view.k);
  const saveState = useStore((s) => s.saveState);
  const canUndo = useStore((s) => s.past.length > 0);
  const canRedo = useStore((s) => s.future.length > 0);
  const modal = useStore((s) => s.modal);
  const setModal = (m: State['modal']) => setState({ modal: m });
  const [opts, setOpts] = useState<ExportOptions>(loadExportOptions);
  const toggleOpt = (key: keyof ExportOptions) => {
    const next = { ...opts, [key]: !opts[key] };
    setOpts(next);
    saveExportOptions(next);
  };
  const [busy, setBusy] = useState(false);
  const [theme, setTheme] = useState<Theme>(loadTheme);

  const zoomBy = (f: number) => {
    const el = document.querySelector('.canvas')!.getBoundingClientRect();
    const v = getState().view;
    const px = el.width / 2, py = el.height / 2;
    const nk = Math.min(2.5, Math.max(0.1, v.k * f));
    setState({ view: { x: px - ((px - v.x) * nk) / v.k, y: py - ((py - v.y) * nk) / v.k, k: nk } });
  };

  const onImage = async (kind: 'png' | 'jpg' | 'pdf') => {
    const flow = getState().flow;
    if (!flow || !canvasApi.world || busy) return;
    setBusy(true);
    const o = loadExportOptions();
    // 白い背景で出すときは、ダークモードでも一時的にライトの色にする
    const root = document.documentElement;
    const prevTheme = root.getAttribute('data-theme');
    if (o.plain) root.setAttribute('data-theme', 'light');
    setState({ exporting: o }); // 選択枠・ポートを消し、見出しを出してから撮る
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
    try {
      const { exportImage } = await import('../export/image'); // 重いので使うときだけ読む
      await exportImage(canvasApi.world, flow, kind, o);
    } catch (e) {
      console.error(e);
      flash('画像の書き出しに失敗しました');
    } finally {
      if (o.plain) {
        if (prevTheme) root.setAttribute('data-theme', prevTheme);
        else root.removeAttribute('data-theme');
      }
      setState({ exporting: false });
      setBusy(false);
    }
  };

  const askFile = useStore((s) => s.askFile);
  const toFile = async () => {
    const f = getState().flow;
    if (!f) return;
    try {
      const name = await saveFlowFile(f);
      if (name) flash(`ファイルに保存しました：${name}`);
    } catch {
      flash('ファイルに保存できませんでした');
    }
  };
  const onDeleteFlow = () => setState({ confirmDel: 'flow' });

  const themeItem = (t: Theme, label: string) => ({
    kind: 'radio' as const,
    label,
    checked: theme === t,
    onSelect: () => {
      applyTheme(t);
      setTheme(t);
    },
  });
  const exportItems: MenuItem[] = [
    { label: 'PNG（画像）', onSelect: () => void onImage('png') },
    { label: 'JPG（画像）', onSelect: () => void onImage('jpg') },
    { label: 'PDF', onSelect: () => void onImage('pdf') },
    { label: 'テキスト（JSON・Mermaid・Markdown）…', onSelect: () => setModal('export') },
    { kind: 'sep' },
    { kind: 'note', label: '画像・PDF の設定' },
    { kind: 'check', label: 'タイトル・出力日・凡例を入れる', checked: opts.header, onToggle: () => toggleOpt('header') },
    { kind: 'check', label: '白い背景・マス目なし（印刷向け）', checked: opts.plain, onToggle: () => toggleOpt('plain') },
  ];
  const moreItems: MenuItem[] = [
    { label: 'この図を複製', onSelect: () => void duplicateFlow() },
    { label: 'この図を削除…', danger: true, onSelect: onDeleteFlow },
    { label: 'サンプルの図を開く', onSelect: () => void createSampleFlow() },
    { label: 'ファイルに保存…', onSelect: () => void toFile() },
    {
      kind: 'check',
      label: '保存のときにファイル保存もすすめる',
      checked: askFile,
      onToggle: () => {
        saveAskFile(!askFile);
        setState({ askFile: !askFile });
      },
    },
    { kind: 'sep' },
    { kind: 'note', label: '画面の明るさ' },
    themeItem('auto', '自動（PCの設定に合わせる）'),
    themeItem('light', 'ライト'),
    themeItem('dark', 'ダーク'),
  ];

  return (
    <header className="top">
      <div className="brand">
        <span className="logo" aria-hidden="true" />
        FlowCanvas
      </div>
      <span className="vsep" aria-hidden="true" />
      <label className="flow-pick">
        <span>図</span>
        <select className="flowsel" value={flowId ?? ''} onChange={(e) => void openFlow(e.target.value)}>
          {flows.map((f) => (
            <option key={f.id} value={f.id}>
              {f.title || '無題'}
            </option>
          ))}
        </select>
      </label>
      <button className="btn" onClick={() => void createFlow()}>＋ 新しい図</button>
      <div className="spacer" />
      <span className={`status${saveState === 'error' ? ' err' : ' wide-only'}`} role="status">{SAVE_LABEL[saveState]}</span>
      <div className="group">
        <button className="icon ghost" title="取り消し (Ctrl+Z)" aria-label="取り消し" disabled={!canUndo} onClick={undo}>↶</button>
        <button className="icon ghost" title="やり直し (Ctrl+Shift+Z)" aria-label="やり直し" disabled={!canRedo} onClick={redo}>↷</button>
      </div>
      <div className="zoom">
        <button className="icon ghost" title="縮小" aria-label="縮小" onClick={() => zoomBy(1 / 1.2)}>−</button>
        <button className="zoom-val" title="全体表示" aria-label={`倍率 ${Math.round(k * 100)}%。押すと全体表示`} onClick={() => canvasApi.fit()}>
          {Math.round(k * 100)}%
        </button>
        <button className="icon ghost" title="拡大" aria-label="拡大" onClick={() => zoomBy(1.2)}>＋</button>
      </div>
      <button className="btn" title="Excel・CSV・JSON を読み込む" onClick={() => setModal('import')}>
        読み込み<span className="wide-only">（Excel・JSON）</span>
      </button>
      <DropMenu label={busy ? '書き出し中…' : '書き出し ▾'} items={exportItems} disabled={busy} />
      <span className="save-wrap">
        <button className="btn primary" title="保存 (Ctrl+S)" disabled={saveState === 'saving'} onClick={() => void saveWithMessage()}>
          保存
        </button>
        <SaveNote />
      </span>
      <button className="btn link" onClick={() => setModal('guide')}>？ 操作ガイド</button>
      <DropMenu label="…" ariaLabel="そのほかの操作" className="icon ghost" items={moreItems} />
      {modal === 'export' && <ExportModal onClose={() => setModal(null)} />}
      {modal === 'import' && <ImportModal onClose={() => setModal(null)} />}
      {modal === 'guide' && <GuideModal onClose={() => setModal(null)} />}
      <ConfirmDelete />
    </header>
  );
}
