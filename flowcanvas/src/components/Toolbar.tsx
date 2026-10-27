import { useEffect, useRef, useState } from 'react';
import {
  createFlow, createSampleFlow, deleteFlow, duplicateFlow, flash, getState, openFlow, redo, saveNow, setState, undo, useStore,
} from '../store/flowStore';
import { loadExportOptions, saveExportOptions, type ExportOptions } from '../export/options';
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
      <button className="icon" title="取り消し (Ctrl+Z)" aria-label="取り消し" disabled={!canUndo} onClick={undo}>↶</button>
      <button className="icon" title="やり直し (Ctrl+Shift+Z)" aria-label="やり直し" disabled={!canRedo} onClick={redo}>↷</button>
      <div className="spacer" />
      <span className={`status${saveState === 'error' ? ' err' : ''}`}>{SAVE_LABEL[saveState]}</span>
      <button className="sm primary" title="保存 (Ctrl+S)" disabled={saveState === 'saving'} onClick={() => void saveWithMessage()}>
        保存
      </button>
      <button className="icon" title="縮小" aria-label="縮小" onClick={() => zoomBy(1 / 1.2)}>−</button>
      <span className="status">{Math.round(k * 100)}%</span>
      <button className="icon" title="拡大" aria-label="拡大" onClick={() => zoomBy(1.2)}>＋</button>
      <button className="sm" onClick={() => canvasApi.fit()}>全体表示</button>
      <span className="sep" />
      <ExportMenu
        busy={busy}
        onPick={(v) => {
          if (v === 'text') setModal('export');
          else void onImage(v);
        }}
      />
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

type ExportKind = 'png' | 'jpg' | 'pdf' | 'text';
const EXPORT_ITEMS: { v: ExportKind; label: string }[] = [
  { v: 'png', label: 'PNG（画像）' },
  { v: 'jpg', label: 'JPG（画像）' },
  { v: 'pdf', label: 'PDF' },
  { v: 'text', label: 'テキスト（JSON・Mermaid・Markdown）…' },
];

/** 書き出しメニュー。プルダウンだと矢印キーで動かしただけで実行されるので、ボタン＋メニューにしている */
function ExportMenu({ busy, onPick }: { busy: boolean; onPick: (v: ExportKind) => void }) {
  const [open, setOpen] = useState(false);
  const [opts, setOpts] = useState<ExportOptions>(loadExportOptions);
  const toggle = (k: keyof ExportOptions) => {
    const next = { ...opts, [k]: !opts[k] };
    setOpts(next);
    saveExportOptions(next);
  };
  const wrap = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    wrap.current?.querySelector<HTMLButtonElement>('[role=menuitem]')?.focus();
    const onDown = (e: PointerEvent) => {
      if (!wrap.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('pointerdown', onDown);
    return () => document.removeEventListener('pointerdown', onDown);
  }, [open]);

  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      e.stopPropagation();
      setOpen(false);
      wrap.current?.querySelector<HTMLButtonElement>('.menu-btn')?.focus();
      return;
    }
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    e.preventDefault();
    const items = [...(wrap.current?.querySelectorAll<HTMLButtonElement>('[role^=menuitem]') ?? [])];
    const i = items.indexOf(document.activeElement as HTMLButtonElement);
    items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
  };

  return (
    <div className="menu-wrap" ref={wrap} onKeyDown={onKey}>
      <button className="sm menu-btn" aria-haspopup="menu" aria-expanded={open} disabled={busy} onClick={() => setOpen((o) => !o)}>
        {busy ? '書き出し中…' : '書き出し ▾'}
      </button>
      {open && (
        <div className="menu" role="menu">
          {EXPORT_ITEMS.map((it) => (
            <button
              key={it.v}
              role="menuitem"
              onClick={() => {
                setOpen(false);
                onPick(it.v);
              }}
            >
              {it.label}
            </button>
          ))}
          <div className="menu-sep" role="separator" />
          <div className="menu-note">画像・PDF の設定（選んでも閉じません）</div>
          <button role="menuitemcheckbox" aria-checked={opts.header} onClick={() => toggle('header')}>
            <span className="check">{opts.header ? '☑' : '☐'}</span>タイトル・出力日・凡例を入れる
          </button>
          <button role="menuitemcheckbox" aria-checked={opts.plain} onClick={() => toggle('plain')}>
            <span className="check">{opts.plain ? '☑' : '☐'}</span>白い背景・マス目なし（印刷向け）
          </button>
        </div>
      )}
    </div>
  );
}
