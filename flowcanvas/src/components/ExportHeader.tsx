import { EXPORT_HEAD_H } from '../export/options';
import type { Flow } from '../model/types';

/** 書き出すときだけ図の上に出す見出し。色は書き出しでも崩れないように直接書く */
export function ExportHeader({ flow }: { flow: Flow }) {
  const d = new Date();
  const date = `${d.getFullYear()}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}`;
  const line = (dash?: string) => (
    <svg width="34" height="10" aria-hidden="true">
      <line x1="1" y1="5" x2="33" y2="5" style={{ stroke: dash ? 'var(--dflow)' : 'var(--flow)', strokeWidth: 2, strokeDasharray: dash }} />
    </svg>
  );
  return (
    <div className="export-head" style={{ top: -EXPORT_HEAD_H, height: EXPORT_HEAD_H - 12 }}>
      <div className="eh-title">{flow.title || '無題'}</div>
      <div className="eh-meta">
        <span>出力日 {date}</span>
        <span className="eh-legend">
          {line()}業務の流れ
        </span>
        <span className="eh-legend">
          {line('6 4')}データの流れ
        </span>
        <span>担当者 {flow.lanes.filter((l) => l.kind === 'actor').length}・システム {flow.lanes.filter((l) => l.kind === 'system').length}・部品 {flow.nodes.length}</span>
      </div>
    </div>
  );
}
