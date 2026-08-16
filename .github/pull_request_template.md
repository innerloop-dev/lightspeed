**What this changes, and why.**

**How you know it works.**

Before trusting a new test, watch it fail against the old code, and say what
you saw. Removing the guard you just added is a better control than deleting
the file: deleting only proves the code was absent.

- [ ] `vendor/bin/pest` passes
- [ ] Any new test was observed failing first
- [ ] Comments explain why, not what

If this touches authorization, the relay, or anything that decides whether a
message is allowed: what does it do when its dependency is missing, stale, or
throwing? Every round of review on this package has found a fail-open path,
and most of them were in the code that handles failure.
