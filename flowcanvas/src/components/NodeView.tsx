import { memo, useRef } from 'react';
import { commitEdit } from '../store/actions';
import { setState } from '../store/flowStore';
import { isIconType, nodeSize, type Node } from '../model/types';
import { NodeIcon } from './Icons';

type Props = { node: Node; top: number; selected: boolean; isSrc: boolean; editing: boolean };

const PORTS = [
  ['top', '50%', '0'],
  ['right', '100%', '50%'],
  ['bottom', '50%', '100%'],
  ['left', '0', '50%'],
] as const;

export const NodeView = memo(function NodeView({ node, top, selected, isSrc, editing }: Props) {
  const [w, h] = nodeSize(node.type, node.label);
  return (
    <div
      className={`nwrap${selected ? ' sel' : ''}${isSrc ? ' src' : ''}`}
      data-id={node.id}
      style={{ left: node.x, top: top + node.ly, width: w, height: h }}
      title={node.note || undefined}
    >
      {isIconType(node.type) ? (
        <div className={`node icon ${node.type}`}>
          <NodeIcon type={node.type} size={40} />
          <span>{node.label}</span>
        </div>
      ) : (
        <div className={`node ${node.type}`}>
          <span>{node.label}</span>
        </div>
      )}
      {editing && <InlineEditor node={node} />}
      {!editing && PORTS.map(([p, left, t]) => (
        <div key={p} className="port" data-port={p} style={{ left, top: t }} />
      ))}
    </div>
  );
});

type LayerProps = { nodes: Node[]; tops: Map<string, number>; selIds: Set<string>; srcId: string | null; editingId: string | null };

export const NodeLayer = memo(function NodeLayer({ nodes, tops, selIds, srcId, editingId }: LayerProps) {
  return (
    <div>
      {nodes.map((n) => (
        <NodeView key={n.id} node={n} top={tops.get(n.lane) ?? 0} selected={selIds.has(n.id)} isSrc={n.id === srcId} editing={n.id === editingId} />
      ))}
    </div>
  );
});

/** 部品の上に重ねる名前の入力欄。Enter で確定、Shift+Enter で改行、Esc で取り消し */
function InlineEditor({ node }: { node: Node }) {
  const done = useRef(false);
  const finish = (value: string | null) => {
    if (done.current) return;
    done.current = true;
    if (value === null) setState({ editing: null });
    else commitEdit(node.id, value);
  };
  return (
    <textarea
      className="inline-edit"
      aria-label="部品の名前"
      defaultValue={node.label}
      autoFocus
      onFocus={(e) => e.currentTarget.select()}
      onPointerDown={(e) => e.stopPropagation()}
      onDoubleClick={(e) => e.stopPropagation()}
      onKeyDown={(e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
          e.preventDefault();
          e.stopPropagation(); // 同じ Enter で「名前を書く」がまた始まらないように
          finish(e.currentTarget.value);
        } else if (e.key === 'Escape') {
          e.preventDefault();
          e.stopPropagation();
          finish(null);
        }
      }}
      onBlur={(e) => finish(e.currentTarget.value)}
    />
  );
}
