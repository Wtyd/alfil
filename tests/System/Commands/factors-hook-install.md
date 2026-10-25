# Factor table — `alfil hook` and the configuration it installs from

```
COMPONENTE: CreateHookCommand::handleV3() — whether to install, given what the parser returned
INVARIANTE(S):
  I1. A configuration the engine would refuse to run (`hook:run`, `flow`) is never installed:
      errors → exit 1, nothing written. (BUG-36 for a file that cannot be read, BUG-37 for one
      that reads but does not validate.)
  I2. What `conf:check` reports about the file, `hook` reports too: every validation warning is
      printed before the install line.
  I3. The parser's verdict decides; the hooks section only decides between "installed" and
      "nothing to install".
```

| Factor | Clases de equivalencia | Valores AVL |
|---|---|---|
| parser outcome | throws with `--config` (missing file, v2, yaml, not an array), throws not-found without `--config`, errors (with or without warnings), warnings only, clean | — (enum) |
| hooks section | events declared, absent or empty | — (enum) |

## Decision table

| outcome | hooks | output | exit | writes | row |
|---|---|---|---|---|---|
| throws, `--config` given | * | the exception message | 1 | nothing | 1 |
| throws not-found, no `--config` | * | `Hook pre-commit created` | 0 | `.git/hooks/pre-commit` (minimal install) | 2 |
| errors | * | `❌` each error; no warning, no install line | 1 | nothing | 3 |
| errors + warnings | * | `❌` each error only — the warnings are noise on a refused install | 1 | nothing | 4 |
| warnings | events | `⚠️` each warning, then `Hook <event> installed` | 0 | `.githooks/<event>`, `core.hooksPath` | 5 |
| warnings | none | `⚠️` each warning, then `No hooks defined in configuration` | 0 | nothing | 6 |
| clean | events | `Hook <event> installed` | 0 | `.githooks/<event>`, `core.hooksPath` | 7 |
| clean | none | `No hooks defined in configuration` | 0 | nothing | 8 |

## Clase patógena

Rows 3–4: a configuration that reads but does not validate. Before 4.0 the command installed it
with exit 0 and no message, so the failure moved to the first commit; a `hooks.command` still
calling the removed `githooks` binary (row 5) was baked into the scripts without a word.

## Cobertura

- `CreateHookCommandTest::an_unusable_explicit_config_installs_nothing` — row 1.
- `CreateHookCommandTest::no_configuration_anywhere_installs_the_minimal_hook` — row 2.
- `CreateHookCommandTest::the_validation_outcome_decides_the_install` — rows 3–6.
- `CreateHookCommandTest::v3_mode_installs_all_configured_hooks_in_githooks_directory` — row 7.
- `CreateHookCommandTest::v3_mode_warns_when_hooks_section_is_empty` — row 8.
- `HookV3ReleaseTest::it_refuses_to_install_from_a_configuration_with_validation_errors` — row 3 on the `.phar`.
- `HookV3ReleaseTest::it_prints_the_validation_warnings_before_installing` — row 5 on the `.phar`.
