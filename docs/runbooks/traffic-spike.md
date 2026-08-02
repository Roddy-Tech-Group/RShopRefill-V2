# Runbook · Traffic spike / suspected DDoS

**Severity when invoked for real:** SEV-3 (slow) → SEV-2 (checkout impaired) → SEV-1 (down)
**Owner:** CTO · **Related:** Cloudflare dashboard · [OPERATIONS.md](../OPERATIONS.md)

> Never absorb an L7 flood at origin. Cloudflare is in front of the site for
> exactly this moment — the fight happens at the edge.

---

## 1 · Detect

- Load/latency alerts; `/health` slow or failing under load.
- Horizon queue waits climbing across ALL queues at once (workers starved of CPU).
- Cloudflare analytics showing a request spike.

## 2 · Diagnose at the edge — Cloudflare Analytics first

| Pattern in Cloudflare | Meaning | Response |
|---|---|---|
| One IP / small ASN set, one route | Attack or scraper | §3A |
| Everything up proportionally, geographies plausible | Real demand (promo? viral?) | §3B |
| One route only, distributed IPs | Scraper botnet or hot page | Rate-limit that route at the edge |

Ask Roddy: **is there a promotion running that engineering wasn't told about?**
(It has happened at every company; put the answer in the incident note either way.)

## 3A · Attack

1. Cloudflare → **Under Attack mode** (or a targeted WAF rate rule on the hot route — prefer targeted).
2. Verify origin IP is not exposed (all DNS records proxied/orange-cloud). If the attack bypasses Cloudflare, the origin IP has leaked — firewall to Cloudflare IP ranges only and plan an IP rotation.
3. Do not restart services mid-flood; they will just die again slower.

## 3B · Real demand — shed the right load

1. Protect checkout first. Pause non-essential Horizon supervisors (marketing/comm queues) so payment and fulfillment workers keep their CPU (see `config/horizon.php` supervisors; pause from the Horizon UI).
2. If on a resizable VPS: scale vertically now, argue later.
3. Widen throttles only on read paths (catalog browsing), never on payment endpoints.

## 4 · Recover

- Attack subsides: lift Under Attack mode (it hurts conversion), keep the targeted rule for a week.
- Demand spike ends: unpause supervisors, restore throttles.

## 5 · Validate

- p95 latency back to baseline; Monitoring page green.
- Queue waits back under thresholds; no stuck paid orders left behind (`fulfillment:retry --all-failed` if any).

## 6 · Prevent

- Record the peak RPS survived — that number IS the capacity plan.
- Standing rule with Roddy: marketing tells engineering **before** campaigns.
- Pre-scale checklist for planned promotions (throttles, comm-queue pause, provider float top-up).
