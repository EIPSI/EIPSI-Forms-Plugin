#!/usr/bin/env python3
"""Read-only comparison of captures; historical collations are reported separately."""
import json, re, sys
from pathlib import Path

a,b=(json.loads(Path(p).read_text()) for p in sys.argv[1:3])
result={'missing_tables':sorted(set(a['tables'])^set(b['tables'])),'column_differences':[],'index_differences':[],'collation_differences':[],'foreign_key_differences':[]}
for slug in sorted(a['tables'].keys()&b['tables'].keys()):
    left,right=a['tables'][slug],b['tables'][slug]
    def columns(table):
        return {c['Field']:{k:c.get(k) for k in ['Type','Null','Default','Extra','Comment']} for c in table['columns']}
    def indices(table):
        return sorted((x['Key_name'],x['Seq_in_index'],x['Column_name'],x['Non_unique'],x.get('Sub_part')) for x in table['indexes'])
    if columns(left)!=columns(right):result['column_differences'].append(slug)
    if indices(left)!=indices(right):result['index_differences'].append(slug)
    if {x['Field']:x['Collation'] for x in left['columns']}!={x['Field']:x['Collation'] for x in right['columns']}:result['collation_differences'].append(slug)
    if sorted(re.findall(r'CONSTRAINT .*FOREIGN KEY.*',left['ddl']))!=sorted(re.findall(r'CONSTRAINT .*FOREIGN KEY.*',right['ddl'])):result['foreign_key_differences'].append(slug)
print(json.dumps(result,indent=2))
sys.exit(any(result[key] for key in result if key!='collation_differences'))
