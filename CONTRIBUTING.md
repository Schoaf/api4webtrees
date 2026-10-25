# Contributing

api4webtrees is a small module with one maintainer. Pull requests are welcome, but please keep to
these rules so that they can be reviewed in reasonable time.

## Before a pull request

* **Open an issue first** and describe the use case: which app needs what, and why the existing
  API cannot do it. Wait for an answer before writing code. **Many wishes can be met by the client
  with the fields the API already has.**
* **One feature per pull request.** A PR that adds two routes and a tag registration is three PRs,
  each with its own docs. Small PRs are merged quickly, large ones wait.
* **Bug fixes** need no issue. Say what was wrong and how to reproduce it.

## In a pull request

* **Do not set an API level.** The maintainer assigns the level at merge; levels are shared with
  [api4webtrees-gedcom7-lab](https://github.com/thobgg/api4webtrees-gedcom7-lab). Describe the
  feature; write "level n" in the docs and CHANGELOG, and it will be replaced.
* **The module must run on webtrees 2.2.** Keep 2.3 in mind: where webtrees differs, branch by
  `class_exists` or `method_exists`, as the module does elsewhere. Do not add dependencies on
  libraries that webtrees may drop.
* **Privacy as webtrees does it.** Every answer shows exactly what webtrees shows this user. A
  new route that reads a record or a file checks `canShow()` on every record it touches.
* **Tests, README, CHANGELOG, `tests/manifest.py`** for every change, as in the existing code.
  German comments in the code are fine; the docs are in English.
* **Which tools you write the code with is your business.** You must have run it on your own
  webtrees and be able to answer questions about it.

## Review

* A pull request may stay open for a while. No answer within a week means the maintainer has not
  got to it yet, not that it is rejected.
* Features that only one client needs may be declined or postponed. The API is for all apps on
  the "App" page, and every level is kept forever.
* The maintainer may merge a part of a PR (one commit) and ask for changes to the rest.
