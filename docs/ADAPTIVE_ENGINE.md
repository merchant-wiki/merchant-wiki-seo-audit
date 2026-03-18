Profiles
•	fast: start batch=80, budget≈1200ms/URL, timeout=8s
•	standard: batch=40, budget≈800ms/URL, timeout=10s
•	safe: batch=20, budget≈600ms/URL, timeout=12s

EWMA (per step)
•	ewma = α*t + (1-α)*ewma_prev, α=0.3.
•	If ewma > budget: ↓batch by 20% (floor 5) and ↑timeout by +2s (cap 15s).
•	If ewma < 0.6*budget: ↑batch by 20% (cap 120) and ↓timeout by −1s (floor 6s).
•	Backoff on errors (HTTP ≥500 / timeouts): halve batch once; cool down 2 ticks.

Tick contract
•	Each tick: process batch items; return new done/total/errors/percent/eta/batch.
•	Frontend polls until percent==100 or user stops.
