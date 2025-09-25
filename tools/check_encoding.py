#!/usr/bin/env python3
import os

root = r"C:/laragon/www/ARSHLINE/wp-content/plugins/arshline"
skip_ext = {'.png', '.jpg', '.jpeg', '.gif', '.ico', '.woff', '.woff2', '.ttf', '.otf', '.eot', '.pdf', '.zip', '.tar', '.gz', '.7z', '.exe', '.dll', '.bin'}
problems = []
this_file = os.path.abspath(__file__)

for dirpath, dirs, files in os.walk(root):
    # skip VCS and common large vendor/build dirs to avoid binary blobs
    dirs[:] = [d for d in dirs if d not in ('.git', 'node_modules', 'vendor', 'dist', 'build')]
    for f in files:
        path = os.path.join(dirpath, f)
        ext = os.path.splitext(f)[1].lower()
        if ext in skip_ext:
            continue
        try:
            b = open(path, 'rb').read()
        except Exception as e:
            problems.append((path, 'read_error', str(e)))
            continue
        try:
            s = b.decode('utf-8')
        except Exception as e:
            problems.append((path, 'utf8_decode_error', str(e)))
            continue
        # skip the scanner file itself to avoid self-reporting
        if os.path.abspath(path) == this_file:
            continue
        if '\ufffd' in s:
            problems.append((path, 'replacement_char', None))
            continue
        if '???' in s:
            problems.append((path, 'triple_question', None))
            continue

if not problems:
    print('NO_PROBLEMS_FOUND')
else:
    for p in problems:
        print('\t'.join([p[0], p[1], p[2] or '']))
