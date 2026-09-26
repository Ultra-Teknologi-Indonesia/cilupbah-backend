import importlib.machinery
import importlib.util
from pathlib import Path
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from marketplace import Marketplace, pdf

ROOT = Path(__file__).resolve().parents[3]
loader = importlib.machinery.SourceFileLoader('runner', str(ROOT / 'scripts/flash-sale-test'))
spec = importlib.util.spec_from_loader(loader.name, loader)
runner = importlib.util.module_from_spec(spec)
loader.exec_module(runner)


class HarnessTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.mock = Marketplace(str(Path(self.temp.name) / 'mock.sqlite'), 0)

    def test_unknown_endpoint_fails_closed(self):
        self.assertEqual(501, self.mock.handle('/api/v2/unknown', {}, {})[0])

    def test_awb_requires_shipment_and_retries_do_not_duplicate(self):
        body = {'package_list': [{'package_number': 'PKGSIM000000000001'}]}
        self.assertEqual([], self.mock.handle('/api/v2/logistics/get_mass_tracking_number', {}, body)[1]['response']['success_list'])
        for _ in range(2):
            self.mock.handle('/api/v2/logistics/mass_ship_order', {}, body)
        self.assertEqual(1, self.mock.handle('/metrics', {}, {})[1]['shipments'])
        self.assertEqual(1, len(self.mock.handle('/api/v2/logistics/get_mass_tracking_number', {}, body)[1]['response']['success_list']))

    def test_document_must_be_created_before_download(self):
        body = {'order_list': [{'order_sn': 'SIM000000000001'}]}
        self.assertEqual(409, self.mock.handle('/api/v2/logistics/download_shipping_document', {}, body)[0])
        self.mock.handle('/api/v2/logistics/create_shipping_document', {}, body)
        status, content = self.mock.handle('/api/v2/logistics/download_shipping_document', {}, body)
        self.assertEqual(200, status)
        self.assertTrue(content.startswith(b'%PDF-'))
        self.assertIn(b'/Count 1', content)
        self.assertEqual(1, self.mock.handle('/metrics', {}, {})[1]['downloads'])

    def test_channel_batch_limit_and_async_readiness_are_enforced(self):
        delayed = Marketplace(str(Path(self.temp.name) / 'delayed.sqlite'), 0, 0, 50, 60_000, 60_000)
        packages = [{'package_number': f'PKGSIM{i:012d}'} for i in range(1, 52)]
        self.assertEqual(422, delayed.handle('/api/v2/logistics/mass_ship_order', {}, {'package_list': packages})[0])
        package = [{'package_number': 'PKGSIM000000000001'}]
        self.assertEqual(200, delayed.handle('/api/v2/logistics/mass_ship_order', {}, {'package_list': package})[0])
        tracking = delayed.handle('/api/v2/logistics/get_mass_tracking_number', {}, {'package_list': package})[1]
        self.assertEqual([], tracking['response']['success_list'])
        order = [{'order_sn': 'SIM000000000001'}]
        delayed.handle('/api/v2/logistics/create_shipping_document', {}, {'order_list': order})
        self.assertEqual('PROCESSING', delayed.handle('/api/v2/logistics/get_shipping_document_result', {}, {'order_list': order})[1]
                                      ['response']['result_list'][0]['status'])

    def test_stock_ledger_retains_latest_value(self):
        for stock in [30, 12]:
            self.mock.handle('/api/v2/product/update_stock', {'shop_id': ['1']}, {'item_id': 2, 'stock_list': [{'seller_stock': [{'stock': stock}]}]})
        with self.mock.connect() as db:
            self.assertIn('12', db.execute('SELECT payload FROM stocks').fetchone()[0])

    def test_fault_profile_and_valid_pdf(self):
        self.mock.fail_every = 2
        self.assertEqual(200, self.mock.handle('/api/v2/logistics/get_channel_list', {}, {})[0])
        self.assertEqual(429, self.mock.handle('/api/v2/logistics/get_channel_list', {}, {})[0])
        self.assertIn(b'/Count 2', pdf(['A', 'B']))

    def test_production_namespace_rejected(self):
        for name in ['cilupbah', 'default', 'kube-system', 'cilupbah-sim-../prod']:
            with self.assertRaises(ValueError):
                runner.validate_namespace(name)

    def test_manifests_never_reference_production_secrets_or_hosts(self):
        args = SimpleNamespace(namespace='cilupbah-sim-test', image='test:latest', node='test-node', count=100,
                               seconds=60, shops=2, skus=3, channel_batch_size=50, label_selection_size=100,
                               label_selection_mode='shop_grouped', traffic_profile='flash_burst', burst_seconds=30,
                               webhook_duplicate_every=10, producers=2, latency_ms=100, fail_every=0,
                               awb_ready_after_ms=1000, document_ready_after_ms=1000, async_slow_every=10,
                               storage_class='local-path', disk='10Gi', shopee_api_rate=4,
                               pull_secret='ghcr-creds')
        baseline = {'deployments': [{'name': name, 'containers': [{'resources': {'limits': {'cpu': '1', 'memory': '1Gi'},
                    'requests': {'cpu': '100m', 'memory': '128Mi'}}, 'tuning': {}}]} for name, _ in runner.PROFILES.values()]}
        manifests = runner.manifests(args, baseline)
        self.assertTrue(any(m['kind'] == 'NetworkPolicy' for m in manifests))
        for m in manifests:
            if m['kind'] != 'Namespace':
                self.assertEqual(args.namespace, m['metadata']['namespace'])
            if m['kind'] in ('Deployment', 'Job'):
                pod = m['spec']['template']['spec']
                self.assertFalse(pod['automountServiceAccountToken'])
                self.assertNotIn('hostNetwork', pod)
                self.assertEqual('test-node', pod['nodeSelector']['kubernetes.io/hostname'])
                for c in pod['containers']:
                    self.assertFalse(any('secretRef' in e for e in c.get('envFrom', [])))
                    for env in c.get('env', []):
                        value = env.get('value', '')
                        self.assertNotIn('https://', value)
                        if env.get('name') in ('SHOPEE_HOST', 'TIKTOK_BASE_URL', 'LAZADA_BASE_URL'):
                            self.assertEqual('http://marketplace:8080', value)

        awb = next(m for m in manifests if m['kind'] == 'Deployment' and m['metadata']['name'] == 'worker-labels-awb')
        awb_resources = awb['spec']['template']['spec']['containers'][0]['resources']
        self.assertEqual('512Mi', awb_resources['requests']['memory'])
        self.assertEqual('1Gi', awb_resources['limits']['memory'])
        environment = next(m for m in manifests if m['kind'] == 'ConfigMap' and m['metadata']['name'] == 'environment')['data']
        self.assertEqual('50', environment['SIM_CHANNEL_BATCH_SIZE'])
        self.assertEqual('100', environment['SIM_LABEL_SELECTION_SIZE'])
        self.assertEqual('flash_burst', environment['SIM_TRAFFIC_PROFILE'])
        self.assertEqual('10', environment['SIM_WEBHOOK_DUPLICATE_EVERY'])

    def test_empty_queue_does_not_hide_missing_work(self):
        snapshot = {'cases': {'offered': 100, 'http_accepted': 100, 'stock_requested': 100, 'producer_errors': 0},
                    'orders': 99, 'awb_ready': 100, 'batches': [{'status': 'ready', 'labels': 100, 'failed': 0}],
                    'inbox': [], 'stock': [], 'failed_jobs': 0, 'queues': {}}
        self.assertFalse(runner.is_complete(snapshot, 100))
        snapshot['orders'] = 100
        snapshot['queues'] = {'x': {'ready': 0, 'delayed': 1, 'reserved': 0}}
        self.assertFalse(runner.is_complete(snapshot, 100))

    def test_scheduler_memory_counts_declared_requests(self):
        pod = {
            'spec': {
                'containers': [{'resources': {'requests': {'memory': '256Mi'}}}, {'resources': {'requests': {'memory': '512Mi'}}}],
                'initContainers': [{'resources': {'requests': {'memory': '2Gi'}}}],
                'overhead': {'memory': '16Mi'},
            },
        }
        self.assertEqual(2 * 1024**3 + 16 * 1024**2, runner.pod_request_memory(pod))

    def test_preflight_rejects_active_simulation_and_unschedulable_memory(self):
        args = SimpleNamespace(namespace='cilupbah-sim-test', image='test:latest', node='test-node',
                               source_namespace='cilupbah')
        baseline = {
            'nodes': {'items': [{'metadata': {'name': 'test-node', 'labels': {'kubernetes.io/hostname': 'test-node'}},
                                 'status': {'allocatable': {'memory': '4Gi'},
                                            'conditions': [{'type': 'Ready', 'status': 'True'}]}}]},
            'node_metrics': {'items': [{'metadata': {'name': 'test-node'}, 'usage': {'memory': '1Gi'}}]},
        }
        objects = [{'kind': 'Deployment', 'spec': {'replicas': 1, 'template': {'spec': {'containers': [
            {'resources': {'requests': {'memory': '1Gi'}}},
        ]}}}}]
        health = {'missing_deployments': [], 'unavailable_deployments': [], 'live_oom_pods': []}
        with patch.object(runner, 'node_requested_memory', return_value=int(3.5 * 1024**3)), \
             patch.object(runner, 'production_health', return_value=health), \
             patch.object(runner, 'active_simulation_namespaces', return_value=['cilupbah-sim-old']):
            report = runner.preflight_report(args, baseline, objects)
        self.assertFalse(report['passed'])
        self.assertIn('cilupbah-sim-old', ' '.join(report['issues']))
        self.assertIn('Memori yang dapat dijadwalkan', ' '.join(report['issues']))

    def test_contract_assertions_reject_missing_replay_or_oversized_batch(self):
        args = SimpleNamespace(count=100, webhook_duplicate_every=10, channel_batch_size=50)
        final = {'cases': {'webhook_attempts': 109}}
        mock = {'batch_sizes': {'/api/v2/logistics/mass_ship_order': {'max': 51}}}
        result = runner.contract_assertions(final, mock, args, {'checked': 2, 'invalid': 0})
        self.assertFalse(result['webhook_attempts_match'])
        self.assertFalse(result['channel_batch_limit_respected'])
        self.assertEqual(51, result['oversized_channel_requests']['/api/v2/logistics/mass_ship_order'])


if __name__ == '__main__':
    unittest.main()
