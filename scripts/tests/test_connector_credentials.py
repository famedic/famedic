import json,sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from evidence_security import Scanner,sensitive_key
class ConnectorCredentialBoundaryTest(unittest.TestCase):
 def test_specific_names(self):
  for name in ['X-Consumer-Credential','x_consumer_credential','consumer_credential','machineCredential']:
   self.assertTrue(sensitive_key(name))
 def test_identifiers_stay_allowed(self):
  for name in ['case_id','assertion_id','operation_id','cart_id']:
   self.assertFalse(sensitive_key(name))
 def test_direct_and_nested(self):
  for obj in [{'X-Consumer-Credential':'synthetic-only-value'},{'nested':{'x_consumer_credential':'synthetic-only-value'}}]:
   s=Scanner();s.obj(obj);self.assertIn('SENSITIVE_FIELD',s.findings)
 def test_header_pairs(self):
  for pair in [{'name':'X-Consumer-Credential','value':'synthetic-only-value'},{'key':'x_consumer_credential','value':'synthetic-only-value'}]:
   s=Scanner();s.obj({'headers':[pair]});self.assertIn('SENSITIVE_PAIR',s.findings)
 def test_embedded_json(self):
  s=Scanner();s.obj({'body':json.dumps({'X-Consumer-Credential':'synthetic-only-value'})});self.assertIn('SENSITIVE_FIELD',s.findings)
if __name__=='__main__':unittest.main()
