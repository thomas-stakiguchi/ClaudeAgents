import { deleteLane } from '../store/actions';
import { deleteFlow, setState, useStore } from '../store/flowStore';
import { Modal } from './Modal';

/** 削除の確認。「はい」で消す、「いいえ」で何もしない（confirmDel に対象が入ると開く） */
export function ConfirmDelete() {
  const target = useStore((s) => s.confirmDel);
  const flow = useStore((s) => s.flow);
  if (!target || !flow) return null;

  const lane = target === 'flow' ? null : flow.lanes.find((l) => l.id === target);
  if (target !== 'flow' && !lane) return null;
  const cnt = lane ? flow.nodes.filter((n) => n.lane === lane.id).length : 0;

  const cancel = () => setState({ confirmDel: null });
  const yes = () => {
    if (lane) deleteLane(lane.id); // confirmDel が同じ id なのでそのまま消える
    else {
      setState({ confirmDel: null });
      void deleteFlow();
    }
  };

  return (
    <Modal title="削除の確認" onClose={cancel}>
      <p className="confirm-q">本当に削除しますか？</p>
      <p className="hint">
        {lane
          ? `「${lane.name}」の枠と、中の部品 ${cnt} 個を消します。Ctrl+Z で元に戻せます。`
          : `「${flow.title}」の図を消します。元には戻せません。`}
      </p>
      <div className="actions">
        <button className="btn" data-autofocus onClick={cancel}>いいえ</button>
        <button className="btn danger" onClick={yes}>はい</button>
      </div>
    </Modal>
  );
}
