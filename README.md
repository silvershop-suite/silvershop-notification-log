# SilverShop Notification Log

An **opt-in, immutable audit log of every email** [SilverShop](https://github.com/silvershop/silvershop-core)
sends: who was mailed, which notification, when, and whether it was accepted by the transport — captured
**zero-touch** at the framework mailer, with a read-only **Notifications** tab on Order and Member and
**configurable, GDPR-minded retention and erasure**.

It is the email counterpart of [silvershop-suite/ledger](https://github.com/silvershop-suite/silvershop-ledger): where the
ledger is an immutable record of *money*, this is an immutable record of *what was sent to whom*. It is
**additive and opt-in** and requires **no changes to core** — it only hooks an existing framework extension
point.

## How capture works (zero-touch)

Every `Email::send()` in the whole app funnels through the framework's `MailerSubscriber`, whose
`onMessage()` ends with `$this->extend('updateOnMessage', $email, $event)`. This module registers one
`Extension` on that point (a single line of YAML). There is **no Injector override, no Mailer decorator, and
no change to any sending module** — install it and every email, from core or any add-on, is logged.

At capture time the message is fully rendered (final To / From / Subject / body, absolute URLs), which is
exactly what gets recorded. Queued and rejected events are skipped, and a capture failure can never block a
send.

## Attribution

Each row is tied back to a customer and (where possible) an order:

- **Member** — the recipient address is matched to a `Member` (`MemberExtension::get_by_identifier()`).
  Works for every email with no sender changes. Admin copies resolve to no member.
- **Order** — read from an `X-SilverShop-Order-ID` header when a sender sets one (deterministic), otherwise
  a zero-touch fallback sniffs `Email::getData()->Order` (core order emails already pass the order into
  `setData()`). So the log attributes order emails on day one against an unmodified core.
- **Notification key** — read from an `X-SilverShop-Notification-Key` header when present, else guessed from
  the email template basename (e.g. `Order_ReceiptEmail` → `order.receipt`). The key drives the GDPR
  **category** (Transactional / Marketing / Admin).

> **Follow-up (not in this module):** senders can set the two `X-SilverShop-*` headers where they build the
> `Email` for exact, deterministic attribution. Setting a header is harmless with or without this log
> installed; it is a one-line add per send-site, intended to be folded into the senders separately.

## What it does

- **`SentNotification`** — an immutable, append-only record: `Recipient`, `RecipientName`, `Subject`,
  `NotificationKey` + `NotificationLabel`, `Category`, `SentAt`, `Success` + `Error`, `MessageID`, optional
  `Body`, `PseudonymisedAt`, `LegalHold`, and `Member` / `Order` links. The factual record of *what was sent*
  cannot be edited or deleted; only the data-protection fields (PII redaction, the member link, the legal
  hold) may change, so an erasure can pseudonymise a row without rewriting history.
- **A read-only "Notifications" tab** on the order (CMS) and on the member (SecurityAdmin), plus a global
  **`NotificationLogAdmin`** with search and CSV export.
- **`PruneSentNotificationsTask`** (`sake tasks:SilverShop-NotificationLog-Prune`) — enforces the retention
  policy.
- **Erasure / export hooks** on `Member` (`$member->eraseNotifications()` / `exportNotifications()`) a central
  "forget / export this customer" flow can call.

## Metadata by default; body is a second opt-in

A metadata row is ~1 KB; a rendered HTML body is ~15–60 KB. The default is **metadata only**
(`store_body = false`) — the data-minimisation default. Turn on `store_body` only if you need exact-copy
retention, and pair it with a shorter body-retention window (below).

```yaml
SilverShop\NotificationLog\Service\NotificationCapture:
  store_body: false      # keep metadata only (recommended default)
  log_all_emails: true   # false = log only shop-attributed mail (a key/order/member resolved)
```

## Installation

```bash
composer require silvershop-suite/notification-log
```

Then run `dev/build`. Optionally schedule the prune task.

---

## EU data-protection & retention — a defensible-design spec (NOT legal advice)

> A sent-notification log is, by definition, **personal data** under the GDPR/AVG: it stores recipient
> addresses and names, and (if `store_body`) bodies containing names, addresses and order details. Its
> retention is therefore governed by EU law and must be deliberate and defensible — which is why every
> retention control here is **configurable and bounded, never "keep forever."** This section documents the
> design posture and the knobs that implement it. **It is not legal advice. Verify the actual windows,
> lawful bases and statutory references with your DPO / legal / tax advisor.** Items to confirm are marked
> *(verify)*.

### Storage limitation (Art. 5(1)(e)) → configurable retention, default 365 days

Retention windows are set in YAML and can be overridden per install in the CMS (**Settings → Notifications**)
without a deploy. A window of `0` means unbounded (that prune pass is skipped). The prune task enforces them.

```yaml
SilverShop\NotificationLog\Model\SentNotification:
  keep_days: 365                # baseline (shipped default)
  keep_days_transactional: 730  # order/receipt/status — longer (contract / legitimate interest) (verify)
  keep_days_marketing: 180      # newsletter/review/stock-alert — shorter (consent) (verify)
  keep_body_days: 90            # drop stored bodies first, keep metadata longer
```

### Lawful basis per type → the split retention (Art. 6)

Different notifications rest on different lawful bases, which justifies different windows (the `Category`
field): **transactional/order** emails on contract (Art. 6(1)(b)) and/or legitimate interest (Art. 6(1)(f))
support a **longer** window; **marketing/opt-in** emails rest on consent (Art. 6(1)(a); electronic direct
marketing under the ePrivacy Directive / in NL the *Telecommunicatiewet* — *verify*) and expire **sooner**.

### Not a system of record (boundary with invoicing/ledger)

**This log is operational, not fiscal.** Statutory financial records (invoices, credit notes — in NL the
*fiscale bewaarplicht*, commonly ~7 years under the AWR — *verify exact scope with your accountant*) are
produced and retained by [silvershop-suite/invoicing](https://github.com/silvershop-suite/silvershop-invoicing) +
[silvershop-suite/ledger](https://github.com/silvershop-suite/silvershop-ledger), **not here.** Do **not** raise this log's
retention to satisfy tax obligations — it may and should expire much sooner (its default is 365 days).

### Right to erasure (Art. 17) → pseudonymise by default

On an erasure request or a Member delete, the default is to **pseudonymise** (Art. 4(5)): null the member
link, hash the recipient, redact name/subject, drop any body, and stamp `PseudonymisedAt` — keeping only the
non-identifying skeleton (which notification, when, category, success) for counts/debugging. **Hard-delete**
is available by config. A per-row **legal hold** (Art. 17(3)) is honoured — held rows are never erased or
pruned.

```yaml
SilverShop\NotificationLog\Service\NotificationErasureService:
  erasure_mode: pseudonymise   # or: delete
```

A central erasure/export flow (e.g. [silvershop-suite/cookieconsent](https://github.com/silvershop-suite/silvershop-cookieconsent))
should call `$member->eraseNotifications()` / `$member->exportNotifications()` so this table is swept too; the
member "Notifications" tab doubles as the per-subject disclosure surface for access requests (Art. 15 / 20 —
*verify*).

### External ESP = a processor with its own retention (Art. 28)

If outgoing mail goes through an external ESP/relay (via `MAILER_DSN`), that ESP is a **data processor** with
its own copy and retention governed by your **Data Processing Agreement** with it (*verify — the DPA is the
shop's responsibility*). This log is the shop's own first-party copy and is independent of the ESP's logs.

## Status

v1 — the metadata audit log. **v2** (planned): opt-in body storage default, authoritative Sent/Failed status
+ message-id + retry (a second subscriber on the mailer's Sent/Failed events), age-based
pseudonymisation, a guarded **resend** action, and full-text search.

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
