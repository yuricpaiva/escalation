# Escalation 2.1 — Motor de fluxos para GLPI 11

## Editor visual 2.1

Abra **Administração > Escalation**, escolha um fluxo e acesse a aba **Editor visual**. O editor permite montar uma sequência de aprovações e ações usando usuários, grupos, categorias, entidades e modelos já cadastrados no GLPI.

Para recompilar os recursos do editor:

```bash
npm install
npm run build
```

Os arquivos gerados ficam em `public/build` e devem acompanhar o plugin na instalação. O servidor de produção não precisa ter Node.js.

Um fluxo comum de pagamento pode ser montado como: **Início → Aprovação do RH → Aprovação Financeira → Atribuir chamado → Fim**. Configure a categoria e o solicitante nas condições de início, escolha os aprovadores em cada etapa e selecione o técnico que receberá o chamado. Use **Validar fluxo** antes de publicar.

The **Escalation** plugin provides versioned, auditable approval workflows for GLPI tickets: triggers and conditions select a flow, sequential steps resolve dynamic approvers, decisions choose a branch, and an idempotent queue executes native GLPI actions.

---

## Features

- **Multi-step flows** — each flow defines ordered steps that execute sequentially; a step only starts after the previous one is fully resolved
- **Optional technician activities** — administrators can make an action available under *Adicionar atividade*; the technician sees only its business name while routing rules remain in the flow
- **Two step types**
  - **People** — validations are sent to specific users and/or to ticket actors (requesters, assigned, observers)
  - **Hierarchy** — validations are resolved dynamically by traversing the requester's supervisor chain
- **Dynamic and fixed hierarchy resolution**
  - *Dynamic*: finds the immediate supervisor of each requester (one level up)
  - *Fixed level*: climbs the supervisor chain until reaching the configured organisational level (e.g. always escalate to the Director)
- **Per-step quorum** (People steps) — `all`, `majority` (> 50 %), or `min1` (first approval advances the flow)
- **Approval templates** — reusable text templates with attached documents sent as the validation request content
- **ITIL category binding** — each flow is linked to one or more ITIL categories; a ticket's category determines which flow (if any) is triggered
- **Hierarchical user levels** — levels are mapped to either **user categories** or **groups**, configured globally under Setup → Configuration → Escalation
- **Approval tab on tickets** — dedicated tab showing each step's status, approvers, and timestamps

---

## Requirements

| Dependency | Version |
|---|---|
| GLPI | ≥ 11.0.0 and < 12.0.0 |
| PHP | ≥ 8.2 |

---

## Installation

1. Download the plugin archive and extract it to `<glpi>/marketplace/escalation/`
2. In GLPI go to **Setup → Plugins**, find *Escalation* and click **Install**, then **Enable**
3. Configure profile rights under **Administration → Profiles → Escalation**
4. Configure the hierarchy type under **Setup → Configuration → Escalation** if legacy hierarchical levels are still used

For upgrades from 1.2.0, read [docs/UPGRADE-2.0.md](docs/UPGRADE-2.0.md). Administration, permissions, cron, import, audit and reprocessing are covered in [docs/ADMINISTRATION.md](docs/ADMINISTRATION.md). A reproducible Docker environment is documented in [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

## Escalation 2.0 capabilities

- Published immutable versions and editable drafts
- AND conditions inside OR groups, scoped by entity and priority
- User, group, direct-supervisor, hierarchy and ticket-actor approvers
- Safe missing-approver state, strict quorum and explicit outcome branches
- Native assignment, status, followup, task, category, notification and related-ticket actions
- Persistent retry queue, execution/action idempotency and append-only audit events
- Separate business decision and automation status
- Importable payment workflow in `examples/payment-approval.json`

---

## Configuration

### 1 — Hierarchy type

Go to **Setup → Configuration → Escalation**.

| Setting | Description |
|---|---|
| User category | Levels are determined by the user's profile category (`UserCategory`) |
| Group | Levels are determined by the user's group membership |

Add a level mapping for each organisational level (level 1 = highest, level N = lowest).

### 2 — Approval flows

Go to **Administration → Escalation → Flows**.

- Create a flow, assign one or more ITIL categories and (optionally) a global auto-rejection timeout in days
- Inside the flow, add steps in order using the inline form

**Step fields:**

| Field | Description |
|---|---|
| Name | Label shown in the approval tab |
| Type | *People* or *Hierarchy* |
| Level / Dynamic | *(Hierarchy only)* Fixed target level, or **Dynamic** (supervisor of each requester) |
| Quorum | *(People only)* `all` / Majority / Minimum 1 |
| Generate on | *Immediately* on ticket creation, or when the ticket is set to **Solved** |
| Timeout (days) | Per-step auto-rejection; 0 = no timeout |
| Approval template | Optional template with pre-filled content and attached documents |

For **People** steps, add specific approvers after creating the step, and optionally include requesters / assigned / observers via the checkboxes.

### 3 — Approval templates

Go to **Administration → Escalation → Templates**.

A template contains rich text (shown as the validation request content) and can have documents attached. Templates are reusable across multiple steps and flows.

---

## How approval flows work

### Optional technician activities

Add **Atividade opcional** to the visual editor at the point where a technician must choose whether extra work is needed. Give it a business-facing name (for example, *Realizar compra pela internet*), choose the internal GLPI action and configure its parameters. On the ticket, the currently assigned technician uses **Adicionar atividade** and selects that name. The configured action then runs and the flow continues normally. If the ticket is solved without selecting it, the optional activity is recorded as skipped and does not block completion.

```
Ticket created
    │
    └─ ITIL category matches a flow?
           │ Yes
           ▼
       Flow starts (TicketFlow created)
           │
           └─ Step 1 activated
                  │
                  ├─ Hierarchy / dynamic  →  finds supervisor of each requester
                  ├─ Hierarchy / fixed    →  climbs supervisor chain to target level
                  └─ People              →  specific users + configured actor groups
                         │
                         ▼
                   TicketValidation created for each resolved approver
                         │
                  Approver(s) respond
                         │
                         ├─ Quorum reached?  Yes  →  Step marked Approved → next step
                         └─ Any refusal?     Yes  →  Flow marked Rejected
                                │
                          All steps approved
                                │
                                ▼
                         Flow marked Approved
                         Followup added to ticket
```

---


## Authors

- Ampris
- Matheus Schmidt

## License

GPL v2+
