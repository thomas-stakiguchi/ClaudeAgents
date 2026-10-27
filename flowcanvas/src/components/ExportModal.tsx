import { useEffect, useRef, useState } from 'react';
import { downloadText, fileName, toJson, toMarkdownTables, toMermaid } from '../export/text';
import type { Flow } from '../model/types';
import { flash, getState, importAsNewFlow, importFlow } from '../store/flowStore';
import { canvasApi } from './Canvas';

const KINDS = [
  { name: 'JSON（保存・共有用）', fn: toJson, ext: 'json', mime: 'application/json' },
  { name: 'Mermaid（Notion・GitHub用）', fn: toMermaid, ext: 'mmd', mime: 'text/plain' },
  { name: 'Markdown表（Backlog・仕様書用）', fn: toMarkdownTables, ext: 'md', mime: 'text/markdown' },
] as const;

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  const box = useRef<HTMLDivElement>(null);
  const close = useRef(onClose);
  close.current = onClose;
  useEffect(() => {
    // 開いたら中の最初の操作できる場所へ。Esc で閉じる
    box.current?.querySelector<HTMLElement>('button, textarea, input')?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        close.current();
      }
    };
    window.addEventListener('keydown', onKey, true);
    return () => window.removeEventListener('keydown', onKey, true);
  }, []);
  return (
    <div className="modal" onPointerDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="box" role="dialog" aria-modal="true" aria-label={title} ref={box}>
        <h3 style={{ margin: 0 }}>{title}</h3>
        {children}
      </div>
    </div>
  );
}

function CopyButton({ text }: { text: string }) {
  const [done, setDone] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      const ta = document.querySelector<HTMLTextAreaElement>('#m-text');
      ta?.select();
      document.execCommand?.('copy');
    }
    setDone(true);
    setTimeout(() => setDone(false), 1500);
  };
  return <button className="sm" onClick={copy}>{done ? 'コピーしました' : 'コピー'}</button>;
}

export function ExportModal({ onClose }: { onClose: () => void }) {
  const [i, setI] = useState(0);
  const flow = getState().flow!;
  const k = KINDS[i];
  const text = k.fn(flow);
  return (
    <Modal title="テキスト書き出し" onClose={onClose}>
      <div className="tabs">
        {KINDS.map((x, j) => (
          <button key={x.name} className={`sm${i === j ? ' on' : ''}`} onClick={() => setI(j)}>
            {x.name}
          </button>
        ))}
      </div>
      <textarea id="m-text" value={text} readOnly />
      <div className="actions">
        <CopyButton text={text} />
        <button className="sm" onClick={() => downloadText(text, fileName(flow.title, k.ext), k.mime)}>ファイルに保存</button>
        <button className="sm" onClick={onClose}>閉じる</button>
      </div>
    </Modal>
  );
}

export function ImportModal({ onClose }: { onClose: () => void }) {
  const [text, setText] = useState('');
  const [asNew, setAsNew] = useState(true);
  const apply = () => {
    try {
      const d = JSON.parse(text) as Flow;
      if (!Array.isArray(d.lanes) || !Array.isArray(d.nodes)) throw new Error();
      onClose();
      if (asNew) {
        void importAsNewFlow(d); // 新しい図を開くと全体が見える倍率になる
        return;
      }
      importFlow(d);
      requestAnimationFrame(() => canvasApi.fit());
    } catch {
      flash('JSONの形式が違います');
    }
  };
  const onFile = async (f: File | undefined) => {
    if (f) setText(await f.text());
  };
  return (
    <Modal title="JSONを読み込む" onClose={onClose}>
      <input type="file" accept=".json,application/json" onChange={(e) => void onFile(e.target.files?.[0])} />
      <textarea id="m-text" value={text} onChange={(e) => setText(e.target.value)} placeholder="ここにJSONを貼り付け" />
      <label className="check-row">
        <input type="checkbox" checked={asNew} onChange={(e) => setAsNew(e.target.checked)} />
        新しい図として読み込む（外すと今の図を置き換えます。Ctrl+Z で戻せます）
      </label>
      <div className="actions">
        <button className="primary sm" onClick={apply}>読み込む</button>
        <button className="sm" onClick={onClose}>閉じる</button>
      </div>
    </Modal>
  );
}
