#!/usr/bin/env python3
"""Synthèse d'une campagne, comparée aux mesures de référence (sans l'application).

Verdict :
  - aucune LEAK / ORACLE / CROSS / GHOSTLEAK ;
  - aucun BROKEN ou MISS qui n'existait pas déjà dans la référence (régression).
"""
import json
import os
import sys
from collections import Counter

d = sys.argv[1]
pairs = [("discovery.json", "out/baseline_discovery.json"), ("actions.json", "out/baseline_actions.json"),
         ("actions2.json", "out/baseline_actions2.json"), ("functions.json", "out/baseline_functions.json")]
bad = Counter()
regress = []
lines = []
for cur, base in pairs:
    p = os.path.join(d, cur)
    if not os.path.exists(p):
        lines.append(f"{cur}: ABSENT")
        bad["absent"] += 1
        continue
    res = json.load(open(p))
    ref = json.load(open(base)) if os.path.exists(base) else []
    refk = {(r["kind"], r["vector"], r["actor"]) for r in ref if r["kind"] in ("BROKEN", "MISS", "FAIL")}
    refpass = {(r["vector"], r["actor"]) for r in ref if r["kind"] == "PASS"}
    c = Counter(r["kind"] for r in res)
    lines.append(f"{cur}: {dict(c)}")
    for r in res:
        if r["kind"] in ("LEAK", "ORACLE", "CROSS", "GHOSTLEAK"):
            bad[r["kind"]] += 1
            lines.append(f"  !! {r['kind']} {r['vector']} {r['actor']} {json.dumps(r['detail'], ensure_ascii=False)[:300]}")
        elif r["kind"] == "FAIL":
            if (r["vector"], r["actor"]) in refpass:
                regress.append(r)
                lines.append(f"  !! RÉGRESSION {r['vector']} {r['actor']} {json.dumps(r['detail'], ensure_ascii=False)[:300]}")
            elif not any((x["vector"], x["actor"]) == (r["vector"], r["actor"]) for x in ref):
                regress.append(r)
                lines.append(f"  !! ÉCHEC (test absent de la référence) {r['vector']} {r['actor']} {json.dumps(r['detail'], ensure_ascii=False)[:300]}")
            else:
                lines.append(f"  .. échec déjà présent sans l'application : {r['vector']} {r['actor']}")
        elif r["kind"] in ("BROKEN", "MISS"):
            if (r["kind"], r["vector"], r["actor"]) not in refk:
                regress.append(r)
                lines.append(f"  !! RÉGRESSION {r['kind']} {r['vector']} {r['actor']} {json.dumps(r['detail'], ensure_ascii=False)[:300]}")
ok = not bad and not regress
lines.append("")
lines.append("VERDICT : " + ("RÉUSSI (0 fuite, 0 oracle, 0 action croisée, 0 régression)" if ok else
                              f"ÉCHEC {dict(bad)} régressions={len(regress)}"))
print("\n".join(lines))
sys.exit(0 if ok else 1)
