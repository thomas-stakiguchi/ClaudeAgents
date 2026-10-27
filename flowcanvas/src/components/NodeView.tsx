import { memo } from 'react';
import { isIconType, nodeSize, type Node } from '../model/types';
import { NodeIcon } from './Icons';

type Props = { node: Node; top: number; selected: boolean; isSrc: boolean };

const PORTS = [
  ['top', '50%', '0'],
  ['right', '100%', '50%'],
  ['bottom', '50%', '100%'],
  ['left', '0', '50%'],
] as const;

export const NodeView = memo(function NodeView({ node, top, selected, isSrc }: Props) {
  const [w, h] = nodeSize(node.type);
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
      {PORTS.map(([p, left, t]) => (
        <div key={p} className="port" data-port={p} style={{ left, top: t }} />
      ))}
    </div>
  );
});

type LayerProps = { nodes: Node[]; tops: Map<string, number>; selId: string | null; srcId: string | null };

export const NodeLayer = memo(function NodeLayer({ nodes, tops, selId, srcId }: LayerProps) {
  return (
    <div>
      {nodes.map((n) => (
        <NodeView key={n.id} node={n} top={tops.get(n.lane) ?? 0} selected={n.id === selId} isSrc={n.id === srcId} />
      ))}
    </div>
  );
});
