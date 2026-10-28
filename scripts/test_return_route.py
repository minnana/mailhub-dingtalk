#!/usr/bin/env python3
"""Offline safety tests: synthetic state only, no privileges, network or ip commands."""
import copy
import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location('return_route', pathlib.Path(__file__).with_name('return-route.py'))
route = importlib.util.module_from_spec(spec)
spec.loader.exec_module(route)

CONFIG = {'gateway': '10.23.0.1', 'device': 'eth0', 'proxy_ip': '172.30.50.2',
          'port': 8443, 'table': 18443, 'local_priority': 18442, 'gateway_priority': 18443}


def rule(kind):
    value = {'priority': CONFIG[kind + '_priority'], 'src': CONFIG['proxy_ip'],
             'ipproto': 'tcp', 'sport': 8443, 'table': 'main' if kind == 'local' else '18443'}
    if kind == 'local':
        value['suppress_prefixlen'] = 0
    return value


def default_route():
    return {'dst': 'default', 'gateway': CONFIG['gateway'], 'dev': 'eth0', 'flags': ['onlink']}


class FakeIP:
    def __init__(self):
        self.rules = [{'priority': 0, 'src': 'all', 'table': 'local'},
                      {'priority': 32766, 'src': 'all', 'table': 'main'},
                      {'priority': 32767, 'src': 'all', 'table': 'default'}]
        self.routes = []
        self.interfaces = [{'ifname': 'eth0', 'flags': ['UP'],
                            'addr_info': [{'family': 'inet', 'local': '10.23.0.10', 'prefixlen': 24}]}]
        self.changes = []
        self.fail = None
        self.commit_before_failure = False
        self.replace_after_failure = False

    def snapshot(self, config):
        return copy.deepcopy(self.rules), copy.deepcopy(self.routes)

    def addresses(self, config):
        return copy.deepcopy(self.interfaces)

    def change(self, action, kind, config):
        self.changes.append((action, kind))
        failure = self.fail == (action, kind)
        if failure and not self.commit_before_failure:
            raise route.PolicyError('simulated failure')
        values = self.routes if kind == 'route' else self.rules
        desired = default_route() if kind == 'route' else rule(kind)
        if action == 'add':
            values.append(desired)
        else:
            values.remove(desired)
        if failure:
            if self.replace_after_failure:
                desired['sport'] = 9443
            raise route.PolicyError('simulated timeout after netlink commit')


class Boundaries(unittest.TestCase):
    def test_config_rejects_unknown_or_duplicate_fields(self):
        with self.assertRaises(route.PolicyError):
            route.validate_config({**CONFIG, 'command': 'bad'})
        with self.assertRaises(route.PolicyError):
            route.unique_object([('port', 8443), ('port', 9443)])

    def test_config_rejects_bool_numbers_and_reserved_ids(self):
        for key, value in (('port', True), ('port', 0), ('port', 65536), ('table', 254),
                           ('table', 255), ('table', 0), ('gateway_priority', 32766),
                           ('local_priority', 0), ('local_priority', 18443)):
            with self.subTest(key=key, value=value), self.assertRaises(route.PolicyError):
                route.validate_config({**CONFIG, key: value})

    def test_config_rejects_nonprivate_address_and_interface_injection(self):
        for key, value in (('gateway', '203.0.113.1'), ('proxy_ip', '127.0.0.1'),
                           ('proxy_ip', '0.0.0.0'), ('gateway', '::1'), ('device', 'eth0;reboot'),
                           ('device', '../eth0'), ('device', 'lo')):
            with self.subTest(key=key), self.assertRaises(route.PolicyError):
                route.validate_config({**CONFIG, key: value})

    def test_observed_json_normalization(self):
        for kind in ('local', 'gateway'):
            self.assertTrue(route.rule_matches(rule(kind), CONFIG, kind))
            numeric = {**rule(kind), 'src': CONFIG['proxy_ip'] + '/32', 'ipproto': 6,
                       'sport': '8443-8443', 'table': 254 if kind == 'local' else 18443}
            self.assertTrue(route.rule_matches(numeric, CONFIG, kind))
        self.assertTrue(route.route_matches(default_route(), CONFIG))

    def test_iproute_numeric_protocol_json_fixture(self):
        # Real ip -N -j -4 schema, with documentation-only private addresses.
        observed = [
            {'priority': 18442, 'src': '172.30.50.2', 'ipproto': 'ipproto-6',
             'sport': 8443, 'table': '254', 'suppress_prefixlen': 0},
            {'priority': 18443, 'src': '172.30.50.2', 'ipproto': 'ipproto-6',
             'sport': 8443, 'table': '18443'},
        ]
        present, problems = route.inspect_snapshot(CONFIG, observed, [default_route()])
        self.assertTrue(all(present.values()))
        self.assertEqual(problems, [])
        self.assertFalse(route.rule_matches({**observed[1], 'ipproto': 'ipproto-17'}, CONFIG, 'gateway'))
        ip = FakeIP()
        ip.rules += observed
        ip.routes = [default_route()]
        route.Policy(CONFIG, ip).apply()
        self.assertEqual(ip.changes, [])

    def test_extra_selectors_never_match(self):
        for field, value in (('fwmark', '0x1'), ('iif', 'eth0'), ('dport', 443),
                             ('not', True), ('goto', 32766), ('protocol', 'static')):
            with self.subTest(field=field):
                self.assertFalse(route.rule_matches({**rule('gateway'), field: value}, CONFIG, 'gateway'))
        self.assertFalse(route.rule_matches({**rule('gateway'), 'sport': '8443-8444'}, CONFIG, 'gateway'))
        self.assertFalse(route.rule_matches({**rule('gateway'), 'src': '172.30.50.0/24'}, CONFIG, 'gateway'))

    def test_route_metric_protocol_or_dynamic_flags_are_conflicts(self):
        for field, value in (('metric', 100), ('protocol', 'static'), ('flags', ['onlink', 'linkdown']),
                             ('type', 'blackhole'), ('gateway', '10.23.0.2')):
            with self.subTest(field=field):
                self.assertFalse(route.route_matches({**default_route(), field: value}, CONFIG))

    def test_empty_apply_and_repeat_are_idempotent(self):
        ip = FakeIP()
        saved = []
        policy = route.Policy(CONFIG, ip)
        policy.apply(lambda: saved.append(True))
        self.assertEqual(ip.changes, [('add', 'route'), ('add', 'local'), ('add', 'gateway')])
        policy.apply(lambda: saved.append(True))
        self.assertEqual(len(ip.changes), 3)
        self.assertEqual(len(saved), 2)

    def test_existing_exact_policy_is_adopted_without_mutation(self):
        ip = FakeIP()
        ip.rules += [rule('local'), rule('gateway')]
        ip.routes = [default_route()]
        route.Policy(CONFIG, ip).apply()
        self.assertEqual(ip.changes, [])

    def test_partial_policy_repairs_only_missing_object(self):
        ip = FakeIP()
        ip.routes = [default_route()]
        ip.rules.append(rule('local'))
        route.Policy(CONFIG, ip).apply()
        self.assertEqual(ip.changes, [('add', 'gateway')])

    def test_orphan_gateway_rule_gets_main_protection_before_route(self):
        ip = FakeIP()
        ip.rules.append(rule('gateway'))
        route.Policy(CONFIG, ip).apply()
        self.assertEqual(ip.changes, [('add', 'local'), ('add', 'route')])

    def test_unknown_priority_and_duplicate_rule_prevent_any_mutation(self):
        for additional in ([{**rule('local'), 'sport': 9443}], [rule('local'), rule('local')]):
            ip = FakeIP()
            ip.rules += additional
            with self.assertRaises(route.PolicyError):
                route.Policy(CONFIG, ip).apply()
            self.assertEqual(ip.changes, [])

    def test_unknown_route_or_table_reference_prevent_any_mutation(self):
        for extra_route in (True, False):
            ip = FakeIP()
            if extra_route:
                ip.routes = [{'dst': '10.50.0.0/16', 'dev': 'eth0'}]
            else:
                ip.rules.append({'priority': 15000, 'src': 'all', 'table': '18443'})
            with self.assertRaises(route.PolicyError):
                route.Policy(CONFIG, ip).apply()
            self.assertEqual(ip.changes, [])

    def test_gateway_must_be_directly_connected_not_self(self):
        for address in ('10.24.0.1', '10.23.0.10', '10.23.0.0', '10.23.0.255'):
            ip = FakeIP()
            with self.subTest(gateway=address), self.assertRaises(route.PolicyError):
                route.Policy({**CONFIG, 'gateway': address}, ip).apply()
            self.assertEqual(ip.changes, [])

    def test_down_interface_prevents_apply(self):
        ip = FakeIP()
        ip.interfaces[0]['flags'] = []
        with self.assertRaises(route.PolicyError):
            route.Policy(CONFIG, ip).apply()
        self.assertEqual(ip.changes, [])

    def test_failure_rolls_back_only_new_objects(self):
        ip = FakeIP()
        ip.routes = [default_route()]
        ip.fail = ('add', 'gateway')
        with self.assertRaises(route.PolicyError):
            route.Policy(CONFIG, ip).apply()
        self.assertEqual(ip.routes, [default_route()])
        self.assertFalse(any(r.get('priority') == 18442 for r in ip.rules))
        self.assertNotIn(('del', 'route'), ip.changes)

    def test_each_add_timeout_after_commit_is_reconciled(self):
        for kind in ('route', 'local', 'gateway'):
            ip = FakeIP()
            ip.fail = ('add', kind)
            ip.commit_before_failure = True
            with self.subTest(kind=kind), self.assertRaises(route.PolicyError):
                route.Policy(CONFIG, ip).apply()
            self.assertEqual(ip.routes, [])
            self.assertEqual(len(ip.rules), 3)

    def test_rollback_refuses_changed_object(self):
        ip = FakeIP()
        ip.fail = ('add', 'gateway')
        ip.commit_before_failure = True
        ip.replace_after_failure = True
        with self.assertRaisesRegex(route.PolicyError, 'Rollback incomplete'):
            route.Policy(CONFIG, ip).apply()
        self.assertFalse(any(action == 'del' for action, kind in ip.changes))

    def test_state_write_failure_rolls_back_policy(self):
        ip = FakeIP()
        def failed_save():
            raise OSError('simulated full disk')
        with self.assertRaises(route.PolicyError):
            route.Policy(CONFIG, ip).apply(failed_save)
        self.assertEqual(ip.routes, [])
        self.assertEqual(len(ip.rules), 3)

    def test_remove_order_and_second_remove_are_idempotent(self):
        ip = FakeIP()
        policy = route.Policy(CONFIG, ip)
        policy.apply()
        ip.changes.clear()
        policy.remove()
        self.assertEqual(ip.changes, [('del', 'gateway'), ('del', 'local'), ('del', 'route')])
        policy.remove()
        self.assertEqual(len(ip.changes), 3)

    def test_remove_refuses_conflict_without_deleting_good_objects(self):
        ip = FakeIP()
        ip.routes = [default_route()]
        ip.rules += [rule('local'), {**rule('gateway'), 'fwmark': '0x1'}]
        with self.assertRaises(route.PolicyError):
            route.Policy(CONFIG, ip).remove()
        self.assertEqual(ip.changes, [])

    def test_changed_configuration_uses_old_state_for_removal(self):
        changed = {**CONFIG, 'port': 9443}
        with self.assertRaises(route.PolicyError):
            route.choose_config(changed, CONFIG, 'apply')
        self.assertEqual(route.choose_config(changed, CONFIG, 'remove'), CONFIG)
        self.assertEqual(route.choose_config(changed, CONFIG, 'status'), CONFIG)

    def test_live_state_detects_missing_rule(self):
        ip = FakeIP()
        policy = route.Policy(CONFIG, ip)
        policy.apply()
        ip.rules.remove(rule('gateway'))
        present, problems = policy.inspect()
        self.assertFalse(present['gateway'])
        self.assertFalse(all(present.values()))
        self.assertEqual(problems, [])


if __name__ == '__main__':
    unittest.main(verbosity=2)
