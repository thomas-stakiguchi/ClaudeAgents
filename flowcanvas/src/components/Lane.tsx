import { memo } from 'react';
import { LANE_H, type Lane as LaneT } from '../model/types';

type Props = { lanes: LaneT[]; width: number; selId: string | null };

/** レーン一式と DATA LAYER の区切り線 */
export const LaneLayer = memo(function LaneLayer({ lanes, width, selId }: Props) {
  const si = lanes.findIndex((l) => l.kind === 'system');
  return (
    <div>
      {lanes.map((l, i) => (
        <Lane key={l.id} lane={l} top={i * LANE_H} width={width} selected={l.id === selId} />
      ))}
      {si > 0 && (
        <div className="divider" style={{ top: si * LANE_H, width }}>
          <span>DATA LAYER</span>
        </div>
      )}
    </div>
  );
});

const Lane = memo(function Lane({ lane, top, width, selected }: { lane: LaneT; top: number; width: number; selected: boolean }) {
  return (
    <div className={`lane ${lane.kind}${selected ? ' sel' : ''}`} style={{ top, width, height: LANE_H }}>
      <div className="head" data-lane={lane.id} title={lane.desc || undefined}>
        <small>{lane.kind === 'actor' ? 'ACTOR' : 'SYSTEM'}</small>
        <div>{lane.name}</div>
      </div>
      <div className="body" />
    </div>
  );
});
