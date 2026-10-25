<?php

declare(strict_types=1);

namespace RobIngram\SilverStripe\UnusedFileReport\Tasks;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Re-publishes every file (not folders) that is published but has draft
 * changes, so it is no longer flagged as "Modified" in the CMS.
 * Draft-only (never published / unpublished) files are left alone.
 *
 * CLI:      vendor/bin/sake tasks:republish-modified-files [--dry-run]
 * Browser:  /dev/tasks/republish-modified-files?dry-run=1
 */
class RepublishModifiedFilesTask extends BuildTask
{
    protected static string $commandName = 'republish-modified-files';

    protected string $title = 'Re-publish modified files';

    protected static string $description = 'Publishes the draft version of every published file that has unpublished changes.';

    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen without publishing anything'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing will be published.</comment>');
        }

        $stats = ['published' => 0, 'skipped' => 0, 'failed' => 0];

        Versioned::withVersionedMode(function () use ($output, $dryRun, &$stats): void {
            Versioned::set_stage(Versioned::DRAFT);

            $ids = $this->getModifiedFileIds();
            $output->writeln(sprintf('Found %d modified file(s).', count($ids)));

            foreach ($ids as $id) {
                /** @var File|null $file */
                $file = File::get()->byID($id);

                if ($file === null) {
                    continue;
                }

                $label = sprintf('#%d %s', $file->ID, $file->getFilename() ?: $file->Name);

                // Double-check with Versioned's own logic (same check the CMS badge uses)
                if (!$file->isPublished() || !$file->isModifiedOnDraft()) {
                    $stats['skipped']++;
                    $output->writeln(sprintf('<comment>%s skipped (not modified)</comment>', $label));
                    $file->destroy();
                    continue;
                }

                if ($dryRun) {
                    $output->writeln(sprintf('Would publish %s', $label));
                    $file->destroy();
                    continue;
                }

                try {
                    // publishSingle: files own nothing, and we don't want to touch anything else
                    $file->publishSingle();
                    $stats['published']++;
                    $output->writeln(sprintf('<info>%s</info> published', $label));
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $output->writeln(sprintf('<error>%s failed: %s</error>', $label, $e->getMessage()));
                }

                // Keep memory down on large asset stores
                $file->destroy();
            }
        });

        $output->writeln('');
        $output->writeln(sprintf(
            'Done. Published: %d, skipped: %d, failed: %d',
            $stats['published'],
            $stats['skipped'],
            $stats['failed']
        ));

        return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * IDs of files that exist on both stages but whose draft version differs
     * from live. Collected up front so publishing doesn't shift the result set.
     *
     * @return int[]
     */
    private function getModifiedFileIds(): array
    {
        $baseTable = DataObject::getSchema()->baseDataTable(File::class);
        $liveTable = File::singleton()->stageTable($baseTable, Versioned::LIVE);

        return File::get()
            ->exclude('ClassName', Folder::class)
            ->innerJoin($liveTable, sprintf('"%s"."ID" = "%s"."ID"', $liveTable, $baseTable))
            ->where(sprintf('"%s"."Version" <> "%s"."Version"', $baseTable, $liveTable))
            ->sort('ID')
            ->column('ID');
    }
}
