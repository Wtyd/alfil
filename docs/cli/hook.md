# alfil hook

Install or remove git hooks.

## Install hooks

```
alfil hook [options]
```

For each event defined in the [`hooks` section of the configuration](../configuration/hooks.md), Alfil:

1. Creates a `.githooks/<event>` script file.
2. Runs `git config core.hooksPath .githooks`.

The generated scripts are universal and identical — each one calls `alfil hook:run <event>`, which resolves the event to its configured flows/jobs. The command prefix is taken from [`hooks.command`](../configuration/hooks.md) in the config (defaults to `php vendor/bin/alfil`).

When you install with `--config=<file>`, the scripts also carry `--config` with that file's path **relative to the repository root**, so every trigger runs the configuration the hooks were installed from — not whatever `alfil.php` the current directory resolves to. Git runs hooks from the top level of the working tree, so the relative path works in every clone that commits `.githooks/`. A file outside the repository can only be baked as an absolute path; the install warns that other clones will not have it.

An explicit `--config` that cannot be used — the path does not exist, the file is a v2 or YAML configuration, or it does not return an array — is an error: the command prints it, exits 1 and installs nothing. The minimal `.git/hooks/` install described under [Legacy mode](#legacy-mode) only applies when there is no `--config` and no `alfil.php` in the default locations.

A configuration that reads but does not validate — the errors [`conf:check`](conf-check.md) reports and `hook:run` refuses to run — is not installed either: the errors are printed and the command exits 1. Validation warnings, such as a `hooks.command` that still calls the removed `githooks` binary, are printed before the install line.

```bash
alfil hook                  # Install all configured hooks
alfil hook --config=qa/alfil.php   # Hooks run qa/alfil.php on every trigger
alfil hook --legacy         # Install pre-commit in .git/hooks/ (Git < 2.9)
alfil hook pre-push --legacy  # Install specific hook in .git/hooks/
```

!!! tip
    The `.githooks/` directory should be committed to version control. The scripts are universal — they never need to be regenerated after config changes, only if you move or rename the file they were installed with via `--config`.

### Legacy mode

The `--legacy` flag installs hooks by copying a script to `.git/hooks/`. This is needed for environments with Git < 2.9 which does not support `core.hooksPath`. You can also pass a custom script file:

```bash
alfil hook pre-commit MyCustomPrecommit.php --legacy
```

## Remove hooks

```
alfil hook:clean [options]
```

```bash
alfil hook:clean                     # Remove .githooks/ + unset core.hooksPath
alfil hook:clean --legacy            # Remove pre-commit from .git/hooks/
alfil hook:clean pre-push --legacy   # Remove specific hook from .git/hooks/
```

Without `--legacy`, removes the entire `.githooks/` directory and unsets `core.hooksPath`. With `--legacy`, removes individual hooks from `.git/hooks/`.

## Internal: hook:run

```
alfil hook:run <event> [--config=<file>]
```

Executes all flows and jobs associated with a git hook event. This is the command that the universal hook script calls internally — you normally don't need to run it manually.

For `pre-commit` events, fast mode is activated automatically.

## See also

- [Configuration: Hooks](../configuration/hooks.md)
- [`alfil status`](status.md) — check hook installation status.
- [Getting Started: Running Your First Hook](../getting-started/first-hook.md)
