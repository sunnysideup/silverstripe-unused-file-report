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
 * Force-writes every Folder (draft stage) and re-publishes those that
 * were already published. Parents are always processed before children.
 *
 * Writing a Folder on draft cascades a draft write to all of its children,
 * which leaves previously clean published files flagged as "Modified".
 * To undo that side effect, files that were published and clean before the
 * run, and are modified after it, get re-published. Files that already had
 * genuine draft changes before the run are left alone.
 *
 * CLI:      vendor/bin/sake tasks:force-write-folders [--dry-run] [--no-publish]
 * Browser:  /dev/tasks/force-write-folders?dry-run=1
 */
class ForceWriteFoldersTask extends BuildTask
{
    protected static string $commandName = 'force-write-folders';

    protected string $title = 'Force write all folders';

    protected static string $description = 'Force-writes every folder, re-publishes the ones that are already published, and re-publishes files that only became modified because of the cascade.';

    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen without writing anything'),
            new InputOption('no-publish', null, InputOption::VALUE_NONE, 'Write to draft only, never publish (folders or files)'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $noPublish = (bool) $input->getOption('no-publish');

        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing will be written.</comment>');
        }

        $stats = ['written' => 0, 'published' => 0, 'files' => 0, 'failed' => 0];

        Versioned::withVersionedMode(function () use ($output, $dryRun, $noPublish, &$stats): void {
            Versioned::set_stage(Versioned::DRAFT);

            // Snapshot files that already have genuine draft changes, so we never publish those.
            $alreadyModified = array_flip($this->getModifiedFileIds());
            $output->writeln(sprintf('%d file(s) already modified before the run; they will be left alone.', count($alreadyModified)));

            // Breadth-first walk from the root so parents are written before children.
            $queue = [0];

            while ($queue !== []) {
                $parentId = array_shift($queue);

                /** @var Folder $folder */
                foreach (Folder::get()->filter('ParentID', $parentId)->sort('ID') as $folder) {
                    $queue[] = $folder->ID;
                    $wasPublished = $folder->isPublished();
                    $label = sprintf('#%d %s', $folder->ID, $folder->getFilename() ?: $folder->Name);

                    if ($dryRun) {
                        $output->writeln(sprintf(
                            'Would write %s%s',
                            $label,
                            $wasPublished && !$noPublish ? ' and publish' : ''
                        ));
                        continue;
                    }

                    try {
                        $folder->forceChange();
                        $folder->write();
                        $stats['written']++;

                        $action = 'written';

                        if ($wasPublished && !$noPublish) {
                            // publishSingle: don't publish draft-only children/files along with it
                            $folder->publishSingle();
                            $stats['published']++;
                            $action .= ' + published';
                        }

                        $output->writeln(sprintf('<info>%s</info> %s', $label, $action));
                    } catch (Throwable $e) {
                        $stats['failed']++;
                        $output->writeln(sprintf('<error>%s failed: %s</error>', $label, $e->getMessage()));
                    }

                    // Keep memory down on large asset trees
                    $folder->destroy();
                }
            }

            if ($dryRun || $noPublish) {
                return;
            }

            // Re-publish files that only became "modified" because of the folder cascade.
            $output->writeln('');
            $output->writeln('Re-publishing files modified by the folder writes...');

            foreach ($this->getModifiedFileIds() as $id) {
                if (isset($alreadyModified[$id])) {
                    continue;
                }

                /** @var File|null $file */
                $file = File::get()->byID($id);

                if ($file === null) {
                    continue;
                }

                $label = sprintf('#%d %s', $file->ID, $file->getFilename() ?: $file->Name);

                try {
                    $file->publishSingle();
                    $stats['files']++;
                    $output->writeln(sprintf('<info>%s</info> re-published', $label));
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $output->writeln(sprintf('<error>%s failed: %s</error>', $label, $e->getMessage()));
                }

                $file->destroy();
            }
        });

        $output->writeln('');
        $output->writeln(sprintf(
            'Done. Folders written: %d, folders published: %d, files re-published: %d, failed: %d',
            $stats['written'],
            $stats['published'],
            $stats['files'],
            $stats['failed']
        ));

        return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * IDs of files (not folders) that are published but whose draft version
     * differs from live, i.e. what the CMS shows as "Modified".
     *
     * @return int[]
     */
    private function getModifiedFileIds(): array
    {
        $baseTable = DataObject::getSchema()->baseDataTable(File::class);
        $liveTable = File::singleton()->stageTable($baseTable, Versioned::LIVE);

        return array_map('intval', File::get()
            ->exclude('ClassName', Folder::class)
            ->innerJoin($liveTable, sprintf('"%s"."ID" = "%s"."ID"', $liveTable, $baseTable))
            ->where(sprintf('"%s"."Version" <> "%s"."Version"', $baseTable, $liveTable))
            ->column('ID'));
    }
}
