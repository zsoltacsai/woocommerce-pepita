#!/usr/bin/env python3
"""Read-only acceptance check for downloaded WP Pepita Sync feeds.

The script never touches a network or a database. Download the feeds yourself
(for example `curl -fsS -o full.xml '<tokened full feed url>'`) and pass the files.

Usage:
  bin/feed-acceptance-check.py FULL.xml STOCK.xml --staging-host fndeco.com/staging \
      [--production-host fndeco.com] [--baseline previous-report.json] [--json report.json]

Exit status: 0 = no blocking finding, 1 = blocking finding(s), 2 = usage/parse error.
"""

import argparse
import json
import re
import sys
import xml.etree.ElementTree as ET
from collections import Counter
from urllib.parse import urlparse

NS = "{https://pepita.hu/feed/1.0}"
GTIN_RE = re.compile(r"^\d{8}$|^\d{12,14}$")
ALLOWED_IMAGE_EXT = (".jpg", ".jpeg", ".png")


def tag(name):
    return NS + name


def text(node, path):
    found = node.find(path)
    return "" if found is None or found.text is None else found.text.strip()


def parse(path):
    try:
        return ET.parse(path).getroot()
    except (OSError, ET.ParseError) as exc:
        print("cannot parse %s: %s" % (path, exc), file=sys.stderr)
        sys.exit(2)


def products(root):
    items = {}
    duplicates = []
    for node in root.iter(tag("Product")):
        pid = text(node, tag("Id"))
        if not pid:
            continue
        if pid in items:
            duplicates.append(pid)
        items[pid] = node
    return items, duplicates


def host_of(url):
    parsed = urlparse(url)
    return (parsed.netloc + parsed.path).lower()


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("full")
    ap.add_argument("stock")
    ap.add_argument("--staging-host", required=True, help="host+path prefix that identifies STAGING URLs, e.g. fndeco.com/staging")
    ap.add_argument("--production-host", default="", help="host that production URLs use (informational)")
    ap.add_argument("--baseline", default="", help="JSON report of an earlier run: product ids must stay stable")
    ap.add_argument("--json", default="", help="write the machine-readable report here")
    args = ap.parse_args()

    full_root, stock_root = parse(args.full), parse(args.stock)
    full, full_dups = products(full_root)
    stock, stock_dups = products(stock_root)

    blocking, warnings = [], []
    staging_prefix = args.staging_host.lower().rstrip("/")

    if full_dups:
        blocking.append("duplicate product ids in full feed: %s" % ", ".join(full_dups[:10]))
    if stock_dups:
        blocking.append("duplicate product ids in stock feed: %s" % ", ".join(stock_dups[:10]))

    # Full / stock consistency.
    only_full = sorted(set(full) - set(stock))
    only_stock = sorted(set(stock) - set(full))
    if only_full or only_stock:
        blocking.append("full/stock id mismatch: %d only in full, %d only in stock" % (len(only_full), len(only_stock)))

    qty_mismatch = []
    price_mismatch = []
    for pid in sorted(set(full) & set(stock)):
        fq = text(full[pid], "%s/%s" % (tag("Availability"), tag("Quantity")))
        sq = text(stock[pid], "%s/%s" % (tag("Availability"), tag("Quantity")))
        fa = text(full[pid], "%s/%s" % (tag("Availability"), tag("Available")))
        sa = text(stock[pid], "%s/%s" % (tag("Availability"), tag("Available")))
        if fq != sq or fa != sa:
            qty_mismatch.append(pid)
        fp = text(full[pid], "%s/%s" % (tag("Prices"), tag("Price")))
        sp = text(stock[pid], "%s/%s" % (tag("Prices"), tag("Price")))
        if fp != sp:
            price_mismatch.append(pid)
    if qty_mismatch:
        blocking.append("quantity/availability differs between full and stock for %d products" % len(qty_mismatch))
    if price_mismatch:
        blocking.append("price differs between full and stock for %d products" % len(price_mismatch))

    # Per-product content checks on the full feed.
    no_photo, no_category, bad_gtin, bad_price, staging_urls, webp_or_bad_ext = [], [], [], [], [], []
    variable = 0
    variation_total = 0
    photo_hosts = Counter()
    for pid, node in full.items():
        photos = [p.text.strip() for p in node.iter(tag("Url")) if p.text]
        if not photos:
            no_photo.append(pid)
        for url in photos:
            photo_hosts[urlparse(url).netloc.lower()] += 1
            if host_of(url).startswith(staging_prefix):
                staging_urls.append(pid)
            path = urlparse(url).path.lower()
            if not path.endswith(ALLOWED_IMAGE_EXT):
                webp_or_bad_ext.append(pid)
        if node.find(tag("Categories")) is None:
            no_category.append(pid)
        gtin = text(node, tag("StructuredId"))
        if gtin and not GTIN_RE.match(gtin):
            bad_gtin.append(pid)
        try:
            if float(text(node, "%s/%s" % (tag("Prices"), tag("Price"))) or "0") <= 0:
                bad_price.append(pid)
        except ValueError:
            bad_price.append(pid)
        variations = node.findall("%s/%s" % (tag("Variations"), tag("Variation")))
        if variations:
            variable += 1
            variation_total += len(variations)

    if no_category:
        blocking.append("%d products without a category" % len(no_category))
    if bad_gtin:
        blocking.append("%d products with an invalid GTIN (must be 8, 12, 13 or 14 digits)" % len(bad_gtin))
    if bad_price:
        blocking.append("%d products with a missing or non-positive price" % len(bad_price))
    if webp_or_bad_ext:
        blocking.append("%d products reference images that are not .jpg/.jpeg/.png" % len(set(webp_or_bad_ext)))
    if no_photo:
        warnings.append("%d products without any photo" % len(no_photo))
    if staging_urls:
        warnings.append(
            "%d products carry STAGING image URLs (%s): fine for staging acceptance, NEVER send this feed to Pepita"
            % (len(set(staging_urls)), staging_prefix)
        )

    # Identifier stability against an earlier run.
    if args.baseline:
        try:
            with open(args.baseline, encoding="utf-8") as handle:
                base = json.load(handle)
            base_ids = set(base.get("full_ids", []))
            removed = sorted(base_ids - set(full))
            if removed:
                blocking.append("%d product ids of the baseline disappeared (identifier instability)" % len(removed))
        except (OSError, ValueError) as exc:
            print("cannot read baseline: %s" % exc, file=sys.stderr)
            sys.exit(2)

    report = {
        "full_products": len(full),
        "stock_products": len(stock),
        "variable_products": variable,
        "variations": variation_total,
        "only_in_full": only_full[:50],
        "only_in_stock": only_stock[:50],
        "quantity_mismatch_count": len(qty_mismatch),
        "price_mismatch_count": len(price_mismatch),
        "without_photo": len(no_photo),
        "without_category": len(no_category),
        "invalid_gtin": len(bad_gtin),
        "staging_image_urls_products": len(set(staging_urls)),
        "image_hosts": dict(photo_hosts),
        "blocking": blocking,
        "warnings": warnings,
        "full_ids": sorted(full),
    }

    print("Full feed products   : %d (variable: %d, variations: %d)" % (len(full), variable, variation_total))
    print("Stock feed products  : %d" % len(stock))
    print("Image hosts          : %s" % dict(photo_hosts))
    for line in warnings:
        print("WARNING : " + line)
    for line in blocking:
        print("BLOCKING: " + line)
    print("RESULT  : %s" % ("FAIL" if blocking else "PASS (no blocking finding)"))

    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump(report, handle, ensure_ascii=False, indent=2)

    sys.exit(1 if blocking else 0)


if __name__ == "__main__":
    main()
