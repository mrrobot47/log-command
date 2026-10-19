# log-command Behat coverage

Suite: `log.feature` (command surface, site resolution, errors), `log-site.feature` (single-site logs), `log-global.feature` (`--cli`, `--global`). Shared steps live in the marked section at the end of `bootstrap/FeatureContext.php`.

## Inventory

The package registers one command, `ee log`, with one subcommand, `show` (there is no `tail` subcommand: `show` itself ends in `tail -n <n> -f <files>` via `system()`).

`ee log show [<site-name>] [--n=<line-count>] [--global] [--cli] [--all] [--nginx] [--php] [--wp] [--access] [--error]`

| Item | Behaviour (from `src/Log_Command.php`) | Covered by |
|---|---|---|
| `ee log` (no subcommand) | Prints usage of `show` | log.feature "ee log without a subcommand lists show" |
| `ee help log show` | Synopsis and option docs | log.feature "ee help log show documents every option" |
| `ee log tail` | Not a subcommand, framework error | log.feature "log tail is not a registered subcommand" |
| Unknown / misspelt flag | Framework "unknown --x parameter" (+ suggestion), exit 1 | log.feature "An unknown flag…", "A misspelt flag…" |
| Extra positional | "Too many positional arguments", exit 1 | log.feature "Too many positional arguments…" |
| `<site-name>` omitted, outside a site dir | `auto_site_name()` error, exit 1 | log.feature "Without a site name…" |
| `<site-name>` omitted, inside a site dir | Site detected from cwd (`get_site_name()` matches the logical path; the step passes it as `PWD` because `app/` is a volume symlink) | log.feature "The site name is detected from the current directory" |
| Unknown site | `auto_site_name()` error ("Could not find the site…") | log.feature "An unknown site is reported" |
| Wrong name inside a site dir | "…is not a valid site-name. Did you mean…" | log.feature "A wrong site name inside a site directory…" |
| Disabled site | `get_site_info()` "is not enabled" error | log.feature "A disabled site is refused" |
| Trailing slash in site name | Accepted | log.feature "A trailing slash…" |
| `http://<site>/` form | Should resolve; fails (LOGCMD-8) | log.feature `@known-bug` "A site given as a URL…" |
| Default types (nginx, php, wp) on html | nginx `access.log` + `error.log` only | log-site "html site default…" |
| Default types on php | nginx + php logs, no wp warning | log-site "php site default…" |
| Default types on wp | nginx + php logs, "wp log doesn't exist!" warning when no debug.log | log-site "wp site default…" |
| `--nginx` | All `*.log` under `logs/nginx` (recursive) | log-site "--nginx…", "Log files in subdirectories…" |
| `--php` | All `*.log` under `logs/php` | log-site "--php…" |
| `--wp` | `app/htdocs/wp-content/debug.log`; warning + "No logs found!" when missing | log-site "--wp without a debug.log…", "--wp follows…" |
| `--access` | Files named `access.log` in nginx (+ php for non-html) | log-site "--access shows…", "--access only picks files named access.log", "--access and --error on an html site…" |
| `--error` | Files named `error.log` in nginx (+ php for non-html) | log-site "--error shows…", "--access and --error on an html site…" |
| `--all` | All five types | log-site "--all on a wp site…" |
| Combined type flags | Union, de-duplicated (`array_unique`) | log-site "Combined type flags…", "--nginx --php on a php site…" |
| Type the site lacks | "No logs found!", exit 1 | log-site outline "A log type the site does not have…" (html `--php`, html `--wp`, php `--wp`) |
| html/php sites drop the wp type | Intended by `unset()`, broken (LOGCMD-3) | log-site `@known-bug` outline "--wp is ignored for non-WordPress sites…" |
| Non-`.log` files and rotated `*.log.1` | Ignored | log-site "Files without a .log extension…" |
| File without extension | PHP warning (LOGCMD-7) | log-site `@known-bug` "A file without an extension…" |
| `--n` default 10 | `tail -n 10` | log-site "The default line count is 10" |
| `--n=<k>` / `--n=0` | Initial lines limited / none | log-site "--n limits…", "--n=0…", log-global "--cli honours --n" (exactly one ee.log line) |
| `--n` without value | Framework warning, falls back to 10 | log-site "--n without a value…" |
| `--n=abc` | tail error but exit 0 (LOGCMD-2) | log-site `@known-bug` "An invalid --n value…" |
| Shell injection via `--n` or a log file name | Unescaped `system()` (LOGCMD-1) | log-site `@known-bug` "--n is not interpreted by the shell", "A log file name is not interpreted by the shell" |
| Following (`tail -f`) | New lines stream until killed | log-site "New lines are streamed while following"; every happy path asserts exit 124 (still running at the timeout) |
| Real nginx access log entry | HTTP request appears in `--access` output | log-site "A real request shows up in the nginx access log" |
| "watching logfile" lines | `EE::log`, path shown relative to the sites dir; should be hidden by `--quiet`, isn't (core CORE-3) | all happy paths; log-site `@known-bug` "--quiet hides…" |
| `--cli` | Follows `{EE_ROOT_DIR}/logs/ee.log`; the "watching logfile" line is also written to ee.log (side effect) | log-global "--cli follows EasyEngine's own log" |
| `--cli` with a site name / type flags | Both ignored | log-global "--cli ignores a site name and type flags" |
| `--global` | ee.log + nginx/php/wp logs of every site | log-global "--global follows ee.log and the logs of every site type" |
| `--global`, WP site without debug.log | Warning, keeps following | log-global "--global warns for a WordPress site…" |
| `--global` with `--cli` and a (nonexistent) site name | `--global` wins, site name ignored (documents current behaviour) | log-global "--global takes precedence…" |
| `--global` and disabled sites | Included | log-global "--global includes disabled sites" |
| `--global` undefined `$wp_logs_path` | PHP warning (LOGCMD-4) | log-global `@known-bug` "--global raises no PHP warnings…" |
| `--global` with type filters | Filters ignored (LOGCMD-5) | log-global `@known-bug` "--global honours a type filter" |
| Service logs (help promises them for `--global`/`--all`) | Never followed (LOGCMD-6) | log-global `@known-bug` "--global includes the global nginx-proxy logs" |
| `--cli`/`--global` "cli log doesn't exist!" and global "No logs found!" | Not covered: unreachable, ee's file logger creates ee.log before the command runs | – |
| Local-DB mariadb logs (`services/mariadb/logs`) | Not covered: never read by the command (part of LOGCMD-6), and a local-DB site adds ~1 min | – |

Side effects: the command is read-only apart from appending its "watching logfile" lines to ee.log (asserted). There are no DB rows, containers, proxy config or hooks to verify; idempotency is implicit (every scenario can be rerun; site state is asserted unchanged for the disabled site).

Estimated coverage: about 95% of the branches of `Log_Command` (every branch except the unreachable missing-ee.log paths).

## Known bugs (`## log-command` in issues.md)

`@known-bug` scenarios assert the fixed behaviour, so they fail until the bug is fixed.

- LOGCMD-1 (High): `--n` and the file list are interpolated unescaped into `system( "tail -n $lines -f $final_string" )`. `logs/php` is writable by www-data in the php container, so a planted file name like `x$(cmd).log` runs as root on the host when the admin follows the logs.
- LOGCMD-2 (Low): `--n` is not validated and tail's exit status is ignored, so invalid values or tail failures exit 0.
- LOGCMD-3 (Low): `unset( $final_types['php'], $final_types['wp'] )` targets string keys of a list, so it is a no-op; html/php sites follow a `wp-content/debug.log` (and an html site would follow `logs/php`).
- LOGCMD-4 (Low): `--global`: `$wp_logs_path` is only set for WP sites, so a non-WP site before the first WP site raises an undefined-variable warning and later ones reuse a stale path.
- LOGCMD-5 (Low): `--global` ignores `--nginx/--php/--wp/--access/--error` (`$final_types` unused), and it also probes nonexistent `logs/access` and `logs/error` directories.
- LOGCMD-6 (Low): help says `--global` includes "all services" and `--all` "service logs", but no service logs (nginx-proxy, local mariadb) are followed. The docblock examples also show wrong paths (`~/easyengine/sites/example.com/logs/debug.log`).
- LOGCMD-7 (Low): `pathinfo()['extension']` read without `isset()`: a file without extension in a log dir raises a PHP warning.
- LOGCMD-8 (Low, site-command helper): `auto_site_name()` accepts `http(s)://<site>/` but returns the args unchanged, so `get_site_info()` fails with "Site http://… does not exist."; site-command's own subcommands use the same pair.
- CORE-3 (Low, core): the logger is set up before the config and arguments are parsed, so `--quiet` never hides the "watching logfile" lines (found on the CI runner, https://github.com/mrrobot47/log-command/actions/runs/36085159478).

## Tags and runtime

- `@known-bug`: 11 scenarios (10 definitions, one outline with 2 examples). Exclude with `--tags='~@known-bug'`.
- No `@le`, `@remote` or `@destructive` scenarios: the command needs no public DNS or backups and changes no global state.
- Sites (prefix `bh-log-command-`, deleted by `@AfterFeature`, which only touches that prefix): `-1` html, `-2` php, `-3` wp, `-4` html (disabled), `-99` guaranteed absent. Sites are created lazily by `Given the site … of type … exists`, so each scenario runs on its own and only the first scenario of a feature pays for creation.
- Every `ee log show` run is wrapped in `timeout` (own process group, so `tail` dies too); exit 124 means "still following".
- Estimated runtime: about 12 minutes (site creation ~4 minutes over the three features, WordPress dominating; 55 scenarios at 3–10 s each).
