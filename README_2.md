# KofC AI Advisor

An AI-powered platform that helps **Knights of Columbus field agents** build initial insurance
recommendations and client plans — grounded in KofC's own products, regulations, and training
material, with a human agent always in the loop and a supervisor able to oversee and shape what
the system learns.

The model (OpenAI) stays fixed and auditable; the intelligence accrues in the parts the
organization controls: the knowledge base, the prompts, the guardrails, and supervisor-approved
exemplars.

---

## What it does

- **Advisor (conversational).** A rep describes a client in their own words — typed or spoken —
  and gets a working plan back, then asks follow-ups with full conversation context. Voice input
  (dictation) and voice output (read-aloud) included.
- **Recommend (structured).** A short member profile produces ranked product recommendations with
  plain-language rationale, deterministic suitability guardrails, and an agent accept/override
  review step.
- **Grounded answers.** Every response is retrieved from a tagged knowledge base of KofC source
  documents and cites the source it used.
- **Supervised feedback loop.** Agents rate and correct answers; a supervisor reviews them on a
  dashboard and promotes approved corrections into a `vetted` collection that feeds back into
  retrieval — learning without retraining.
- **Admin tooling.** Document upload by category, a supervisor dashboard, and user management.

---

## Architecture

```
 React SPA (web/)  ──/api──►  PHP API (api/)  ──►  MySQL / RDS
 advisor.stockloyal.com        Apache + PHP 8         (audit, KB, users)
        │                          │
        │                          ├──►  OpenAI Chat  (gpt-4o-mini)
        │                          └──►  OpenAI Embeddings (text-embedding-3-small)
        │
 Admin pages (admin/*.html) ──/api──►  admin-only endpoints
```

- **Frontend:** React + Vite SPA. Same-origin with the API (`/api`) in both dev (via Vite proxy)
  and production (Apache vhost), so session-cookie auth works without CORS.
- **Backend:** plain PHP 8 endpoints (PDO, exception mode). No framework.
- **Data tier:** MySQL/MariaDB locally; a dedicated database on RDS in production.
- **AI:** OpenAI for chat completions and embeddings. A mock mode (`ai_mock`) runs the full
  pipeline with no key or cost for UI work.
- **Retrieval (RAG):** document chunks are embedded and stored in MySQL; cosine similarity in PHP.
  Swap the brute-force scan for a vector index (pgvector / OpenSearch) later behind the same
  interface.

---

## Tech stack

React 18, Vite 5, lucide-react · PHP 8 (PDO) · MySQL / MariaDB · OpenAI API · Apache `httpd` ·
AWS EC2 + RDS · Let's Encrypt.

---

## Project structure

```
kofc/
├── web/                    React SPA
│   ├── index.html
│   ├── package.json
│   ├── vite.config.js      (dev proxy: /api -> XAMPP)
│   └── src/
│       ├── main.jsx        Root: auth gate (login / forced password change / app)
│       ├── App.jsx         Advisor + Recommend
│       ├── Login.jsx
│       ├── ChangePassword.jsx
│       └── api.js          BASE = '/api'
│
├── api/                    PHP API (served at /api)
│   ├── config.php          loads secrets (env / /etc/kofc / config.local.php)
│   ├── cors.php  ai.php     shared helpers (CORS, OpenAI)
│   ├── login.php  logout.php  me.php  change-password.php
│   ├── user-list/save/delete.php   user-create.php (CLI)
│   ├── recommend.php  review.php    products.php  guardrails.php
│   ├── ask.php  chat.php             conversational + Q&A
│   ├── kb.php  kb-extract.php  kb-ingest.php (CLI)
│   ├── kb-upload/list/delete.php     collections.php
│   ├── feedback.php  feedback-list/review.php  metrics.php
│   └── admin-auth.php
│
├── admin/                  server-served admin pages (admin session required)
│   ├── index.html          knowledge-base document upload
│   ├── supervisor.html     feedback dashboard + promote-to-vetted
│   └── users.html          user management
│
├── sql/                    schema (load order below)
├── kb_sources/             source .txt by collection (kb_sources/<collection>/*.txt)
├── storage/                uploaded originals (not web-served)
├── deploy/                 AWS runbook + vhost + deploy script  (see deploy/DEPLOY.md)
└── README.md
```

---

## Local development (XAMPP)

**Prerequisites:** XAMPP (Apache + MySQL/MariaDB), Node 18+, project at `C:\xampp\htdocs\kofc`.

**1. Create the database and load the schema** (order matters):

```powershell
C:\xampp\mysql\bin\mysql -u root -e "CREATE DATABASE IF NOT EXISTS kofc_advisor"
cd C:\xampp\htdocs\kofc\sql
foreach ($f in 'schema','advisor_questions','kb_chunks','kb_collections','conversations','feedback','users','users_must_change') {
  Get-Content "$f.sql" -Raw | C:\xampp\mysql\bin\mysql -u root kofc_advisor
}
```

**2. Configure the backend.** Copy `api/config.local.php.example` → `api/config.local.php` and set
your OpenAI key. Leave `ai_mock => true` to run with no key/cost, or set it `false` to go live.

**3. Create a login user:**

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\kofc\api\user-create.php robert 'YourPassword' admin
```

**4. Run the SPA:**

```powershell
cd C:\xampp\htdocs\kofc\web
npm install
npm run dev
```

Open `http://localhost:5173` and sign in. Vite proxies `/api` to XAMPP, so the SPA is same-origin
with the backend in dev.

**5. Admin pages** are served by Apache at `http://localhost/kofc/admin/`. They require an admin
session; quickest local path is to set `'auth_disabled' => true` in `config.local.php` while
working on them (always `false` anywhere hosted).

---

## Database schema

| File | Purpose |
|------|---------|
| `schema.sql` | `recommendations`, `recommendation_reviews` (recommendation audit + review) |
| `advisor_questions.sql` | log of free-form questions |
| `kb_chunks.sql` | embedded document chunks (RAG store) |
| `kb_collections.sql` | adds `collection` / `jurisdiction` to `kb_chunks` |
| `conversations.sql` | `conversations`, `conversation_messages` (advisor chat) |
| `feedback.sql` | `advisor_feedback` (supervised loop) |
| `users.sql` | login users (bcrypt hashes, roles) |
| `users_must_change.sql` | adds forced-password-change flag |

Every AI output is written to an audit row tied to the exact inputs and model that produced it —
the compliance backbone.

---

## Knowledge base & authority order

Documents are tagged with a **collection**, and retrieval pulls a balanced mix and labels each in
**authority order**, so the model knows which sources are binding:

1. **Licensing & Regulations** — binding; override everything.
2. **Policy & Product** — authoritative facts (terms, rates, eligibility).
3. **Vetted (Supervisor-Approved)** — strongly preferred for matching questions.
4. **Sales & Training** — approach and positioning only; never overrides the above.

Add documents two ways: drop `.txt` files into `kb_sources/<collection>/` and run
`php api/kb-ingest.php`, or upload `.txt`/`.docx`/`.pdf` through `admin/index.html`. (PDF needs
`composer require smalot/pdfparser`.)

---

## The supervised feedback loop

1. **Capture** — agents thumbs-up/down advisor replies; a thumbs-down can include a reason code and
   a corrected answer.
2. **Measure** — the supervisor dashboard (`admin/supervisor.html`) shows positive rate, pending
   review, top down-vote reasons, and recommendation accuracy.
3. **Curate** — a supervisor reviews each item and edits the approved answer.
4. **Apply** — **Promote to Vetted** embeds the approved Q&A into the `vetted` collection, where it
   becomes retrievable knowledge the advisor prefers for similar questions.

The model never changes. The supervisor controls what gets learned, and every promotion is traceable
to who approved it.

---

## Auth & roles

Session-based login against the `users` table (bcrypt). Two roles: **agent** (advisor + recommend)
and **admin** (everything, plus the admin pages). Admins provision accounts via `admin/users.html`
with a temporary password; the user is forced to set their own on first login. Guardrails prevent
deleting your own account or the last remaining admin.

---

## Configuration & secrets

`config.php` loads settings from the first source that exists: `KOFC_CONFIG` env path →
`/etc/kofc/config.local.php` (production, outside the webroot) → `api/config.local.php` (local dev).
Keys: `db_*`, `openai_api_key`, `ai_model`, `ai_mock`, `auth_disabled`. Secrets never live in the
repo; `config.local.php`, `*.pem`, and `node_modules/` are gitignored.

---

## Deployment

Production target: a single EC2 box (Apache + PHP) serving the built SPA and API, with a dedicated
database on RDS, HTTPS via Let's Encrypt, on `advisor.stockloyal.com`. Full step-by-step runbook,
Apache vhost, RDS setup SQL, and a one-command Windows deploy script are in **`deploy/DEPLOY.md`**.

```powershell
.\deploy\deploy.ps1 -KeyPath C:\path\to\kofc.pem -Host ec2-user@<elastic-ip>
```

---

## Design principles

- **Agent-in-the-loop.** AI output is always an *initial* recommendation for a licensed agent to
  review — never a binding suitability determination.
- **Authority-ordered grounding.** Regulations bind over facts, facts over sales technique.
- **Auditability.** Every recommendation, question, and conversation is logged with its inputs and
  model version.
- **Human oversight of learning.** Nothing enters the system's knowledge without a supervisor
  approving it.

## Hardening backlog

Real admin auth + server-level protection on `/admin`; transaction-suitability rules (LTC / annuity
best-interest) as a distinct body alongside agent licensing; website crawler and internal-repository
connectors for ingestion; vector index at scale; optional fine-tuning once enough supervisor-approved
examples accumulate.
