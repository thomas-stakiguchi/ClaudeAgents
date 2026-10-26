import { toJpeg, toPng } from 'html-to-image';
import { jsPDF } from 'jspdf';
import { contentRight } from '../model/geometry';
import { LANE_H, type Flow } from '../model/types';
import { download, fileName } from './text';

const PAD = 20;

type Opts = Parameters<typeof toPng>[1];

async function capture(world: HTMLElement, flow: Flow, fmt: 'png' | 'jpeg') {
  // 図の全体（画面に映っている範囲ではない）を余白20pxで切り出す
  const w = contentRight(flow) + 40;
  const h = Math.max(1, flow.lanes.length) * LANE_H;
  const bg = getComputedStyle(document.body).backgroundColor;
  const opts: Opts = {
    width: w + PAD * 2,
    height: h + PAD * 2,
    backgroundColor: bg,
    pixelRatio: 2,
    style: { transform: `translate(${PAD}px,${PAD}px)`, left: '0', top: '0' },
  };
  const fn = fmt === 'png' ? toPng : toJpeg;
  try {
    return await fn(world, fmt === 'jpeg' ? { ...opts, quality: 0.95 } : opts);
  } catch {
    // Webフォントが読めない環境ではフォント埋め込みを諦めて再挑戦
    return await fn(world, { ...opts, skipFonts: true });
  }
}

export async function exportImage(world: HTMLElement, flow: Flow, kind: 'png' | 'jpg' | 'pdf') {
  if (kind === 'png') return download(await capture(world, flow, 'png'), fileName(flow.title, 'png'));
  if (kind === 'jpg') return download(await capture(world, flow, 'jpeg'), fileName(flow.title, 'jpg'));

  // PDF：横向き A3。収まらなければ縮小（拡大はしない）
  const url = await capture(world, flow, 'jpeg'); // PNG だとPDFが数十MBになるので JPEG で貼る
  const img = await new Promise<HTMLImageElement>((ok, ng) => {
    const i = new Image();
    i.onload = () => ok(i);
    i.onerror = ng;
    i.src = url;
  });
  const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a3' });
  const pw = pdf.internal.pageSize.getWidth(), ph = pdf.internal.pageSize.getHeight();
  const margin = 10;
  const pxToMm = 25.4 / 96 / 2; // pixelRatio 2 で撮っているので半分
  const iw = img.width * pxToMm, ih = img.height * pxToMm;
  const s = Math.min(1, (pw - margin * 2) / iw, (ph - margin * 2) / ih);
  pdf.addImage(url, 'JPEG', margin, margin, iw * s, ih * s);
  pdf.save(fileName(flow.title, 'pdf'));
}
