"""
Camada de acesso ao PostgreSQL (relatório Contabilidade — ContaFarma).

Mesmo padrão do relatorios-bi/relatorio-parceiros-tax/db.py. Credenciais vêm de
`.dbconfig.json` (gerado por api/relatorio-conexao.php a partir do painel
"Configurar conexão" em Relatórios BI — kwconfig.relatorios_bi_conexoes) nesta
mesma pasta. Se o arquivo não existir (ex.: dev local, modo demo), cai de volta
para variáveis de ambiente (.env) — mesmo comportamento de antes. Lido a cada
conexão (não só no import) para que uma alteração salva no painel valha sem
precisar reiniciar o Gunicorn. Uma conexão por consulta é suficiente para o
volume deste relatório; se a carga crescer, trocar por um pool
(psycopg2.pool) aqui dentro sem mexer no resto do código.

Único parâmetro diferente do relatório irmão: dbname=bx_sync_contabilidade
(mesmo host, usuário e senha).
"""

import json
import os
from contextlib import contextmanager

_CONFIG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".dbconfig.json")

# .env é opcional (ex.: no modo demo nem precisa do banco, ou quando .dbconfig.json existe).
try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
    pass


def _load_db_config():
    if os.path.exists(_CONFIG_FILE):
        with open(_CONFIG_FILE, "r", encoding="utf-8") as f:
            cfg = json.load(f)
        return {
            "host":     cfg.get("host", "127.0.0.1"),
            "port":     str(cfg.get("port", "5432")),
            "dbname":   cfg.get("dbname", "bx_sync_contabilidade"),
            "user":     cfg.get("user", "postgres"),
            "password": cfg.get("password", ""),
            "connect_timeout": 10,
        }
    return {
        "host":     os.getenv("DB_HOST", "127.0.0.1"),
        "port":     os.getenv("DB_PORT", "5432"),
        "dbname":   os.getenv("DB_NAME", "bx_sync_contabilidade"),
        "user":     os.getenv("DB_USER", "postgres"),
        "password": os.getenv("DB_PASSWORD", ""),
        "connect_timeout": 10,
    }


@contextmanager
def get_cursor():
    """Abre conexão + cursor (dict), garante fechamento."""
    import psycopg2
    import psycopg2.extras
    conn = psycopg2.connect(**_load_db_config())
    try:
        cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        yield cur
        conn.commit()
    finally:
        conn.close()


def fetch_all(sql, params=None):
    with get_cursor() as cur:
        cur.execute(sql, params or {})
        return [dict(r) for r in cur.fetchall()]


def fetch_one(sql, params=None):
    with get_cursor() as cur:
        cur.execute(sql, params or {})
        row = cur.fetchone()
        return dict(row) if row else None
