#!/usr/bin/env python3
"""Offline diagnostic canaries. Emit categories only, never fixture contents."""
import argparse
import json
import sys
from pathlib import Path
import xml.etree.ElementTree as ET
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from evidence_security import Scanner

CASES = [
    ('BEARER', {'authorization':'Bearer SYNTHETIC_CANARY_NOT_A_TOKEN'}),
    ('AUTHORIZATION', {'headers':[{'key':'Authorization','value':'fixture-private-value'}]}),
    ('OTP', {'otp_code':'482613'}),
    ('HMAC', {'hmac_secret':'fixture-private-value'}),
    ('PHONE', {'phone':'+525500009991'}),
    ('EMAIL', {'email':'canary@example.invalid'}),
    ('NAME', {'full_name':'Persona Sintetica'}),
    ('PERSONAL_IDENTIFIER', {'personal_identifier':'ID-SYNTHETIC-0001'}),
    ('CURP_FIELD', {'curp':'SYNTHETIC-IDENTIFIER'}),
    ('RFC_FIELD', {'rfc':'SYNTHETIC-IDENTIFIER'}),
    ('DELEGATED_USER_ID', {'user_id':999991}),
    ('SENSITIVE_HEADER_API_KEY', {'headers':[{'key':'X-Api-Key','value':'fixture-private-value'}]}),
    ('SENSITIVE_HEADER_SIGNATURE', {'headers':[{'key':'X-Atix-Signature','value':'fixture-private-value'}]}),
]

def diagnose():
    rows=[]
    for label,fixture in CASES:
        for fmt in ['JSON','XML_JUNIT']:
            raw=json.dumps(fixture).encode()
            if fmt=='XML_JUNIT':
                root=ET.Element('testsuite',name='execution-0001',tests='1')
                case=ET.SubElement(root,'testcase',name='assertion-0001',classname='execution-0001',time='0')
                ET.SubElement(case,'failure',message=raw.decode()).text=raw.decode()
                raw=ET.tostring(root)
            scanner=Scanner();scanner.blob(raw,'.json' if fmt=='JSON' else '.xml')
            rows.append({'fixture':label,'format':fmt,'blocked':bool(scanner.findings),
                         'categories':sorted(scanner.findings),
                         'interpretation':'BLOCKED_BY_POLICY_OR_SIGNATURE' if scanner.findings else 'DETECTION_GAP'})
    return {'scope':'SYNTHETIC_ONLY_NO_REAL_VALUES','result':'GAPS_FOUND' if any(not r['blocked'] for r in rows) else 'ALL_CANARIES_BLOCKED',
            'rows':rows,'blocked':sum(r['blocked'] for r in rows),'gaps':sum(not r['blocked'] for r in rows),
            'XML_note':'Strict JUnit format rejection is not semantic recognition of each PII type.',
            'production_scanner_modified':False,'limitations':['No general recognition of unlabelled human names or identifiers in arbitrary prose.']}

if __name__=='__main__':
    ap=argparse.ArgumentParser();ap.add_argument('--report',type=Path,required=True);args=ap.parse_args()
    report=diagnose()
    with args.report.open('x') as handle:handle.write(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:report[k] for k in ['result','blocked','gaps']}))
    raise SystemExit(2 if report['gaps'] else 0)
