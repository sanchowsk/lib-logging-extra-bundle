# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 2.3.0
### Added
- Added `StdoutJsonFormatter` (service `paysera_logging_extra.formatter.stdout_json`) — a compact one-object-per-line JSON formatter for stdout, collected by VictoriaLogs. Emits `timestamp`, `application_name`, `channel`, syslog `level` (DEBUG=7 … EMERGENCY=0), `level_name`, `message`, optional `context`/`extra`, and a top-level `correlation_id` (hoisted from `extra`), matching the canonical `StdoutJsonFormatter` from `evp/lib-application-logging-bundle` for those fields (it does not split exceptions into a `full_message` field). Lines are capped at 32766 bytes; oversize records are flagged `truncated` and shrunk by dropping `context`/`extra` then truncating `message`. Opt in by wiring a `php://stdout` handler with this formatter into the existing `graylog_failsafe` `whatfailuregroup` (see README). Existing GELF/Graylog behaviour is unchanged.

## 2.2.0
### Added
- Added Symfony ^6 support.

## 2.1.1 - 2023-04-13
### Fixed
- Fix support for Symfony 5.x

## 2.1.0 - 2023-03-29
### Added
- Added Symfony ^5 support.

## 2.0.0 - 2023-03-09
### Added
- PHP 8 support

## 1.0.2 - 2022-08-30
### Fixed
- Fixing deprecation error in symfony versions above 4.2

## 1.0.1 - 2021-03-18
### Changed
- Removed strict types in `FormatterTrait` method parameters, allowing for `monolog/monolog:^1.24` compatibility

## 1.0.0 - 2020-03-13
### Added
- Added `Paysera-Correlation-Id` header to response containing current request's correlation id
