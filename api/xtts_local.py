#!/usr/bin/env python3
"""Interface local e independente para gerar os audios XTTS dos flashcards.

Edite somente o bloco CONFIGURACAO antes de executar. Este utilitario nao le o
arquivo .env do site e deve ser exposto apenas em localhost.
"""

from __future__ import annotations

import argparse
import base64
import html
import json
import re
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

import mysql.connector
import soundfile as sf
import torch
from Crypto.Cipher import AES
from Crypto.Random import get_random_bytes
from TTS.api import TTS


# ============================ CONFIGURACAO ============================
# Preencha estes valores diretamente. O programa deliberadamente nao usa .env.
DB_HOST = "HOST"
DB_PORT = 3306
DB_USER = "USER"
DB_PASSWORD = "PASSWORD"
DB_NAME = "DATABASE"

# Deve ser exatamente a mesma ENCRYPTION_KEY usada pelo site.
ENCRYPTION_KEY = "CHAVE_DE_CRIPTOGRAFIA_DO_SITE"

BASE_DIR = Path(__file__).resolve().parent
VOICE_PATH = BASE_DIR / "voices" / "a34 (75).wav"
MODEL_NAME = "tts_models/multilingual/multi-dataset/xtts_v2"
HTTP_HOST = "127.0.0.1"
HTTP_PORT = 8765
# ====================================================================

LANGUAGES = {
    "en": ("Ingles", "en"),
    "en_us": ("Ingles americano", "en"),
    "en_gb": ("Ingles britanico", "en"),
    "pt_br": ("Portugues", "pt"),
    "es": ("Espanhol", "es"),
    "fr": ("Frances", "fr"),
    "zh": ("Mandarim", "zh-cn"),
}

_tts = None
_tts_lock = threading.Lock()


def database_connection():
    """Abre uma conexao nova, evitando compartilhar conexoes entre threads."""
    return mysql.connector.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        charset="utf8mb4",
        autocommit=False,
    )


def openssl_key() -> bytes:
    """Replica o preenchimento/truncamento de chave feito pelo OpenSSL/PHP."""
    raw = ENCRYPTION_KEY.encode("utf-8")
    return raw[:32].ljust(32, b"\0")


def encrypt_data(value: str) -> str:
    """Produz o mesmo envelope base64 de Security::encryptData do PHP."""
    cipher = AES.new(openssl_key(), AES.MODE_GCM, nonce=get_random_bytes(12), mac_len=16)
    ciphertext, tag = cipher.encrypt_and_digest(value.encode("utf-8"))
    # openssl_encrypt sem OPENSSL_RAW_DATA devolve o ciphertext em base64.
    inner_ciphertext = base64.b64encode(ciphertext)
    return base64.b64encode(cipher.nonce + tag + inner_ciphertext).decode("ascii")


def decrypt_data(value: str | None) -> str:
    if not value:
        return ""
    try:
        payload = base64.b64decode(value.strip(), validate=True)
        nonce, tag, inner = payload[:12], payload[12:28], payload[28:]
        ciphertext = base64.b64decode(inner, validate=True)
        cipher = AES.new(openssl_key(), AES.MODE_GCM, nonce=nonce, mac_len=16)
        return cipher.decrypt_and_verify(ciphertext, tag).decode("utf-8")
    except (ValueError, UnicodeDecodeError):
        return ""


def decrypt_map(value: str | None) -> dict[str, str]:
    try:
        decoded = json.loads(decrypt_data(value))
        return decoded if isinstance(decoded, dict) else {}
    except json.JSONDecodeError:
        return {}


def clean_text(value: str) -> str:
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]*>", " ", value))).strip()


def language_key(value: str | None, fallback: str) -> str:
    normalized = (value or "").lower().replace("-", "_")
    aliases = {"pt": "pt_br", "zh_cn": "zh", "cmn_cn": "zh"}
    normalized = aliases.get(normalized, normalized)
    return normalized if normalized in LANGUAGES else fallback


def card_items(only_missing: bool = True) -> list[dict]:
    """Transforma cada texto/idioma em um item independente da interface."""
    sql = """
        SELECT f.id, f.directory_id, f.front_encrypted, f.back_encrypted,
               f.front_translations_encrypted, f.back_translations_encrypted,
               f.audio_front_encrypted, f.audio_back_encrypted,
               f.audio_front_translations_encrypted,
               f.audio_back_translations_encrypted,
               d.deck_front_language, d.deck_back_language
          FROM flashcards f
          JOIN directories d ON d.id = f.directory_id
         ORDER BY f.directory_id, f.sort_order, f.id
    """
    connection = database_connection()
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute(sql)
        rows = cursor.fetchall()
    finally:
        connection.close()

    result = []
    for row in rows:
        for side, fallback, deck_language in (
            ("front", "pt_br", row.get("deck_front_language")),
            ("back", "en_gb", row.get("deck_back_language")),
        ):
            default_language = language_key(deck_language, fallback)
            texts = decrypt_map(row.get(f"{side}_translations_encrypted"))
            legacy_text = clean_text(decrypt_data(row.get(f"{side}_encrypted")))
            if legacy_text and default_language not in texts:
                texts[default_language] = legacy_text

            audio_map = decrypt_map(row.get(f"audio_{side}_translations_encrypted"))
            for lang, raw_text in texts.items():
                if lang not in LANGUAGES:
                    continue
                text = clean_text(str(raw_text))
                if not text:
                    continue
                is_default = lang == default_language
                has_audio = bool(
                    row.get(f"audio_{side}_encrypted") if is_default else audio_map.get(lang)
                )
                if only_missing and has_audio:
                    continue
                result.append({
                    "card_id": int(row["id"]),
                    "deck_id": int(row["directory_id"]),
                    "side": side,
                    "language": lang,
                    "language_label": LANGUAGES[lang][0],
                    "text": text,
                    "has_audio": has_audio,
                    "is_default": is_default,
                })
    return result


def tts_model():
    global _tts
    if _tts is None:
        device = "cuda" if torch.cuda.is_available() else "cpu"
        _tts = TTS(model_name=MODEL_NAME).to(device)
    return _tts


def generate_audio(card_id: int, side: str, lang: str) -> None:
    candidates = [item for item in card_items(False) if (
        item["card_id"], item["side"], item["language"]
    ) == (card_id, side, lang)]
    if not candidates:
        raise ValueError("Texto/idioma nao encontrado para este card.")
    item = candidates[0]
    if not VOICE_PATH.is_file():
        raise FileNotFoundError(f"Audio de referencia nao encontrado: {VOICE_PATH}")
    sf.info(str(VOICE_PATH))

    temporary_path = None
    try:
        with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as temporary:
            temporary_path = Path(temporary.name)
        with _tts_lock:
            tts_model().tts_to_file(
                text=item["text"],
                file_path=str(temporary_path),
                speaker_wav=str(VOICE_PATH),
                language=LANGUAGES[lang][1],
                temperature=0.2,
            )
        audio_encrypted = encrypt_data(base64.b64encode(temporary_path.read_bytes()).decode("ascii"))
        persist_audio(item, audio_encrypted)
    finally:
        if temporary_path is not None:
            temporary_path.unlink(missing_ok=True)


def persist_audio(item: dict, audio_encrypted: str) -> None:
    side = item["side"]
    connection = database_connection()
    try:
        cursor = connection.cursor(dictionary=True)
        if item["is_default"]:
            query = f"""UPDATE flashcards
                           SET audio_{side}_encrypted = %s, has_audio_{side} = 1
                         WHERE id = %s"""
            cursor.execute(query, (audio_encrypted, item["card_id"]))
        else:
            column = f"audio_{side}_translations_encrypted"
            cursor.execute(f"SELECT {column} FROM flashcards WHERE id = %s FOR UPDATE", (item["card_id"],))
            row = cursor.fetchone()
            if not row:
                raise ValueError("Card nao encontrado durante a gravacao.")
            audio_map = decrypt_map(row.get(column))
            audio_map[item["language"]] = audio_encrypted
            encrypted_map = encrypt_data(json.dumps(audio_map, ensure_ascii=False, separators=(",", ":")))
            cursor.execute(f"UPDATE flashcards SET {column} = %s WHERE id = %s", (encrypted_map, item["card_id"]))
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


PAGE = """<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>XTTS local</title><style>
body{margin:0;background:#07101f;color:#e5edf8;font:15px system-ui}.wrap{max-width:1050px;margin:auto;padding:28px 16px}
h1{margin:0}.top{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap}.muted{color:#8ea1ba}
button{border:0;border-radius:9px;padding:10px 14px;background:#2563eb;color:white;font-weight:700;cursor:pointer}button:disabled{opacity:.55;cursor:wait}
.filter{background:#263449}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:13px;margin-top:20px}
.card{background:#101c2f;border:1px solid #283850;border-radius:13px;padding:15px}.meta{font-size:12px;color:#8ea1ba}.text{line-height:1.5;margin:11px 0;overflow-wrap:anywhere}
.badge{display:inline-block;padding:3px 8px;border-radius:99px;background:#34445d;font-size:12px}.ok{background:#075f46}.error{color:#fda4af;margin-top:8px;font-size:13px}
</style></head><body><main class="wrap"><div class="top"><div><h1>Gerador XTTS local</h1><div class="muted" id="summary">Carregando...</div></div>
<button class="filter" id="toggle">Mostrar todos</button></div><div class="grid" id="cards"></div></main><script>
let missing=true, items=[]; const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function load(){let r=await fetch('/api/items?missing='+(missing?1:0));let d=await r.json();if(!r.ok)throw Error(d.error||'Falha');items=d.items;render()}
function render(){summary.textContent=`${items.length} texto(s) ${missing?'sem audio':'no total'}`;cards.innerHTML=items.map((x,i)=>`<article class="card"><div class="meta">Deck #${x.deck_id} · Card #${x.card_id} · ${x.side==='front'?'frente':'verso'}</div><p><span class="badge ${x.has_audio?'ok':''}">${esc(x.language_label)} · ${x.has_audio?'com audio':'sem audio'}</span></p><div class="text">${esc(x.text)}</div><button onclick="generate(${i},this)">${x.has_audio?'Gerar novo audio':'Gerar audio'}</button><div class="error" id="e${i}"></div></article>`).join('')||'<p class="muted">Nenhum texto pendente.</p>'}
async function generate(i,b){b.disabled=true;b.textContent='Gerando...';document.querySelector('#e'+i).textContent='';try{let r=await fetch('/api/generate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(items[i])});let d=await r.json();if(!r.ok)throw Error(d.error||'Falha');items[i].has_audio=true;b.textContent='Gerar novo audio';b.disabled=false;b.previousElementSibling?.classList.add('ok')}catch(e){b.disabled=false;b.textContent=items[i].has_audio?'Gerar novo audio':'Gerar audio';document.querySelector('#e'+i).textContent=e.message}}
toggle.onclick=()=>{missing=!missing;toggle.textContent=missing?'Mostrar todos':'Mostrar apenas pendentes';load().catch(e=>summary.textContent=e.message)};load().catch(e=>summary.textContent=e.message);
</script></body></html>"""


class Handler(BaseHTTPRequestHandler):
    def send_json(self, status: int, payload: dict):
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        parsed = urlparse(self.path)
        try:
            if parsed.path == "/":
                data = PAGE.encode("utf-8")
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)
            elif parsed.path == "/api/items":
                missing = parse_qs(parsed.query).get("missing", ["1"])[0] != "0"
                self.send_json(200, {"items": card_items(missing)})
            else:
                self.send_json(404, {"error": "Rota nao encontrada."})
        except Exception as error:
            self.send_json(500, {"error": str(error)})

    def do_POST(self):
        if urlparse(self.path).path != "/api/generate":
            return self.send_json(404, {"error": "Rota nao encontrada."})
        try:
            length = int(self.headers.get("Content-Length", "0"))
            body = json.loads(self.rfile.read(length))
            generate_audio(int(body["card_id"]), str(body["side"]), str(body["language"]))
            self.send_json(200, {"status": "success"})
        except Exception as error:
            self.send_json(400, {"error": str(error)})

    def log_message(self, message, *args):
        print(f"[{self.log_date_time_string()}] {message % args}")


def validate_configuration() -> None:
    placeholders = {"HOST", "USER", "PASSWORD", "DATABASE", "CHAVE_DE_CRIPTOGRAFIA_DO_SITE"}
    values = {DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, ENCRYPTION_KEY}
    if placeholders & values:
        raise RuntimeError("Preencha o bloco CONFIGURACAO no inicio do arquivo antes de executar.")


def main() -> None:
    parser = argparse.ArgumentParser(description="Interface local para geracao XTTS")
    parser.add_argument("--check", action="store_true", help="testa configuracao, banco, voz e criptografia")
    args = parser.parse_args()
    validate_configuration()
    if args.check:
        sf.info(str(VOICE_PATH))
        connection = database_connection()
        connection.close()
        probe = "teste de criptografia"
        if decrypt_data(encrypt_data(probe)) != probe:
            raise RuntimeError("Falha no autoteste de criptografia.")
        print("Configuracao, banco, WAV e criptografia verificados com sucesso.")
        return
    server = ThreadingHTTPServer((HTTP_HOST, HTTP_PORT), Handler)
    print(f"Interface disponivel em http://{HTTP_HOST}:{HTTP_PORT}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nServidor encerrado.")
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
