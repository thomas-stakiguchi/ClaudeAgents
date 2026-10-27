import { useState } from 'react';
import {
  createFlow, createSampleFlow, deleteFlow, duplicateFlow, flash, getState, openFlow, redo, saveNow, setState, undo, useStore,
} from '../store/flowStore';
import { applyTheme, loadTheme, type Theme } from '../theme';
import { canvasApi } from './Canvas';
import { ExportModal, ImportModal } from './ExportModal';

const SAVE_LABEL = { saved: '保存済', dirty: '未保存', saving: '保存中…', error: '保存できませんでした' } as const;

export async function saveWithMessage() {
  const ok = await saveNow();
  flash(ok ? '保存しました' : '保存できませんでした。npm run dev が動いているか確認してください');
}

export function Toolbar() {
  const flows = useStore((s) => s.flows);
  const flowId = useStore((s) => s.flow?.id);
  const k = useStore((s) => s.view.k);
  const saveState = useStore((s) => s.saveState);
  const canUndo = useStore((s) => s.past.length > 0);
  const canRedo = useStore((s) => s.future.length > 0);
  const confirmDel = useStore((s) => s.confirmDel);
  const [modal, setModal] = useState<'export' | 'import' | null>(null);
  const [busy, setBusy] = useState(false);
  const [theme, setTheme] = useState<Theme>(loadTheme);

  const zoomBy = (f: number) => {
    const el = document.querySelector('.canvas')!.getBoundingClientRect();
    const v = getState().view;
    const px = el.width / 2, py = el.height / 2;
    const nk = Math.min(2.5, Math.max(0.25, v.k * f));
    setState({ view: { x: px - ((px - v.x) * nk) / v.k, y: py - ((py - v.y) * nk) / v.k, k: nk } });
  };

  const onImage = async (kind: 'png' | 'jpg' | 'pdf') => {
    const flow = getState().flow;
    if (!flow || !canvasApi.world || busy) return;
    setBusy(true);
    setState({ exporting: true }); // 選択枠・ポートを消してから撮る
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
    try {
      const { exportImage } = await import('../export/image'); // 重いので使うときだけ読む
      await exportImage(canvasApi.world, flow, kind);
    } catch (e) {
      console.error(e);
      flash('画像の書き出しに失敗しました');
    } finally {
      setState({ exporting: false });
      setBusy(false);
    }
  };

  const onDeleteFlow = () => {
    if (confirmDel !== 'flow') {
      setState({ confirmDel: 'flow' });
      flash('もう一度「削除」を押すとこの図を消します');
      return;
    }
    setState({ confirmDel: null });
    void deleteFlow();
  };

  return (
    <div className="top">
      <div className="brand">
        FlowCanvas
      </div>
      <span className="lbl">図：</span>
      <select className="flowsel" value={flowId ?? ''} onChange={(e) => void openFlow(e.target.value)} title="図の切替">
        {flows.map((f) => (
          <option key={f.id} value={f.id}>
            {f.title || '無題'}
          </option>
        ))}
      </select>
      <button className="sm" onClick={() => void createFlow()}>新しい図</button>
      <button className="sm" onClick={() => void duplicateFlow()}>図を複製</button>
      <button className={`sm${confirmDel === 'flow' ? ' danger' : ''}`} onClick={onDeleteFlow}>
        {confirmDel === 'flow' ? '本当に削除する' : '図を削除'}
      </button>
      <span className="sep" />
      <button className="icon" title="取り消し (Ctrl+Z)" disabled={!canUndo} onClick={undo}>↶</button>
      <button className="icon" title="やり直し (Ctrl+Shift+Z)" disabled={!canRedo} onClick={redo}>↷</button>
      <div className="spacer" />
      <span className={`status${saveState === 'error' ? ' err' : ''}`}>{SAVE_LABEL[saveState]}</span>
      <button className="sm primary" title="保存 (Ctrl+S)" disabled={saveState === 'saving'} onClick={() => void saveWithMessage()}>
        保存
      </button>
      <button className="icon" onClick={() => zoomBy(1 / 1.2)}>−</button>
      <span className="status">{Math.round(k * 100)}%</span>
      <button className="icon" onClick={() => zoomBy(1.2)}>＋</button>
      <button className="sm" onClick={() => canvasApi.fit()}>全体表示</button>
      <span className="sep" />
      <select
        style={{ width: 'auto' }}
        value=""
        disabled={busy}
        title="書き出し"
        onChange={(e) => {
          const v = e.target.value;
          if (v === 'text') setModal('export');
          else if (v) void onImage(v as 'png' | 'jpg' | 'pdf');
        }}
      >
        <option value="">{busy ? '書き出し中…' : '書き出し…'}</option>
        <option value="png">PNG</option>
        <option value="jpg">JPG</option>
        <option value="pdf">PDF</option>
        <option value="text">テキスト</option>
      </select>
      <button className="sm" onClick={() => setModal('import')}>読み込み</button>
      <button className="sm" onClick={() => void createSampleFlow()}>サンプルの図</button>
      <select
        style={{ width: 'auto' }}
        value={theme}
        title="画面の明るさ"
        onChange={(e) => {
          const t = e.target.value as Theme;
          applyTheme(t);
          setTheme(t);
        }}
      >
        <option value="auto">🌓 自動</option>
        <option value="light">☀ ライト</option>
        <option value="dark">🌙 ダーク</option>
      </select>
      {modal === 'export' && <ExportModal onClose={() => setModal(null)} />}
      {modal === 'import' && <ImportModal onClose={() => setModal(null)} />}
    </div>
  );
}
