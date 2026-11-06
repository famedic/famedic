"""Offline comparator regression; not Akubica interoperability evidence."""
import base64,copy,importlib.util,json,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PACKAGE=ROOT/'docs/Akubica/v1.3.0-rc1.1/FASE-2B1G-PAQUETE-ATIX'
spec=importlib.util.spec_from_file_location('atix_results_compare',PACKAGE/'comparar-resultados.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)

class ComparatorTests(unittest.TestCase):
    def setUp(self):
        self.expected=json.loads((PACKAGE/'vectors.json').read_text())
        self.submission={'schema':'atix-vector-results-v1','producer':{'team':'FAMEDIC_REFERENCE','implementation':'self-test-only',
            'language_runtime':'Python stdlib','build_ref':'synthetic-self-test','execution_utc':'2000-01-01T00:00:00Z'},
            'results':[{k:v[k] for k in ['id','canonical_base64','canonical_sha256','signature']} for v in self.expected['vectors']]}
    def compare(self):return module.compare(self.expected,self.submission)
    def test_exact_reference_match_is_not_interoperability_or_approval(self):
        out=self.compare();self.assertEqual('VECTORS_MATCH',out['status'])
        self.assertFalse(out['interoperability_demonstrated']);self.assertFalse(out['protocol_approved']);self.assertFalse(out['producer_origin_verified'])
    def test_declared_akubica_team_is_not_origin_verification(self):
        self.submission['producer']['team']='AKUBICA';out=self.compare()
        self.assertEqual('VECTORS_MATCH',out['status']);self.assertFalse(out['producer_origin_verified']);self.assertFalse(out['interoperability_demonstrated'])
    def test_changed_canonical_byte_is_rejected_even_with_original_hash_and_signature(self):
        row=self.submission['results'][0];row['canonical_base64']=base64.b64encode(base64.b64decode(row['canonical_base64'])+b'\n').decode()
        self.assertEqual('MISMATCH',self.compare()['status'])
    def test_changed_signature_is_rejected(self):
        self.submission['results'][0]['signature']='0'*64;self.assertEqual('MISMATCH',self.compare()['status'])
    def test_wrong_sha_is_rejected(self):
        self.submission['results'][0]['canonical_sha256']='0'*64;self.assertEqual('MISMATCH',self.compare()['status'])
    def test_missing_vector_is_rejected(self):
        self.submission['results'].pop();self.assertEqual('INVALID_SUBMISSION',self.compare()['status'])
    def test_duplicate_vector_is_rejected(self):
        self.submission['results'][-1]=copy.deepcopy(self.submission['results'][0]);self.assertEqual('INVALID_SUBMISSION',self.compare()['status'])
    def test_unknown_vector_is_rejected(self):
        self.submission['results'][0]['id']='unknown';self.assertEqual('INVALID_SUBMISSION',self.compare()['status'])
    def test_malformed_base64_is_rejected(self):
        self.submission['results'][0]['canonical_base64']='!bad!';self.assertEqual('INVALID_SUBMISSION',self.compare()['status'])
    def test_no_inputs_credentials_or_extra_fields_allowed_in_return(self):
        self.submission['results'][0]['pat']='PUBLIC_SYNTHETIC_NOT_REAL';self.assertEqual('INVALID_SUBMISSION',self.compare()['status'])
    def test_blank_template_cannot_pass(self):
        template=json.loads((PACKAGE/'RESULTADOS-ATIX-PLANTILLA.json').read_text());self.assertNotEqual('VECTORS_MATCH',module.compare(self.expected,template)['status'])
    def test_oversize_file_is_rejected_before_json_parsing(self):
        with tempfile.TemporaryDirectory(prefix='atix-compare-test-') as temp:
            p=Path(temp)/'large.json';p.write_bytes(b'x'*1048577)
            with self.assertRaisesRegex(ValueError,'FILE_TOO_LARGE'):module.load(p)
if __name__=='__main__':unittest.main()
