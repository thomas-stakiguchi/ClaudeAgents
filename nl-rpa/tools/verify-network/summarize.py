import json,sys
d=json.load(open(sys.argv[1] if len(sys.argv)>1 else 'result.json'))
rows=d['results']
layers=sorted({r['layer'] for r in rows})
for layer in layers:
    for evil in ('name','ip'):
        rs=[r for r in rows if r['layer']==layer and r['evil']==evil]
        if not rs: continue
        leaked=[r['case'] for r in rs if r['kind']!='base' and r['canary_hits']>0]
        base=[r for r in rs if r['kind']=='base']
        print(f"[{layer}/{evil}] 外へ届いた: {len(leaked)}/{len([r for r in rs if r['kind']!='base'])} {leaked} | 基準(許可画面)OK: {base[0]['app_ok_hit'] if base else '-'}")
