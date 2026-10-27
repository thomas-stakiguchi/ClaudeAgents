import { memo } from 'react';
import { LANE_H, type Lane as LaneT } from '../model/types';

type Props = { lanes: LaneT[]; width: number; selId: string | null };

/** レーン一式と、担当者とシステムの区切り線 */
export const LaneLayer = memo(function LaneLayer({ lanes, width, selId }: Props) {
  const si = lanes.findIndex((l) => l.kind === 'system');
  return (
    <div>
      {lanes.map((l, i) => (
        <Lane key={l.id} lane={l} top={i * LANE_H} width={width} selected={l.id === selId} />
      ))}
      {si > 0 && (
        <div className="divider" style={{ top: si * LANE_H, width }}>
          <span>ここから下はシステム（データの置き場所）</span>
        </div>
      )}
    </div>
  );
});

const Lane = memo(function Lane({ lane, top, width, selected }: { lane: LaneT; top: number; width: number; selected: boolean }) {
  return (
    <div className={`lane ${lane.kind}${selected ? ' sel' : ''}`} style={{ top, width, height: LANE_H }}>
      <div className="head" data-lane={lane.id} title={lane.desc || undefined}>
        <small>{lane.kind === 'actor' ? '担当者' : 'システム'}</small>
        <div>{lane.name}</div>
      </div>
      <div className="body" />
    </div>
  );
});
