import re
p = r'c:/laragon/www/ARSHLINE/wp-content/plugins/arshline/src/Dashboard/dashboard-template.php'
with open(p,'r',encoding='utf-8') as f:
    txt = f.read()
scripts = re.findall(r'<script[^>]*>([\s\S]*?)</script>', txt, flags=re.I)
s = scripts[1]
offs = [72,104,2191,2209,2319]
for o in offs:
    start = max(0,o-80)
    end = min(len(s), o+80)
    seg = s[start:end]
    # compute line number
    lines_before = s[:o].count('\n')+1
    print('\n--- offset', o, 'line ~', lines_before, '---')
    print(seg.replace('\n','\n'))
