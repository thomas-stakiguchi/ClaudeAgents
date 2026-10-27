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
  // 色を変えた線の矢じり。色ごとに1つ作る
  const colors = useMemo(() => [...new Set(edges.map((e) => e.color).filter((c): c is string => !!c))], [edges]);
  const markerOf = useMemo(() => new Map(colors.map((c, i) => [c, `arC${i}`])), [colors]);
  return (
    <svg className="edges" width={width} height={height}>
      <defs>
        {(['arF', 'arD'] as const).map((id) => (
          <marker key={id} id={id} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">
            <path className={id} d="M0 0L10 5L0 10z" style={{ fill: MARKER_FILL[id] }} />
          </marker>
        ))}
        {colors.map((c) => (
          <marker key={c} id={markerOf.get(c)} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">
            <path d="M0 0L10 5L0 10z" style={{ fill: c }} />
          </marker>
        ))}
      </defs>
      {edges.map((e) => {
        const a = byId.get(e.from), b = byId.get(e.to);
        if (!a || !b) return null;
        const selected = e.id === selId;
        const marker = e.color ? markerOf.get(e.color)! : e.kind === 'flow' ? 'arF' : 'arD';
        return <EdgeView key={e.id} edge={e} a={a} b={b} aTop={tops.get(a.lane) ?? 0} bTop={tops.get(b.lane) ?? 0} selected={selected} marker={marker} />;
      })}
      {pending && <path className="line pending" d={pending} style={{ fill: 'none', stroke: 'var(--ok)', strokeWidth: 2, strokeDasharray: '4 4' }} />}
    </svg>
  );
});

type EdgeProps = { edge: Edge; a: Node; b: Node; aTop: number; bTop: number; selected: boolean; marker: string };

const EdgeView = memo(function EdgeView({ edge, a, b, aTop, bTop, selected, marker }: EdgeProps) {
  const { d, mx, my } = edgePath(nodeRect(a, aTop), nodeRect(b, bTop), edge);
  return (
    <g>
      <path className="hit" d={d} data-id={edge.id} style={{ fill: 'none', stroke: 'transparent' }} />
      {/* 選んでいる線は色を変えず、下に太い帯を敷いて目立たせる（変えた色をそのまま確かめられるように） */}
      {selected && <path d={d} style={SEL_HALO} />}
      <path className={`line ${edge.kind}${selected ? ' sel' : ''}`} d={d} markerEnd={`url(#${marker})`} style={lineStyle(edge, selected)} />
      {edge.label && (
        <text x={mx} y={my - 6} textAnchor="middle" style={TEXT_STYLE}>
          {edge.label}
        </text>
      )}
    </g>
  );
});

// 画像書き出し（html-to-image）では SVG にクラスの CSS が効かないので、見た目はインラインで持たせる
const MARKER_FILL = { arF: 'var(--flow)', arD: 'var(--dflow)' } as const;

function lineStyle({ kind, color }: Edge, selected: boolean): React.CSSProperties {
  return {
    fill: 'none',
    stroke: color ?? (kind === 'flow' ? 'var(--flow)' : 'var(--dflow)'),
    strokeWidth: selected ? 2.6 : color ? 2.2 : 1.8,
    strokeDasharray: kind === 'data' ? '6 4' : undefined,
  };
}

const SEL_HALO: React.CSSProperties = { fill: 'none', stroke: 'var(--sel)', strokeWidth: 10, strokeOpacity: 0.55, strokeLinecap: 'round' };

const TEXT_STYLE: React.CSSProperties = {
  fontFamily: 'var(--font)',
  fontSize: 11,
  fill: 'var(--fg)',
  paintOrder: 'stroke',
  stroke: 'var(--bg)',
  strokeWidth: 4,
};
