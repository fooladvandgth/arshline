import sys
f = sys.argv[1]
o = int(sys.argv[2])
with open(f, 'r', encoding='utf-8') as fh:
    s = fh.read()
start = max(0, o-400)
end = min(len(s), o+400)
pre = s[:o]
line = pre.count('\n') + 1
print('file len', len(s))
print('offset', o, 'approx line', line)
print('---context---')
print(s[start:end])
print('---end---')
