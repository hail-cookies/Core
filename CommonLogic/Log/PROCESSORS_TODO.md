# Processor port to Monolog 3 (prerequisite for CSV writer)

Target pattern: each processor implements `Monolog\Processor\ProcessorInterface`, takes and returns a
`LogRecord`, and writes its value into `extra` under a key constant. Column order is decided by the
formatter (`COLUMNS`), not by the processors.

- [x] `IdProcessor` — move `context['id']` to `extra['id']`
  - [x] Make idempotent: `$record->context['id'] ?? $record->extra['id'] ?? null`
  - [x] Add `ProcessorInterface` and a `KEY` constant
  - [x] Create Abstract to generalize moving a context key to extra, with idempotent behaviour.
- [x] `RequestIdProcessor` — write request scope ID to `extra`; fix key casing (`requestid` → `requestId`)
- [ ] `UserNameProcessor` — write user name to `extra`
- [x] `ActionAliasProcessor` — write action alias to `extra`
- [x] `ContextFilterProcessor` — remove keys via `$record->with(context: ...)` (`context` is readonly)
- [ ] `DebugWidgetProcessor` — port to `LogRecord`; extract standalone detail-JSON generator for `MonitorLogHandler`
- [ ] Remove `AbstractColumnPositionProcessor` once no subclass uses it
- [ ] Use the processors' `KEY` constants in the formatter's `COLUMNS`; strip them from the column-10 `extra` JSON
- [ ] **Timing:** processors on the inner CSV handler run at `FingersCrossedHandler` *flush* time, not log
  time. Buffered entries therefore get the user/action current at flush. Decide whether to push
  `UserNameProcessor`/`ActionAliasProcessor` (and `RequestIdProcessor`) onto the `FingersCrossedHandler`
  to capture log-time values. Pre-existing behaviour — document the decision either way.