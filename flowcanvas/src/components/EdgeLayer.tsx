import { memo, useMemo } from 'react';
import { edgePath, nodeRect } from '../model/geometry';
import type { Edge, Node } from '../model/types';

type Props = {
  edges: Edge[];
  nodes: Node[];
  tops: Map<string, number>;
  selId: string | null;
  width: number;
  height: number;
  pending: string | null; // 接続中の仮の線（path の d）
};

export const EdgeLayer = memo(function EdgeLayer({ edges, nodes, tops, selId, width, height, pending }: Props) {
  const byId = useMemo(() => new Map(nodes.map((n) => [n.id, n])), [nodes]);
  return (
    <svg className="edges" width={width} height={height}>
      <defs>
        {(['arF', 'arD', 'arS'] as const).map((id) => (
          <marker key={id} id={id} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">
            <path className={id} d="M0 0L10 5L0 10z" style={{ fill: MARKER_FILL[id] }} />
          </marker>
        ))}
      </defs>
      {edges.map((e) => {
        const a = byId.get(e.from), b = byId.get(e.to);
        if (!a || !b) return null;
        return <EdgeView key={e.id} edge={e} a={a} b={b} aTop={tops.get(a.lane) ?? 0} bTop={tops.get(b.lane) ?? 0} selected={e.id === selId} />;
      })}
      {pending && <path className="line pending" d={pending} style={{ fill: 'none', stroke: 'var(--ok)', strokeWidth: 2, strokeDasharray: '4 4' }} />}
    </svg>
  );
});

type EdgeProps = { edge: Edge; a: Node; b: Node; aTop: number; bTop: number; selected: boolean };

const EdgeView = memo(function EdgeView({ edge, a, b, aTop, bTop, selected }: EdgeProps) {
  const { d, mx, my } = edgePath(nodeRect(a, aTop), nodeRect(b, bTop));
  const marker = selected ? 'arS' : edge.kind === 'flow' ? 'arF' : 'arD';
  return (
    <g>
      <path className="hit" d={d} data-id={edge.id} style={{ fill: 'none', stroke: 'transparent' }} />
      <path className={`line ${edge.kind}${selected ? ' sel' : ''}`} d={d} markerEnd={`url(#${marker})`} style={lineStyle(edge.kind, selected)} />
      {edge.label && (
        <text x={mx} y={my - 6} textAnchor="middle" style={TEXT_STYLE}>
          {edge.label}
        </text>
      )}
    </g>
  );
});

// 画像書き出し（html-to-image）では SVG にクラスの CSS が効かないので、見た目はインラインで持たせる
const MARKER_FILL = { arF: 'var(--flow)', arD: 'var(--dflow)', arS: 'var(--accent)' } as const;

function lineStyle(kind: Edge['kind'], selected: boolean): React.CSSProperties {
  return {
    fill: 'none',
    stroke: selected ? 'var(--accent)' : kind === 'flow' ? 'var(--flow)' : 'var(--dflow)',
    strokeWidth: selected ? 3 : 1.8,
    strokeDasharray: kind === 'data' ? '6 4' : undefined,
  };
}

const TEXT_STYLE: React.CSSProperties = {
  fontFamily: 'var(--font)',
  fontSize: 11,
  fill: 'var(--fg)',
  paintOrder: 'stroke',
  stroke: 'var(--bg)',
  strokeWidth: 4,
};
