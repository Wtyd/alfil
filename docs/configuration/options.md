# Options

Execution options control how flows run their jobs. They can be set globally (for all flows) or per flow.

## Available options

| Keyword | Type | Default | Description |
|---|---|---|---|
| `processes` | Integer | `1` | Total CPU cores budget for parallel execution. |
| `fail-fast` | Boolean | `false` | Stop the flow when a job fails. |
| `ignore-errors-on-exit` | Boolean | `false` | Flow returns exit 0 even if any job has errors. Overrides job-level setting. |
| `main-branch` | String | Auto-detected | Main branch name for `fast-branch` diff computation. |
| `fast-branch-fallback` | String | `'full'` | Fallback when `fast-branch` cannot compute the diff (e.g. shallow clone). `'full'` runs with all paths; `'fast'` falls back to staged files only. |
| `executable-prefix` | String | `''` | Command prefix prepended to all job executables (e.g. `'docker exec -i app'`). |
| `reports` | Map<string,string> | `[]` | Map of `format => path` to write extra report files alongside `--format`/`--output`. See [Multi-report](#multi-report). |
| `time-budget` | Object | `null` | Flow-level `warn-after` / `fail-after` thresholds (seconds) over the sum of executed-job durations. See [Time budget](#time-budget-time-budget). |
| `memory-budget` | Object | `null` | Flow-level `warn-above` / `fail-above` thresholds (MB) over the simultaneous RSS sum across jobs in flight. See [Memory budget](#memory-budget-memory-budget). |
| `allocator` | String | `'fifo'` | Admission strategy when the pool fills: `fifo` (strict order) or `greedy` (first-fit scan). See [Allocator strategy](#allocator-strategy-allocator). |
| `stats` | Boolean | `false` | Activate RSS sampling and emit the `--stats` summary table. See [Stats](#stats-stats). |
| `history-size` | Integer | `0` | Persist each run under `.githooks/history/` and keep the last N per flow (FIFO) for [`profile`](../cli/profile.md). `0` disables it. See [`alfil profile`](../cli/profile.md#enabling-history). |

## Priority

Options are resolved from lowest to highest priority:

1. **Defaults** — built-in values.
2. **Global options** — `flows.options`.
3. **Flow options** — per-flow `options`.
4. **CLI flags** — `--fail-fast`, `--processes=N` override everything.

```php
'flows' => [
    'options' => [
        'fail-fast' => false,      // global default
        'processes' => 2,
    ],

    'qa' => [
        'options' => ['fail-fast' => true],  // overrides for this flow
        'jobs' => ['phpcs_src', 'phpstan_src'],
    ],
],
```

Running `alfil flow qa --processes=4` would use `fail-fast=true` (from flow) and `processes=4` (from CLI).

### Per-key cascade

The cascade is evaluated **key by key**, not as a whole block. When a per-flow `options` block declares one key, the other keys still inherit from `flows.options`. Declaring `'options' => ['fail-fast' => true]` for a flow does **not** drop globally declared `executable-prefix`, `fast-branch-fallback`, `processes`, `reports`, budgets, allocator, stats, `history-size`, etc. — every key is resolved independently against the cascade above. This applies equally to declarative meta-flows with their own `options` block.

```php
'flows' => [
    'options' => [
        'executable-prefix'    => 'docker exec -i app',
        'fast-branch-fallback' => 'fast',
        'reports'              => ['sarif' => 'reports/global.sarif'],
        'processes'            => 4,
    ],

    'qa' => [
        'options' => ['fail-fast' => true], // only overrides this key
        'jobs'    => ['phpcs_src', 'phpstan_src'],
        // executable-prefix, fast-branch-fallback, reports, processes
        // are all inherited from flows.options
    ],
],
```

> Versions before 3.4 read `executable-prefix`, `fast-branch-fallback` and `reports` block-level (`flow.options ?? globals`), so declaring a single per-flow key silently dropped those three. Fixed in 3.4 — see the [3.4 changelog entry](../changelog.md#34).

## Validation of invalid values

The validity criterion for each option is single-sourced (it lives in the option's owning class and is shared by both origins), but the **action taken on an invalid value depends on where it came from**:

- **Config file — a contract.** An invalid value (`processes: 0`, `time-budget: {warn-after: 'x'}`, `allocator: 'random'`) is a **hard error**: [`conf:check`](../cli/conf-check.md) reports it and the run aborts. Config values are also strictly typed — a string where an integer is expected (`processes: '2'`) is an error.
- **CLI flag — best-effort.** An invalid value (`--processes=0`, `--warn-after=x`, `--allocator=random`) emits a **warning on stderr and is ignored**: the cascade falls back to the configured value or the default and the run continues.

This mirrors the existing `--allocator` behaviour across every option, so an invalid flag never silently changes what runs.

## Thread budget

The `processes` option controls the **total CPU cores** available — not just the number of parallel jobs, and not just a soft hint. It is the **absolute ceiling**: no individual job can spawn more workers than `processes` allows. When `processes > 1`, Alfil distributes threads across jobs that support internal parallelism:

| Tool | Internal parallelism flag |
|---|---|
| phpcs / phpcbf | `--parallel` |
| parallel-lint | `-j` |
| psalm | `--threads` |
| paratest | `--processes` |
| phpstan | Worker count from `.neon` config (not adjustable at runtime) |

For example, with `processes: 4` and two threadable jobs, each gets approximately 2 threads. Use `alfil flow <name> --monitor` to see the actual thread usage.

### Per-job reservation (`cores`)

A job can opt out of the automatic split by declaring [`cores: N`](jobs.md#reserving-cores-cores-or-the-tools-native-flag). The allocator reserves exactly N cores for that job and, when the tool is controllable (phpcs, psalm, parallel-lint, paratest), passes the corresponding flag automatically. Useful to guarantee a specific budget for paratest workers or to pin a phpstan configuration.

When the declared value exceeds `processes` (`cores: 8` on a flow with `processes: 4`), the runtime clamps it to the budget — both the native flag emitted to the tool and the pool's accounting see `4`. The same clamp applies to the native flag declared without `cores` and to capability defaults (parallel-lint's default `-j 10`, paratest's default `--processes=4`). For uncontrollable tools (phpstan reads `.neon`, custom scripts are opaque) the runtime cannot force the clamp; [`conf:check`](../cli/conf-check.md) emits a cross-flow warning instead. See [The flow rules](jobs.md#the-flow-rules) for the full pattern.

See [How-To: Parallel Execution](../how-to/parallel-execution.md) for detailed examples.

## Fail-fast and ignore-errors-on-exit

`fail-fast` and `ignore-errors-on-exit` are **not compatible at flow level**. If both are `true`, `ignore-errors-on-exit` is ignored.

However, `fail-fast` is compatible with `ignore-errors-on-exit` at **job level**. A job with `ignore-errors-on-exit: true` will not trigger the flow's fail-fast, even if it detects problems.

```php
'flows' => [
    'safe' => [
        'options' => ['ignore-errors-on-exit' => true],
        'jobs'    => ['first_job', 'second_job'],  // flow always returns exit 0
    ],

    'strict' => [
        'options' => ['fail-fast' => true],
        'jobs'    => ['first_job', 'third_job'],   // stops at first failure
    ],
],
```

The `ignore-errors-on-exit` at flow level overrides the same option for all jobs in that flow.

## Executable prefix

The `executable-prefix` option prepends a command to every job's executable. This is the key to running Alfil inside Docker, Laravel Sail, or any remote environment.

```php
'flows' => [
    'options' => [
        'executable-prefix' => 'docker exec -i app',
    ],
],
```

With this, a job configured as `vendor/bin/phpstan analyse src` will run as `docker exec -i app vendor/bin/phpstan analyse src`.

### Per-job override

Individual jobs can override or opt out of the global prefix:

```php
'jobs' => [
    'phpstan_src' => [
        'type'  => 'phpstan',
        'paths' => ['src'],
        // Uses global prefix (docker exec -i app vendor/bin/phpstan ...)
    ],
    'eslint_src' => [
        'type'              => 'custom',
        'script'            => 'npx eslint src/',
        'executable-prefix' => '',     // Opt out: runs locally, not in Docker
    ],
    'phpcs_remote' => [
        'type'              => 'phpcs',
        'paths'             => ['src'],
        'executable-prefix' => 'ssh server',  // Different prefix for this job
    ],
],
```

### Priority

1. **Per-job `executable-prefix`** — highest priority. Set to `''` (empty string) or `null` to explicitly opt out.
2. **Flow-level `options.executable-prefix`** — applies to all jobs in that flow.
3. **Global `flows.options.executable-prefix`** — applies to all jobs.

### Local override

The most common pattern is to set `executable-prefix` in `alfil.local.php` so each developer configures their own environment:

```php
// alfil.local.php (not committed)
<?php
return [
    'flows' => [
        'options' => [
            'executable-prefix' => 'docker exec -i app',
        ],
    ],
];
```

See [Configuration File: Local Override](file.md#local-override-alfillocalphp) and [How-To: Docker & Local Override](../how-to/docker-local-override.md).

## Multi-report

The `reports` option emits one or more report files in a single flow run, in the style of PHPUnit's `--log-junit`/`--coverage-html` flags or Psalm's `--report=`. Useful when a CI pipeline needs **SARIF** for GitHub Code Scanning, **JUnit** for the test dashboard and **Code Climate** for GitLab MR widgets — without re-running the analysis three times.

### Declarative configuration

```php
'flows' => [
    'qa' => [
        'jobs' => ['phpstan-src', 'phpcs', 'phpunit'],
        'options' => [
            'reports' => [
                'sarif'       => 'reports/qa.sarif',
                'junit'       => 'reports/junit.xml',
                'codeclimate' => 'reports/gl-code-quality.json',
            ],
        ],
    ],
],
```

Valid format keys are `json`, `junit`, `sarif`, `codeclimate` (the structured set). Any other key is rejected by `conf:check`. Missing parent directories are created on run.

### CLI flags

The same effect can be obtained per-invocation:

```bash
alfil flow qa \
  --report-sarif=reports/qa.sarif \
  --report-junit=reports/junit.xml \
  --report-codeclimate=reports/gl-code-quality.json
```

### Precedence (per format)

CLI wins over config, **format by format**. Given a config that declares SARIF and JUnit, `--report-sarif=other.sarif` overrides only the SARIF entry; the JUnit one keeps the config value.

### Combining `--format` and `--report-*`

`--format=` keeps governing **stdout** exactly as before. The `--report-*` files are always extra targets:

| Invocation | stdout | Files |
|---|---|---|
| `flow qa` | text summary | — |
| `flow qa --format=json` | JSON | — |
| `flow qa --format=json --output=foo.json` | (silent) | `foo.json` |
| `flow qa --report-sarif=q.sarif` | text summary | `q.sarif` |
| `flow qa --format=json --report-sarif=q.sarif --report-junit=q.xml` | JSON | `q.sarif`, `q.xml` |
| `flow qa --format=sarif --report-sarif=q.sarif` | SARIF | `q.sarif` (same payload twice — different destinations) |

### `--no-reports`

Skips the `reports` section from config without cancelling the CLI `--report-*` flags (PHPUnit `--no-coverage` style). Useful when an external consumer (an AI tool, an ad-hoc script) wants to read JSON cleanly without dropping files declared by the project's config:

```bash
# Read JSON without writing any report file
alfil flow qa --format=json --no-reports

# Same, but still write a single SARIF that the tool needs
alfil flow qa --format=json --no-reports --report-sarif=/tmp/q.sarif
```

See [How-To: CI/CD](../how-to/ci-cd.md) for end-to-end pipeline recipes.

## Time budget (`time-budget`)

Watches the **accumulated execution time** of a flow against declared
`warn-after` / `fail-after` thresholds (seconds). Catches drift across
the whole pipeline — a flow can cross `fail-after` and exit `1` even
when every job individually returned `0`. Independent of per-job
`warn-after` / `fail-after` thresholds: the two layers answer different
questions ("is this *job* regressing?" vs. "is the pipeline as a whole
regressing?") and remain decoupled.

```php
'flows' => [
    'options' => [
        // Default global. Inherited by any flow that does not redefine it.
        'time-budget' => [
            'warn-after' => 800,   // seconds
            'fail-after' => 1200,
        ],
    ],
    'quick' => [
        'options' => [
            // Per-flow override.
            'time-budget' => ['warn-after' => 60],
        ],
        'jobs' => ['phpcbf', 'parallel-lint'],
    ],
],
```

**Behaviour when crossed:**

| Threshold | Action |
|---|---|
| `warn-after` | `⚠` annotation, exit `0` |
| `fail-after` | Exit `1` even if every job had passed individually |

The flow value is evaluated **post-hoc** (sum of executed-job durations
once the run finishes). It does not preempt the schedule — it surfaces
drift after the fact.

**Per-job thresholds** are declared directly inside the job with flat
`warn-after` / `fail-after` keys — see [Jobs → Per-job time threshold](jobs.md#per-job-time-threshold-warn-after-fail-after).
At both levels `conf:check` rejects `warn-after >= fail-after` and
non-positive integers; `time-budget` placed inside a job is rejected
(it is reserved for `flows.options`).

CLI overrides: `--warn-after=N`, `--fail-after=N`, `--no-time-budget`.
Apply flow-level on `flow` / `flows`; apply to the single job on
`alfil job <name>`. `--no-time-budget` always wins and disables both
layers for that run; mixing it with `--warn-after` / `--fail-after`
emits a stderr warning and ignores the conflicting flags.

## Memory budget (`memory-budget`)

Watches the **simultaneous** RSS sum across all jobs in flight against
declared `warn-above` / `fail-above` thresholds (MB). Independent of
per-job `memory` thresholds — they answer different questions ("is this
job leaking?" vs. "is the runner about to OOM?").

```php
'flows' => [
    'options' => [
        'processes'     => 10,
        // Default global. Inherited by any flow that does not redefine it.
        'memory-budget' => [
            'warn-above' => 5500,
            'fail-above' => 6000,
        ],
    ],
    'quick' => [
        'options' => [
            // Per-flow override.
            'memory-budget' => ['warn-above' => 800],
        ],
        'jobs' => ['phpcbf', 'parallel-lint'],
    ],
],
```

**Behaviour when crossed:**

| Threshold | Action |
|---|---|
| `warn-above` | `⚠` annotation, exit `0` |
| `fail-above` | **Kills jobs in flight** — the whole process tree of each job (`SIGTERM` leaf-to-root, then `SIGKILL` after a 5 s grace), skips queued jobs with reason `"flow memory-budget exceeded"`, exit `1` even if every job had passed |

CLI overrides: `--memory-warn-above=N`, `--memory-fail-above=N`,
`--no-memory-budget`. The last disables both the per-job and flow-level
evaluation for that run.

**Linux and macOS in v3.3.** Windows degrades gracefully: the runtime
emits one stderr warning (`⚠ Memory budget disabled: RSS sampling not
available on Windows`) and disables thresholds. The 2D allocator still
schedules using the declared `memory:` reservations, and `--stats`
still reports cores info.

## Allocator strategy (`allocator`)

Controls admission order when the pool fills. Two values:

| Value | Behaviour |
|---|---|
| `fifo` (default) | Strict declaration order. If the head of the queue does not fit (cores or memory), the entire queue waits — predictable for CI parity. |
| `greedy` | First-fit scan over the entire queue. Picks the first job that fits the current resources. Cannot starve (the queue is closed and finite). |

```php
'flows' => [
    'options' => ['allocator' => 'greedy'],
    'qa' => [
        'options' => ['allocator' => 'fifo'], // per-flow override
        'jobs'    => [...],
    ],
],
```

CLI override: `--allocator=fifo|greedy`.

The strategy applies to both 1D mode (cores only) and 2D mode (cores +
memory). 2D mode activates only when both a `memory-budget` is declared
and at least one job has a short-form `memory:` reservation.

## Stats (`stats`)

Activates RSS sampling and emits the canonical `--stats` summary table
plus the `stats` block in JSON v2. Independent of thresholds — useful
for calibration runs (declare nothing; observe peaks; then set
`memory-budget` and `memory:` with knowledge).

```php
'flows' => [
    'options' => ['stats' => true],
],
```

CLI override: `--stats` (always wins).

Sample output:

```
Results: 5/5 passed in 21.6s ✔

+----------------+--------+--------+------------+-------------+
| Job            | Status | Time   | Peak Cores | Peak Memory |
+----------------+--------+--------+------------+-------------+
| phpstan-src    | OK     | 8.2s   | 2          | 1850 MB     |
| ...            |        |        |            |             |
+----------------+--------+--------+------------+-------------+
| TOTAL (flow)   | 5/5 ✔  | 21.6s  | 8/10       | 5410 MB     |
+----------------+--------+--------+------------+-------------+

Memory peak at 12.3s: phpstan-src 1880 + phpunit 1240 + ...
Cores peak at 12.3s:  phpstan-src + phpunit + ...
```

The `cores` sub-block of the JSON `stats` block is emitted always when
stats are active (deterministic from the schedule); the `memory` sub-block
is emitted only when the sampler actually produced data.
