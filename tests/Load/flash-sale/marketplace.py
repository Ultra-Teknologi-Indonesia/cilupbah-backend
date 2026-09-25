"""Offline Shopee contract simulator. Unknown endpoints fail closed, never proxy.

SQLite is only the simulator ledger, NOT a substitute for application PostgreSQL.
All state is bounded by the run's volume on disk rather than an in-memory list.
"""
import json
import os
import sqlite3
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit


def pdf(labels):
    objects = [b"", b""]
    pages = []
    for label in labels:
        label = ''.join(c for c in label if c.isalnum() or c in '-_')
        page = len(objects) + 1
        pages.append(page)
        stream = f"BT /F1 12 Tf 15 240 Td (SIMULATION - NOT FOR SHIPPING) Tj 0 -30 Td ({label}) Tj ET".encode()
        objects.extend([
            f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 283 425] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents {page+1} 0 R >>".encode(),
            b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"\nendstream",
        ])
    objects[0] = b"<< /Type /Catalog /Pages 2 0 R >>"
    objects[1] = f"<< /Type /Pages /Count {len(pages)} /Kids [{' '.join(f'{p} 0 R' for p in pages)}] >>".encode()
    output = b"%PDF-1.4\n"
    offsets = [0]
    for i, obj in enumerate(objects, 1):
        offsets.append(len(output))
        output += f"{i} 0 obj\n".encode() + obj + b"\nendobj\n"
    start = len(output)
    output += f"xref\n0 {len(offsets)}\n0000000000 65535 f \n".encode()
    output += b''.join(f"{o:010d} 00000 n \n".encode() for o in offsets[1:])
    return output + f"trailer\n<< /Size {len(offsets)} /Root 1 0 R >>\nstartxref\n{start}\n%%EOF\n".encode()


class Marketplace:
    def __init__(self, path, latency_ms=100, fail_every=0):
        self.path, self.latency_ms, self.fail_every = path, latency_ms, fail_every
        with self.connect() as db:
            db.executescript('''
                PRAGMA journal_mode=WAL;
                CREATE TABLE IF NOT EXISTS calls(path TEXT PRIMARY KEY, total INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS shipments(package TEXT PRIMARY KEY, created REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS stocks(shop TEXT, item TEXT, payload TEXT, updated REAL,
                    PRIMARY KEY(shop,item));
                CREATE TABLE IF NOT EXISTS documents(order_no TEXT PRIMARY KEY, created REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS downloads(order_no TEXT PRIMARY KEY, downloaded REAL NOT NULL);
            ''')

    def connect(self):
        return sqlite3.connect(self.path, timeout=30)

    def handle(self, path, query, body):
        if path == '/health':
            return 200, {'simulation': True}
        if path == '/metrics':
            with self.connect() as db:
                return 200, {
                    'calls': dict(db.execute('SELECT path,total FROM calls')),
                    **{t: db.execute(f'SELECT count(*) FROM {t}').fetchone()[0]
                       for t in ('shipments', 'stocks', 'documents', 'downloads')},
                }
        time.sleep(self.latency_ms / 1000)
        with self.connect() as db:
            db.execute('INSERT INTO calls VALUES (?,1) ON CONFLICT(path) DO UPDATE SET total=total+1', (path,))
            n = db.execute('SELECT total FROM calls WHERE path=?', (path,)).fetchone()[0]
        if self.fail_every and n % self.fail_every == 0:
            return 429, {'error': 'error_server', 'message': 'Injected simulation throttling'}
        op = path.rsplit('/', 1)[-1]
        result = {}
        packages = body.get('package_list', [])
        orders = body.get('order_list', [])
        with self.connect() as db:
            if op == 'get_order_detail':
                result['order_list'] = []
                for order in query.get('order_sn_list', [''])[0].split(','):
                    if not order.startswith('SIM') or not order[3:].isdigit():
                        return 400, {'error': 'simulation_unknown_order'}
                    seq = int(order[3:])
                    sku = (seq - 1) % int(os.environ.get('SIM_SKUS', '100')) + 1
                    result['order_list'].append({
                        'order_sn': order, 'order_status': 'READY_TO_SHIP',
                        'create_time': int(time.time()), 'update_time': int(time.time()),
                        'pay_time': int(time.time()), 'total_amount': 10000,
                        'buyer_username': 'simulation', 'shipping_carrier': 'SIM REG',
                        'recipient_address': {'name': 'SIMULATION', 'phone': '000', 'full_address': 'TEST ONLY'},
                        'package_list': [{'package_number': 'PKG'+order, 'logistics_channel_id': 8001}],
                        'item_list': [{'item_id': sku, 'item_name': 'SIM SKU', 'model_id': sku,
                                       'model_sku': f'SIM-SKU-{sku}', 'model_quantity_purchased': 1,
                                       'model_discounted_price': 10000}],
                    })
            elif op == 'get_channel_list':
                result = {'logistics_channel_list': [{'logistics_channel_id': 8001, 'logistics_channel_name': 'SIM REG'}]}
            elif op in ('get_mass_shipping_parameter', 'get_shipping_parameter'):
                result = {'info_needed': {'dropoff': []}, 'dropoff': {'branch_list': []}}
            elif op == 'mass_ship_order':
                for p in packages:
                    db.execute('INSERT OR IGNORE INTO shipments VALUES (?,?)', (p['package_number'], time.time()))
                result = {'success_list': packages, 'fail_list': []}
            elif op == 'get_mass_tracking_number':
                result = {'success_list': [], 'fail_list': []}
                for p in packages:
                    if db.execute('SELECT 1 FROM shipments WHERE package=?', (p['package_number'],)).fetchone():
                        result['success_list'].append({**p, 'tracking_number': 'AWB'+p['package_number']})
                    else:
                        result['fail_list'].append({**p, 'fail_reason': 'Not shipped yet'})
            elif op == 'get_tracking_number':
                package = 'PKG'+query.get('order_sn', [''])[0]
                found = db.execute('SELECT 1 FROM shipments WHERE package=?', (package,)).fetchone()
                result = {'tracking_number': 'AWB'+package if found else ''}
            elif op == 'create_shipping_document':
                for o in orders:
                    db.execute('INSERT OR IGNORE INTO documents VALUES (?,?)', (o['order_sn'], time.time()))
                result = {'result_list': orders}
            elif op == 'get_shipping_document_result':
                result = {'result_list': [{**o, 'status': 'READY' if db.execute(
                    'SELECT 1 FROM documents WHERE order_no=?', (o['order_sn'],)).fetchone() else 'PROCESSING'} for o in orders]}
            elif op == 'get_shipping_document_parameter':
                result = {'result_list': [{**o, 'shipping_document_type': 'NORMAL_AIR_WAYBILL',
                    'suggest_shipping_document_type': 'NORMAL_AIR_WAYBILL',
                    'selectable_shipping_document_type': ['NORMAL_AIR_WAYBILL']} for o in orders]}
            elif op == 'download_shipping_document':
                if not orders or any(not db.execute('SELECT 1 FROM documents WHERE order_no=?', (o['order_sn'],)).fetchone() for o in orders):
                    return 409, {'error': 'simulation_document_not_created'}
                for o in orders:
                    db.execute('INSERT OR IGNORE INTO downloads VALUES (?,?)', (o['order_sn'], time.time()))
                return 200, pdf([o['order_sn'] for o in orders])
            elif op == 'get_model_list':
                sku = int(query.get('item_id', ['0'])[0])
                result = {'model': [{'model_id': sku, 'model_sku': f'SIM-SKU-{sku}'}]}
            elif op == 'update_stock':
                db.execute('INSERT INTO stocks VALUES (?,?,?,?) ON CONFLICT(shop,item) DO UPDATE SET payload=excluded.payload,updated=excluded.updated',
                           (query.get('shop_id', [''])[0], str(body['item_id']), json.dumps(body['stock_list']), time.time()))
            elif op == 'get_escrow_detail':
                result = {'order_sn': query.get('order_sn', [''])[0], 'order_income': {'escrow_amount': 10000}}
            else:
                return 501, {'error': 'simulation_unsupported_endpoint', 'message': path}
        return 200, {'error': '', 'message': '', 'response': result}


def serve():
    mock = Marketplace(os.environ.get('SIM_LEDGER', '/data/marketplace.sqlite'),
                       int(os.environ.get('SIM_LATENCY_MS', '100')), int(os.environ.get('SIM_FAIL_EVERY', '0')))

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self):
            self.respond()

        def do_POST(self):
            self.respond()

        def respond(self):
            parsed = urlsplit(self.path)
            try:
                size = int(self.headers.get('Content-Length', '0'))
                if size > 2_000_000:
                    self.send_error(413)
                    return
                body = json.loads(self.rfile.read(size)) if size else {}
                status, result = mock.handle(parsed.path, parse_qs(parsed.query), body)
                binary = isinstance(result, bytes)
                content = result if binary else json.dumps(result).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/pdf' if binary else 'application/json')
                self.send_header('Content-Length', str(len(content)))
                self.end_headers()
                self.wfile.write(content)
            except (BrokenPipeError, ConnectionResetError):
                pass
            except Exception as error:
                print(json.dumps({'simulator_error': str(error)}), flush=True)
                self.send_error(500)

        def log_message(self, *_):
            pass

    ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever()


if __name__ == '__main__':
    serve()
