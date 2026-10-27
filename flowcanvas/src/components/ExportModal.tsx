import { useState } from 'react';
import { Modal } from './Modal';
import { downloadText, fileName, toJson, toMarkdownTables, toMermaid } from '../export/text';
import { getState } from '../store/flowStore';

const KINDS = [
  { name: 'JSON（保存・共有用）', fn: toJson, ext: 'json', mime: 'application/json' },
  { name: 'Mermaid（Notion・GitHub用）', fn: toMermaid, ext: 'mmd', mime: 'text/plain' },
  { name: 'Markdown表（Backlog・仕様書用）', fn: toMarkdownTables, ext: 'md', mime: 'text/markdown' },
] as const;

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
  return <button className="btn" onClick={copy}>{done ? 'コピーしました' : 'コピー'}</button>;
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
          <button key={x.name} role="tab" aria-selected={i === j} className={`tab${i === j ? ' on' : ''}`} onClick={() => setI(j)}>
            {x.name}
          </button>
        ))}
      </div>
      <textarea id="m-text" value={text} readOnly />
      <div className="actions">
        <CopyButton text={text} />
        <button className="btn primary" onClick={() => downloadText(text, fileName(flow.title, k.ext), k.mime)}>ファイルに保存</button>
      </div>
    </Modal>
  );
}
