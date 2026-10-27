import { memo } from 'react';
import { laneHeight } from '../model/geometry';
import { LANE_H_MAX, LANE_H_MIN, type Lane as LaneT } from '../model/types';
import { resizeLane } from '../store/actions';

type Props = { lanes: LaneT[]; width: number; selId: string | null };

/** レーン一式と、担当者とシステムの区切り線 */
export const LaneLayer = memo(function LaneLayer({ lanes, width, selId }: Props) {
  let y = 0;
  let dividerTop = -1;
  const rows = lanes.map((l, i) => {
    const top = y;
    y += laneHeight(l);
    if (l.kind === 'system' && dividerTop < 0 && i > 0) dividerTop = top;
    return <Lane key={l.id} lane={l} top={top} width={width} selected={l.id === selId} />;
  });
  return (
    <div>
      {rows}
      {dividerTop > 0 && (
        <div className="divider" style={{ top: dividerTop, width }}>
          <span>ここから下はシステム（データの置き場所）</span>
        </div>
      )}
    </div>
  );
});

const Lane = memo(function Lane({ lane, top, width, selected }: { lane: LaneT; top: number; width: number; selected: boolean }) {
  const h = laneHeight(lane);
  return (
    <div className={`lane ${lane.kind}${selected ? ' sel' : ''}`} style={{ top, width, height: h }}>
      <div className="head" data-lane={lane.id} title={lane.desc || undefined}>
        <small>{lane.kind === 'actor' ? '担当者' : 'システム'}</small>
        <div>{lane.name}</div>
        <div className="lane-size">
          <button className="icon sm" aria-label={`${lane.name}の枠を狭く`} title="狭く" disabled={h <= LANE_H_MIN} onClick={() => resizeLane(lane.id, -1)}>
            狭く
          </button>
          <button className="icon sm" aria-label={`${lane.name}の枠を広く`} title="広く" disabled={h >= LANE_H_MAX} onClick={() => resizeLane(lane.id, 1)}>
            広く
          </button>
        </div>
      </div>
      <div className="body" />
      {/* 下の境目をドラッグして高さを変える */}
      <div className="lane-resize" data-lane={lane.id} title="ドラッグで枠の高さを変える" />
    </div>
  );
});
