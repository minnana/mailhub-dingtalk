#!/usr/bin/env python3
"""Offline tests for deployment input boundaries. Does not use Docker or network."""
import contextlib, importlib.util, io, json, pathlib, tempfile, unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('runtime_config',ROOT/'templates/runtime_config.py')
config=importlib.util.module_from_spec(spec)
spec.loader.exec_module(config)

class ConfigurationBoundaries(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.path=pathlib.Path(self.temp.name)/'settings'
        self.settings=json.loads((ROOT/'templates/bootstrap.example.json').read_text())
    def tearDown(self): self.temp.cleanup()
    def commands(self,args,**kw):
        if args[:4]==['ip','-j','-4','route']: return b'[{"dst":"192.168.0.0/22"}]'
        return '' if kw.get('text') else b'[]'
    def rendered(self):
        self.path.write_text(json.dumps(self.settings))
        output=io.StringIO()
        with patch.object(config.subprocess,'check_output',side_effect=self.commands),contextlib.redirect_stdout(output):
            config.render(self.path)
        return output.getvalue()
    def test_no_email_and_dynamic_pool(self):
        text=self.rendered()
        self.assertIn('ACME_EMAIL=\n',text)
        self.assertIn('PROXY_IP=172.30.240.2\n',text)
        self.assertIn('PROXY_DYNAMIC_RANGE=172.30.240.128/25\n',text)
        self.path.write_text((ROOT/'templates/versions.env').read_text()+text)
        config.check_env(self.path)
    def test_unknown_bootstrap_setting(self):
        self.settings['client_secret']='not-a-real-secret'
        with self.assertRaises(SystemExit): self.rendered()
    def test_overlapping_network(self):
        self.settings['proxy_subnet']='192.168.1.0/24'
        with self.assertRaises(SystemExit): self.rendered()
    def test_existing_project_rejected(self):
        self.path.write_text(json.dumps(self.settings))
        with patch.object(config.subprocess,'check_output',return_value='existing-container'),self.assertRaises(SystemExit):
            config.render(self.path)
    def test_shell_injection_rejected(self):
        self.path.write_text((ROOT/'templates/versions.env').read_text()+self.rendered()+'EXTRA=$(id)\n')
        with self.assertRaises(SystemExit): config.check_env(self.path)
    def test_duplicate_env_key_rejected(self):
        self.path.write_text((ROOT/'templates/versions.env').read_text()+self.rendered()+'DOMAIN=example.com\n')
        with self.assertRaises(SystemExit): config.check_env(self.path)
    def test_acmedns_missing_secret_never_registers(self):
        self.path.write_text("ACMEDNS_BASE_URL='https://auth.acme-dns.io'\n")
        with self.assertRaises(SystemExit): config.dns_check(self.path,'dns_acmedns')
    def test_acmedns_complete_config(self):
        self.path.write_text("ACMEDNS_BASE_URL='https://auth.acme-dns.io'\nACMEDNS_USERNAME='test-id'\nACMEDNS_PASSWORD='test-not-real'\nACMEDNS_SUBDOMAIN='test-subdomain'\n")
        config.dns_check(self.path,'dns_acmedns')
    def test_dns_credentials_cannot_execute_shell(self):
        self.path.write_text("DP_Id='123'\nDP_Key='$(id)'\n")
        with self.assertRaises(SystemExit): config.dns_check(self.path,'dns_dp')
    def test_dns_provider_keys_cannot_mix(self):
        self.path.write_text("Tencent_SecretId='test'\nTencent_SecretKey='test'\nDP_Key='test'\n")
        with self.assertRaises(SystemExit): config.dns_check(self.path,'dns_tencent')
    def test_mail_dns_rejects_container_local_or_invalid_addresses(self):
        for value in ('127.0.0.53','0.0.0.0','169.254.1.1','224.0.0.1','192.0.2.1;id',True):
            with self.subTest(value=value):
                self.settings['mail_dns_primary']=value
                with self.assertRaises(SystemExit): self.rendered()
    def test_mail_dns_private_resolver_and_legacy_env(self):
        self.settings['mail_dns_primary']='10.23.0.1'
        rendered=self.rendered()
        self.assertIn('MAIL_DNS_PRIMARY=10.23.0.1',rendered)
        text=(ROOT/'templates/versions.env').read_text()+rendered
        self.path.write_text(text)
        config.check_env(self.path)
        self.path.write_text('\n'.join(l for l in text.splitlines() if not l.startswith('MAIL_DNS_'))+'\n')
        config.check_env(self.path)

if __name__=='__main__': unittest.main(verbosity=2)
