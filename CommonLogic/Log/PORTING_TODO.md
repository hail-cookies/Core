# Monolog 3 porting todo

## Processors (prerequisite for CSV writer)

Target pattern: each processor implements `Monolog\Processor\ProcessorInterface`, takes and returns a
`LogRecord`, and writes its value into `extra` under a key constant. Column order is decided by the
formatter (`COLUMNS`), not by the processors.

- [x] `IdProcessor` — move `context['id']` to `extra['id']`
  - [x] Make idempotent: `$record->context['id'] ?? $record->extra['id'] ?? null`
  - [x] Add `ProcessorInterface` and a `KEY` constant
  - [x] Create Abstract to generalize moving a context key to extra, with idempotent behaviour.
- [x] `RequestIdProcessor` — write request scope ID to `extra`; fix key casing (`requestid` → `requestId`)
- [x] `UserNameProcessor` — write user name to `extra`
- [x] `ActionAliasProcessor` — write action alias to `extra`
- [x] `ContextFilterProcessor` — remove keys via `$record->with(context: ...)` (`context` is readonly)
- [x] `DebugWidgetProcessor` — port to `LogRecord`; extract standalone detail-JSON generator for `MonitorLogHandler`
- [x] Remove `AbstractColumnPositionProcessor` once no subclass uses it
- [x] Use the processors' `KEY` constants in `CsvLogConfig::VERSIONS` columns
- [ ] CSV formatter: strip all column keys from the column-10 `extra` JSON
- [ ] **Timing:** processors on the inner CSV handler run at `FingersCrossedHandler` *flush* time, not log
  time. Buffered entries therefore get the user/action current at flush. Decide whether to push
  `UserNameProcessor`/`ActionAliasProcessor` (and `RequestIdProcessor`) onto the `FingersCrossedHandler`
  to capture log-time values. Pre-existing behaviour — document the decision either way.

## Version-aware reader (`LogCsvBuilder`)

Goal: render logs of *all* format versions through a single `exface.Core.LOG_ENTRY` object. The model
addresses columns by **name**; the query builder translates names to positions per file version using
`CsvLogConfig`. Old files are never migrated.

- [ ] Find the hook point in `CsvBuilder` / `CsvBuilderTrait::readCsv()`: a single place where data
  addresses are resolved to column indexes
- [ ] Create `QueryBuilders\LogCsvBuilder extends CsvBuilder`
  - [ ] Detect the version from the file name being read (`file_suffix` in `CsvLogConfig::VERSIONS`;
    no suffix = version 1)
  - [ ] Translate name → index via `CsvLogConfig::getColumns($version)` for **columns, filters and
    sorters** (filters currently use the numeric data address as alias, `CsvBuilder` line ~104)
  - [ ] Return `null` for columns that do not exist in the file's version
  - [ ] Take delimiter/enclosure/escape from `CsvLogConfig` instead of `DATA_ADDRESS_PROPS`
- [ ] Check how query builders are assigned (per data source, not per object); likely add a dedicated
  "log files" data source using `LogCsvBuilder` and point `LOG_ENTRY` at it
- [ ] Change `LOG_ENTRY` attribute DATA_ADDRESS from indexes (`0`–`10`) to column names
  (`Model/exface.Core.LOG_ENTRY/04_ATTRIBUTE.json`)
- [ ] Check `TRACE_LOG` entries: same reader, or keep them on plain `CsvBuilder`?
- [ ] Choose a file naming scheme so the `LOG` / `TRACE_LOG` file masks (`logs/*.log`,
  `logs/traces/*.csv`) match all versions, e.g. `<date>.v2.log`
- [ ] Writer side: build file names from `CsvLogConfig` in `LoggerFactory` and `Tracer`
- [ ] `LogCleaner`: validate field count and repair per version via `CsvLogConfig`
- [ ] `LogCleaner::cleanUpLogs()` (line ~128) derives the details folder via `pathinfo(PATHINFO_FILENAME)`.
  For `<date>.v2.log` that yields `<date>.v2` instead of `<date>` → expired v2 detail folders are never
  deleted. Strip the version suffix (shared helper in `CsvLogConfig`)
- [ ] Test: mixed v1 + current-version files render correctly, incl. filtering and sorting