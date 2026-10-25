# Changelog

All notable changes to this project are documented here.

## [4.0]

### Changed

**GitHooks is now Alfil.** Same tool and configuration shape, new names: the package is `wtyd/alfil` (`wtyd/githooks` is abandoned in its favour), the binary `vendor/bin/alfil`, the configuration file `alfil.php`, the PHP namespace `Wtyd\Alfil`. Upgrading is four commands:

```bash
composer remove --dev wtyd/githooks
composer require --dev wtyd/alfil
vendor/bin/alfil hook          # the 3.x hook scripts call vendor/bin/githooks, which no longer exists
vendor/bin/alfil conf:check
```

There is no `vendor/bin/githooks` alias: until `alfil hook` regenerates them, the 3.x scripts in `.githooks/` fail on every commit. `alfil status` flags them (`callsRemovedBinary` in its JSON) and `conf:check` warns about a `hooks.command` that still calls the old binary. `.githooks/` itself keeps its name. Machine-facing names change too: SARIF `tool.driver.name` is `alfil` (GitHub Code Scanning closes and reopens existing alerts once under the new name) and the GitLab CI section ids are `alfil_job_N` / `alfil_summary`. See [Upgrading from 3.x to 4.0](migration/to-4-0.md).

### Deprecated

The old names keep working until 5.0, each reported as a structured deprecation (`deprecations` in JSON, SARIF and `conf:check`):

- `githooks.php` / `githooks.local.php` are still found when there is no `alfil.php` (`kind: config-file-rename`). The new name wins over the location, a `githooks.php` next to an `alfil.php` is reported as ignored, and an override whose name does not match its main file says which one to rename. See [Configuration File](configuration/file.md#search-paths).
- `$GITHOOKS_BRANCH` is still read when `$ALFIL_BRANCH` is unset (`kind: env-var-rename`).
- `Wtyd\GitHooks\Utils\ComposerUpdater::phpOldVersions`, called from the `composer.json` scripts of PHP 7.4/8.0 projects, still resolves.
- The JSON `runtime` block emits `githooksVersion` next to the new `alfilVersion`, with the same value.

### Added

**`flow --repeat=N` finds flaky tests, test by test.** A test that passes on one run and fails on the next used to surface as a red job someone re-ran until it went green, without ever learning which test it was. `alfil flow tests --repeat=10` runs the flow ten times — every pass, whatever its outcome — reads the junit file each test job writes and crosses the results per test: passed every time is stable, failed every time is broken, both is **flaky**. The report lists the flaky and failing tests with their counts (`7/10 passed`) and exits 1 when there is one. A job declares its file with the new `junit` key; `phpunit` and `paratest` jobs reuse their `log-junit`. Junit files are deleted before every pass, so a pass that crashes without writing one is reported as such, never read as the previous pass's results. `--flap-threshold=N` raises the bar for noisy suites: the rarer outcome must happen at least N times, below that the test is listed as *suspect* without failing the command. `--format=json` gives the same report as one JSON document with its own schema (`version: 1`). It repeats, it never retries: re-running a failure until it passes would hide the flaky test instead of finding it. See [Finding Flaky Tests](how-to/flaky-tests.md).

### Removed

**The v2 engine.** Everything the 3.x line had marked "removed in v4.0" is gone: the `tool` command, YAML configuration files, the `Options` / `Tools` configuration shape, the camelCase job keys (`executablePath`, `otherArguments`, `ignoreErrorsOnExit`, `failFast`) and `conf:migrate` itself. A configuration 4.0 does not read is rejected with one error — the same in every command, naming the file and the way out:

```text
$ alfil flow qa
v2 configuration format (Options/Tools) is not supported since 4.0: /srv/app/alfil.php. Migrate it with GitHooks 3.8 ('githooks conf:migrate') before upgrading.
```

A `githooks.yml` is rejected on its extension alone, never parsed; one left behind in the project root or `qa/` gets the same kind of message instead of "not found". A job declaring a camelCase key is rejected with the kebab-case key named: `Job 'phpstan_src': key 'executablePath' was removed in 4.0. Use 'executable-path'.` Migrate with the last 3.x release first — see [Upgrading from v2](migration/v2-to-v3.md).

- `conf:init --legacy`, which generated the v2 format.
- `symfony/yaml` is no longer bundled in the `.phar`.

### Changed

- **`conf:check --format=json` is schema version 2.** The `legacy` marker and the `hint` field of version 1 only described the v2 case. The four v3 blocks (`options`, `hooks`, `flows`, `jobs`) are always present, and a file that cannot be read comes back as the error form (`valid: false`, the message in `errors[0]`, exit 1). See [`conf:check`](cli/conf-check.md).
- The message when no configuration file is found names the file that was looked up: `Configuration file not found. Expected alfil.php in the project root or in qa/ (or pass --config=PATH).`
- A PHP configuration that does not return an array is reported in one line with exit 1 by `flow`, `flows` and `job`, like every other configuration error; it used to escape with a stack trace.

### Fixed

- **`alfil hook --config=<file>` installed a hook when the file could not be read.** A missing path, a v2 or YAML file, or a file that did not return an array all fell back to the minimal `.git/hooks/` install: the command reported `Hook pre-commit created`, exited 0 and left behind a script pointing at a configuration that does not work. An explicit `--config` that cannot be used is now an error (exit 1) and nothing is written; the minimal install only applies when there is no `--config` and no file in the default locations. See [`alfil hook`](cli/hook.md).
- **A `commit-msg` job run through `flow` or `flows` never received the message file.** The 3.x documentation promised `GITHOOKS_COMMIT_MSG_FILE` as the way to hand it over when the job runs outside the hook, but the variable was never read: the job fell back to `.git/COMMIT_EDITMSG` whatever the script passed, and found nothing at all in a git worktree, where `.git` is a file. `ALFIL_COMMIT_MSG_FILE` is now read, after the hook argument and `--message-file` / `--message` and before `.git/COMMIT_EDITMSG`, so a hook script of your own can run `ALFIL_COMMIT_MSG_FILE="$1" vendor/bin/alfil flow commit-checks`. A variable pointing at a missing file is reported as *cannot read message file*, never silently replaced by the fallback. The old name is not read; it never did anything. See [Commit Message Validation](tools/commit-msg.md).

## [3.8.1]

### Fixed

- **`githooks job` in fast mode ran the job over its whole `paths` when it should have skipped it.** With `--fast`, `--fast-branch` or `--fast-dirty`, a job whose change set was empty — or held no file inside the job's `paths` — ran over its original `paths` instead of being skipped, while `githooks flow` with the same job skipped it (`no changes to validate` / `no staged files match its paths`). A hook that points straight at a job (`'pre-commit' => ['phpstan_src']`) went through the same path, so a commit that did not touch the job's `paths` still analysed all of them. `job` and job hooks now skip exactly like `flow`, with the same reason in every output format; a change set that does match still narrows the job to those files, as before. See [Execution modes](execution-modes.md).

## [3.8]

### Added

**Native `pint` job type.** Laravel Pint — Laravel's default code-style fixer, built on PHP CS Fixer — is now a first-class fixer job instead of a `custom` script, completing the Symfony/Laravel fixer pair (`php-cs-fixer` / `pint`). It plugs into the fixer pipeline the native types share: fixes applied to staged files are automatically re-staged (scoped to the files the tool rewrote), `--fast` mode feeds it only the staged files under its `paths`, and `test: true` maps to `--test` for check-only runs (the job fails on style issues, nothing is rewritten, no re-stage). Exit-code semantics verified against Pint 1.30: fix mode exits 0 whether or not it fixed anything (`fixApplied: true`), `--test` normalizes the dirty verdict to exit 1, and Pint's `--dirty` selection stays deliberately unmapped — file selection belongs to GitHooks. Like Pest, Pint is installed in the target project: GitHooks builds and runs its command but does not ship it. See [Laravel Pint](tools/pint.md).

### Fixed

- **`history-size` was reset to 0 when a flow declared its own `options` block.** Like `executable-prefix`, `fast-branch-fallback` and `reports` before it (3.4), the key was read block-level instead of per-key, so a flow that overrode an unrelated option (`fail-fast`, `processes`…) silently stopped persisting its runs under `.githooks/history/` — and `profile` had nothing to report for it. It now inherits per-key like the rest of the execution options. See [Per-key cascade](configuration/options.md#per-key-cascade).
- **`memory-budget.fail-above` killed the shell, not the job.** The kill that fires when the simultaneous RSS sum crosses `fail-above` only reached the `sh -c` wrapper Symfony Process spawns for each job; the real analyzer underneath — and its own workers, such as `artisan test --parallel` — was reparented to PID 1 and kept running, still holding the very memory the budget was meant to reclaim. The runtime now enumerates each job's process tree **before** signalling and terminates it leaf-to-root: `SIGTERM` to every descendant and then to the wrapper, `SIGKILL` after a 5-second grace to anything still alive (zombies do not count as survivors). Linux (`/proc`) and macOS (`ps`) are covered; without `ext-posix` the runtime falls back to the previous `Process::stop()` behaviour and says so on stderr. Reported from a CI incident where a parallel test suite outlived the flow that had "killed" it. See [Memory budget](configuration/options.md#memory-budget-memory-budget).
- **A missing `--config` file surfaced PHP's own `require(...): failed to open stream` error** in `conf:check`, both in text and in the JSON `errors[0]`, while `flow` and `job` already said `Configuration file not found: <path>`. All commands now give that same message with the resolved path; exit code unchanged (`1`). Other read failures (permissions, a PHP syntax error inside the file) still propagate the real error.
- **A typo in a `pest` job's `runner` selected the binary runner in silence.** `runner` only recognised `artisan`; any other value (`artizan`, `phpunit`, …) fell back to `vendor/bin/pest` and neither `conf:check` nor the run said a word — unlike every other enumerated key, which validates its value and suggests the fix. `conf:check` now reports the job with a warning (`unknown value 'artizan' for 'runner' (valid: binary, artisan); falling back to 'binary'. Did you mean 'artisan'?`); the fallback itself is unchanged, so existing configurations keep running. See [Pest](tools/pest.md).
- **Hooks installed with `--config` ran a different configuration on every trigger.** The generated `.githooks/<event>` script called `hook:run` without `--config`, so each trigger resolved `githooks.php` from the working directory instead of the file the hooks were installed from — and when that default file was a legacy v2 config, every commit was blocked with `hook:run requires v3 configuration format`. `githooks hook --config=<file>` now bakes `--config` into the scripts with the path **relative to the repository root**, so a committed `.githooks/` keeps working for every clone; a file outside the repository is baked absolute and the install warns that other clones will not have it. Installing without `--config` produces the same script as before. See [`githooks hook`](cli/hook.md).
- **`githooks hook` over a v2 configuration installed a hook that blocked every commit.** The generated script called `hook:run`, which reads `hooks`/`flows`/`jobs` and rejects a v2 file outright, so the very next commit died with `hook:run requires v3 configuration format` — while the install had reported the hook created and exited 0. The script for a v2 configuration now drives `tool all`, the v2 entry point it used before hooks moved to `core.hooksPath`, and the install says the configuration has not been migrated yet. `--config` is baked in on this path too, for the same reason it is on the v3 one. Migrating with `conf:migrate` still gets you one hook per configured event. See [`githooks hook`](cli/hook.md).
- **An unknown `--stats-sort` value was ignored without a word.** The table came back in completion order, which is also what a correct `--stats-sort=exec` produces, so a typo looked like a working flag sorting by something else. Every other enumerated flag says when it cannot use what it was given (`--allocator`, `--format`, a `pest` job's `runner`); this one now does too, naming the valid values and the fallback. The sort behaviour itself is unchanged.
- **A job with an empty `paths` list passed `conf:check` and then failed at run time.** The command came out without a single path and the tool answered with a usage error, on a configuration that had just been reported as valid — an easy state to reach by accident when the array is built from a filter. `conf:check` now warns (`key 'paths' is an empty list; the tool will run without any path`); the run itself is unchanged.

## [3.7]

### Added

**`re-stage` on `custom` jobs — auto-stage fixes from any fixer.** The automatic re-staging that the native fixer types (`phpcbf`, `php-cs-fixer`, `rector`) apply after fixing staged files was out of reach for `custom` jobs: to auto-fix and re-add files in a `pre-commit` hook you had to hand-roll `... && git add <files>` in the `script`, with all its edge cases (path quoting, the no-op case, partially staged files). A `custom` job can now declare `re-stage: true`: a successful run (exit code 0) is treated as a fix and its changes to the staged files are re-staged automatically — the same behaviour the native fixers get, now available to any fixer run via `custom` (Pint, Duster, npm formatters…). Opt-in (default off, so linters/checkers are unaffected), and a non-zero exit never re-stages, so a failing tool can't slip partial output into the commit. See [Custom jobs → Auto-staging fixes](tools/custom.md#auto-staging-fixes-re-stage).

**`--format=text|json` for the diagnostic commands (`conf:check`, `status`, `system:info`).** These commands only spoke human-readable tables and text, so non-human consumers (AI assistants, CI, scripts) had to scrape ASCII tables with colors — and `conf:check`, despite being the command most often run by tooling, did not accept the flag at all. All three now emit a clean, parseable JSON document on stdout (no ANSI, no tables) with a per-command schema versioned independently (`version: 1`):

```console
$ githooks conf:check --format=json | jq '.jobs[] | select(.status != "ok")'
$ githooks status --format=json | jq '.events[] | select(.status != "synced")'
$ githooks system:info --format=json
```

`conf:check` reports `valid`, global `options`, `hooks`, `flows` and a `jobs` array (each with its resolved `command`, a `status` of `ok`/`warning`/`error` and its `issues`), plus top-level `errors`, `warnings` and `deprecations`; a legacy configuration is flagged with `legacy: true` and a hint to run `conf:migrate`. `status` reports the hooks path and per-event `status`/`targets`. `system:info` reports `{cpus, processes, warning}`. The exit code is unchanged from text mode, and an unknown `--format` value warns on stderr and falls back to `text` (same behavior as `flow`). See [`conf:check`](cli/conf-check.md), [`status`](cli/status.md) and [`system:info`](cli/system-info.md).

**Native `pest` job type.** Pest — the PHPUnit-based framework and Laravel's default test runner — is now a first-class job type instead of a `custom` script. It wraps `vendor/bin/pest` or, with `runner: artisan`, `php artisan test`; the verdict comes from the exit code; `--parallel` integrates with the thread budget (`cores`/`--processes`); and Pest's own flags are mapped (`--coverage`/`--min`/`--only-covered`, `--mutate`/`--covered-only`, `--bail`, `--compact`, plus the inherited `-c`/`--group`/`--filter`). Pest is installed in the target project — GitHooks builds and runs its command but does not ship it. See [Pest](tools/pest.md).

### Changed

**Auto-staging after a fix is limited to the files the job rewrote.** The re-stage that follows a fixer (`phpcbf`, `php-cs-fixer`, `rector`, and now `custom` with `re-stage`) ran `git add` over the **whole index**, so any file you had staged was re-added in full — including working-tree edits you had deliberately left out of the commit, in files the tool never touched. GitHooks now fingerprints the index before the flow starts and re-stages only the staged files whose content changed while the jobs ran. `git add` still cannot stage part of a file: if the tool rewrites a file that also carried unstaged edits of yours, those go in with the fix. See [Custom jobs → Auto-staging fixes](tools/custom.md#auto-staging-fixes-re-stage).

**Option validation is now consistent across config and CLI.** The same validity criterion for each execution option is single-sourced and applied from both origins, with a uniform policy: a **config file** is a contract — an invalid value (`processes`, `time-budget`, `memory-budget`, `allocator`) is a hard error via `conf:check` — while a **CLI flag** is best-effort — an invalid value warns on stderr and is ignored, falling back to the configured value or default. In particular, `flow`/`flows --processes=0` (or a negative/non-numeric value) now emits a warning and falls back instead of being **silently clamped** to `1`, matching how `--allocator` and the budget flags already behaved. Valid values are unaffected. See [Options → Validation](configuration/options.md#validation-of-invalid-values).

### Fixed

- **`conf:migrate` dropped the v2 `script` tool and produced an unusable file.** A named script is listed in `Tools` by its alias (`my-script`) while its configuration lives under the `script` key. The migrator looked the alias up as if it were a tool name, found nothing, and wrote `type: 'my-script'` — not a supported type — silently discarding the command it was supposed to convert. Every later command then rejected the migrated file, while `conf:migrate` itself had reported success. Both halves are fixed: the alias resolves to its `script` section, and the command now validates what it wrote, reporting the errors and exiting **1** instead of claiming a migration that does not work.
- **`type: script` jobs were never validated.** The type reached no key validator, so a job with neither `executable-path` nor arguments built an **empty command**, passed `conf:check` as `ok` and was counted as a passing job while running nothing — and keys it does not understand (`script`, `re-stage`) were accepted in silence. A `script` job now requires `executable-path`, and its unknown and CLI-only keys are reported like every other type's. A job declared as something other than an array is no longer discarded without an error either. See [The `script` type](tools/custom.md#the-script-type).
- **`conf:check` hid four declared options.** The text table listed a fixed set of rows, so `main-branch`, `fast-branch-fallback`, `time-budget.*` and `history-size` never appeared even when configured — a `time-budget` able to fail the flow was invisible in the command whose purpose is to show your effective configuration.
- **An unknown `--format` on `flow`/`flows` warned on stdout.** The advisory is not payload and now goes to stderr, matching the diagnostic commands. The fallback to text and the exit code are unchanged.

## [3.6]

### Added

**Run history + `profile` — temporal trends per flow.** Until now every `--stats` was an isolated snapshot, so "is `phpstan_src` slower this week, and since when?" had no answer. A run can now persist its full JSON v2 payload under `.githooks/history/<timestamp>-<flow>.json`, opt-in via `flow <name> --save-history` or `flows.options.history-size: N` in config (kept to the last N runs per flow, FIFO; 0 disables). Two new commands query that history:

```console
$ githooks profile qa --job=phpstan-src
phpstan-src · last 30 runs · time
  ▁▃▆▆█▆▃▁▂▃▆█▇▆▅▅▆▆▆▇█▇▆▆▅▅▆▆▆▇
  min: 1.8s · p50: 4.0s · p95: 5.6s · max: 6.1s · trend: ↑ +18.0% vs prev 15

$ githooks profile:list qa          # timestamp · total time · passed · failed per run
```

`profile` charts `time` (default), `peak-memory` or `peak-cores` (the last two only for runs recorded with `--stats`), filterable with `--job=`, `--since=YYYY-MM-DD` and `--last=N`, and `--format=json` for tooling. `conf:init` adds `.githooks/history/` to `.gitignore` — the history is local by design and never travels to the repo. See [`githooks profile`](cli/profile.md).

**Per-dimension warn icons in the `--stats` Status column.** A warned job used to show a single generic `OK ⚠`, triggered only by the memory threshold — the operator could not tell *which* resource crossed its warn level. The Status column now distinguishes the dimension and combines them: `OK ⏱` (time `warn-after`), `OK ▤` (memory `warn-above`), `OK ⏱▤` (both, time before memory). The TOTAL row's Peak Cores cell is marked with `⚙` in yellow on real over-subscription (`peak > limit`). See [Parallel execution → Calibrating with `--stats`](how-to/parallel-execution.md#calibrating-with-stats).

**`flow --dry-run` now shows the full plan, including discarded jobs.** A dry-run used to list only the jobs that *would run* and silently omit the ones the mode discards — so `flow qa --fast --dry-run` on a clean tree produced an empty payload, while a real `--fast` run reported all 8 jobs as skipped. The dry-run now reaches parity with a real run: discarded jobs surface as `skipped` with their reason alongside the would-run jobs, and the payload carries `dryRun: true` so a consumer (AI / CI) can tell a plan from an execution. Combine with `--fast`/`--fast-branch`/`--fast-dirty` to preview exactly what a flow would run in that mode. See [Flow command](cli/flow.md).

**Animated spinner while jobs run.** The running-job lane now shows a braille spinner (`⠋⠙⠹…`) that rotates in place instead of a static hourglass, making it obvious at a glance that a job is actively working. Monochrome and single-width, so the line never shifts between frames. This now also covers **single-job runs** (`githooks job <name>`) in an interactive terminal — previously a single tool ran with no on-screen progress until it finished. Non-TTY / CI output is unchanged (no spinner — it would be log noise; piped runs keep the tool's streamed output).

### Fixed

**`githooks job --memory-warn-above` / `--memory-fail-above` now take effect.** The flags were parsed but never applied to the job's memory threshold, so they silently did nothing — unlike `--warn-after` / `--fail-after`, which always worked. They now override the per-job memory threshold (replacing any `memory` declared in config) exactly as documented in [Job command](cli/job.md), so a single-job run can warn or fail on RSS without editing the config file.

**`githooks job --ignore-errors-on-exit` now takes effect.** The flag was declared and documented but never read from the CLI, so a failing job still exited non-zero. It now overrides the job's `ignore-errors-on-exit` for the run (CLI wins over config), letting you execute a job whose failure must not break the command without editing the config file.

**`on` declared on a meta-flow is now honoured when the meta-flow is invoked alone.** A meta-flow that declared `on` (per-branch execution) silently ran in `full` regardless of the branch — the rule was validated but never evaluated, unlike a meta-flow's `options` (which already applied when invoked alone). A meta-flow invoked alone (`githooks flows ci-pack`) now resolves the branch and applies the matching execution mode, propagating it to the expanded flows, exactly like a normal flow. Mixed/ad-hoc runs are unchanged (meta-level `on`/`options` stay ignored there).

**Removed the inert `--fast-branch-fallback` CLI flag from `flows`.** It was declared and documented but never read — only the `fast-branch-fallback` config option ever took effect. Passing it on the CLI silently did nothing; it now errors as an unknown option. Set the strategy via `options.fast-branch-fallback: fast|full` in config (or a `githooks.local.php` override), which is unchanged.

**Parallel `--fail-fast` no longer drops jobs that finished in the same poll as the failure.** Under parallel execution with `--fail-fast`, a job that completed in the same poll batch *after* the first failing job had its result silently discarded — it ran but was missing from the report and the JSON/JUnit payload. The whole batch is now collected before the queue drains, so every job that actually executed appears in the output.

## [3.5.1]

### Fixed

**The parallel dashboard no longer repeats completed-job lines on a TTY without ANSI support.** The live dashboard redraws in place with cursor-up escapes. On a stream that reports as a TTY but does not honour them — `NO_COLOR`, `TERM=dumb` or unset, IDE output panels — the cursor never moved, so each finished job's line was re-printed once per ~200 ms refresh tick (a fast job could stack dozens of copies while a slow one kept running). The live dashboard is now gated on ANSI decoration as well as the TTY check, so those streams degrade to the clean append-only renderer — one line per job:

```console
$ NO_COLOR=1 githooks flow qa     # a TTY that does not support ANSI
  ⏳ phpcpd...
  ⏳ phpstan-src...
  phpcpd - OK. Time: 0.31s
  phpstan-src - OK. Time: 1.06s
  ...
Results: 8/8 passed in 9.20s ✔️
```

Color-capable terminals are unaffected — the live, in-place dashboard is preserved. See [Interactive parallel dashboard](how-to/output-formats.md#interactive-parallel-dashboard).

## [3.5]

### Added

**`--stats-sort=name|type` — order the `--stats` table.** With many similarly-named jobs the table came out in completion order (non-deterministic under `processes > 1`). Sorting groups related jobs and adds a `#` column with the real execution order:

```console
$ githooks flow qa --stats --stats-sort=name
| # | Job         | Status | Time  |
| 2 | phpcs_app   | OK     | 10ms  |
| 4 | phpcs_src   | OK     | 11ms  |
| 1 | phpstan_app | OK     | 10ms  |
```

Structured formats (JSON/JUnit/SARIF) stay in execution order; JSON v2 gains a per-job `executionOrder`. See [`githooks flow`](cli/flow.md) and [JSON v2 per-job fields](how-to/output-formats.md#per-job-fields).

**Native `commit-msg` job — declarative commit-message validation.** An inline job type (no shell spawned) that checks the commit subject against declarative rules or the `conventional-commits` preset, wired to Git's `commit-msg` hook. It replaces hand-written bash hooks and is validated by `conf:check`:

```console
$ git commit -m "Add stuff."
✗ commit-msg: subject failed rule 'pattern'.
  Subject:   Add stuff.
  Reason:    Use Conventional Commits: tipo(scope?)!?: descripción.
  Example:   feat(api): add user endpoint
```

Configure with `'commit-msg' => ['commit-format']` plus a `commit-format` job (`preset` or custom `rules`). **Upgrade note:** reinstall hooks with `githooks hook` so the script forwards Git's message-file argument. See [Commit Message Validation](tools/commit-msg.md).

**`--diag` — runtime diagnostics for CI post-mortems.** Prints a machine snapshot (version, platform, CPU/cgroup, system memory, load, start timestamp) before the run, so a hung CI job shows whether PHP was blocked or starved. Auto-on in CI, opt-in locally:

```console
$ githooks flow qa --diag
githooks 3.5.0 · linux · cpus=20 (cgroup limit: 20) · mem=3791 MB / 8096 MB · load=1.6 / 1.9 / 1.9 · 2026-06-03T23:17:05+00:00
Settings:
  ...
```

JSON v2 also gains a `runtime` node and absolute `startedAt`/`endedAt` per job and flow (always present, independent of `--diag`). See [Runtime diagnostics and absolute timestamps](how-to/output-formats.md#runtime-diagnostics-and-absolute-timestamps).

**`--format=claude-code` — AI agent stop-hook output.** Emits the [Claude Code](https://claude.com/claude-code) stop-hook protocol on `flow`/`flows`/`job`. On success it is silent and exits 0; on a QA failure it prints one block line aggregating the failed jobs and **still exits 0** (the protocol only honors the block on a zero exit):

```console
$ githooks flow qa --fast-dirty --format=claude-code
{"decision":"block","reason":"## phpcs\nsrc/Foo.php:12 line too long\n\n## phpstan\nsrc/Bar.php:8 undefined variable $x"}
```

Pairs with `--fast-dirty` (3.4) to close the agent loop; a genuine config error still exits 1. See [AI Agent Hooks (Claude Code)](how-to/ai-hooks.md).

### Fixed

**`other-arguments` is now honored by `custom` jobs in simple mode** (without `paths`). The verbatim `script` form silently dropped `other-arguments`, so the `extends` + `other-arguments` pattern built the identical command for every variant. It is now appended after the script:

```console
$ githooks flow qa --dry-run     # three Jest shards sharing a base via extends
  jest_ci_shard_1
     yarn tests:ci --shard 1/3
  jest_ci_shard_2
     yarn tests:ci --shard 2/3
  jest_ci_shard_3
     yarn tests:ci --shard 3/3
```

See [custom tool — simple mode](tools/custom.md#simple-mode).

## [3.4.1]

### Fixed

- **Unknown CLI options no longer silently shift the parser** in `flow`, `flows` and `job`. The three execution commands keep `ignoreValidationErrors()` on so that `job <name> -- <args>` keeps forwarding extra args to the underlying tool, but a typo such as `flow qa --foo=bar --config=/path/x.php` would previously make Symfony swallow `--foo` *and* silently drop `--config`, then fall back to `qa/githooks.php` — wrong config, wrong jobs, no error. A new `ValidatesUnknownOptionsBeforeDashDash` concern now inspects the input tokens before either command reads `--config`, rejects unknown long options and short shortcuts (cluster-aware), and emits a Symfony-style `The "--foo" option does not exist.` per offender; `flow` and `flows` additionally emit a custom error if `--` itself is present (neither command supports passthrough). The new behaviour caps any input-validation typo at exit 1 *before* the configuration file is resolved, so a typo can no longer accidentally execute the project-wide QA flow.

- **`script`-typed jobs now report their job key in OK/KO/SKIP logs instead of the executable path**. `ScriptJob::getDisplayName()` historically overrode the parent and returned `$this->executable`, so two parallel jobs of `type: script` sharing the same `executable-path` (e.g. two shards invoking the same `./run-tests` runner with different `other-arguments`) printed two identical `./run-tests - OK. Time: …` lines, making them indistinguishable in the dashboard, JSON v2 dry-run and stats. The override was undocumented and inconsistent with every other Job type (Phpstan, Phpunit, Phpcs, Phpmd, Psalm, ParallelLint, Rector, PhpCsFixer, Phpcpd, Paratest, Custom, all of which inherit `JobAbstract::getDisplayName()` returning `$this->name`). Removed. The JSON v2 envelope is unaffected — its `name`/`type` fields come from `getName()`/`getType()`, not `getDisplayName()`.

## [3.4]

### Added

- **`--fast-dirty` execution mode.** Fourth execution mode targeting the **unified working tree**: tracked files modified vs `HEAD` (staged or unstaged, excluding deletions) ∪ untracked files honouring `.gitignore`. Fills the gap between `--fast` (staged only) and `--fast-branch` (branch diff). Designed for **AI agentic hooks** (Claude Code, Cursor, Cline, Copilot agent…) — the agent touches files without staging and we want the same pre-commit flow with `--format=json`. Available as `--fast-dirty` on `flow`/`flows`/`job` and as `execution: 'fast-dirty'` in flow/job declarations. Mutually exclusive with `--fast`/`--fast-branch`/`--files`/`--files-from`. Clean working tree → accelerable jobs skipped, exit 0 (no fallback to `full`). See [Fast-dirty mode](execution-modes.md#fast-dirty-mode-fast-dirty).

- **Intra-flow dependencies with `needs: [<job>, ...]`.** A flow entry can declare other jobs in the same flow it depends on:
    ```php
    'qa' => [
        'jobs' => [
            'yarn_install',
            ['job' => 'eslint',   'needs' => ['yarn_install']],
            ['job' => 'prettier', 'needs' => ['yarn_install']],
            'phpstan_src',  // independent — parallel with yarn_install
        ],
    ],
    ```
    Jobs wait until all their `needs` complete successfully; skip propagation is visible end-to-end (`needs X failed`, `needs X was skipped`). The TTY dashboard gains a `⏸ jobName (waiting X, Y)` lane and JSON v2 emits `needs: [...]`. `conf:check` validates the DAG statically (cycles, missing references, duplicates, empty list). **Behaviour change**: `fail-fast` now lets jobs in `running` finish naturally instead of terminating them — it cancels pending work, not in-flight work. See [Job dependencies (`needs`)](configuration/flows.md#job-dependencies-needs).

- **Per-flow execution mode by branch with `on => [branch_pattern => attrs]`.** A flow picks its execution mode based on the current branch:
    ```php
    'ci' => [
        'on' => [
            'master' => ['execution' => 'full'],
            '*'      => ['execution' => 'fast-branch'],
        ],
        'jobs' => [/* ... */],
    ],
    ```
    The execution mode lives **inside the flow declaration**, so a single CI step (`script: vendor/bin/githooks flows ci`) covers both protected and feature branches without branch-aware conditionals in the CI definition or duplicated jobs. Pattern matching is **first declared wins**. Branch detection cascades from a new `--branch=X` flag on `githooks flow` through `$GITHOOKS_BRANCH`, CI env vars and `git rev-parse`. The `Settings:` header reports `mode = X (flows.<X>.on)` when the branch match wins. See [Branch-driven execution mode (`on`)](configuration/flows.md#branch-driven-execution-mode-on).

- **Declarative per-flow-entry admission with `only-files` / `exclude-files`.** Flow entries in `flows.<X>.jobs` now accept the existing string form **or** an object `{job, only-files?, exclude-files?}` that gates whether the job runs based on the change set, independently of the job's own `paths` filtering. The decision is binary (`skipped: true` with `skipReason` vs run) and applies to all job types. In `full` mode the rules are no-op. Replaces the `type: custom` + `git diff … grep -qE …; exit 0` workaround that surfaces as `passed` and breaks on POSIX-less runners. Same glob semantics as hook-level `only-files`. Combined with `on` (above), the flow declaration decides both **which mode** runs per branch and **which jobs** are admitted per change set — the CI pipeline stays a single `flows` invocation without branch-aware conditionals or per-job rules duplicated in the CI YAML, and the admission logic is exercised the same way locally and in CI (GitLab CI's `rules:` / `changes:` and GitHub Actions's `paths:` filters can be coarse and pipeline-dependent). See [Per-entry admission rules](configuration/flows.md#per-entry-admission-rules-only-files-exclude-files).

### Fixed

- **`executable-prefix`, `fast-branch-fallback` and `reports` now cascade per-key from `flows.options`** when a flow declares its own `options` block. When a flow declared its own `options` to override an unrelated key (e.g. `processes`), the three keys above were read block-level instead of per-key and silently dropped their global value. Now they inherit per-key like `fail-fast` / `processes` / `time-budget` / `memory-budget` / `allocator` / `stats` already did. See [Per-key cascade](configuration/options.md#per-key-cascade).

- **GitLab CI / GitHub Actions sections no longer leak raw tool JSON when `--format=codeclimate` or `--format=sarif`** (or `reports.codeclimate` / `reports.sarif` in config) is active. Structured formats reconfigure each tool to emit JSON so the file-based formatters can parse it; the side effect was that failing jobs printed the raw JSON blob as the visible body of their CI section. A new humanising display layer translates the per-tool JSON into a familiar `file  line N  message  [rule]` listing while the raw payload stays available unchanged for file-based reports and JSON v2 `output`. See [Human-readable KO body](how-to/ci-cd.md#human-readable-ko-body-under-formatcodeclimate-formatsarif).

- **`githooks job <name> --format=json` now reflects the job's declared `execution` in the envelope.** When a job declared `execution: fast` / `fast-branch` (or the new `fast-dirty`), the JSON v2 envelope still reported `executionMode: "full"` / `source: "default"` even though the file-set filtering already honoured the declared mode — CI dashboards and AI consumers read the wrong mode. The `executionMode` value and its `effectiveOptions` `source` line now reflect `jobs.<name>.execution`.

## [3.3.3]

### Fixed

- **Fast-branch / fast no longer fail with spurious "no files" errors when a job's tool config strips every input via its internal exclusion list.** Repro: a branch touches only files under one subtree (e.g. `src/foo/...`); the wrapper hands those files to a job whose `.neon` declares `excludePaths.analyse: [src/foo]` (PHPStan) or whose `--ignore` CSV covers them (PHPCS). The tool drops 100 % of the input and exits non-zero with `[ERROR] No files found to analyse.` (PHPStan, exit 1) or `ERROR: All specified files were excluded or did not match filtering rules.` (PHPCS, exit 16 on older versions and the PHPCSStandards fork). Before this fix the wrapper reported the job as failed, breaking MRs in projects that split coverage across complementary jobs. PHPStan and PHPCS now recognise these "empty after filtering" exits, reinterpret them as `skipped: true` with `skipReason` instead of `success: false`, and bypass threshold evaluation (the tool didn't do real work, so timing it would be meaningless). PHPMD already tolerates this case natively (`exit 0` when its `--exclude` empties the set); the other accelerable tools (parallel-lint, psalm, rector, php-cs-fixer) silently ignore non-matching inputs and do not need an override.

## [3.3.2] ⚠️ Do not use — broken release

**This release is functionally identical to 3.3.1.** The git tag `v3.3.2` was published against a master commit whose bundled `.phar` binaries (`builds/githooks`, `builds/php7.4/githooks`) had never been updated from the `rc-3.3.2` branch where CI compiled them. Since GitHooks runs as a standalone `.phar`, installing `wtyd/githooks:3.3.2` ships the v3.3.1 binary under the v3.3.2 tag name. The fixes listed below are present in the source code of the tag but **not** in the executed binary.

**Use v3.3.3** — same fixes, correctly bundled.

### Fixed

- Code Climate and SARIF reports requested via `flows.options.reports.codeclimate` / `reports.sarif` in config or via `--report-codeclimate=PATH` / `--report-sarif=PATH` CLI flags came out empty (`[]` / no findings) when the primary `--format` was anything other than `codeclimate` / `sarif`. The flag that asks each tool for JSON output only activated on `--format=codeclimate|sarif`, so every tool ran with its default human-text format and the report parsers (which all do `json_decode()` over stdout) found nothing to extract. Affects every tool with a JSON-dependent parser: PHPStan (`--error-format=json`), PHPCS (`--report=json`), PHPMD (positional `json` format), Psalm (`--output-format=json`) and parallel-lint (`--json`). Fixed: tool-level JSON output is now requested whenever a codeclimate or sarif payload will be produced, regardless of how it was requested.

### Improved

- JUnit `<failure>` payloads now pretty-print embedded JSON so GitLab/Jenkins viewers render each finding on its own indented block. PHPMD already emits `JSON_PRETTY_PRINT` natively; PHPStan/PHPCS/Psalm/parallel-lint emit compact one-liner JSON. When a pipeline triggers tool JSON output (typical GitLab setup pairs JUnit + Code Climate), the JUnit `<failure>` arrived as a single 1000+ char line. The formatter now detects a parseable JSON span inside `<failure>` and re-encodes it with indentation; non-JSON outputs (custom jobs, scripts) and JSON with prologue/epilogue (PHPStan's "Instructions for interpreting errors" stderr block) are preserved verbatim except for the JSON span itself. Idempotent for already-pretty payloads (semantics preserved; bytes may differ because `JSON_UNESCAPED_SLASHES` turns `\/` into `/`).

## [3.3.1]

### Fixed

- `--fast` / `--fast-branch` no longer leave non-accelerable jobs (`phpunit`, `paratest`, `phpcpd`, `script`, `custom`, `composer-*`) running their full suites when the effective input set is empty (no staged files / no diff vs base). The skip is now universal: any job — accelerable or not, with or without `paths` declared — is skipped with reason `no changes to validate` when the mode produced no input. Restores parity with the v2.x contract ("nothing changed = nothing to run").
- `cache:clear` now resolves the **effective** cache path for each job instead of relying on hard-coded defaults. Previously the command read a regex on the top-level `.neon` (PHPStan, ignoring `includes:` and placeholders) and used hard-coded literals for every other tool — `cache:clear` silently reported "not found" while the real cache lived elsewhere. After the fix:
    - PHPStan: `tmpDir:` is followed through `includes:` recursively (cycle-safe) and `%currentWorkingDirectory%` / `%rootDir%` are expanded.
    - Psalm: reads `cacheDirectory` from `psalm.xml`, resolved relative to the XML.
    - PHPCS: reads job arg `cache`, then `<arg name="cache" value="..."/>` from the ruleset.
    - PHPUnit: reads `cacheResultFile` and `cacheDirectory` (10+) from `phpunit.xml` / `phpunit.xml.dist`.
    - Rector: best-effort regex over `cacheDirectory(...)` in `rector.php` (literal, `__DIR__ . '/literal'`, `sys_get_temp_dir() . '/literal'`). Default fixed to `sys_get_temp_dir() . '/rector_cached_files'` (was incorrect `/tmp/rector`, also non-portable on Windows).
    - PHP-CS-Fixer: best-effort regex over `setCacheFile(...)` in `.php-cs-fixer.php`; respects job arg `cache-file` over the config (matching what php-cs-fixer itself does).
    - PHPMD: default fixed to `.phpmd.result.cache` (was incorrect `.phpmd.cache`).
- When Rector / PHP-CS-Fixer config uses a dynamic expression for the cache path (variable, helper, env), `cache:clear` falls back to the default and surfaces a warning explaining why and how to override. Last-resort meta-arg `cache-dir` on the Rector job lets users force a path; PHP-CS-Fixer relies on its existing `cache-file` arg (which the tool itself respects as `--cache-file`). See [`cache:clear`](cli/cache-clear.md).
- PHPUnit cache precedence: when `phpunit.xml` declared both `cacheResultFile` (legacy) and `cacheDirectory` (PHPUnit 10+), GitHooks picked the legacy one and ignored the modern attribute. PHPUnit itself does the opposite — `cacheDirectory` wins. Users migrating to PHPUnit 10 with both attributes for transitional reasons saw `cache:clear` deleting the wrong path. Now `cacheDirectory` takes precedence, with `cacheResultFile` as fallback.
- PHPStan `tmpDir:` inside `services:` was misread as the root `parameters.tmpDir`. A NEON service constructor argument named `tmpDir` would be returned as the PHPStan tmpDir, leading `cache:clear` to delete a path the user never declared as cache. The resolver now requires `tmpDir:` to live directly under a top-level `parameters:` block.
- Meta-args with whitespace-only values (`'cache-dir' => '   '`, `'cache-file' => '   '`, `'cache' => '   '`) were accepted as literal paths. Now trimmed before validation; whitespace-only falls back to the default rather than producing `rm -rf "   "`.

## [3.3.0]

### New Features

#### Combined flow runs (`flows` command + meta-flows)

A new `flows` command runs **several flows in a single plan** — one PHP runtime, one shared thread budget, one combined `FlowResult` — replacing the typical "two CI steps that each spin up `composer install`" pattern with a single invocation.

- **Four invocation modes** (auto-detected from the args):
  - `flows qa` (single normal flow) — equivalent to `flow qa`, identical `FlowResult`.
  - `flows qa lint` (≥ 2 normal flows) — ad-hoc combination, jobs deduped by first-occurrence order.
  - `flows ci-pack` (1 meta-flow declared in config) — declarative composition with the meta-flow's own options.
  - `flows ci-pack deploy` (mixed) — meta-flow + extra flows, where per-flow / per-alias options are deliberately ignored.
- **Meta-flows in config** ([`flows.<alias>.flows`](configuration/flows.md#meta-flows)): a flow that lists other flows instead of jobs. Declares its own `options` and `reports`. `conf:check` validates the new shape: each `flows.<X>` declares exactly one of `jobs` or `flows`, references must point at existing normal flows (no nesting in v3.3), and the jobs/flows/meta-flows namespace must stay flat.
- **`flows.options` cascade per key**: `cli > flows.<X>.options > flows.options > default` (single-flow / declarative) collapses to `cli > flows.options > default` for ad-hoc and mixed runs (per-flow/alias options are intentionally ignored — see [why](https://wtyd.github.io/alfil/configuration/flows/#meta-flows)).
- **Ignored-options warning**: when a flow or alias's `options` block is ignored because of the run mode, `flows` emits a one-line notice naming the ignored sources so the operator knows what is and isn't being applied.

#### Cross-cutting conditions header + `effectiveOptions`

A new conditions header is emitted at the start of every `flow`, `flows` and `job` run to make the active options visible at a glance:

```
Settings:
  processes     = 4    (cli)
  fail-fast     = true (flows.ci-pack.options)
  mode          = full (default)
  time-budget   = none (default)
  memory-budget = none (default)
  allocator     = fifo (default)
  stats         = false (default)
Flows: qa, lint
```

Every row carries its `(source)` parenthesis — `(default)` included — so the column stays aligned and the audit trail is complete.

- **Channels**: stdout in text mode (default); stderr when a structured format is combined with `--show-progress`. Silent for plain `--format=json|junit|sarif|codeclimate` so stdout payloads stay clean.
- **JSON v2 contract** (always present in `flow` / `flows` / `job` runs): a new root `effectiveOptions` block listing each option's `value` and `source`. `source` ∈ `{cli, flows.<X>.options, flows.<alias>.options, flows.options, default}`.
- **`flows[]` root field** (multi-flow only): the list of normal flows actually executed after meta-flow expansion. Absent in `flow X` and single-flow degenerate runs (so existing single-flow consumers ignore it).

Both fields are additive. Consumers that read v2 today keep working unchanged; modern consumers (CI dashboards, AI tools) can now show the precise option resolution without cross-referencing config + CLI.

#### Files mode (`--files` / `--files-from` / `--exclude-pattern`)

`flow` and `job` accept three new flags that drive a flow against an **explicit list of files** supplied by the user. Covers IDE on-save (single-file analysis), CIs with shallow checkouts where `--fast-branch` cannot compute a diff, and any external tool that already produced a list of paths.

- **`--files=a,b,c`** — CSV. Paths resolve against CWD; absolute paths are accepted as-is. Directories expand recursively to `.php` / `.phtml`.
- **`--files-from=PATH`** — manifest with one path per line. Comments (`#`), blanks, CRLF and UTF-8 BOM are tolerated. Use this to bypass the shell `ARG_MAX` limit (`git diff --name-only origin/main...HEAD > /tmp/changed.txt && githooks flow qa --files-from=/tmp/changed.txt`).
- **`--exclude-pattern=glob1,glob2`** — drop matching paths from the input list (post-expansion). Same glob syntax as hook config (`*`, `**`, `?`). Requires `--files` / `--files-from`.
- **Behaviour**: accelerable jobs (phpstan, phpcs, phpcbf, phpmd, psalm, parallel-lint, php-cs-fixer, rector, custom with `accelerable: true`) run only on the intersection of input files and their configured `paths`; jobs with no match are skipped with reason `"no input files match its paths"`. Non-accelerable jobs (phpunit, phpcpd, composer-*, script) ignore the list and run with their original `paths`.
- **JSON v2**: when files mode is active, `executionMode` is `"files"` and a new root `inputFiles` block plus per-job `inputFiles` slice (accelerable jobs only) are emitted. Backward-compatible — fields are absent in the legacy modes.
- **Mixing**: `--files` / `--files-from` win over `--fast` / `--fast-branch` with a warning. The two file flags are mutually exclusive.
- **CLI-only**: `conf:check` rejects `files` / `files-from` keys declared in `flow.options` or in a job (volatile by design). See [How-To: --files / --files-from](how-to/files-flag.md).

#### Multi-report ([`reports`](configuration/options.md#multi-report) / `--report-*`)

PHPUnit-style multi-report: a single `flow` (or `job`) run can emit several report files at once instead of being executed once per format. Pipelines that need SARIF (Code Scanning) plus JUnit (test dashboards) plus Code Climate (GitLab MR widgets) no longer have to re-analyse everything 3 times.

- **CLI flags**: `--report-json=PATH`, `--report-junit=PATH`, `--report-sarif=PATH`, `--report-codeclimate=PATH`. Each one writes the corresponding format to the given path. Combine as needed.
- **Declarative config**: new `reports` map under `flows.options` and per-flow `options`:
  ```php
  'options' => ['reports' => ['sarif' => 'reports/qa.sarif', 'junit' => 'reports/junit.xml']]
  ```
- **Precedence**: CLI overrides config **format by format**. `--report-sarif=other.sarif` only overrides the SARIF entry; other formats keep config values.
- **`--no-reports`**: PHPUnit `--no-coverage`-style flag that skips the config `reports` section without cancelling CLI `--report-*` flags. Lets a consumer (an AI tool, an ad-hoc script) read clean JSON from stdout without dropping side-effect files declared by the project's config:
  ```bash
  githooks flow qa --format=json --no-reports
  ```
- **`--format` is unchanged**: still governs **stdout** the same way as in 3.2. `--format=sarif --report-sarif=foo.sarif` is legal and produces both stdout SARIF and the file.
- **`conf:check` validation**: rejects unsupported format keys, non-string paths and unwritable target locations; warns when the parent directory does not exist (it gets created on run).

#### Performance monitor — flow `time-budget` + per-job `warn-after` / `fail-after`

Two parallel, independent systems that watch the temporal health of every QA run:

- **Per-job thresholds** (`jobs.<name>.warn-after` / `fail-after`, seconds): catch local regressions of a specific job. Crossing `warn-after` annotates `⚠`; crossing `fail-after` flips a passing job to KO with exit `1`.
- **Flow time-budget** (`flows.options.time-budget` or `flows.<name>.options.time-budget`): catch accumulated drift across the whole flow. The post-hoc sum of executed-job durations is compared with `warn-after` / `fail-after` declared at the flow level. **A flow that crosses `fail-after` exits 1 even when every job passed individually** — the conceptual key of the feature.
- **Independence**: declaring `time-budget` at the flow level does NOT propagate `warn-after` / `fail-after` to individual jobs. The two layers answer different questions ("is this job regressing?" vs. "is the pipeline as a whole regressing?") and remain decoupled.
- **CLI overrides**: `--warn-after=N`, `--fail-after=N` (flow-level on `flow` / `flows`; job-level on `job`). `--no-time-budget` disables both layers for that run; mixing it with `--warn-after` emits a warning on stderr.
- **JSON v2 (explicit-null pattern)**: a new root `timeBudget` field (object or `null`) and per-job `threshold` field (object or `null`) are always present. Consumers can write `if (job.threshold) { … }` without existence checks. `reason` is a string when warned/failed is `true`, `null` otherwise.
- **Conditions header**: extended with a `time-budget=...` segment showing the effective values and their origin (`flows.options`, `flows.<X>.options`, `cli`, `default`).
- **`conf:check` validation**: rejects non-positive integers, `warn-after >= fail-after`, `time-budget` placed inside a job; warns on unknown keys with did-you-mean suggestions.

#### Memory budget + 2D allocator + RSS sampler (Linux)

GitHooks now declaratively watches RSS consumption per job and across the whole flow, schedules admissions in 2D (cores + memory) when both axes are constrained, and surfaces peaks in a canonical `--stats` table — none of GrumPHP, CaptainHook, lefthook, pre-commit or golangci-lint expose this combination.

- **Per-job memory threshold** (`jobs.<name>.memory`): two equivalent forms.
  - Short form `memory: 2000` (MB) — single warn threshold AND scheduler reservation when a flow `memory-budget` is declared.
  - Extended form `memory: { warn-above: 1500, fail-above: 2000 }` — explicit thresholds, no reservation.
  - Crossing `warn-above` annotates `⚠`; crossing `fail-above` flips the job to KO with exit `1` even when the tool itself returned `0`.
- **Flow `memory-budget`** (`flows.options.memory-budget` or per-flow): observational watchdog over the simultaneous RSS sum across jobs in flight. Crossing `fail-above` **kills jobs in flight** (`process->stop(0)`) and skips the queued ones with reason `"flow memory-budget exceeded"`. The flow exits 1 even if every individual job had passed (the conceptual key of the feature).
- **2D allocator** (`flows.options.allocator: fifo|greedy`): when a `memory-budget` is declared **and** at least one job has a short-form `memory:` reservation, the pool admits jobs only when both cores and memory fit. FIFO blocks the entire queue when the head does not fit; greedy scans for the first fitting job. 1D mode (cores only) is preserved when either side of the precondition is missing.
- **RSS sampler**: Linux via `/proc/<PID>/status` walked across the process tree (root + descendants — Symfony's shell wrapper alone is ~1 MB; the actual analyzers are children); macOS via a single `ps -o pid=,ppid=,rss= -ax` invocation per tick. Polled every 1 second while jobs are in flight. Windows degrades gracefully — a short stderr warning (`⚠ Memory budget disabled: RSS sampling not available on Windows`) disables thresholds; the 2D allocator still schedules from declared `memory:` reservations and `--stats` still emits the cores axis.
- **`--stats` table**: 5-column summary (Job / Status / Time / Peak Cores / Peak Memory) with a TOTAL row + temporal attribution lines `Memory peak at Xs: jobA Pmb + jobB Pmb...` and `Cores peak at Xs:  jobA + jobB...`. Active when `--stats` (CLI) or `stats: true` (config).
- **CLI overrides**: `--memory-warn-above=N`, `--memory-fail-above=N`, `--no-memory-budget`, `--allocator=fifo|greedy`, `--stats`. Apply flow-level except in `githooks job` where they apply to the single job.
- **JSON v2**: new root-level `memoryBudget` and `stats` blocks (always present under the explicit-null pattern), per-job `memoryReserved`, `memoryPeak`, `memoryThreshold` and `killedReason`. SARIF / JUnit / Code Climate are unchanged in this iteration.
- **Conditions header**: extended with `memory-budget=warn-above=WMB,fail-above=FMB (origin)`, `allocator=fifo|greedy (origin)` and `stats=true|false (origin)` segments alongside `time-budget`.
- **`conf:check` validation**: positive-integer guards, warn/fail ordering, `memory > memory-budget.warn-above` (could-never-run), unknown allocator values, `memory-budget` typo suggestions.

#### `cores` ↔ native thread flag interchangeability

`cores: N` and the tool's native threading flag (`parallel` on phpcs/phpcbf, `threads` on psalm, `jobs` on parallel-lint, `processes` on paratest) now work identically in both directions: declaring **either** one reserves N cores in the budget and emits the right CLI flag at runtime. Until v3.3 declaring only the native flag (without `cores`) was silently dropped in parallel mode and the allocator distributed the budget evenly instead.

- **No config change required**: the existing pattern of pinning with `cores: N` keeps working unchanged.
- **`conf:check` warning — single-threaded tools**: declaring `cores > 1` on `phpmd`, `phpunit` or `phpcpd` now emits a warning ("`<tool>` is single-threaded; `cores` reserves slots in the budget without benefit"). The tool only uses one core, so reserving more slows admission of other jobs without gain. `cores: 1` and absence of `cores` are silent. `type: custom` is exempt — user scripts may have their own concurrency the system can't inspect.
- **`conf:check` fix — phpcbf**: the conflict warning between `cores` and `parallel` (already emitted for phpcs) now applies to phpcbf as well.
- **`conf:check` validation — native flag**: when declared without `cores`, the native threading flag (`parallel` / `threads` / `jobs` / `processes`) is validated as a positive integer — symmetric with `cores`. A `parallel: -1` or `threads: '4'` now warns instead of silently degrading at the allocator.
- **Symmetric clamp**: a native flag value > `processes` is clamped to the budget at runtime, the same way `cores: N > processes` was clamped before.
- **The flow rules — args clamp at every path**: until v3.3 a job declaring more cores than the flow's `processes` budget still spawned its declared workers in the SO (the pool's accounting was clamped, but `args['parallel']` / `args['threads']` / etc. were not). Same job in two flows ("local" with `processes: 4`, "ci" with `processes: 16`) had to choose one of the two budgets in its declaration. Now `applyThreadLimit()` clamps the override to the flow's budget before reaching the tool, in both the explicit-override and the sequential-default paths. Declare the maximum your job can use; each flow caps it.
- **`conf:check` cross-flow warning for uncontrollable jobs**: phpstan reads its workers from `.neon` and custom jobs are opaque scripts — GitHooks cannot force either to honour the flow budget at runtime. When `phpstan.maximumNumberOfProcesses` (read from the configured `.neon`) or `cores: N` declared on a `type: custom` job exceeds a flow's `processes`, `conf:check` now emits a warning per affected flow naming both values and explaining that other jobs will wait in serial while the offending one runs. Same job referenced by multiple flows is validated against each flow's budget independently; flows that fit are silent. Warning, not error — the user may know their machine can absorb it.

### Deprecations

#### kebab-case keys for `jobs.<name>` (step 1 of 3)

The four legacy camelCase keys inherited from v2 inside `jobs.<name>` are deprecated in favour of their kebab-case counterparts. Both forms keep working in v3.3.x; the camelCase forms will be **removed in v4.0**.

| camelCase (deprecated) | kebab-case (canonical)  |
| ---------------------- | ----------------------- |
| `executablePath`       | `executable-path`       |
| `otherArguments`       | `other-arguments`       |
| `ignoreErrorsOnExit`   | `ignore-errors-on-exit` |
| `failFast`             | `fail-fast`             |

- **Runtime warning**: every command that loads the config (`flow`, `flows`, `job`, `conf:check`, `system:info`) emits a `Deprecated: 'X' is renamed to 'Y'. Will be removed in v4.0.` line on stderr per camelCase key found.
- **Structured output**: a new root-level `deprecations[]` block in JSON v2 (and `runs[0].properties.deprecations` in SARIF) lists each detection as `{job, oldKey, newKey, removalVersion, kind}`. As a side-effect, the JSON v2 also gains a root `warnings[]` field (always present, empty when no warnings) — useful for CI dashboards and AI consumers.
- **Conflict**: declaring both forms for the same key in the same job aborts that job with an error (`conflicting keys '...' and '...'`). Pick one.
- **Out of scope for v3.3**: `conf:migrate` is **not** updated yet — that is step 2 of the deprecation plan, in a later v3.x. The camelCase removal itself is step 3, in v4.0.

Migration guide: the v3.3 deprecations page (folded into [Upgrading from v2](migration/v2-to-v3.md) in 4.0).

---

## [3.2.0]

### New Features

#### Redesigned output system

The output behaviour now depends on the format and the execution context. The unifying rule: **the format decides whether the output streams live or is buffered and emitted at the end.**

- **Live streaming in `githooks job X` (single job)**: tool output (phpstan, phpcs, etc.) is now streamed in real time instead of buffered. Long-running jobs (phpmd, phpunit with coverage) no longer look frozen — you see the tool's actual progress as it happens.
- **Live streaming in `githooks flow` with `processes=1`**: each job is streamed with a header separator between jobs (like `make` or `docker compose up`). You see each tool's output as it runs instead of only `OK/KO` lines at the end.
- **Interactive parallel dashboard in `githooks flow` with `processes > 1`**: when running in a TTY, the output upgrades to a live dashboard with three states — ⏺ queued, ⏳ running (with a live timer), ✓/✗ done. On completion it collapses to a clean summary. In non-TTY environments (CI, piped stdout) it falls back to append-only streaming text so logs remain parseable. Activated automatically via `posix_isatty(STDOUT)`; no flag needed.
- **stdout/stderr split for structured formats**: for `json`, `junit`, `codeclimate` and `sarif`, progress lines (`OK job (Xms) [Y/Z]`, `Done.`, colours) route to stderr and the structured payload stays on stdout. Enables `githooks flow qa --format=json > report.json` without contamination.
- **TTY-aware progress with `--show-progress` override**: the stderr progress handler only emits when stderr is a TTY, so `flow|job --format=json | jq ...` works off pipes, CI and agents **without `2>/dev/null`**. Pass `--show-progress` to force progress even off a TTY — useful for long-running CI pipelines. `--dry-run` never emits progress.

#### Output formats

- **JSON schema v2 ([`--format=json`](how-to/output-formats.md#json-v2))**: enriched per-job fields (`type`, `exitCode`, `paths`, `skipped`, `skipReason`, `fixApplied`) plus top-level `version: 2`, `executionMode`, `passed`, `failed`, `skipped` counters. Stable contract for AI tools, CI dashboards and scripts.
- **JUnit `<skipped>` support**: skipped jobs now emit `<skipped>` elements with a reason attribute.
- **Code Climate format ([`--format=codeclimate`](how-to/output-formats.md#code-climate))**: GitLab-compatible Code Quality report.
- **SARIF format ([`--format=sarif`](how-to/output-formats.md#sarif))**: SARIF 2.1.0 report for GitHub Code Scanning, Azure DevOps and other static-analysis consumers.
- **Unified output target for structured formats**: `json`, `junit`, `codeclimate` and `sarif` all print to stdout by default; pass `--output=PATH` to write the payload to a file. Shell redirection (`> file`) remains equivalent.

#### CI integration

- **Native CI annotations ([CI/CD Integration](how-to/ci-cd.md#ci-annotations))**: auto-detects `GITHUB_ACTIONS=true` or `GITLAB_CI` and wraps job output in `::group::`/`::endgroup::` plus `::error file=…,line=…::` annotations (GitHub) or `section_start:`/`section_end:` markers (GitLab). Parses `file.php:LINE` patterns from tool output.
- **`--no-ci` flag**: opt out of the auto-detection when a CI env var is set but you want plain output (running `act` locally, custom CI where those markers aren't parsed, or scripting on top of GitHooks).

#### New native job types
- **[PHP CS Fixer (`type: php-cs-fixer`)](tools/phpcsfixer.md)**: native support with `config`, `rules`, `dry-run`, `diff`, `allow-risky`, `using-cache`, `cache-file` keywords. Accelerable.
- **[Rector (`type: rector`)](tools/rector.md)**: native support with `config`, `dry-run`, `clear-cache`, `no-progress-bar` keywords. Accelerable.
- **[Paratest (`type: paratest`)](tools/paratest.md)**: first-class support for [paratest](https://github.com/paratestphp/paratest), the parallel driver for PHPUnit. Inherits every PHPUnit keyword and adds `processes` (linked to `cores`).

#### Thread budget

- **Per-job `cores` reservation ([`cores`](configuration/jobs.md#reserving-cores-cores-or-the-tools-native-flag))**: every job can declare `cores: N` to reserve N slots in the thread budget. Controllable tools (phpcs, psalm, parallel-lint, paratest) automatically receive their native threading flag (`--parallel`, `--threads`, `-j`, `--processes`) with the same value, so you configure parallelism once per job regardless of the tool. Budget-only tools (phpstan, custom jobs) use `cores` to keep the `--monitor` peak accurate without forcing worker count. `conf:check` warns when `cores` coexists with a tool's native threading flag.

#### Other
- **`conf:check` command truncation**: long generated commands are truncated to 80 chars (with `…`) in the job table to keep the output readable on narrow terminals. `githooks job X --dry-run` still shows the full command.
- **All supported tools ship as dev dependencies**: `brianium/paratest`, `friendsofphp/php-cs-fixer`, `rector/rector` and `sebastian/phpcpd` are now declared in `require-dev`, and `psalm` is correctly stripped from the `.phar` at build time (it was being embedded by mistake). Running `composer install` in the repo gives every supported tool a binary under `vendor/bin/`, and the distributed `.phar` no longer ships QA tools internally.

---

## [3.1.0]

### New Features
- **Local override (`githooks.local.php`)**: GitHooks looks for a `githooks.local.php` file alongside `githooks.php`. If found, its contents are merged over the main config using `array_replace_recursive`. Allows per-developer environment customization without modifying the shared config. Add `githooks.local.php` to `.gitignore`. See [Docker & Local Override](how-to/docker-local-override.md).
- **`executable-prefix` option**: New option at global, flow, and job level. Prepends a command to all job executables (e.g. `'docker exec -i app'`). Per-job override with `''` or `null` to opt out. Enables Docker, Laravel Sail, and remote environments from a single config. See [Options: executable-prefix](configuration/options.md#executable-prefix).
- **Extra arguments via `--` for `job` command**: `githooks job phpunit_all -- --filter=testFoo` passes extra flags to the underlying tool. Enables dynamic execution from AI tools, scripts, or quick debugging without modifying configuration. See [`githooks job`](cli/job.md).
- **External documentation site**: Full MkDocs Material site with getting started guide, configuration reference, CLI reference, tool docs, how-to guides, migration guides, and comparison page.

### Bug Fixes
- Fix skipped job warnings not showing orange color in terminal output.
- Fix parallel execution deadlock when a job's reserved cores exceeded the total `processes` budget. The thread allocator now clamps both explicit `cores: N` overrides and uncontrollable tools' default workers (e.g. PHPStan reading 4 from `.neon` while `processes: 2`) to the budget, so the admission queue can always admit the head instead of rejecting it forever and spinning the executor at 100% CPU.

---

## [3.0.0] - 2026-04-10

### Breaking Changes
- **PHP minimum raised to 7.4**. Dropped support for PHP 7.0-7.3.
- **SecurityChecker tool removed**. Use a [`custom` job with `composer audit`](tools/custom.md) as replacement.
- **New configuration format: hooks/flows/jobs**. Replaces the previous `Options`/`Tools` format. The old format still works but emits a deprecation warning.
- **`tool` command deprecated**. Replaced by [`flow`](cli/flow.md) and [`job`](cli/job.md) commands. Will be removed in v4.0.
- **YAML configuration deprecated**. PHP format is now the primary format. YAML still works but emits a deprecation warning. Will be removed in v4.0.

### New Architecture — Hooks, Flows, Jobs
- **[Hooks](configuration/hooks.md)**: Map git events (`pre-commit`, `pre-push`, etc.) to flows and jobs. Uses `core.hooksPath` with a universal script instead of copying files to `.git/hooks/`.
- **[Flows](configuration/flows.md)**: Named groups of jobs with shared options (`fail-fast`, `processes`). Reusable across hooks and directly executable from CLI/CI.
- **[Jobs](configuration/jobs.md)**: Individual QA tasks with declarative configuration. Each job declares a `type` (phpstan, phpcs, phpunit, custom, etc.) and its arguments.

### New Commands
- [`githooks flow <name>`](cli/flow.md) — Run a flow by name. Supports `--fail-fast`, `--processes=N`, `--exclude-jobs`, `--only-jobs`, `--dry-run`, `--format=json|junit`, `--fast`, `--fast-branch`, `--monitor`.
- [`githooks job <name>`](cli/job.md) — Run a single job by name. Supports `--dry-run`, `--format=json|junit`, `--fast`, `--fast-branch`.
- [`githooks hook:run <event>`](cli/hook.md) — Run all flows/jobs associated with a git hook event (called by the universal hook script).
- [`githooks status`](cli/status.md) — Show installed hooks, their sync state with config (synced/missing/orphan), and target flows/jobs.
- [`githooks system:info`](cli/system-info.md) — Show detected CPUs and current `processes` configuration with budget warning.
- `githooks conf:migrate` — Migrate v2 configuration to v3 format with automatic backup.
- [`githooks cache:clear`](cli/cache-clear.md) — Clear cache files generated by QA tools. Accepts job names, flow names, or a mix.

### Updated Commands
- [`githooks hook`](cli/hook.md) — Now uses `core.hooksPath` + `.githooks/` directory instead of copying scripts to `.git/hooks/`. `--legacy` flag preserves old behavior (Git < 2.9).
- `githooks hook:clean` — Default now removes `.githooks/` + unsets `core.hooksPath`. `--legacy` flag removes individual hooks from `.git/hooks/`.
- [`githooks conf:init`](cli/conf-init.md) — Now supports `--legacy` flag to generate v2 format.
- [`githooks conf:check`](cli/conf-check.md) — Updated for v3: shows Options, Hooks, Flows, and Jobs tables with the full command each job will execute. Deep validation: verifies executables exist, paths are valid, and config files are accessible.

### New Job Types
- **[Custom](tools/custom.md)**: Replaces the v2 `script` tool. Supports `script` key (simple mode) and a new structured mode via `executablePath` + `paths` + `otherArguments`. Structured mode enables `--fast` acceleration identical to standard tools.

### Execution Modes and Structured Output
- **[`--format=json` and `--format=junit`](how-to/output-formats.md)**: Structured output for `flow` and `job` commands. JSON for machine-readable results; JUnit XML for CI test reporting.
- **[`fast-branch` execution mode](execution-modes.md)**: New third mode alongside `full` and `fast`. Analyzes files that differ between the current branch and the main branch. Ideal for CI/CD. Non-accelerable jobs always run with full paths. Per-job `accelerable` key overrides the default. Deleted files are excluded automatically.
- **`fast-branch-fallback` option**: Controls behavior when `fast-branch` cannot compute the diff (e.g. shallow clone). Values: `full` (default) or `fast`.
- **`main-branch` option**: Configure the main branch name for `fast-branch` diff computation. Auto-detected if not specified.
- **[Thread budget](how-to/parallel-execution.md)**: `processes` now controls total CPU cores, not just parallel jobs. GitHooks distributes threads across jobs respecting each tool's capabilities (phpcs `--parallel`, parallel-lint `-j`, psalm `--threads`). PHPStan workers detected from `.neon` config.
- **`--monitor` flag**: Shows peak estimated thread usage after flow execution, with warning if budget was exceeded.
- **Job argument validation**: `conf:check` and `flow`/`job` commands validate job configuration keys and types at parse time.

### Developer Experience
- **`--dry-run` flag**: Shows the exact shell command each job would execute without running anything. Works with all output formats — `--format=json` includes a `command` field per job.
- **`--only-jobs` flag**: Inverse of `--exclude-jobs` for the `flow` command. Run only the specified jobs: `githooks flow qa --only-jobs=phpstan_src,phpmd_src`.
- **Deep validation in `conf:check`**: Checks that executables exist, that configured `paths` are real directories, and that referenced config files are accessible.
- **Auto-detection of `executablePath`**: When omitted, GitHooks looks for `vendor/bin/{tool}` before falling back to system PATH.

### Conditional Execution
- **[`exclude-files`](configuration/hooks.md#condition-keys)**: Excludes staged files matching glob patterns from triggering execution. Always prevails over `only-files`.
- **[`exclude-on`](configuration/hooks.md#condition-keys)**: Excludes branches matching glob patterns. Always prevails over `only-on`.
- **Double-star (`**`) glob support**: File patterns now support `**` for recursive directory matching. `src/**/*.php` matches all PHP files under `src/` at any depth.
- **`hooks.command` config key**: Customize the command used in generated hook scripts (e.g. `'command' => 'php7.4 vendor/bin/githooks'`).
