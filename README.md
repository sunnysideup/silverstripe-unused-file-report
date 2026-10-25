# Unused File Report

This module adds an "Unused Files" report to the CMS. Because generating the list
of potentially unused files could take a long time on even a reasonably small site
the report does not work from live data. Instead, a task has been created that will
populate a table in the DB with the list of files that may not be in use.

In addition, if the [Queued Jobs](https://github.com/symbiote/silverstripe-queuedjobs)
module is installed a job will be created that will allow the task to be scheduled
at a convenient time.

## Versions

This version requires Silverstripe 5.

For Silverstripe 3 use the 1.x releases.
For Silverstripe 4 use the 2.x releases.

## Installation

```
composer require robingram/silverstripe-unused-file-report
```

## Running the task

### Through a browser

The report builder task will appear under `/dev/tasks/` as "Build table for
Unused File Reports". Click on the task title to initiate it.

### From the command line

The task can be run from the command line using `sake`:

```sake dev/tasks/UnusedFileReportBuildTask```

## Viewing the report

The report will appear under the Reports tab as "Unused Files Report". If the
builder task has not been run the report will contain no data.

## Running/scheduling the job

If the Queued Jobs module is installed then the report builder job will appear
in the "Create job of type" drop-down list in the Jobs tab as `UnusedFileReportJob`.

## Data clean-up tasks

Two build tasks help to clean up folders and files whose draft and live
versions are out of sync (for example, files flagged as "Modified" in the CMS).
Both tasks should be run with `--dry-run` first.

### Force write all folders

Force-writes every folder on the draft stage and re-publishes the folders that
were already published. Folders are processed breadth-first, so parents are
always written before their children. Folders that are not published are
written to draft only. Only the folder itself is published (`publishSingle`),
so draft-only children and files are not published as a side effect.

CLI:

```
vendor/bin/sake tasks:force-write-folders [--dry-run] [--no-publish]
```

Browser:

```
/dev/tasks/force-write-folders?dry-run=1
```

Options:

| Option         | Description                                         |
|----------------|-----------------------------------------------------|
| `--dry-run`    | Report what would happen without writing anything.  |
| `--no-publish` | Write to draft only, never publish.                 |

The task prints a summary (written, published, failed) and exits with a
failure code if any folder failed.

### Re-publish modified files

Publishes the draft version of every file (not folders) that is published but
has unpublished draft changes, so it is no longer flagged as "Modified" in the
CMS. Draft-only files (never published, or unpublished) are left alone.

CLI:

```
vendor/bin/sake tasks:republish-modified-files [--dry-run]
```

Browser:

```
/dev/tasks/republish-modified-files?dry-run=1
```

Options:

| Option      | Description                                           |
|-------------|-------------------------------------------------------|
| `--dry-run` | Report what would happen without publishing anything. |

The task prints a summary (published, skipped, failed) and exits with a
failure code if any file failed.

Tip: run "Force write all folders" first if folders are also out of sync.

## Credits

Thanks to Christchurch City Council, New Zealand, for enabling the development
of this module.
