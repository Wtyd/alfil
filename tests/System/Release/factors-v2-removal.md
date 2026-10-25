# Factor table — the v2 engine removal, as seen from the `.phar` (4.0)

```
COMPONENTE: ConfigurationParser::parse() → UnsupportedConfigurationFormatException, as surfaced by every command
INVARIANTE(S):
  I1. A configuration 4.0 does not read is rejected with ONE error line, the same text in every
      command (text and JSON), naming the file and the way out; exit 1.
  I2. The kind of rejection depends only on the file, never on the command that loads it.
  I3. `hook` writes nothing (no script, no core.hooksPath) when the configuration is rejected.
```

| Factor | Clases de equivalencia | Valores AVL |
|---|---|---|
| command (who loads the file) | `flow`, `flows`, `job`, `conf:check` text, `conf:check --format=json`, `hook`, `hook:run`, `status`, `cache:clear` | — (enum) |
| configuration kind | v2 (`Options`/`Tools`), `.yml` extension, PHP not returning an array, v3 with camelCase job keys | — (enum) |

## Decision table

The command axis is only exhaustive for the headline kind (v2): I2 says the other kinds go through the same
code path, so they are sampled on three commands that reach the parser from different entry points
(`flow`, `conf:check`, `hook`).

| kind | command | output | exit | row |
|---|---|---|---|---|
| v2 | each of the 8 text commands | `v2 configuration format (Options/Tools) is not supported since 4.0: <abs path>. Migrate it with GitHooks 3.8 ('githooks conf:migrate') before upgrading.` (`conf:check` prefixes `❌ `) | 1 | 1–8 |
| v2 | `conf:check --format=json` | `{version: 2, valid: false, errors: [same text], warnings: [], deprecations: []}` and nothing else | 1 | 9 |
| yaml | `flow`, `conf:check`, `hook` | `YAML configuration files are not supported since 4.0: <abs path>. Migrate it with GitHooks 3.8 ('githooks conf:migrate') to githooks.php before upgrading.` | 1 | 10–12 |
| not array | `flow`, `conf:check`, `hook` | `PHP configuration file does not return an array.` (3.8.1 already said it in `conf:check`; `flow`/`flows`/`job` escaped with a stack trace) | 1 | 13–15 |
| camelCase job keys | `conf:check --format=json` | `errors` = one `Job 'lint': key '<old>' was removed in 4.0. Use '<new>'.` per key, in declaration order | 1 | 16 |
| v2 / yaml / not array | `hook` | I3: hook scripts unchanged, `core.hooksPath` unchanged | 1 | 17 |

## Clase patógena

Row 17: a rejected configuration that still installs a hook (the BUG-36 family, closed in hito 1 for the
missing-file case). The release tests run against this repository's own wiring, so "writes nothing" is
asserted as a snapshot of `.githooks/pre-commit`, `.git/hooks/pre-commit` and `core.hooksPath` before and after.

## Cobertura

- `V2RemovalReleaseTest::a_configuration_4_0_does_not_read_is_rejected_with_one_line` — rows 1–8, 10–15.
- `V2RemovalReleaseTest::conf_check_json_carries_the_rejection_as_its_only_error` — row 9.
- `V2RemovalReleaseTest::camel_case_job_keys_are_rejected_naming_the_kebab_case_key` — row 16.
- `V2RemovalReleaseTest::hook_writes_nothing_for_a_configuration_4_0_does_not_read` — row 17.
- `HookV3ReleaseTest::it_refuses_to_install_from_a_missing_configuration_file` — the missing-file kind.
