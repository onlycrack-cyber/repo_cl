#!/usr/bin/env python3
"""Upgrade the "HPE SSMC StorageArray" template without losing history.

The 2.0 template replaces six master items with one. If it is imported in one
go with "Delete missing", Zabbix deletes the old masters first, and with them
every dependent item and LLD rule still attached (disks, volumes, nodes,
batteries, alerts): all discovered items and their history are lost.

This script imports twice through the API:
  1. create/update only: dependents and LLD rules are re-pointed to the new master;
  2. with "Delete missing": only the now-unused old master items are removed.

Usage:
  ZABBIX_URL=https://zabbix.example.com ZABBIX_API_TOKEN=... \
      ./zbx_template_upgrade.py zbx_export_templates_hpe_ssmc.yaml [--dry-run]
"""
import argparse
import json
import os
import sys
import urllib.request

UPDATE = {'createMissing': True, 'updateExisting': True}


def rules(delete_missing):
    r = {k: dict(UPDATE) for k in ('template_groups', 'templates', 'valueMaps',
                                    'templateDashboards', 'items', 'discoveryRules',
                                    'triggers', 'graphs', 'httptests')}
    r['templateLinkage'] = {'createMissing': True}
    if delete_missing:
        for k in ('items', 'discoveryRules', 'triggers', 'graphs', 'httptests',
                  'valueMaps', 'templateDashboards'):
            r[k]['deleteMissing'] = True
    return r


class Api:
    def __init__(self, url, token):
        self.url = url.rstrip('/') + '/api_jsonrpc.php'
        self.token = token

    def call(self, method, params):
        body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()
        req = urllib.request.Request(self.url, body, {'Content-Type': 'application/json-rpc',
                                                      'Authorization': 'Bearer ' + self.token})
        with urllib.request.urlopen(req, timeout=120) as resp:
            res = json.loads(resp.read())
        if 'error' in res:
            raise RuntimeError('%s failed: %s' % (method, res['error'].get('data', res['error'])))
        return res['result']


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument('template', help='path to zbx_export_templates_hpe_ssmc.yaml')
    p.add_argument('--dry-run', action='store_true', help='show what would be removed, change nothing')
    args = p.parse_args()
    url, token = os.environ.get('ZABBIX_URL'), os.environ.get('ZABBIX_API_TOKEN')
    if not url or not token:
        p.error('set ZABBIX_URL and ZABBIX_API_TOKEN')
    with open(args.template) as fh:
        source = fh.read()
    api = Api(url, token)

    if args.dry_run:
        changes = api.call('configuration.importcompare', {'format': 'yaml', 'rules': rules(True),
                                                           'source': source})
        print(json.dumps(changes, indent=2))
        return 0
    api.call('configuration.import', {'format': 'yaml', 'rules': rules(False), 'source': source})
    print('step 1/2: items and discovery rules re-pointed to the new master item')
    api.call('configuration.import', {'format': 'yaml', 'rules': rules(True), 'source': source})
    print('step 2/2: obsolete master items removed')
    return 0


if __name__ == '__main__':
    sys.exit(main())
