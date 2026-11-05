#!/usr/bin/env python3
"""Instrumentation invariants, separate from the historical functional count."""
import hashlib,json,os,re,subprocess,sys,unittest,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];OUT=Path(os.environ['UX_F0_OUTPUT'])
class Invariants(unittest.TestCase):
 def test_product_unchanged(self):
  before=json.loads((OUT/'product-before.json').read_text())
  self.assertTrue(before)
  for name,digest in before.items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),digest,name)
 def test_immutable_contract(self):
  all_wrappers=json.loads((OUT/'all-instrumentation-equivalence.json').read_text());self.assertTrue(all_wrappers['equal']);result=json.loads((OUT/'equivalence.json').read_text());self.assertTrue(result['equal']);self.assertEqual(hashlib.sha256((ROOT/'tests/ux-f1a/before-contract.json').read_bytes()).hexdigest(),result['reference_sha256'])
 def test_opt_in(self):
  result=subprocess.run(['node','tests/ux-f0/client.cjs'],cwd=ROOT,env=dict(os.environ,EIPSI_UX_F0='0'),capture_output=True,text=True)
  self.assertNotEqual(result.returncode,0);self.assertIn('Explicit isolated profiling opt-in required',result.stderr)
 def test_coverage(self):
  import csv
  with (OUT/'performance-scenarios.csv').open() as handle:rows=list(csv.DictReader(handle))
  scenarios={r['scenario'] for r in rows}
  self.assertTrue({'P'+str(i) for i in range(1,19)}<=scenarios)
  for scenario in {'P'+str(i) for i in range(1,19)}:self.assertGreaterEqual(sum(r['scenario']==scenario and r['repetition']!='warmup' for r in rows),20)
 def test_database_coverage(self):
  data=[json.loads(x) for x in (OUT/'server-http.jsonl').read_text().splitlines()]
  self.assertTrue(all(x['pfs']['count']>=x['query_count'] and x['pfs']['ms']>=0 for x in data))
  self.assertTrue(any(x['pfs']['count']>x['query_count']*2 for x in data if x['scenario']=='P3'))
 def test_retry_contract(self):
  data=json.loads((OUT/'transport-experiments.json').read_text());retry=[x for x in data if x.get('kind') in ['before-response','ambiguous']]
  self.assertEqual(len(retry),2);self.assertTrue(all(x['retry_payload_equal'] for x in retry))
 def test_no_private_measurements(self):
  # Aggregates may contain source paths and cryptographic hashes of public sources,
  # never actual email values, query text, capability strings or runtime IDs.
  for name in ['client-scenarios.json','client-details.json','payloads.json','render-trace.json','transport-experiments.json','matrices.json','server-records.json','server-http.jsonl','server-queries.json','client-extra.json','server-payloads.json','server-assets.json','cpu-samples.json']:
   text=(OUT/name).read_text();self.assertNotRegex(text,r'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}');self.assertNotRegex(text,r'\b(?:NX|NV|SE|PT|CAP)_[a-f0-9]{16,}');self.assertNotIn('SELECT * FROM',text);self.assertNotIn('eipsi_cap=',text)
 def test_package_exclusion(self):
  package=ROOT/'.cache/production-operations/EIPSI-Forms-Plugin-validation.zip'
  with zipfile.ZipFile(package) as z:self.assertFalse(any('/tests/' in name or 'ux-f0' in name.lower() for name in z.namelist()))
 def test_no_production_hook(self):
  for folder in ['src','includes','admin']:
   for file in (ROOT/folder).rglob('*'):
    if file.is_file() and file.suffix in ['.php','.js','.json']:self.assertNotIn(b'__UXF0',file.read_bytes(),str(file))
if __name__=='__main__':unittest.main()
