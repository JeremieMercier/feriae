# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-09-07

First release.

### Added

- Import of the public holidays of a country or a region as GLPI close
  times, from the plugin page (Setup > Plugins > Feriae): source and
  period, target entity and calendars, holiday types, with a preview of
  what the selected region and year contain.
- Yasumi as the holiday source behind a `HolidaySource` interface: 165
  countries and regions computed offline, holiday and region names in the
  user language with an English fallback, plus providers for the French
  overseas territories.
- Fixed dates (New Year's Day, Christmas...) imported as recurrent close
  times shared by the imported years, moving holidays as one dated period
  per year.
- Idempotent imports: existing periods are refreshed rather than
  duplicated, periods deleted or moved by hand are left alone, and a
  "replace" option starts over.
- Tracking of what each import created, per source, region, entity and
  year, with the list of its days and calendars, a "Child entities"
  switch, and buttons to remove or renew a batch, or all of them.
- Automatic imports renewed every year by the `importholidays` automatic
  action, with a "Run" button per import and for all of them.
- Close times of the active entities shown in the planning as all-day
  events, with their own filter and colour, and a non-blocking warning
  when a task or an external event is planned on a closed day.
- Uninstall removes the close times the plugin imported, never the ones
  entered by hand.
- French translation.

[0.1.0]: https://github.com/JeremieMercier/feriae/releases/tag/0.1.0
