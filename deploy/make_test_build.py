#!/usr/bin/env python3
"""Builds a side-by-side TEST copy of the SSMC template and collector.

    python3 deploy/make_test_build.py [OUT_DIR]        (default: dist-test)

Writes OUT_DIR/ssmc_collect_test.py and OUT_DIR/zbx_export_templates_hpe_ssmc_TEST.yaml, which can be
installed on a server that already runs the production pair without replacing anything:

* the template is called "HPE SSMC StorageArray TEST" and every uuid in it is new (an import matches
  objects by uuid, so reused uuids would overwrite the production template);
* the External check keys call ssmc_collect_test.py, a copy of the collector with its own file name;
* template-level user macros keep their names, so link only one of the two templates to a host.
"""
import hashlib
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
NAME, TEST_NAME = 'HPE SSMC StorageArray', 'HPE SSMC StorageArray TEST'
SCRIPT, TEST_SCRIPT = 'ssmc_collect.py', 'ssmc_collect_test.py'


def new_uuid(old):
    """Deterministic uuid v4-shaped value derived from the old one (stable between builds)."""
    h = bytearray(hashlib.md5(('ssmc-test-build:' + old).encode()).digest())
    h[6] = (h[6] & 0x0F) | 0x40
    h[8] = (h[8] & 0x3F) | 0x80
    return h.hex()


def build_template(text):
    # The template group is shared and must keep its uuid, or the import would create a duplicate group.
    head, sep, rest = text.partition('\n  templates:\n')
    if not sep:
        raise SystemExit('unexpected template layout: no "templates:" section')
    rest = re.sub(r'(uuid: )([0-9a-f]{32})', lambda m: m.group(1) + new_uuid(m.group(2)), rest)
    text = (head + sep + rest).replace(NAME, TEST_NAME).replace(SCRIPT, TEST_SCRIPT)
    return re.sub(r'(version: 7\.0-\d+\.\d+\.\d+)\n', r'\1-test\n', text, count=1)


def build_script(text):
    text = re.sub(r"VERSION = '([^']+)'", r"VERSION = '\1-test'", text, count=1)
    return text.replace(SCRIPT, TEST_SCRIPT)


def main(out_dir):
    os.makedirs(out_dir, exist_ok=True)
    with open(os.path.join(ROOT, 'zbx_export_templates_hpe_ssmc.yaml')) as fh:
        template = build_template(fh.read())
    with open(os.path.join(ROOT, SCRIPT)) as fh:
        script = build_script(fh.read())
    t_path = os.path.join(out_dir, 'zbx_export_templates_hpe_ssmc_TEST.yaml')
    s_path = os.path.join(out_dir, TEST_SCRIPT)
    with open(t_path, 'w') as fh:
        fh.write(template)
    with open(s_path, 'w') as fh:
        fh.write(script)
    os.chmod(s_path, 0o755)
    print(t_path)
    print(s_path)


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, 'dist-test'))
