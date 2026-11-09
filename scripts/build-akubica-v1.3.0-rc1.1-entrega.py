#!/usr/bin/env python3
"""Future release builder. Default is check-only; never reuses RC1 outputs."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import tempfile
import zipfile
from evidence_security import Scanner, UnsafeEvidence

REQUIRED = {
    'README-v1.3.0-rc1.1.md', 'GUIA-CONSUMO-v1.3.0-rc1.1.md',
    'akubica-openapi-v1.3.0-rc1.1.yaml', 'openapi-changelog-v1.3.0-rc1.1.md',
    'endpoint-matrix-v1.3.0-rc1.1.md', 'CHECKLIST-endpoint-operationId-v1.3.0-rc1.1.md',
    'VALIDATION-FINAL-v1.3.0-rc1.1.md', 'NEWMAN-runbook-v1.3.0-rc1.1.md',
    'Famedic-Akubica-v1.3.0-rc1.1.postman_collection.json',
    'Famedic-Akubica-v1.3.0-rc1.1.postman_environment.template.json',
    'newman-phase-a-report.json', 'newman-phase-a-junit.xml', 'newman-phase-a-summary.json',
    'newman-phase-b-report.json', 'newman-phase-b-junit.xml', 'newman-phase-b-summary.json',
    'STAGING-BUILD-v1.3.0-rc1.1.md', 'SECURITY-GATE-v1.3.0-rc1.1.json',
}


def verify_source(source):
    if source.is_symlink() or not source.is_dir():
        raise UnsafeEvidence('INVALID_SOURCE')
    if set(p.name for p in source.iterdir()) != REQUIRED:
        raise UnsafeEvidence('INVENTORY_MISMATCH')
    if any(p.is_symlink() or not p.is_file() for p in source.iterdir()):
        raise UnsafeEvidence('INVALID_SOURCE_MEMBER')
    scan = Scanner(); scan.path(source)
    if scan.findings:
        raise UnsafeEvidence('SOURCE_SCAN_FAILED')
    # Reports must be complete projections, not arbitrary JSON that happens to scan clean.
    import yaml
    from evidence_security import read_limited, strict_json, validate_projected_newman, project_junit
    spec = yaml.safe_load(read_limited(source / 'akubica-openapi-v1.3.0-rc1.1.yaml').decode())
    for phase in ('a', 'b'):
        report = strict_json(read_limited(source / f'newman-phase-{phase}-report.json').decode())
        summary = strict_json(read_limited(source / f'newman-phase-{phase}-summary.json').decode())
        validate_projected_newman(report, spec)
        validate_projected_newman(summary, spec)
        if report != summary:
            raise UnsafeEvidence('PROJECTED_SUMMARY_MISMATCH')
        project_junit(read_limited(source / f'newman-phase-{phase}-junit.xml'))
    # Attestation must be completed by the authorized release owner, never auto-filled.
    from evidence_security import read_limited, strict_json
    gate = strict_json(read_limited(source / 'SECURITY-GATE-v1.3.0-rc1.1.json').decode())
    if gate != {'pat_revocation_verified': True, 'hmac_containment_verified': True,
                'manual_pii_review_completed': True, 'staging_build_verified': True}:
        raise UnsafeEvidence('ADMIN_GATE_PENDING')


def build(source, destination):
    verify_source(source)
    if destination.name != 'akubica-v1.3.0-rc1.1-entrega' or destination.exists() or destination.is_symlink():
        raise UnsafeEvidence('INVALID_OR_EXISTING_DESTINATION')
    final_zip = Path(str(destination) + '.zip')
    sidecar = Path(str(final_zip) + '.sha256')
    if final_zip.exists() or final_zip.is_symlink() or sidecar.exists() or sidecar.is_symlink():
        raise UnsafeEvidence('ZIP_ALREADY_EXISTS')
    if not destination.parent.is_dir() or destination.parent.is_symlink():
        raise UnsafeEvidence('INVALID_PARENT')
    os.umask(0o077)
    # Only this temporary tree is automatically cleaned; historical outputs are untouched.
    with tempfile.TemporaryDirectory(prefix='.rc11-build-', dir=destination.parent) as temp:
        temp = Path(temp); stage = temp / destination.name; stage.mkdir(mode=0o700)
        for name in sorted(REQUIRED):
            shutil.copyfile(source / name, stage / name)
        # Scan copied bytes again to cover source changes between check and copy.
        verify_source(stage)
        manifest = '# RC1.1 inventory\n\n' + '\n'.join('- ' + name for name in sorted(REQUIRED)) + '\n'
        (stage / 'MANIFEST-v1.3.0-rc1.1.md').write_text(manifest)
        sums = ''.join(hashlib.sha256(p.read_bytes()).hexdigest() + '  ' + p.name + '\n'
                       for p in sorted(stage.iterdir()))
        (stage / 'SHA256SUMS').write_text(sums)
        scan = Scanner(); scan.path(stage)
        if scan.findings:
            raise UnsafeEvidence('STAGED_SCAN_FAILED')
        archive_path = temp / 'release.zip'
        with zipfile.ZipFile(archive_path, 'x', zipfile.ZIP_DEFLATED) as archive:
            for p in sorted(stage.iterdir()):
                archive.write(p, destination.name + '/' + p.name)
        scan = Scanner(); scan.path(archive_path)
        if scan.findings:
            raise UnsafeEvidence('ZIP_SCAN_FAILED')
        with zipfile.ZipFile(archive_path) as archive:
            expected = {destination.name + '/' + p.name: p for p in stage.iterdir()}
            if set(archive.namelist()) != set(expected) or any(archive.read(n) != p.read_bytes() for n, p in expected.items()):
                raise UnsafeEvidence('ZIP_INTEGRITY_FAILED')
        # mkdir and hard-link are exclusive; an existing artifact is never replaced.
        destination.mkdir(mode=0o700)
        for p in stage.iterdir():
            with (destination / p.name).open('xb') as handle:
                handle.write(p.read_bytes())
        os.link(archive_path, final_zip)
        with Path(str(final_zip) + '.sha256').open('x') as handle:
            handle.write(hashlib.sha256(final_zip.read_bytes()).hexdigest() + '  ' + final_zip.name + '\n')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path)
    parser.add_argument('--build', type=Path, metavar='NEW_DESTINATION')
    args = parser.parse_args()
    try:
        if args.build is None:
            verify_source(args.source)
        else:
            build(args.source, args.build)
        print('PASS: security checks only; release approval remains separate' if args.build is None else 'PASS: new archive verified')
        return 0
    except Exception:
        print('FAIL: build blocked; input values and paths omitted')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
