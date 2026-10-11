#!/usr/bin/env python3
"""Genere schema_pg.sql (PostgreSQL) a partir de schema.sql (MySQL)."""
import re

src = open('schema.sql').read()

src = re.sub(r'\bINT AUTO_INCREMENT PRIMARY KEY\b', 'SERIAL PRIMARY KEY', src, flags=re.I)
src = re.sub(r'\bINT PRIMARY KEY AUTO_INCREMENT\b', 'SERIAL PRIMARY KEY', src, flags=re.I)
src = re.sub(r'\bTINYINT\(1\)', 'SMALLINT', src, flags=re.I)
src = re.sub(r'\bTINYINT\b', 'SMALLINT', src, flags=re.I)
src = re.sub(r'\bUNSIGNED\b', '', src, flags=re.I)
src = re.sub(r'\bDATETIME\b', 'TIMESTAMP', src, flags=re.I)
src = re.sub(r'\bDOUBLE\b', 'DOUBLE PRECISION', src, flags=re.I)
src = re.sub(r'\bLONGTEXT\b', 'TEXT', src, flags=re.I)
src = re.sub(r'\bMEDIUMTEXT\b', 'TEXT', src, flags=re.I)
src = re.sub(r'\s+ON UPDATE CURRENT_TIMESTAMP', '', src, flags=re.I)
src = re.sub(r'SET NAMES utf8mb4;', '', src)

out_lines = []
indexes = []
in_table = None

for line in src.splitlines():
    m = re.match(r'\s*CREATE TABLE IF NOT EXISTS\s+([a-zA-Z0-9_]+)\s*\(', line)
    if m:
        in_table = m.group(1)
        out_lines.append(line)
        continue

    if in_table:
        mk = re.match(r'\s*(UNIQUE\s+)?KEY\s+([a-zA-Z0-9_]+)\s*\(([^)]*)\)\s*,?\s*$', line, re.I)
        if mk:
            uniq, name, cols = mk.group(1), mk.group(2), mk.group(3)
            cols = cols.strip()
            if uniq:
                indexes.append('ALTER TABLE %s ADD CONSTRAINT %s UNIQUE (%s);' % (in_table, name, cols))
            else:
                indexes.append('CREATE INDEX IF NOT EXISTS %s ON %s (%s);' % (name, in_table, cols))
            continue

        if re.match(r'\s*\)\s*(ENGINE=InnoDB.*)?;', line):
            if out_lines and out_lines[-1].rstrip().endswith(','):
                out_lines[-1] = out_lines[-1].rstrip()[:-1]
            out_lines.append(');')
            out_lines.extend(indexes)
            indexes = []
            in_table = None
            continue

    out_lines.append(line)

text = '\n'.join(out_lines) + '\n'
# Donnees initiales : INSERT IGNORE -> INSERT ... ON CONFLICT DO NOTHING
text = re.sub(r'\bINSERT IGNORE INTO\b', 'INSERT INTO', text, flags=re.I)
text = re.sub(r"(INSERT INTO [^;]*?VALUES[^;]*?);", r"\1 ON CONFLICT DO NOTHING;", text, flags=re.S)

open('schema_pg.sql', 'w').write(text)
print("schema_pg.sql ecrit")