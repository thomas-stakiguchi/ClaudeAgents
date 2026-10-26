import { clampNode } from '../model/geometry';
import type { Edge, EdgeKind, Lane, LaneKind, Node, NodeType } from '../model/types';
import { deleteLane, deleteSel, setLaneKind } from '../store/actions';
import { edit, useStore } from '../store/flowStore';

export function SidebarRight() {
  const flow = useStore((s) => s.flow);
  const sel = useStore((s) => s.sel);
  const confirmDel = useStore((s) => s.confirmDel);
  if (!flow) return <aside className="side right" />;

  if (sel?.kind === 'node') {
    const n = flow.nodes.find((x) => x.id === sel.id);
    if (n) {
      const set = (fn: (d: Node) => void, coalesce?: string) =>
        edit((f) => {
          const d = f.nodes.find((x) => x.id === n.id);
          if (d) fn(d);
        }, { coalesce });
      return (
        <aside className="side right">
          <section key={n.id}>
            <h3>部品</h3>
            <div className="field">
              <label htmlFor="p-label">名前</label>
              <textarea id="p-label" value={n.label} onChange={(e) => set((d) => void (d.label = e.target.value), `label:${n.id}`)} />
            </div>
            <div className="field">
              <label>種類</label>
              <select value={n.type} onChange={(e) => set((d) => { d.type = e.target.value as NodeType; clampNode(d); })}>
                <option value="start">開始</option>
                <option value="end">終了</option>
                <option value="task">作業</option>
                <option value="decision">判断</option>
                <option value="data">データ</option>
              </select>
            </div>
            <div className="field">
              <label>レーン</label>
              <select value={n.lane} onChange={(e) => set((d) => { d.lane = e.target.value; clampNode(d); })}>
                {flow.lanes.map((l) => (
                  <option key={l.id} value={l.id}>{l.name}</option>
                ))}
              </select>
            </div>
            <div className="field">
              <label>メモ（図には出ません）</label>
              <textarea value={n.note ?? ''} onChange={(e) => set((d) => void (d.note = e.target.value), `note:${n.id}`)} />
            </div>
            <button className="danger" onClick={deleteSel}>この部品を削除</button>
          </section>
        </aside>
      );
    }
  }

  if (sel?.kind === 'edge') {
    const e = flow.edges.find((x) => x.id === sel.id);
    if (e) {
      const a = flow.nodes.find((n) => n.id === e.from), b = flow.nodes.find((n) => n.id === e.to);
      const set = (fn: (d: Edge) => void, coalesce?: string) =>
        edit((f) => {
          const d = f.edges.find((x) => x.id === e.id);
          if (d) fn(d);
        }, { coalesce });
      return (
        <aside className="side right">
          <section key={e.id}>
            <h3>矢印</h3>
            <dl className="kv" style={{ marginBottom: 10 }}>
              <dt>元</dt><dd>{a?.label ?? '?'}</dd>
              <dt>先</dt><dd>{b?.label ?? '?'}</dd>
            </dl>
            <div className="field">
              <label>種類</label>
              <select value={e.kind} onChange={(ev) => set((d) => void (d.kind = ev.target.value as EdgeKind))}>
                <option value="flow">業務の流れ（実線）</option>
                <option value="data">データの流れ（点線）</option>
              </select>
            </div>
            <div className="field">
              <label htmlFor="p-elabel">ラベル（条件・受け渡す内容）</label>
              <input type="text" id="p-elabel" value={e.label ?? ''} onChange={(ev) => set((d) => void (d.label = ev.target.value), `elabel:${e.id}`)} />
            </div>
            <button className="sm" onClick={() => set((d) => void ([d.from, d.to] = [d.to, d.from]))}>向きを反転</button>
            <button className="sm danger" style={{ marginLeft: 6 }} onClick={deleteSel}>削除</button>
          </section>
        </aside>
      );
    }
  }

  if (sel?.kind === 'lane') {
    const l = flow.lanes.find((x) => x.id === sel.id);
    if (l) {
      const cnt = flow.nodes.filter((n) => n.lane === l.id).length;
      const set = (fn: (d: Lane) => void, coalesce?: string) =>
        edit((f) => {
          const d = f.lanes.find((x) => x.id === l.id);
          if (d) fn(d);
        }, { coalesce });
      return (
        <aside className="side right">
          <section key={l.id}>
            <h3>{l.kind === 'actor' ? 'Actor' : 'システム'}</h3>
            <div className="field">
              <label htmlFor="p-lane-name">名前</label>
              <input type="text" id="p-lane-name" value={l.name} onChange={(e) => set((d) => void (d.name = e.target.value), `lname:${l.id}`)} />
            </div>
            <div className="field">
              <label>区分</label>
              <select value={l.kind} onChange={(e) => setLaneKind(l.id, e.target.value as LaneKind)}>
                <option value="actor">Actor（担当・部署）</option>
                <option value="system">関連システム</option>
              </select>
            </div>
            <div className="field">
              <label>説明</label>
              <textarea value={l.desc ?? ''} onChange={(e) => set((d) => void (d.desc = e.target.value), `ldesc:${l.id}`)} />
            </div>
            <p className="hint" style={{ marginBottom: 10 }}>このレーンの部品：{cnt} 個</p>
            <button className="danger" onClick={() => deleteLane(l.id)}>レーンを削除</button>
            {confirmDel === l.id && (
              <p className="hint warn">部品が {cnt} 個あります。もう一度「レーンを削除」を押すと部品ごと消えます。</p>
            )}
          </section>
        </aside>
      );
    }
  }

  const count = (k: string) => flow.lanes.filter((l) => l.kind === k).length;
  return (
    <aside className="side right">
      <section>
        <h3>プロパティ</h3>
        <p className="hint">部品・矢印・レーンをクリックすると、ここで編集できます。</p>
      </section>
      <section>
        <h3>この図</h3>
        <div className="field">
          <label htmlFor="p-title">タイトル</label>
          <input type="text" id="p-title" value={flow.title} onChange={(e) => edit((d) => void (d.title = e.target.value), { coalesce: 'title' })} />
        </div>
        <dl className="kv">
          <dt>Actor</dt><dd>{count('actor')}</dd>
          <dt>システム</dt><dd>{count('system')}</dd>
          <dt>部品</dt><dd>{flow.nodes.length}</dd>
          <dt>業務の矢印</dt><dd>{flow.edges.filter((e) => e.kind === 'flow').length}</dd>
          <dt>データの矢印</dt><dd>{flow.edges.filter((e) => e.kind === 'data').length}</dd>
        </dl>
      </section>
    </aside>
  );
}
