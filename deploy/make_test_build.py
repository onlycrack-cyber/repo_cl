#!/usr/bin/env python3
"""Builds side-by-side copies (suffix "v2") of the SSMC template + collector and the VMware capacity template.

    python3 deploy/make_test_build.py [OUT_DIR] [--suffix v2] [--product all|ssmc|vmware]
                                                   (default OUT_DIR: dist-<suffix>)

Writes OUT_DIR/ssmc_collect_<suffix>.py, OUT_DIR/zbx_export_templates_hpe_ssmc_<suffix>.yaml and
OUT_DIR/zbx_template_vmware_capacity_<suffix>.yaml, which can be installed on a server that already runs
the production files without replacing anything:

* the template is called "HPE SSMC StorageArray <suffix>" and every uuid in it is new (an import matches
  objects by uuid, so reused uuids would overwrite the production template);
* the External check keys call ssmc_collect_<suffix>.py, a copy of the collector with its own file name;
* template-level user macros keep their names, so link only one of the two templates to a host.

The SSMC build also writes the Grafana dashboards hpe-3par-zabbix_<suffix>.json and
hpe-3par-health_<suffix>.json: same panels, but another uid and the title ends with "<suffix>", so importing them
does not overwrite the production dashboards. They find items by name, which the copy keeps.

VMware copy: "VMware capacity planning <suffix>", all uuids new, item keys vmw.cap.* renamed to
vmw.cap_<suffix>.* (formulas and triggers follow) and the discovery keys written with a space before the
quoted parameter (vmware.datastore.discovery[ "{$VMWARE.URL}"]), so both copies can even be linked to the same
vCenter host. Item names stay the same: the Grafana dashboards find items by name, so on one host they would
show both copies; link the copy to a separate test host to compare cleanly.
"""
import argparse
import hashlib
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
NAME = 'HPE SSMC StorageArray'
SCRIPT = 'ssmc_collect.py'
VMW_NAME = 'VMware capacity planning'
VMW_FILE = 'zbx_template_vmware_capacity.yaml'


def new_uuid(old, suffix, product='ssmc'):
    """Deterministic uuid v4-shaped value derived from the old one (stable between builds)."""
    h = bytearray(hashlib.md5(('%s-%s-build:' % (product, suffix) + old).encode()).digest())
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


def build_vmware_template(text, suffix):
    head, sep, rest = text.partition('\n  templates:\n')
    if not sep:
        raise SystemExit('unexpected template layout: no "templates:" section')
    rest = re.sub(r'(uuid: )([0-9a-f]{32})', lambda m: m.group(1) + new_uuid(m.group(2), suffix, 'vmware'), rest)
    text = (head + sep + rest).replace(VMW_NAME, '%s %s' % (VMW_NAME, suffix))
    text = text.replace('vmw.cap.', 'vmw.cap_%s.' % suffix)
    text = text.replace('discovery["{$VMWARE.URL}"]', 'discovery[ "{$VMWARE.URL}"]')
    return re.sub(r'(version: 7\.0-\d+\.\d+\.\d+)\n', r'\1-%s\n' % suffix, text, count=1)


def build_dashboard(text, suffix):
    d = json.loads(text)
    d['uid'] = '%s-%s' % (d['uid'], suffix)
    d['title'] = '%s %s' % (d['title'], suffix)
    d['tags'] = list(d.get('tags', [])) + [suffix]
    d.pop('id', None)
    return json.dumps(d, ensure_ascii=False, indent=2) + '\n'


def script_name(suffix):
    return SCRIPT.replace('.py', '_%s.py' % suffix)


def build_script(text, suffix):
    text = re.sub(r"VERSION = '([^']+)'", r"VERSION = '\1-%s'" % suffix, text, count=1)
    return text.replace(SCRIPT, script_name(suffix))


def main(argv=None):
    ap = argparse.ArgumentParser(description='Build a side-by-side copy of the SSMC template and collector.')
    ap.add_argument('out_dir', nargs='?')
    ap.add_argument('--suffix', default='v2', help='suffix of the names (default v2)')
    ap.add_argument('--product', choices=('all', 'ssmc', 'vmware'), default='all')
    args = ap.parse_args(argv)
    if not re.fullmatch(r'[A-Za-z0-9]+', args.suffix):
        ap.error('--suffix must be letters and digits only')
    out_dir = args.out_dir or os.path.join(ROOT, 'dist-' + args.suffix)
    os.makedirs(out_dir, exist_ok=True)
    written = []
    if args.product in ('all', 'ssmc'):
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
        written += [t_path, s_path]
        for name in ('hpe-3par-zabbix', 'hpe-3par-health'):
            with open(os.path.join(ROOT, 'grafana', name + '.json')) as fh:
                dash = build_dashboard(fh.read(), args.suffix)
            d_path = os.path.join(out_dir, '%s_%s.json' % (name, args.suffix))
            with open(d_path, 'w') as fh:
                fh.write(dash)
            written.append(d_path)
    if args.product in ('all', 'vmware'):
        with open(os.path.join(ROOT, 'vmware', VMW_FILE)) as fh:
            template = build_vmware_template(fh.read(), args.suffix)
        v_path = os.path.join(out_dir, VMW_FILE.replace('.yaml', '_%s.yaml' % args.suffix))
        with open(v_path, 'w') as fh:
            fh.write(template)
        written.append(v_path)
    print('\n'.join(written))


if __name__ == '__main__':
    main()
