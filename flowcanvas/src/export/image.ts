import { toJpeg, toPng } from 'html-to-image';
import { jsPDF } from 'jspdf';
import { contentRight } from '../model/geometry';
import { HEAD_W, LANE_H, type Flow } from '../model/types';
import { EXPORT_HEAD_H, type ExportOptions } from './options';
import { download, fileName } from './text';

const PAD = 20;
const MAX_PX = 16000; // ブラウザの画像の大きさの上限（約16,384px）より少し小さく

type Opts = Parameters<typeof toPng>[1];

async function capture(world: HTMLElement, flow: Flow, o: ExportOptions, fmt: 'png' | 'jpeg') {
  // 図の全体（画面に映っている範囲ではない）を余白20pxで切り出す。見出しを入れるときはその分上に足す
  const head = o.header ? EXPORT_HEAD_H : 0;
  const w = Math.max(contentRight(flow) + 40, o.header ? 720 : 0) + PAD * 2;
  const h = Math.max(1, flow.lanes.length) * LANE_H + head + PAD * 2;
  // 大きな図は上限を超えないよう解像度を下げる（ふつうは2倍）
  const pixelRatio = Math.min(2, MAX_PX / w, MAX_PX / h);
  const opts: Opts = {
    width: w,
    height: h,
    backgroundColor: o.plain ? '#ffffff' : getComputedStyle(document.body).backgroundColor,
    pixelRatio,
    style: { transform: `translate(${PAD}px,${PAD + head}px)`, left: '0', top: '0' },
  };
  const fn = fmt === 'png' ? toPng : toJpeg;
  let url: string;
  try {
    url = await fn(world, fmt === 'jpeg' ? { ...opts, quality: 0.95 } : opts);
  } catch {
    // Webフォントが読めない環境ではフォント埋め込みを諦めて再挑戦
    url = await fn(world, { ...opts, skipFonts: true });
  }
  return { url, cssW: w, cssH: h, head, bg: opts.backgroundColor! };
}

export async function exportImage(world: HTMLElement, flow: Flow, kind: 'png' | 'jpg' | 'pdf', o: ExportOptions) {
  if (kind === 'png') return download((await capture(world, flow, o, 'png')).url, fileName(flow.title, 'png'));
  if (kind === 'jpg') return download((await capture(world, flow, o, 'jpeg')).url, fileName(flow.title, 'jpg'));
  await exportPdf(world, flow, o);
}

/*
 * PDF：A3。図の形に合わせて横向き・縦向きを選ぶ。
 * 1ページに収めると文字が小さくなりすぎる（60%未満）ときは、横に分けて複数ページにする。
 */
async function exportPdf(world: HTMLElement, flow: Flow, o: ExportOptions) {
  const shot = await capture(world, flow, o, 'jpeg'); // PNG だとPDFが数十MBになるので JPEG で貼る
  const img = await loadImage(shot.url);
  const pxToMm = 25.4 / 96; // 画面の1px（96dpi）を mm に
  const iw = shot.cssW * pxToMm, ih = shot.cssH * pxToMm;
  const orientation = iw >= ih ? 'landscape' : 'portrait';
  const pdf = new jsPDF({ orientation, unit: 'mm', format: 'a3' });
  const pw = pdf.internal.pageSize.getWidth(), ph = pdf.internal.pageSize.getHeight();
  const M = 10, FOOT = 8; // 余白とページ番号の高さ
  const aw = pw - M * 2, ah = ph - M * 2 - FOOT;

  const one = Math.min(1, aw / iw, ah / ih);
  if (one >= 0.6) {
    pdf.addImage(shot.url, 'JPEG', M, M, iw * one, ih * one);
    pdf.save(fileName(flow.title, 'pdf'));
    return;
  }

  // 複数ページ：高さをページに合わせ、横を何枚かに分ける（つなぎ目が分かるよう少し重ねる）。
  // 2ページ目からも左端に枠の名前の列を付けて、どの枠か分かるようにする
  const s = Math.min(1, ah / ih);
  const pageCss = aw / s / pxToMm; // 1ページに入る幅（画面の px）
  const headCss = PAD + HEAD_W; // 枠の名前の列（左の余白を含む）
  const overlap = 40;
  const r = img.width / shot.cssW; // 画像の px / 画面の px
  const slices: { x0: number; w: number }[] = [{ x0: 0, w: Math.min(pageCss, shot.cssW) }];
  while (slices[slices.length - 1].x0 + slices[slices.length - 1].w < shot.cssW) {
    const last = slices[slices.length - 1];
    const x0 = last.x0 + last.w - overlap;
    slices.push({ x0, w: Math.min(pageCss - headCss, shot.cssW - x0) });
  }
  const pages = slices.length;
  const laneTop = (PAD + shot.head) * r; // 見出しより下（枠の部分）だけを左端に付ける
  for (let i = 0; i < pages; i++) {
    const { x0, w } = slices[i];
    const lead = i === 0 ? 0 : headCss;
    const c = document.createElement('canvas');
    c.width = Math.round((lead + w) * r);
    c.height = img.height;
    const ctx = c.getContext('2d')!;
    ctx.fillStyle = shot.bg;
    ctx.fillRect(0, 0, c.width, c.height);
    if (lead) ctx.drawImage(img, 0, laneTop, lead * r, img.height - laneTop, 0, laneTop, lead * r, img.height - laneTop);
    ctx.drawImage(img, Math.round(x0 * r), 0, Math.round(w * r), img.height, Math.round(lead * r), 0, Math.round(w * r), img.height);
    if (i > 0) pdf.addPage('a3', orientation);
    pdf.addImage(c.toDataURL('image/jpeg', 0.92), 'JPEG', M, M, (lead + w) * pxToMm * s, ih * s);
    pdf.setFontSize(9);
    pdf.text(`${i + 1} / ${pages}`, pw - M, ph - M / 2, { align: 'right' });
  }
  pdf.save(fileName(flow.title, 'pdf'));
}

function loadImage(url: string) {
  return new Promise<HTMLImageElement>((ok, ng) => {
    const i = new Image();
    i.onload = () => ok(i);
    i.onerror = ng;
    i.src = url;
  });
}
