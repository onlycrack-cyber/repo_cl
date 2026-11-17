#!/usr/bin/env python3
"""Builds a side-by-side copy (suffix "v2") of the SSMC template and collector.

    python3 deploy/make_test_build.py [OUT_DIR] [--suffix v2]        (default OUT_DIR: dist-<suffix>)

Writes OUT_DIR/ssmc_collect_<suffix>.py and OUT_DIR/zbx_export_templates_hpe_ssmc_<suffix>.yaml, which can be
installed on a server that already runs the production pair without replacing anything:

* the template is called "HPE SSMC StorageArray <suffix>" and every uuid in it is new (an import matches
  objects by uuid, so reused uuids would overwrite the production template);
* the External check keys call ssmc_collect_<suffix>.py, a copy of the collector with its own file name;
* template-level user macros keep their names, so link only one of the two templates to a host.
"""
import argparse
import hashlib
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
NAME = 'HPE SSMC StorageArray'
SCRIPT = 'ssmc_collect.py'


def new_uuid(old, suffix):
    """Deterministic uuid v4-shaped value derived from the old one (stable between builds)."""
    h = bytearray(hashlib.md5(('ssmc-%s-build:' % suffix + old).encode()).digest())
    h[6] = (h[6] & 0x0F) | 0x40
    h[8] = (h[8] & 0x3F) | 0x80
    return h.hex()


def build_template(text, suffix):
    # The template group is shared and must keep its uuid, or the import would create a duplicate group.
    head, sep, rest = text.partition('\n  templates:\n')
    if not sep:
        raise SystemExit('unexpected template layout: no "templates:" section')
    rest = re.sub(r'(uuid: )([0-9a-f]{32})', lambda m: m.group(1) + new_uuid(m.group(2), suffix), rest)
    text = (head + sep + rest).replace(NAME, '%s %s' % (NAME, suffix)).replace(SCRIPT, script_name(suffix))
    return re.sub(r'(version: 7\.0-\d+\.\d+\.\d+)\n', r'\1-%s\n' % suffix, text, count=1)


def script_name(suffix):
    return SCRIPT.replace('.py', '_%s.py' % suffix)


def build_script(text, suffix):
    text = re.sub(r"VERSION = '([^']+)'", r"VERSION = '\1-%s'" % suffix, text, count=1)
    return text.replace(SCRIPT, script_name(suffix))


def main(argv=None):
    ap = argparse.ArgumentParser(description='Build a side-by-side copy of the SSMC template and collector.')
    ap.add_argument('out_dir', nargs='?')
    ap.add_argument('--suffix', default='v2', help='suffix of the names (default v2)')
    args = ap.parse_args(argv)
    if not re.fullmatch(r'[A-Za-z0-9]+', args.suffix):
        ap.error('--suffix must be letters and digits only')
    out_dir = args.out_dir or os.path.join(ROOT, 'dist-' + args.suffix)
    os.makedirs(out_dir, exist_ok=True)
    with open(os.path.join(ROOT, 'zbx_export_templates_hpe_ssmc.yaml')) as fh:
        template = build_template(fh.read(), args.suffix)
    with open(os.path.join(ROOT, SCRIPT)) as fh:
        script = build_script(fh.read(), args.suffix)
    t_path = os.path.join(out_dir, 'zbx_export_templates_hpe_ssmc_%s.yaml' % args.suffix)
    s_path = os.path.join(out_dir, script_name(args.suffix))
    with open(t_path, 'w') as fh:
        fh.write(template)
    with open(s_path, 'w') as fh:
        fh.write(script)
    os.chmod(s_path, 0o755)
    print(t_path)
    print(s_path)


if __name__ == '__main__':
    main()
