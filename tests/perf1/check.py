#!/usr/bin/env python3
import csv,gzip,hashlib,json,os,re,unittest,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];O=Path(os.environ['PERF1_OUTPUT'])
def read(n,base=O):return json.loads((base/n).read_text())
def gz(n,lines=False):
 with gzip.open(O/n,'rt') as f:return [json.loads(x) for x in f] if lines else json.load(f)
class Checks(unittest.TestCase):
 def test_product_frozen(self):
  data=read('product-before.json');self.assertTrue(data)
  for name,h in data.items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),h,name)
  for name,h in read('copy-manifest-before.json').items():self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),h,name)
 def test_baseline_full(self):self.assertEqual(read('baseline-full-summary.json')['exit_code'],0)
 def test_fixture_contracts(self):
  for folder in [O,O/'control-original']:
   data=read('checks.json',folder);self.assertTrue(data);self.assertTrue(all(x['pass'] for x in data))
   for scenario in ['P3','P10']:
    for i in range(1,9):self.assertTrue(any(x['case'].startswith(scenario+'-E'+str(i)+' ') for x in data))
 def test_replay_exact_private_state(self):
  a=read('logical-snapshots.json')
  self.assertTrue(a['P3']['exact_private_rows_equal']);self.assertTrue(a['P10']['exact_private_rows_equal']);self.assertTrue(a['P10']['exact_response_equal'])
  self.assertEqual(a['P3']['before'],a['P3']['after']);self.assertEqual(a['P10']['before'],a['P10']['after'])
 def test_complete_profile_counts(self):
  data=[x for x in gz('method-profiles.json.gz') if x['scenario']=='P10' and x['repetition']!='warmup']
  data3=[x for x in gz('http-profiles.jsonl.gz',True) if x['scenario']=='P3' and x['repetition']!='warmup'];self.assertEqual(len(data),20);self.assertEqual(len(data3),20)
  for r in data+data3:self.assertFalse(r['trace_truncated']);self.assertEqual(sum(q['count'] for q in r['queries']),r['db_statements']);self.assertGreater(r['transactions'],0)
  for r in data:self.assertEqual(sum(v['self_db_statements'] for v in r['calls'].values()),r['db_statements'])
 def test_contexts_are_counts_not_identifiers(self):
  for r in gz('method-profiles.json.gz'):
   if r['scenario']=='P10':
    v=[v for key,v in r['calls'].items() if 'StudyVersionRepository::compiled@' in key][0];self.assertEqual(v['distinct_input_contexts'],1);self.assertEqual(sum(v['object_call_histogram']),v['calls'])
 def test_sanitized_data(self):
  data=[gz('method-profiles.json.gz'),gz('http-profiles.jsonl.gz',True),read('logical-snapshots.json'),read('results.json'),read('crypto.json')]
  for d in data:
   text=json.dumps(d);self.assertNotRegex(text,r'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}');self.assertNotRegex(text,r'\b(?:PT|SE|PS|CP|NX|NV)_[a-f0-9]{16,}');self.assertNotIn('SELECT * FROM',text);self.assertNotIn('eipsi_cap=',text)
 def test_package_isolation(self):
  with zipfile.ZipFile(ROOT/'.cache/production-operations/EIPSI-Forms-Plugin-validation.zip') as z:self.assertFalse(any('perf1' in p.lower() or '/tests/' in p for p in z.namelist()))
  for f in (ROOT/'includes').rglob('*.php'):self.assertNotIn(b'Perf1Profiler',f.read_bytes())
if __name__=='__main__':unittest.main()
