import unittest,json,csv,hashlib,re,zipfile
from pathlib import Path
R=Path(__file__).resolve().parents[2];O=Path('/home/m2u/eipsi/wp-eco/informes/EIPSI-Forms/PERF-2-evidence')
def read(n):return json.loads((O/n).read_text())
def csvrows(n):
 with (O/n).open() as f:return list(csv.DictReader(f))
class Evidence(unittest.TestCase):
 def test_product_frozen(self):
  h=read('product-before.json');self.assertEqual(h,read('product-after.json'))
  for n,v in h.items():self.assertEqual(hashlib.sha256((R/n).read_bytes()).hexdigest(),v,n)
 def test_approved_reference_and_golden(self):
  h=json.loads((O.parent/'PERF-1-evidence/product-before.json').read_text());self.assertEqual(len(h),790)
  for n,v in h.items():self.assertEqual(hashlib.sha256((R/n).read_bytes()).hexdigest(),v,n)
  self.assertEqual(hashlib.sha256((R/'tests/ux-f1a/before-contract.json').read_bytes()).hexdigest(),'2f66abcc690211e0dde4ace6287a200dc17d6650f16344e97e1409147bb64173')
 def test_full(self):self.assertEqual(read('baseline-full-summary.json')['exit_code'],0)
 def test_completed_cohorts(self):
  for cohort in ['experiment','control-original']:self.assertEqual(read(cohort+'/run.json')['exit_code'],0);self.assertTrue(all(c['pass'] for c in read(cohort+'/checks.json')))
 def test_measurements_and_a_reads(self):
  rs=csvrows('candidate-a.csv');self.assertEqual(len(rs),160)
  for s in ['P3','P10']:
   for v in ['original','baseline','A1','B1']:self.assertEqual(len([r for r in rs if r['scenario']==s and r['variant']==v]),20)
   a=[r for r in rs if r['scenario']==s and r['variant']=='A1'];b=[r for r in rs if r['scenario']==s and r['variant']=='baseline']
   for x,y in zip(a,b):
    for field in ['version_reads','locking_reads','transaction_boundaries','db_statements']:self.assertEqual(x[field],y[field])
    self.assertEqual(x['compiled_constructed'],'1');self.assertEqual(int(x['hits']),int(x['compiled_requested'])-1)
 def test_b_intended_query_savings(self):
  rs=csvrows('candidate-b.csv')
  for s in ['P3','P10']:
   a=[r for r in rs if r['scenario']==s and r['variant']=='B1'];b=[r for r in rs if r['scenario']==s and r['variant']=='baseline']
   for x,y in zip(a,b):self.assertEqual(int(y['db_statements'])-int(x['db_statements']),int(x['guard_hits']));self.assertEqual(x['transaction_boundaries'],y['transaction_boundaries'])
 def test_happy_path_exact_but_not_failure_equivalent(self):
  e=read('equivalence.json');self.assertFalse(e['candidate_a_failure_equivalence']);self.assertFalse(e['candidate_b_failure_equivalence'])
  for s in ['P3','P10']:
   for v in ['A1','B1']:
    r=e['scenarios'][s][v];self.assertTrue(r['exact_full_logical_rows']);self.assertTrue(r['exact_transaction_boundaries']);self.assertFalse(r['id_normalization']);self.assertFalse(r['timestamp_normalization'])
   self.assertTrue(e['scenarios'][s]['A1']['exact_direct_sql_and_locks'])
 def test_counterexamples(self):
  f=read('failure-paths.json');a,b=f['candidate_a_guard'];self.assertEqual(a['outcome'],'SUCCESS_SAME_GRAPH');self.assertEqual(b['outcome']['class'],'Error')
  dd=f['ddl_checks'];self.assertTrue(all(c['pass'] for c in dd))
  for k in ['engine','drop','rename','remote connection loss']:self.assertTrue(any('COUNTEREXAMPLE' in c['case'] and k in c['case'] for c in dd))
 def test_concurrency_and_failure_coverage(self):
  c=read('concurrency.json')['checks'];self.assertTrue(c);self.assertTrue(all(r['pass'] for r in c))
  for pattern in ['duplicate concurrent','CAS one winner','enrollment race']:self.assertTrue(any(pattern in r['case'] for r in c))
  f=read('failure-paths.json')['owner_checks']
  for pattern in ['rollback','expired','stale revision','reused command']:self.assertTrue(any(pattern in r['case'] for r in f))
 def test_package_and_privacy(self):
  with zipfile.ZipFile(R/'.cache/production-operations/EIPSI-Forms-Plugin-validation.zip') as z:self.assertFalse(any('/tests/' in n or 'perf2' in n.lower() for n in z.namelist()))
  for p in (R/'includes').rglob('*.php'):self.assertNotIn(b'Perf2Context',p.read_bytes())
  for n in ['equivalence.json','failure-paths.json','concurrency.json','experiment/contexts.jsonl']:
   s=(O/n).read_text();self.assertNotRegex(s,r'\b(?:PT|SE|PS|CP|NX|NV)_[a-f0-9]{16,}');self.assertNotRegex(s,r'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}');self.assertNotIn('eipsi_cap=',s)
if __name__=='__main__':unittest.main()
