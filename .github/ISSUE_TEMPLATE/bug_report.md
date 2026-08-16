---
name: Bug report
about: Something behaves differently from what the docs say
labels: bug
---

**What happened, and what you expected instead.**

**How to reproduce it.** The more of this you can give, the faster it gets
fixed. A failing test is ideal; the commands you ran are fine.

**Your setup:**

- PHP version, and `php -m | grep swoole`
- Laravel version
- Redis version, and whether it is local or over a network
- Relevant `LIGHTSPEED_*` settings, with secrets removed
- `worker_num`, and whether `enable_coroutine` is on

**Anything the server logged.** Run with `--log-verbose` if you can.

If this is a security issue, please do not file it here.
See [SECURITY.md](../../SECURITY.md).
