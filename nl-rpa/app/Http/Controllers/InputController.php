<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** 入力データ（CSV）の取り込み。読み込み時に内容を取り込んで固定する（SEC-14） */
class InputController extends Controller
{
    private const MAX_BYTES = 1_048_576;
    private const MAX_ROWS = 10000;
    private const MAX_COLUMNS = 50;

    public function store(Request $request)
    {
        $by = $this->operator($request);
        $file = $request->file('csv');
        if ($file === null || ! $file->isValid() || $file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            return back()->withErrors(['csv' => 'CSVファイルを選んでください（1MBまで）']);
        }
        $text = (string) file_get_contents($file->getRealPath());
        $text = preg_replace('/\A\xEF\xBB\xBF/', '', $text) ?? $text; // BOM
        if (! mb_check_encoding($text, 'UTF-8')) {
            return back()->withErrors(['csv' => 'UTF-8のCSVにしてください']);
        }
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        $header = fgetcsv($fh, 0, ',', '"', '');
        if (! is_array($header) || $header === [null] || count($header) > self::MAX_COLUMNS) {
            return back()->withErrors(['csv' => '1行目に、列名が必要です']);
        }
        if (count(array_unique($header)) !== count($header) || in_array('', $header, true)) {
            return back()->withErrors(['csv' => '列名が、空か重複しています']);
        }
        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($r === [null]) {
                continue; // 空行
            }
            if (count($r) !== count($header)) {
                return back()->withErrors(['csv' => (count($rows) + 2).'行目の列数が、列名の数と違います']);
            }
            $rows[] = array_combine($header, array_map('strval', $r));
            if (count($rows) > self::MAX_ROWS) {
                return back()->withErrors(['csv' => '行数が多すぎます（'.self::MAX_ROWS.'行まで）']);
            }
        }
        fclose($fh);
        if ($rows === []) {
            return back()->withErrors(['csv' => 'データの行がありません']);
        }
        $id = $this->engine->store->saveInputSet($rows, $by);
        $this->audit('input_imported', 'input:'.$id, $by, ['input_set_id' => $id, 'sha256' => $this->engine->store->getInputSet($id)['sha256'], 'rows' => count($rows)]);

        return redirect('/')->with('ok', "入力データを取り込みました（番号 {$id}、".count($rows).'行）');
    }
}
