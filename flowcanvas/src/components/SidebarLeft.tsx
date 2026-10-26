import { useState } from 'react';
import type { EdgeKind, LaneKind, NodeType } from '../model/types';
import { addLane, addNode, deleteLane, moveLane, runSentence } from '../store/actions';
import { setState, useStore } from '../store/flowStore';

const PALETTE: { type: NodeType; title: string; sub: string; wide?: boolean }[] = [
  { type: 'start', title: '● 開始', sub: 'フローの起点' },
  { type: 'end', title: '◉ 終了', sub: 'フローの終点' },
  { type: 'task', title: '▭ 作業', sub: '担当者がやること' },
  { type: 'decision', title: '◇ 判断', sub: '分岐・承認' },
  { type: 'data', title: '⌸ データ', sub: 'システム内のレコード・ファイル', wide: true },
];

function LaneList({ kind }: { kind: LaneKind }) {
  const lanes = useStore((s) => s.flow?.lanes);
  const selId = useStore((s) => (s.sel?.kind === 'lane' ? s.sel.id : null));
  const ls = lanes?.filter((l) => l.kind === kind) ?? [];
  if (!ls.length) return <p className="hint">まだありません。「＋ 追加」で枠を作ります。</p>;
  const select = (id: string) => setState({ sel: { kind: 'lane', id } });
  return (
    <div className="list">
      {ls.map((l) => (
        <div key={l.id} className={`row${l.id === selId ? ' sel' : ''}`}>
          <span className="sw" style={{ background: kind === 'actor' ? 'var(--node-line)' : 'var(--data-line)' }} />
          <span
            className="nm"
            onClick={() => select(l.id)}
            onDoubleClick={() => {
              select(l.id);
              requestAnimationFrame(() => {
                const i = document.querySelector<HTMLInputElement>('#p-lane-name');
                i?.focus();
                i?.select();
              });
            }}
          >
            {l.name}
          </span>
          <span className="ops">
            <button className="icon sm" title="上へ" onClick={() => moveLane(l.id, -1)}>↑</button>
            <button className="icon sm" title="下へ" onClick={() => moveLane(l.id, 1)}>↓</button>
            <button className="icon sm" title="削除" onClick={() => deleteLane(l.id)}>×</button>
          </span>
        </div>
      ))}
    </div>
  );
}

function ConnectSection() {
  const tool = useStore((s) => s.tool);
  const edgeKind = useStore((s) => s.edgeKind);
  const [text, setText] = useState('');
  const [msg, setMsg] = useState<{ ok: boolean; message: string } | null>(null);
  const run = () => {
    if (!text.trim()) return;
    const r = runSentence(text);
    setMsg(r);
    if (r.ok) setText('');
  };
  return (
    <section>
      <h3>③ 線でつなぐ</h3>
      <div className="tools">
        <button className={tool === 'select' ? 'on' : ''} title="V" onClick={() => setState({ tool: 'select', connectSrc: null })}>
          選択・移動
        </button>
        <button className={tool === 'connect' ? 'on' : ''} title="C" onClick={() => setState({ tool: 'connect', connectSrc: null })}>
          接続
        </button>
      </div>
      <div className="field">
        <label>これから引く線</label>
        <select value={edgeKind} onChange={(e) => setState({ edgeKind: e.target.value as EdgeKind })}>
          <option value="flow">業務の流れ（実線）</option>
          <option value="data">データの流れ（点線）</option>
        </select>
      </div>
      <p className="hint">
        部品にマウスを乗せると丸が出ます。丸をクリック → 相手の部品をクリックでつながります。「接続」を押してから 元 → 先 の順にクリックしても同じです。
      </p>
      <div className="field nl" style={{ marginTop: 12, marginBottom: 0 }}>
        <label htmlFor="nl-text">文章でつなぐ</label>
        <textarea
          id="nl-text"
          value={text}
          placeholder={'例：作業とデータを点線で繋ぐ。線は赤。'}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => {
            // Enter で実行、Shift+Enter で改行（変換確定の Enter は除く）
            if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
              e.preventDefault();
              run();
            }
          }}
        />
        <button className="primary sm" style={{ marginTop: 6, alignSelf: 'flex-start' }} onClick={run}>
          実行（Enter）
        </button>
        {msg && <p className={`msg ${msg.ok ? 'ok' : 'ng'}`}>{msg.message}</p>}
        <p className="hint" style={{ marginTop: 6 }}>
          部品の名前を2つ書きます。「AからBへ」で向きが決まります。
          <br />
          線：点線／実線　色：赤・青・緑・黄・オレンジ・紫・黒 など
          <br />
          ラベル：「ラベルは〇〇」
          <br />
          線を選んでから「赤にする」だけでも色を変えられます。
        </p>
      </div>
    </section>
  );
}

export function SidebarLeft() {
  return (
    <aside className="side">
      <section>
        <h3>① 枠を作る</h3>
        <div className="subhead">
          Actor（担当）<button className="sm" onClick={() => addLane('actor')}>＋ 追加</button>
        </div>
        <LaneList kind="actor" />
        <div className="subhead" style={{ marginTop: 10 }}>
          関連システム<button className="sm" onClick={() => addLane('system')}>＋ 追加</button>
        </div>
        <LaneList kind="system" />
      </section>
      <section>
        <h3>② 部品を置く</h3>
        <div className="palette">
          {PALETTE.map((p) => (
            <div key={p.type} className="pal" style={p.wide ? { gridColumn: '1/-1' } : undefined} onClick={() => addNode(p.type)}>
              <b>{p.title}</b>
              <span>{p.sub}</span>
            </div>
          ))}
        </div>
        <p className="hint" style={{ marginTop: 8 }}>
          クリックすると選択中のレーンに置かれます。ドラッグで別レーンへ移動できます。
        </p>
      </section>
      <ConnectSection />
      <details className="help">
        <summary>そのほかの操作</summary>
        <p className="hint">
          ダブルクリック：名前を編集
          <br />
          Delete：選択中を削除　／　Esc：接続をやめる
          <br />
          Ctrl+Z：取り消し　Ctrl+Shift+Z：やり直し
          <br />
          ホイール：上下移動　Ctrl+ホイール：拡大縮小
          <br />
          背景ドラッグ：画面を動かす
        </p>
      </details>
    </aside>
  );
}
