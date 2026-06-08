# Setup Guide

Simple steps to run this project locally.

**Stack:** Laravel + Inertia + Vue (frontend) · MySQL via Docker (database only)

---

## Requirements

- PHP 8.3+, Composer, Node.js 18+
- Docker & Docker Compose

---

## 1. Start MySQL (Docker)

```bash
docker compose up -d
```

This starts MySQL on `127.0.0.1:3306` with database `finance_ai`.

---

## 2. Install dependencies

```bash
cp .env.example .env (if .env is available)
composer install
npm install
php artisan key:generate
```

---

## 3. Setup database

```bash
php artisan migrate:fresh --seed
```

---

## 4. Run the app

Use **two terminals**:

```bash
# Terminal 1 — Laravel
php artisan serve

# Terminal 2 — Vite (Inertia + Vue)
npm run dev
```

Open **http://127.0.0.1:8000**

---

## 5. Login

| Email             | Password   |
|-------------------|------------|
| john@example.com  | password   |
| sarah@example.com | password   |
| ali@example.com   | password   |

After login you land on **`/app`** (chat UI).

---

## Optional: OpenAI

Works without a key (rule-based routing + mock receipt OCR).

For LLM intent classification and real receipt OCR:

```env
OPENAI_API_KEY=sk-your-key-here
```

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| DB connection failed | Run `docker compose up -d` and check `.env` DB settings |
| Blank / unstyled page | Make sure `npm run dev` is running |
| Port 8000 busy | `php artisan serve --port=8001` |
| Stale UI after changes | Hard refresh (`Cmd+Shift+R`) or restart `npm run dev` |

---

## Stop MySQL

```bash
docker compose down
```
