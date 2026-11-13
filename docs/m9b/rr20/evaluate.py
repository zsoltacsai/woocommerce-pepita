#!/usr/bin/env python3
"""Evaluate RR20 invariants from an evidence directory. Exit 1 on any violation.

I2 exactly one valid WooCommerce order; I5 claim<->order consistency; I3 stock reduced
exactly once (or not at all only if no order exists); I8 a 200 answer implies a final order;
I7 the retry converges without manual DB intervention; I6 no orphan rows (compared with 0-before).
"""
import json, re, sys, os

d = sys.argv[1]
def rd(n): return open(os.path.join(d, n), encoding="utf-8", errors="replace").read()
def code(n): return rd(n).strip()
def state(label):
    t = rd(label + ".state.json")
    return json.loads(t[t.index("{"):])
def claim(label):
    rows = [l for l in rd(label + ".claims.txt").splitlines() if l.startswith("|") and "external_id" not in l]
    if not rows: return None
    c = [x.strip() for x in rows[0].strip("|").split("|")]
    return {"status": c[1], "attempts": int(c[2]), "wc_order_id": int(c[3])}
def orphans(label):
    return [int(x) for x in re.findall(r"\|\s+(\d+)\s+\|", rd(label + ".orphans.txt"))]

payload = json.loads(rd("payload.json"))
qty = {p["sku"]: int(p["quantity"]) for p in payload["products"]}
A, B, C = code("A.code"), code("B.code"), code("C.code")
before, final = state("0-before"), state("5-after-retry")
cl = claim("5-after-retry")
orders = final["orders"]
fails, notes = [], []

def check(name, ok, detail=""):
    print(("PASS  " if ok else "FAIL  ") + name + ((" - " + detail) if detail else ""))
    if not ok: fails.append(name)

print("responses: A=%s B=%s C=%s" % (A, B, C))
check("I2 exactly one WooCommerce order for the external id after retry", len(orders) == 1, "found %d" % len(orders))
o = orders[0] if len(orders) == 1 else None
if o:
    check("I2 order is finalized (no in-progress marker, imported_at set)", o["in_progress"] in ("", None, False) and bool(o["imported_at"]), json.dumps(o))
    check("I2 order has items and a non-zero total", o["items"] >= 1 and float(o["total"]) > 0, "items=%s total=%s" % (o["items"], o["total"]))
    check("I2 order status is a final import status", o["status"] in ("processing", "on-hold"), o["status"])
check("I5 claim is completed and points at the existing order", cl is not None and cl["status"] == "completed" and o is not None and cl["wc_order_id"] == int(o["id"]), json.dumps(cl))
for sku, q in qty.items():
    b, a = before["stock"].get(sku), final["stock"].get(sku)
    if b is not None and a is not None:
        check("I3 stock of %s reduced exactly once (by %d)" % (sku, q), int(b) - int(a) == q, "before=%s after=%s" % (b, a))
# I8: any success answer must be backed by a finalized order at the time it was given.
for label in ("3-B-done", "4-A-finished", "5-after-retry"):
    pass
if B == "200":  # (control mode: B is n/a)
    sB = state("3-B-done")["orders"]
    check("I8 B answered 200 only if a finalized order existed at that moment",
          len(sB) == 1 and sB[0]["in_progress"] in ("", None, False) and bool(sB[0]["imported_at"]),
          "order at B time: " + json.dumps(sB))
if C == "200":
    check("I8 retry answered 200 only with a finalized order present", o is not None and o["in_progress"] in ("", None, False))
check("I7 retry did not need manual DB intervention (C is 200 or retry-409)", C in ("200", "409"), C)
ob, oa = orphans("0-before"), orphans("5-after-retry")
check("I6 no new orphan rows", ob == oa, "before=%s after=%s" % (ob, oa))
trace = [json.loads(l) for l in rd("trace.jsonl").splitlines() if l.strip().startswith("{")] if os.path.exists(os.path.join(d, "trace.jsonl")) else []
ghost = [t for t in trace if t.get("event") == "hook" and t.get("exists") is False and t.get("hook") not in ("woocommerce_delete_order",)]
check("I4 no order-status/payment/stock hook fired for a non-existent order", not ghost, json.dumps(ghost[:3]))
mails = [t for t in trace if t.get("event") == "wp_mail"]
check("I4 no customer e-mail was sent", not mails, json.dumps(mails[:2]))
dels = [t for t in trace if t.get("hook") == "woocommerce_delete_order"]
print("deletions observed: %d" % len(dels))
print("RESULT: " + ("FAIL (%d)" % len(fails) if fails else "PASS"))
sys.exit(1 if fails else 0)
