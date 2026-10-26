import type { LaneKind, NodeType } from '../model/types';
import { addLane, addNode, deleteLane, moveLane } from '../store/actions';
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

export function SidebarLeft() {
  return (
    <aside className="side">
      <section>
        <h3>
          Actor（担当）<button className="sm" onClick={() => addLane('actor')}>＋ 追加</button>
        </h3>
        <LaneList kind="actor" />
      </section>
      <section>
        <h3>
          関連システム<button className="sm" onClick={() => addLane('system')}>＋ 追加</button>
        </h3>
        <LaneList kind="system" />
      </section>
      <section>
        <h3>部品を置く</h3>
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
      <section>
        <h3>操作</h3>
        <p className="hint">
          接続：部品にマウスを乗せて出る丸をクリック → 相手の部品をクリック
          <br />
          または 部品を選んで C キー → 相手の部品をクリック
          <br />
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
      </section>
    </aside>
  );
}
